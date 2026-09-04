<?php
/**
 * Integration API — Tickets. API-key auth (see includes/api-auth.php), not
 * session auth — this is for external platforms (e.g. a future mobile
 * client), not the staff app. Keys are org-level, not tied to a specific
 * vendor/user, so unlike the session-based pages/api/tickets.php there is no
 * per-vendor or per-hub visibility scoping here — a key with tickets.read
 * sees every ticket, same as customers.php/payment-requests.php already do
 * for their resources.
 *
 * GET   /api/v1/tickets                    list (paginated, optional ?status=, ?assignedTo=, ?search=)
 * GET   /api/v1/tickets?id=<id>             single record
 * PATCH /api/v1/tickets?id=<id>             update status/priority/assignment/RCA
 * POST  /api/v1/tickets?id=<id>&resource=photos   upload proof-of-service photos (multipart/form-data, field "photos[]")
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/api-auth.php';

function ticketOut(array $t): array {
    return [
        'id' => $t['id'], 'ticket_number' => $t['ticket_number'], 'customer_id' => $t['customer_id'],
        'customer_name' => $t['customer_name'], 'type' => $t['type'], 'priority' => $t['priority'],
        'status' => $t['status'], 'description' => $t['description'], 'assigned_to' => $t['assigned_to'],
        'vendor_id' => $t['vendor_id'], 'maintenance_vendor_id' => $t['maintenance_vendor_id'], 'hub_id' => $t['hub_id'],
        'roca_root_cause' => $t['roca_root_cause'], 'roca_observation' => $t['roca_observation'],
        'roca_corrective_action' => $t['roca_corrective_action'], 'roca_analysis' => $t['roca_analysis'],
        'sla_breach_at' => $t['sla_breach_at'], 'resolved_at' => $t['resolved_at'], 'closed_at' => $t['closed_at'],
        'created_at' => $t['created_at'], 'updated_at' => $t['updated_at'],
        // Exposed so a client can send it back on PATCH for optimistic locking —
        // same lock_version column/contract as pages/ticket-detail.php and the
        // internal api/tickets.php PATCH handler.
        'lock_version' => (int)($t['lock_version'] ?? 0),
    ];
}

$id       = trim($_GET['id'] ?? '');
$resource = trim($_GET['resource'] ?? '');

if (method() === 'GET') {
    $key = requireApiScope('tickets.read');
    if ($id) {
        $t = dbFetch("SELECT * FROM tickets WHERE id = ?", [$id]);
        if (!$t) jsonResponse(['error' => 'Not found'], 404);
        jsonResponse(['data' => ticketOut($t)]);
    }
    $status     = trim($_GET['status'] ?? '');
    $assignedTo = trim($_GET['assignedTo'] ?? '');
    $search     = trim($_GET['search'] ?? '');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(200, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $where = []; $params = [];
    if ($status)     { $where[] = "status = ?"; $params[] = $status; }
    if ($assignedTo) { $where[] = "assigned_to = ?"; $params[] = $assignedTo; }
    if ($search) {
        $like = "%$search%";
        $where[] = "(ticket_number LIKE ? OR description LIKE ? OR customer_name LIKE ?)";
        array_push($params, $like, $like, $like);
    }
    $whereSQL = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $total = (int)(dbFetch("SELECT COUNT(*) c FROM tickets" . $whereSQL, $params)['c'] ?? 0);
    $rows  = dbFetchAll("SELECT * FROM tickets" . $whereSQL . " ORDER BY created_at DESC LIMIT $limit OFFSET $offset", $params);
    jsonResponse(['data' => array_map('ticketOut', $rows), 'page' => $page, 'limit' => $limit, 'total' => $total]);
}

if (method() === 'PATCH') {
    $key = requireApiScope('tickets.write');
    if (!$id) jsonResponse(['error' => '?id= is required'], 400);
    $existing = dbFetch("SELECT * FROM tickets WHERE id = ?", [$id]);
    if (!$existing) jsonResponse(['error' => 'Not found'], 404);

    $b = getBody();
    $newStatus   = $b['status'] ?? $existing['status'];
    $isResolving = in_array($newStatus, ['resolved', 'closed'], true) && !in_array($existing['status'], ['resolved', 'closed'], true);
    // Same RCA rule as every other write path (web UI, internal API) — the
    // first transition into resolved/closed must carry a root cause.
    if ($isResolving && empty(trim($b['roca_root_cause'] ?? ''))) {
        jsonResponse(['error' => 'roca_root_cause is required when resolving or closing a ticket.'], 400);
    }

    $allowed = ['status', 'priority', 'description', 'olt', 'assigned_to',
        'roca_root_cause', 'roca_observation', 'roca_corrective_action', 'roca_analysis'];
    $sets = []; $vals = [];
    foreach ($allowed as $col) {
        if (array_key_exists($col, $b)) { $sets[] = "$col=?"; $vals[] = $b[$col]; }
    }
    if (!$sets) jsonResponse(['error' => 'Nothing to update'], 400);
    $sets[] = "updated_at=NOW()"; $sets[] = "lock_version=lock_version+1";
    if ($newStatus === 'resolved') $sets[] = "resolved_at=NOW()";
    if ($newStatus === 'closed')   $sets[] = "closed_at=NOW()";

    // Optimistic lock — same contract as the internal API: send back the
    // lock_version you last read and the write is rejected with 409 if
    // someone else (staff UI, another integration call) changed it since.
    if (array_key_exists('lock_version', $b)) {
        $vals[] = $id; $vals[] = (int)$b['lock_version'];
        $st = dbRun("UPDATE tickets SET " . implode(',', $sets) . " WHERE id=? AND lock_version=?", $vals);
        if ($st->rowCount() === 0) {
            jsonResponse(['error' => 'This ticket was updated by someone else since you last read it. Refetch and retry.'], 409);
        }
    } else {
        $vals[] = $id;
        dbRun("UPDATE tickets SET " . implode(',', $sets) . " WHERE id=?", $vals);
    }
    auditLog('api_update', 'ticket', $id);

    $t = dbFetch("SELECT * FROM tickets WHERE id = ?", [$id]);
    fireWebhooks('ticket.updated', ticketOut($t));
    jsonResponse(['data' => ticketOut($t)]);
}

if (method() === 'POST' && $resource === 'photos') {
    $key = requireApiScope('tickets.write');
    if (!$id) jsonResponse(['error' => '?id= is required'], 400);
    $existing = dbFetch("SELECT id FROM tickets WHERE id = ?", [$id]);
    if (!$existing) jsonResponse(['error' => 'Not found'], 404);

    $check = validateTicketPhotos($_FILES['photos'] ?? []);
    if (!$check['ok']) jsonResponse(['error' => $check['error']], 400);
    if ($check['count'] === 0) jsonResponse(['error' => 'No photos in the "photos[]" field.'], 400);

    $saved = saveTicketPhotos($_FILES['photos'], $id, '', 'API: ' . $key['name']);
    auditLog('api_upload_photos', 'ticket', $id);
    jsonResponse(['saved' => $saved], 201);
}

jsonResponse(['error' => 'Method not allowed, or missing ?resource= for this method'], 405);
