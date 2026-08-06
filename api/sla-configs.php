<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (method() === 'GET') { jsonResponse(dbFetchAll("SELECT * FROM sla_configs ORDER BY priority")); }
if (method() === 'POST') {
    if (!isAdmin()) jsonResponse(['error'=>'Forbidden'],403);
    $b = getBody();
    $priority = $b['priority'] ?? 'p3';
    $rh = (int)($b['responseHours'] ?? 4);
    $resh = (int)($b['resolveHours'] ?? 24);
    $existing = dbFetch("SELECT id FROM sla_configs WHERE priority=?", [$priority]);
    if ($existing) {
        dbRun("UPDATE sla_configs SET response_time_hours=?, resolution_time_hours=? WHERE priority=?", [$rh, $resh, $priority]);
    } else {
        dbRun("INSERT INTO sla_configs (id,priority,response_time_hours,resolution_time_hours) VALUES (?,?,?,?)",
            [newUuid(), $priority, $rh, $resh]);
    }
    jsonResponse(dbFetch("SELECT * FROM sla_configs WHERE priority=?", [$priority]));
}
