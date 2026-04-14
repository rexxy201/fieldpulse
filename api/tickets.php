<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$user = currentUser();
$role = $user['role'];
$id   = $segments[2] ?? null;
$sub  = $segments[3] ?? null;

// ─── Comments sub-resource
if ($id && $sub === 'comments') {
    if (method() === 'GET') {
        jsonResponse(dbFetchAll("SELECT * FROM ticket_comments WHERE ticket_id = ? ORDER BY created_at", [$id]));
    }
    if (method() === 'POST') {
        $b = getBody();
        $newId = newUuid();
        dbRun("INSERT INTO ticket_comments (id, ticket_id, user_id, user_name, content) VALUES (?,?,?,?,?)",
            [$newId, $id, $user['id'], $user['name'], $b['content'] ?? '']);
        $row = dbFetch("SELECT * FROM ticket_comments WHERE id = ?", [$newId]);
        jsonResponse($row, 201);
    }
}

// ─── Bulk update
if ($id === 'bulk-update' && method() === 'POST') {
    $b = getBody();
    $ids = $b['ids'] ?? [];
    if (empty($ids)) jsonResponse(['error' => 'No IDs'], 400);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    if (!empty($b['status']))     dbRun("UPDATE tickets SET status=?, updated_at=NOW() WHERE id IN ($placeholders)", array_merge([$b['status']], $ids));
    if (!empty($b['assignedTo'])) dbRun("UPDATE tickets SET assigned_to=?, updated_at=NOW() WHERE id IN ($placeholders)", array_merge([$b['assignedTo']], $ids));
    if (!empty($b['priority']))   dbRun("UPDATE tickets SET priority=?, updated_at=NOW() WHERE id IN ($placeholders)", array_merge([$b['priority']], $ids));
    jsonResponse(['updated' => count($ids)]);
}

// ─── Auto-dispatch
if ($id === 'auto-dispatch' && method() === 'POST') {
    $unassigned = dbFetchAll("SELECT * FROM tickets WHERE status='open' AND assigned_to IS NULL");
    $dispatched = 0;
    foreach ($unassigned as $t) {
        $eng = dbFetch("SELECT id, name FROM users WHERE role='engineer' AND hub_id=? LIMIT 1", [$t['hub_id']]);
        if ($eng) {
            dbRun("UPDATE tickets SET assigned_to=?, status='in_progress', updated_at=NOW() WHERE id=?", [$eng['id'], $t['id']]);
            $dispatched++;
        }
    }
    jsonResponse(['dispatched' => $dispatched]);
}

// ─── Single ticket GET
if ($id && method() === 'GET') {
    $t = dbFetch("SELECT * FROM tickets WHERE id = ?", [$id]);
    if (!$t) jsonResponse(['error' => 'Not found'], 404);
    jsonResponse($t);
}

// ─── Ticket PATCH
if ($id && method() === 'PATCH') {
    $b = getBody();
    $allowed = ['status','priority','assigned_to','description','olt','roca_root_cause','roca_observation','roca_corrective_action','roca_analysis'];
    $sets = []; $vals = [];
    foreach ($allowed as $col) {
        if (array_key_exists($col, $b)) { $sets[] = "$col = ?"; $vals[] = $b[$col]; }
    }
    if (!$sets) jsonResponse(['error' => 'Nothing to update'], 400);
    $sets[] = "updated_at = NOW()";
    if (($b['status'] ?? '') === 'resolved') { $sets[] = "resolved_at = NOW()"; }
    if (($b['status'] ?? '') === 'closed')   { $sets[] = "closed_at = NOW()"; }
    $vals[] = $id;
    dbRun("UPDATE tickets SET " . implode(', ', $sets) . " WHERE id = ?", $vals);
    $t = dbFetch("SELECT * FROM tickets WHERE id = ?", [$id]);
    auditLog('update', 'ticket', $id);
    jsonResponse($t);
}

// ─── Delete
if ($id && method() === 'DELETE') {
    dbRun("DELETE FROM tickets WHERE id = ?", [$id]);
    jsonResponse(['ok' => true]);
}

// ─── List tickets
if (method() === 'GET') {
    $where = []; $params = [];
    if ($role === 'engineer') { $where[] = "assigned_to = ?"; $params[] = $user['id']; }
    $sql = "SELECT * FROM tickets" . ($where ? " WHERE " . implode(' AND ', $where) : "") . " ORDER BY created_at DESC";
    jsonResponse(dbFetchAll($sql, $params));
}

// ─── Create ticket
if (method() === 'POST') {
    $b = getBody();
    $sla = dbFetch("SELECT resolution_time_hours FROM sla_configs WHERE priority = ?", [$b['priority'] ?? 'p3']);
    $hours = $sla ? (int)$sla['resolution_time_hours'] : 24;
    $ticketNum = generateTicketNumber('INC');
    $newId = newUuid();
    $_slaExpr = dbNowPlusInterval($hours, 'HOUR');
    dbRun(
        "INSERT INTO tickets (id,ticket_number,description,priority,type,status,customer_id,customer_name,hub_id,assigned_to,fault_type_id,olt,created_by,sla_breach_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, {$_slaExpr})",
        [$newId,$ticketNum,$b['description']??'',$b['priority']??'p3',$b['type']??'fault','open',
         $b['customerId']??null,$b['customerName']??'',$b['hubId']??null,
         $b['assignedTo']??null,$b['faultTypeId']??null,$b['olt']??null,$user['id']]
    );
    $row = dbFetch("SELECT * FROM tickets WHERE id = ?", [$newId]);
    auditLog('create', 'ticket', $newId);
    jsonResponse($row, 201);
}
