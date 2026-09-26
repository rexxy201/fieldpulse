<?php
/**
 * SLA Warning Checker — call via cron every 15–30 minutes
 * e.g. cPanel cron:  * /15 * * * *  curl -s https://yourdomain.com/api/sla-check
 *
 * Sends warning emails when tickets are within slaWarnHours of their SLA deadline.
 * Also sends breach emails when SLA has just been crossed.
 * Tracks sent warnings via sla_warned_at column to avoid duplicates.
 */
require_once __DIR__ . '/../config.php';

// Token guard — required. No slaCheckToken configured means this endpoint
// refuses every request rather than silently allowing unauthenticated
// access; set it via Admin -> Automation & Cron Tokens before wiring the cron.
$cfg        = getAppConfig();
$guardToken = trim($cfg['slaCheckToken'] ?? '');
if ($guardToken === '' || ($_GET['token'] ?? '') !== $guardToken) {
    http_response_code(403);
    exit('Forbidden');
}

$warnHours = max(1, (int)($cfg['slaWarnHours'] ?? 2));
$warned    = 0;
$breached  = 0;
$errors    = [];

// ── Find tickets that need a warning ─────────────────────────────────────────
// Approaching breach: sla_breach_at is within warnHours AND not yet warned AND not resolved
$approaching = dbFetchAll(
    "SELECT t.*,
            u_assigned.name  AS assigned_name,  u_assigned.email  AS assigned_email,
            u_creator.name   AS creator_name,   u_creator.email   AS creator_email,
            u_supervisor.name AS sup_name,       u_supervisor.email AS sup_email
     FROM tickets t
     LEFT JOIN users u_assigned   ON u_assigned.id   = t.assigned_to
     LEFT JOIN users u_creator    ON u_creator.id    = t.created_by
     LEFT JOIN users u_supervisor ON u_supervisor.id = (
         SELECT u2.id FROM users u2
         LEFT JOIN (SELECT user_id, MAX(created_at) AS ll FROM audit_logs WHERE action='login' GROUP BY user_id) al ON al.user_id = u2.id
         WHERE u2.role IN ('supervisor-fiber','supervisor-noc','cx_supervisor','admin','project_admin')
         AND u2.status = 'active'
         ORDER BY COALESCE(al.ll,'2000-01-01') DESC LIMIT 1
     )
     WHERE t.sla_breach_at IS NOT NULL
       AND t.status NOT IN ('resolved','closed')
       AND t.sla_warned_at IS NULL
       AND t.sla_breach_at > NOW()
       AND t.sla_breach_at <= " . dbNowPlusInterval($warnHours, 'HOUR')
);

foreach ($approaching as $t) {
    $tn   = $t['ticket_number'] ?? '?';
    $desc = htmlspecialchars(substr($t['description'] ?? '', 0, 120));
    $due  = date('d M Y H:i', strtotime($t['sla_breach_at']));
    $link = siteBaseUrl() . '/ticket/' . $t['id'];
    $subj = "⚠ SLA Warning — {$tn} due {$due}";
    $body = "<p style='color:#d97706;font-weight:700'>⚠ SLA Warning</p>
             <p>Ticket <strong>{$tn}</strong> is approaching its SLA deadline.</p>
             <table style='border-collapse:collapse;font-size:.9rem'>
               <tr><td style='padding:4px 10px;background:#fef3c7;font-weight:600'>Ticket</td><td style='padding:4px 10px'>{$tn}</td></tr>
               <tr><td style='padding:4px 10px;background:#fef3c7;font-weight:600'>Issue</td><td style='padding:4px 10px'>{$desc}</td></tr>
               <tr><td style='padding:4px 10px;background:#fef3c7;font-weight:600'>SLA Due</td><td style='padding:4px 10px;color:#d97706;font-weight:700'>{$due}</td></tr>
             </table>
             <p><a href='{$link}'>View &amp; update ticket →</a></p>
             <p style='color:#64748b;font-size:.85rem'>FieldPulse · MangoNet</p>";

    $recipients = [];
    if (!empty($t['assigned_email']))  $recipients[] = [$t['assigned_email'],  $t['assigned_name']];
    if (!empty($t['creator_email']) && $t['creator_email'] !== $t['assigned_email'])
                                       $recipients[] = [$t['creator_email'],   $t['creator_name']];
    if (!empty($t['sup_email'])    && !in_array($t['sup_email'], array_column($recipients, 0)))
                                       $recipients[] = [$t['sup_email'],       $t['sup_name']];

    foreach ($recipients as [$email, $name]) {
        try {
            sendEmail($email, $name ?: 'Team', $subj, $body);
            $warned++;
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    // Mark as warned
    dbRun("UPDATE tickets SET sla_warned_at = NOW() WHERE id = ?", [$t['id']]);
}

// ── Find newly breached tickets (breach in last 30 min, not already warned) ──
$newlyBreached = dbFetchAll(
    "SELECT t.*,
            u_assigned.name AS assigned_name, u_assigned.email AS assigned_email,
            u_creator.name  AS creator_name,  u_creator.email  AS creator_email
     FROM tickets t
     LEFT JOIN users u_assigned ON u_assigned.id = t.assigned_to
     LEFT JOIN users u_creator  ON u_creator.id  = t.created_by
     WHERE t.sla_breach_at IS NOT NULL
       AND t.status NOT IN ('resolved','closed')
       AND t.sla_breach_at <= NOW()
       AND t.sla_breach_at >= " . dbNowMinusInterval(30, 'MINUTE') . "
       AND t.sla_warned_at IS NULL"
);

foreach ($newlyBreached as $t) {
    $tn   = $t['ticket_number'] ?? '?';
    $desc = htmlspecialchars(substr($t['description'] ?? '', 0, 120));
    $due  = date('d M Y H:i', strtotime($t['sla_breach_at']));
    $link = siteBaseUrl() . '/ticket/' . $t['id'];
    $subj = "🚨 SLA BREACHED — {$tn}";
    $body = "<p style='color:#dc2626;font-weight:700'>🚨 SLA Breached</p>
             <p>Ticket <strong>{$tn}</strong> has exceeded its SLA deadline and requires immediate attention.</p>
             <table style='border-collapse:collapse;font-size:.9rem'>
               <tr><td style='padding:4px 10px;background:#fee2e2;font-weight:600'>Ticket</td><td style='padding:4px 10px'>{$tn}</td></tr>
               <tr><td style='padding:4px 10px;background:#fee2e2;font-weight:600'>Issue</td><td style='padding:4px 10px'>{$desc}</td></tr>
               <tr><td style='padding:4px 10px;background:#fee2e2;font-weight:600'>SLA Was Due</td><td style='padding:4px 10px;color:#dc2626;font-weight:700'>{$due}</td></tr>
             </table>
             <p><a href='{$link}'>Resolve ticket now →</a></p>
             <p style='color:#64748b;font-size:.85rem'>FieldPulse · MangoNet</p>";

    $recipients = [];
    if (!empty($t['assigned_email'])) $recipients[] = [$t['assigned_email'], $t['assigned_name']];
    if (!empty($t['creator_email']) && $t['creator_email'] !== $t['assigned_email'])
                                      $recipients[] = [$t['creator_email'],  $t['creator_name']];

    foreach ($recipients as [$email, $name]) {
        try {
            sendEmail($email, $name ?: 'Team', $subj, $body);
            $breached++;
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
    dbRun("UPDATE tickets SET sla_warned_at = NOW() WHERE id = ?", [$t['id']]);
}

jsonResponse([
    'ok'         => true,
    'warnings'   => $warned,
    'breached'   => $breached,
    'errors'     => $errors,
    'checked_at' => date('Y-m-d H:i:s'),
]);
