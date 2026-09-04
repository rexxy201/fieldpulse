<?php
/**
 * Integration API — Installations. API-key auth (see includes/api-auth.php),
 * not session auth. Keys are org-level, so (like tickets.php/customers.php)
 * there is no per-vendor scoping here — a key with installations.read sees
 * every job.
 *
 * GET   /api/v1/installations                list (paginated, optional ?status=, ?vendorId=)
 * GET   /api/v1/installations?id=<id>        single record
 * PATCH /api/v1/installations?id=<id>        update stage/fields
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/api-auth.php';

function installationOut(array $p): array {
    return [
        'id' => $p['id'], 'name' => $p['name'], 'phone' => $p['phone'], 'address' => $p['address'],
        'email' => $p['email'], 'plan' => $p['plan'], 'ticket_id' => $p['ticket_id'], 'vendor_id' => $p['vendor_id'],
        'hub_id' => $p['hub_id'], 'status' => $p['status'], 'notes' => $p['notes'],
        'payment_confirmed_at' => $p['payment_confirmed_at'], 'sla_due_at' => $p['sla_due_at'],
        'amount_paid' => $p['amount_paid'] !== null ? (float)$p['amount_paid'] : null,
        'network_user_id' => $p['network_user_id'], 'router_type' => $p['router_type'],
        'connection_status' => $p['connection_status'], 'connection_date' => $p['connection_date'],
        'cable_laid_date' => $p['cable_laid_date'], 'completed_at' => $p['completed_at'],
        'on_hold_reason' => $p['on_hold_reason'], 'refund_reason' => $p['refund_reason'],
        'created_at' => $p['created_at'], 'updated_at' => $p['updated_at'],
    ];
}

$id = trim($_GET['id'] ?? '');

if (method() === 'GET') {
    $key = requireApiScope('installations.read');
    if ($id) {
        $p = dbFetch("SELECT * FROM installation_profiles WHERE id = ?", [$id]);
        if (!$p) jsonResponse(['error' => 'Not found'], 404);
        jsonResponse(['data' => installationOut($p)]);
    }
    $status   = trim($_GET['status'] ?? '');
    $vendorId = trim($_GET['vendorId'] ?? '');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(200, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $where = []; $params = [];
    if ($status)   { $where[] = "status = ?"; $params[] = $status; }
    if ($vendorId) { $where[] = "vendor_id = ?"; $params[] = $vendorId; }
    $whereSQL = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $total = (int)(dbFetch("SELECT COUNT(*) c FROM installation_profiles" . $whereSQL, $params)['c'] ?? 0);
    $rows  = dbFetchAll("SELECT * FROM installation_profiles" . $whereSQL . " ORDER BY created_at DESC LIMIT $limit OFFSET $offset", $params);
    jsonResponse(['data' => array_map('installationOut', $rows), 'page' => $page, 'limit' => $limit, 'total' => $total]);
}

if (method() === 'PATCH') {
    $key = requireApiScope('installations.write');
    if (!$id) jsonResponse(['error' => '?id= is required'], 400);
    $existing = dbFetch("SELECT * FROM installation_profiles WHERE id = ?", [$id]);
    if (!$existing) jsonResponse(['error' => 'Not found'], 404);

    $b = getBody();
    // Same stage rule as the internal API/UI — Cable Laid needs its date set
    // in the same write, not left to a follow-up call.
    if (($b['status'] ?? '') === 'cable_laying' && empty($b['cable_laid_date']) && empty($existing['cable_laid_date'])) {
        jsonResponse(['error' => 'cable_laid_date is required when status is cable_laying.'], 400);
    }

    $allowed = ['name', 'phone', 'address', 'email', 'plan', 'ticket_id', 'vendor_id', 'status', 'notes',
        'amount_paid', 'network_user_id', 'router_type', 'connection_status', 'connection_date',
        'cable_laid_date', 'on_hold_reason', 'refund_reason'];
    $sets = []; $vals = [];
    foreach ($allowed as $col) {
        if (array_key_exists($col, $b)) { $sets[] = "$col=?"; $vals[] = $b[$col]; }
    }
    if (array_key_exists('payment_confirmed_at', $b) && !empty($b['payment_confirmed_at'])) {
        $sets[] = "payment_confirmed_at=?"; $vals[] = $b['payment_confirmed_at'];
        $sets[] = "sla_due_at=?"; $vals[] = addWorkingDays($b['payment_confirmed_at'], INSTALLATION_SLA_WORKING_DAYS);
    }
    if (($b['status'] ?? null) === 'connected' && empty($existing['completed_at'])) {
        $sets[] = "completed_at=NOW()";
    }
    if (!$sets) jsonResponse(['error' => 'Nothing to update'], 400);
    $sets[] = "updated_at=NOW()"; $vals[] = $id;
    dbRun("UPDATE installation_profiles SET " . implode(',', $sets) . " WHERE id=?", $vals);
    auditLog('api_update', 'installation', $id);

    $p = dbFetch("SELECT * FROM installation_profiles WHERE id = ?", [$id]);
    fireWebhooks('installation.updated', installationOut($p));
    jsonResponse(['data' => installationOut($p)]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
