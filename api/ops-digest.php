<?php
/**
 * Automated Ops Digest — call via cron (weekly is typical)
 * e.g. cPanel cron schedule "weekly":
 *   curl -s "https://fieldpulse.mangonetonline.com/api/ops-digest?token=YOUR_TOKEN"
 *
 * Gathers ticket + installation performance for the period, has AI turn it
 * into a short narrative, and emails it to the recipients configured in
 * Admin -> AI Assistant -> Automated Ops Digest. If AI isn't enabled/fails,
 * falls back to a plain numbers-only email so the digest still goes out.
 *
 * Same optional token-guard pattern as api/sla-check.php.
 */
require_once __DIR__ . '/../config.php';

$cfg        = getAppConfig();
$guardToken = trim($cfg['slaCheckToken'] ?? '');
if ($guardToken && ($_GET['token'] ?? '') !== $guardToken) {
    http_response_code(403);
    exit('Forbidden');
}

if (($cfg['opsDigestEnabled'] ?? '0') !== '1') {
    jsonResponse(['ok' => true, 'sent' => false, 'reason' => 'Ops digest is disabled in Admin settings.']);
}
$recipients = array_filter(array_map('trim', explode(',', $cfg['opsDigestEmails'] ?? '')));
if (!$recipients) {
    jsonResponse(['ok' => true, 'sent' => false, 'reason' => 'No recipient emails configured.']);
}

$days = max(1, (int)($_GET['days'] ?? 7));
$_ivPeriod = dbNowMinusInterval($days, 'DAY');
$_tsDiff   = dbSecondsDiff('created_at', 'resolved_at');

// ── Ticket stats ─────────────────────────────────────────────────────────
$ticketStats = dbFetch(
    "SELECT
        COUNT(*) AS created,
        SUM(CASE WHEN resolved_at IS NOT NULL THEN 1 ELSE 0 END) AS resolved,
        ROUND(AVG(CASE WHEN resolved_at IS NOT NULL THEN {$_tsDiff}/3600.0 END), 1) AS mttr_hours,
        SUM(CASE WHEN sla_breach_at IS NOT NULL AND (resolved_at < sla_breach_at OR (status NOT IN ('resolved','closed') AND sla_breach_at > NOW())) THEN 1 ELSE 0 END) AS sla_ok,
        SUM(CASE WHEN sla_breach_at IS NOT NULL THEN 1 ELSE 0 END) AS sla_total
     FROM tickets WHERE created_at >= {$_ivPeriod}"
);
$openNow = dbFetch("SELECT COUNT(*) AS c FROM tickets WHERE status NOT IN ('resolved','closed')")['c'] ?? 0;
$breachedNow = dbFetch("SELECT COUNT(*) AS c FROM tickets WHERE status NOT IN ('resolved','closed') AND sla_breach_at IS NOT NULL AND sla_breach_at < NOW()")['c'] ?? 0;
$slaRate = ($ticketStats['sla_total'] ?? 0) > 0 ? round($ticketStats['sla_ok'] / $ticketStats['sla_total'] * 100, 1) : null;

$topEngineers = dbFetchAll(
    "SELECT u.name, COUNT(t.id) AS resolved
     FROM users u JOIN tickets t ON t.assigned_to = u.id
     WHERE t.resolved_at >= {$_ivPeriod} AND t.status IN ('resolved','closed')
     GROUP BY u.id, u.name ORDER BY resolved DESC LIMIT 3"
);

// ── Installation stats ───────────────────────────────────────────────────
$_diffPay = dbSecondsDiff('payment_confirmed_at', 'completed_at');
$installStats = dbFetch(
    "SELECT
        SUM(CASE WHEN status = 'completed' AND completed_at >= {$_ivPeriod} THEN 1 ELSE 0 END) AS completed_in_period,
        ROUND(AVG(CASE WHEN status='completed' AND completed_at >= {$_ivPeriod} AND payment_confirmed_at IS NOT NULL THEN ({$_diffPay})/3600.0 END), 1) AS avg_hours
     FROM installation_profiles"
);
$installPending = dbFetch("SELECT COUNT(*) AS c FROM installation_profiles WHERE status <> 'completed'")['c'] ?? 0;
$installOverdue = dbFetch("SELECT COUNT(*) AS c FROM installation_profiles WHERE status <> 'completed' AND sla_due_at IS NOT NULL AND sla_due_at < NOW()")['c'] ?? 0;

// ── Vendors currently overdue ────────────────────────────────────────────
$vendorsOverdue = dbFetchAll(
    "SELECT v.name, COUNT(p.id) AS overdue_count
     FROM installation_profiles p JOIN vendors v ON v.id = p.vendor_id
     WHERE p.status <> 'completed' AND p.sla_due_at IS NOT NULL AND p.sla_due_at < NOW()
     GROUP BY v.id, v.name ORDER BY overdue_count DESC LIMIT 5"
);

$summary = [
    'period_days'              => $days,
    'tickets_created'          => (int)($ticketStats['created'] ?? 0),
    'tickets_resolved'         => (int)($ticketStats['resolved'] ?? 0),
    'mttr_hours'               => $ticketStats['mttr_hours'] ?? null,
    'sla_compliance_pct'       => $slaRate,
    'open_tickets_now'         => (int)$openNow,
    'breached_tickets_now'     => (int)$breachedNow,
    'top_engineers'            => array_map(fn($e) => "{$e['name']} ({$e['resolved']} resolved)", $topEngineers),
    'installations_completed'  => (int)($installStats['completed_in_period'] ?? 0),
    'avg_install_hours'        => $installStats['avg_hours'] ?? null,
    'installations_pending'    => (int)$installPending,
    'installations_overdue'    => (int)$installOverdue,
    'vendors_overdue'          => array_map(fn($v) => "{$v['name']} ({$v['overdue_count']} overdue)", $vendorsOverdue),
];

// ── Narrative (AI if available, plain numbers otherwise) ────────────────
$narrative = null;
if (aiEnabled()) {
    $system = "You write a short, plain-language weekly operations summary for an internet service provider's "
            . "management team, from the ticket/installation stats given as JSON. 3-5 short paragraphs or a tight "
            . "bulleted list — whichever reads better. Call out anything that looks concerning (SLA compliance "
            . "below 85%, overdue installs, breached tickets) plainly, and anything that looks good too. No fluff, "
            . "no generic advice, just what the numbers say. Plain text only, no markdown headers.";
    $narrative = aiChat($system, json_encode($summary, JSON_PRETTY_PRINT));
}

$appCfg = getAppConfig();
$co = htmlspecialchars($appCfg['companyName'] ?? 'FieldPulse');
$periodLabel = "{$days}-day";

if ($narrative) {
    $bodyHtml = "<p>" . nl2br(htmlspecialchars($narrative)) . "</p>";
} else {
    // Fallback: plain numbers, no AI narrative
    $bodyHtml = "<ul>
        <li>Tickets created: {$summary['tickets_created']}, resolved: {$summary['tickets_resolved']}</li>
        <li>MTTR: " . ($summary['mttr_hours'] ?? '—') . " hrs — SLA compliance: " . ($summary['sla_compliance_pct'] !== null ? $summary['sla_compliance_pct'].'%' : '—') . "</li>
        <li>Currently open: {$summary['open_tickets_now']}, currently breached: {$summary['breached_tickets_now']}</li>
        <li>Installations completed: {$summary['installations_completed']}, pending: {$summary['installations_pending']}, overdue: {$summary['installations_overdue']}</li>
      </ul>";
}

$subject = "{$co} Ops Digest — Last {$days} Days";
$fullHtml = "<h2 style='color:#0ea5e9'>{$co} Operations Digest</h2>
             <p style='color:#64748b;font-size:.85rem'>{$periodLabel} summary, generated " . date('d M Y H:i') . "</p>
             {$bodyHtml}
             <p style='color:#94a3b8;font-size:.8rem;margin-top:2rem'>Generated automatically by FieldPulse.</p>";

$sentCount = 0;
foreach ($recipients as $email) {
    if (sendEmail($email, 'Team', $subject, $fullHtml)) $sentCount++;
}

jsonResponse([
    'ok'        => true,
    'sent'      => $sentCount > 0,
    'recipients'=> count($recipients),
    'delivered' => $sentCount,
    'ai_used'   => $narrative !== null,
    'summary'   => $summary,
]);
