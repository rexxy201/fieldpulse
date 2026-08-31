<?php
/**
 * Report Subscriptions — call via cron (daily is enough; each subscription's
 * own cadence decides whether it's actually due, see reportSubscriptionIsDue()):
 *   curl -s "https://fieldpulse.mangonetonline.com/api/report-digest?token=YOUR_TOKEN"
 *
 * Generalizes the ops-digest/finance-digest pattern to any report on the
 * Reports page — each row in report_subscriptions is "this report, this
 * cadence, these recipients," created from the Reports page itself.
 * Same optional token-guard pattern as api/sla-check.php.
 */
require_once __DIR__ . '/../config.php';

$cfg        = getAppConfig();
$guardToken = trim($cfg['slaCheckToken'] ?? '');
if ($guardToken && ($_GET['token'] ?? '') !== $guardToken) {
    http_response_code(403);
    exit('Forbidden');
}

$subs = dbFetchAll("SELECT * FROM report_subscriptions WHERE enabled = 1");
$due  = array_filter($subs, 'reportSubscriptionIsDue');

$appCfg = getAppConfig();
$co = htmlspecialchars($appCfg['companyName'] ?? 'FieldPulse');
$results = [];

foreach ($due as $sub) {
    $days = reportSubscriptionWindowDays($sub['cadence']);
    $sinceExpr = dbNowMinusInterval($days, 'DAY');
    $hoursCreated = "ROUND(AVG(CASE WHEN t.resolved_at IS NOT NULL THEN (" . dbSecondsDiff('t.created_at', 't.resolved_at') . ")/3600.0 END), 1)";
    $type = $sub['report_type'];
    $rows = []; $cols = []; $title = REPORT_SUBSCRIPTION_TYPES[$type] ?? $type;

    if (in_array($type, ['department','engineer','olt','vendor','issue'], true)) {
        $cols = ['Name', 'Total', 'Resolved', 'Avg Hours'];
        $rows = match ($type) {
            'department' => dbFetchAll(
                "SELECT COALESCE(NULLIF(ft.route_to,''), 'unassigned') AS label, COUNT(*) total,
                        SUM(CASE WHEN t.status IN ('resolved','closed') THEN 1 ELSE 0 END) resolved, {$hoursCreated} avg_hours
                 FROM tickets t LEFT JOIN fault_types ft ON ft.id = t.fault_type_id
                 WHERE t.created_at >= {$sinceExpr} GROUP BY label ORDER BY total DESC LIMIT 10"
            ),
            'engineer' => dbFetchAll(
                "SELECT u.name AS label, COUNT(t.id) total,
                        SUM(CASE WHEN t.status IN ('resolved','closed') THEN 1 ELSE 0 END) resolved, {$hoursCreated} avg_hours
                 FROM users u LEFT JOIN tickets t ON t.assigned_to = u.id AND t.created_at >= {$sinceExpr}
                 WHERE u.role IN ('engineer','noc_engineer') GROUP BY u.id,u.name ORDER BY total DESC LIMIT 10"
            ),
            'olt' => dbFetchAll(
                "SELECT t.olt AS label, COUNT(*) total,
                        SUM(CASE WHEN t.status IN ('resolved','closed') THEN 1 ELSE 0 END) resolved, {$hoursCreated} avg_hours
                 FROM tickets t WHERE t.olt IS NOT NULL AND t.olt<>'' AND t.created_at >= {$sinceExpr}
                 GROUP BY t.olt ORDER BY total DESC LIMIT 10"
            ),
            'vendor' => dbFetchAll(
                "SELECT v.name AS label, COUNT(t.id) total,
                        SUM(CASE WHEN t.status IN ('resolved','closed') THEN 1 ELSE 0 END) resolved, {$hoursCreated} avg_hours
                 FROM vendors v LEFT JOIN tickets t ON t.vendor_id = v.id AND t.created_at >= {$sinceExpr}
                 GROUP BY v.id,v.name ORDER BY total DESC LIMIT 10"
            ),
            'issue' => dbFetchAll(
                "SELECT ft.name AS label, COUNT(t.id) total,
                        SUM(CASE WHEN t.status IN ('resolved','closed') THEN 1 ELSE 0 END) resolved, {$hoursCreated} avg_hours
                 FROM fault_types ft LEFT JOIN tickets t ON t.fault_type_id = ft.id AND t.created_at >= {$sinceExpr}
                 GROUP BY ft.id,ft.name ORDER BY total DESC LIMIT 10"
            ),
            default => [],
        };
    } elseif ($type === 'recurring') {
        $cols = ['Customer', 'Issue', 'Occurrences'];
        $rows = dbFetchAll(
            "SELECT c.name AS label, COALESCE(ft.name,'(no issue type)') AS label2, COUNT(*) AS total
             FROM tickets t JOIN customers c ON c.id = t.customer_id LEFT JOIN fault_types ft ON ft.id = t.fault_type_id
             WHERE t.customer_id IS NOT NULL AND t.created_at >= {$sinceExpr}
             GROUP BY c.id,c.name,ft.id,ft.name HAVING COUNT(*) > 1 ORDER BY total DESC LIMIT 10"
        );
    } elseif ($type === 'resolution') {
        $cols = ['Metric', 'Value'];
        $summary = dbFetch(
            "SELECT COUNT(*) total, SUM(CASE WHEN t.escalated_at IS NOT NULL THEN 1 ELSE 0 END) escalated,
                    {$hoursCreated} avg_hours
             FROM tickets t WHERE t.resolved_at IS NOT NULL AND t.created_at >= {$sinceExpr}"
        );
        $rows = [
            ['label' => 'Tickets resolved', 'total' => (int)($summary['total'] ?? 0)],
            ['label' => 'Escalated before resolution', 'total' => (int)($summary['escalated'] ?? 0)],
            ['label' => 'Avg hours to resolve', 'total' => $summary['avg_hours'] ?? '—'],
        ];
    }

    // ── Render + send ────────────────────────────────────────────────────────
    $rowsHtml = '';
    foreach ($rows as $r) {
        if ($type === 'recurring') {
            $rowsHtml .= '<tr><td>' . htmlspecialchars($r['label']) . '</td><td>' . htmlspecialchars($r['label2']) . '</td><td>' . (int)$r['total'] . '</td></tr>';
        } elseif ($type === 'resolution') {
            $rowsHtml .= '<tr><td>' . htmlspecialchars($r['label']) . '</td><td>' . htmlspecialchars((string)$r['total']) . '</td></tr>';
        } else {
            $rowsHtml .= '<tr><td>' . htmlspecialchars($r['label'] ?? '') . '</td><td>' . (int)($r['total'] ?? 0) . '</td><td>' . (int)($r['resolved'] ?? 0) . '</td><td>' . ($r['avg_hours'] ?? '—') . '</td></tr>';
        }
    }
    if (!$rowsHtml) $rowsHtml = '<tr><td colspan="' . count($cols) . '" style="color:#94a3b8">No data for this period.</td></tr>';

    $headHtml = '<tr>' . implode('', array_map(fn($c) => "<th style='text-align:left;border-bottom:2px solid #e2e8f0;padding:.4rem'>{$c}</th>", $cols)) . '</tr>';
    $bodyHtml = "<h2 style='color:#0ea5e9'>{$co} — {$title}</h2>
        <p style='color:#64748b;font-size:.85rem'>Last {$days} day" . ($days === 1 ? '' : 's') . ", generated " . date('d M Y H:i') . "</p>
        <table style='border-collapse:collapse;width:100%;font-size:.85rem'>{$headHtml}{$rowsHtml}</table>
        <p style='color:#94a3b8;font-size:.8rem;margin-top:2rem'>You're receiving this because a Report Subscription was set up on the Reports page in FieldPulse.</p>";

    $recipients = array_filter(array_map('trim', explode(',', $sub['recipients'])));
    $sentCount = 0;
    foreach ($recipients as $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && sendEmail($email, 'Team', "{$co} — {$title}", $bodyHtml)) $sentCount++;
    }
    dbRun("UPDATE report_subscriptions SET last_sent_at = NOW() WHERE id = ?", [$sub['id']]);
    $results[] = ['id' => $sub['id'], 'report_type' => $type, 'recipients' => count($recipients), 'delivered' => $sentCount];
}

jsonResponse(['ok' => true, 'due' => count($due), 'results' => $results]);
