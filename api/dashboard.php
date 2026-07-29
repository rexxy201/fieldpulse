<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$_tsDiff = dbSecondsDiff('created_at', 'resolved_at');
$stats = dbFetch("
    SELECT
        SUM(CASE WHEN status NOT IN ('closed','resolved') THEN 1 ELSE 0 END) AS open_tickets,
        SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) AS in_progress,
        SUM(CASE WHEN sla_breach_at < NOW() AND status NOT IN ('closed','resolved') THEN 1 ELSE 0 END) AS sla_breached,
        SUM(CASE WHEN type='installation' AND status NOT IN ('closed','resolved','completed') THEN 1 ELSE 0 END) AS pending_installs,
        AVG(CASE WHEN resolved_at IS NOT NULL THEN {$_tsDiff}/3600.0 ELSE NULL END) AS mttr_hours,
        SUM(CASE WHEN status NOT IN ('closed','resolved') AND sla_breach_at IS NOT NULL THEN 1 ELSE 0 END) AS total_sla,
        SUM(CASE WHEN sla_breach_at > NOW() AND status NOT IN ('closed','resolved') THEN 1 ELSE 0 END) AS sla_ok
    FROM tickets
");

// Hourly chart (last 24h)
$_iv24 = dbNowMinusInterval(24, 'HOUR');
$_hrFn = dbHour('created_at');
$chart = dbFetchAll("
    SELECT {$_hrFn} AS hour, COUNT(*) AS count
    FROM tickets WHERE created_at >= {$_iv24}
    GROUP BY hour ORDER BY hour
");

// Recent tickets
$recent = dbFetchAll("SELECT id,ticket_number,status,priority,customer_name,created_at FROM tickets ORDER BY created_at DESC LIMIT 8");

jsonResponse(['stats'=>$stats,'chart'=>$chart,'recent'=>$recent]);
