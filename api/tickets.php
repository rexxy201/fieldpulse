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
        $eng = dbFetch("SELECT id, name FROM users WHERE role IN ('engineer','noc_engineer') AND hub_id=? LIMIT 1", [$t['hub_id']]);
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
    $prevTicket = dbFetch("SELECT created_by, assigned_to, customer_id, status FROM tickets WHERE id = ?", [$id]);
    dbRun("UPDATE tickets SET " . implode(', ', $sets) . " WHERE id = ?", $vals);
    $t = dbFetch("SELECT * FROM tickets WHERE id = ?", [$id]);
    auditLog('update', 'ticket', $id);

    $newStatus   = $b['status'] ?? null;
    $isResolving = $newStatus && in_array($newStatus, ['resolved','closed'])
                   && !in_array($prevTicket['status'], ['resolved','closed']);

    // Email creator on status change
    if (isset($b['status']) && !empty($prevTicket['created_by']) && $prevTicket['created_by'] !== $user['id']) {
        $creator = dbFetch("SELECT name,email FROM users WHERE id = ?", [$prevTicket['created_by']]);
        if ($creator) emailTicketUpdated($t, $creator, $user['name'], $b['status']);
    }
    // Email new assignee
    if (isset($b['assigned_to']) && $b['assigned_to'] !== $prevTicket['assigned_to']) {
        $assignee = dbFetch("SELECT name,email FROM users WHERE id = ?", [$b['assigned_to']]);
        if ($assignee) emailTicketAssigned($t, $assignee);
    }
    // First time ticket reaches resolved/closed → email customer (once)
    if ($isResolving && !empty($prevTicket['customer_id'])) {
        $cust = dbFetch("SELECT name,email FROM customers WHERE id = ?", [$prevTicket['customer_id']]);
        if ($cust && !empty($cust['email'])) emailCustomerTicketResolved($t, $cust);
    }
    jsonResponse($t);
}

// ─── Delete
if ($id && method() === 'DELETE') {
    dbRun("DELETE FROM tickets WHERE id = ?", [$id]);
    jsonResponse(['ok' => true]);
}

// ─── List tickets
if (method() === 'GET') {
    [$scopeSql, $params] = ticketScopeSql('');
    $sql = "SELECT * FROM tickets" . ($scopeSql ? " WHERE $scopeSql" : "") . " ORDER BY created_at DESC";
    jsonResponse(dbFetchAll($sql, $params));
}

// ─── Create ticket
if (method() === 'POST') {
    $b = getBody();
    $sla = dbFetch("SELECT resolution_time_hours FROM sla_configs WHERE priority = ?", [$b['priority'] ?? 'p3']);
    $hours = $sla ? (int)$sla['resolution_time_hours'] : 24;

    // Derive type from fault type category
    $ftRow = !empty($b['faultTypeId']) ? dbFetch("SELECT category FROM fault_types WHERE id = ?", [$b['faultTypeId']]) : null;
    $cat   = strtolower($ftRow['category'] ?? '');
    $type  = str_contains($cat,'install') ? 'installation' : (str_contains($cat,'maintenance') ? 'maintenance' : 'fault');

    // Scope-based subject resolution
    $scope      = $b['scope'] ?? 'customer';
    $hubId      = $b['hubId'] ?? null;
    $customerId = null;
    $customerName = $b['customerName'] ?? '';

    if ($scope === 'city') {
        $cityName = trim($b['cityName'] ?? '');
        $customerName = $cityName ?: 'City Outage';
        $hubId = getHubIdForCity($cityName) ?: $hubId;
    } elseif ($scope === 'hub') {
        if ($hubId) {
            $hubRow = dbFetch("SELECT name FROM hubs WHERE id=?", [$hubId]);
            $customerName = $hubRow['name'] ?? 'Hub Outage';
        }
    } else { // customer
        $customerId = $b['customerId'] ?? null;
        if ($customerId) {
            $custRow = dbFetch("SELECT mailing_city, hub_id, name FROM customers WHERE id = ?", [$customerId]);
            if ($custRow) {
                $customerName = $custRow['name'] ?? $customerName;
                $mappedHub = !empty($custRow['hub_id']) ? $custRow['hub_id']
                           : getHubIdForCity($custRow['mailing_city'] ?? '');
                if ($mappedHub) {
                    $hubId = $mappedHub;
                    if (empty($custRow['hub_id'])) {
                        dbRun("UPDATE customers SET hub_id=? WHERE id=?", [$mappedHub, $customerId]);
                    }
                }
            }
        }
    }

    // Auto-assign: fiber/installation → hub team; others → supervisor
    $assignee = null;
    if (!empty($b['faultTypeId'])) {
        $ftRoute = dbFetch("SELECT route_to FROM fault_types WHERE id=?", [$b['faultTypeId']]);
        $routeTo = strtolower($ftRoute['route_to'] ?? '');
        if (in_array($routeTo, ['fiber', 'installation']) && $hubId) {
            $assignee = getAutoAssignFiber($b['faultTypeId'], $hubId);
        } else {
            $assignee = getAutoAssignSupervisor($b['faultTypeId']);
        }
    }
    $assignedTo = $assignee['id'] ?? ($b['assignedTo'] ?? null);

    $prefix    = $type === 'installation' ? 'ORD' : 'INC';
    $ticketNum = generateTicketNumber($prefix);
    $newId     = newUuid();
    $_slaExpr  = dbNowPlusInterval($hours, 'HOUR');
    dbRun(
        "INSERT INTO tickets (id,ticket_number,description,priority,type,status,ticket_scope,customer_id,customer_name,hub_id,assigned_to,fault_type_id,olt,created_by,sla_breach_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, {$_slaExpr})",
        [$newId,$ticketNum,$b['description']??'',$b['priority']??'p3',$type,'open',$scope,
         $customerId,$customerName,$hubId,
         $assignedTo,$b['faultTypeId']??null,$b['olt']??null,$user['id']]
    );
    $row = dbFetch("SELECT * FROM tickets WHERE id = ?", [$newId]);
    auditLog('create', 'ticket', $newId);
    if ($assignee && !empty($assignee['email'])) emailTicketAssigned($row, $assignee);
    jsonResponse($row, 201);
}