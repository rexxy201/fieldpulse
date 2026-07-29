<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$byStatus   = dbFetchAll("SELECT status, COUNT(*) AS count FROM tickets GROUP BY status");
$byPriority = dbFetchAll("SELECT priority, COUNT(*) AS count FROM tickets GROUP BY priority");
$_iv30   = dbNowMinusInterval(30, 'DAY');
$_tsDiff = dbSecondsDiff('created_at', 'resolved_at');
$_dateDay = dbDate('created_at');
$trend      = dbFetchAll("SELECT {$_dateDay} AS day, COUNT(*) AS count FROM tickets WHERE created_at >= {$_iv30} GROUP BY day ORDER BY day");
$mttr       = dbFetch("SELECT AVG({$_tsDiff}/3600.0) AS avg_hours FROM tickets WHERE resolved_at IS NOT NULL");
$slaTotal   = dbFetch("SELECT COUNT(*) AS total, SUM(CASE WHEN sla_breach_at > resolved_at OR (sla_breach_at > NOW() AND status NOT IN ('closed','resolved')) THEN 1 ELSE 0 END) AS ok FROM tickets WHERE sla_breach_at IS NOT NULL");
$leaderboard= dbFetchAll("
    SELECT u.name, COUNT(t.id) AS resolved
    FROM users u LEFT JOIN tickets t ON t.assigned_to=u.id AND t.status IN ('resolved','closed')
    WHERE u.role IN ('engineer','noc_engineer')
    GROUP BY u.name ORDER BY resolved DESC LIMIT 10
");

jsonResponse(compact('byStatus','byPriority','trend','mttr','slaTotal','leaderboard'));