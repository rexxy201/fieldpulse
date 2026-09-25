<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (method() === 'POST') verifyCsrf();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── GET: list serials for an item ────────────────────────────────────────────
if (method() === 'GET' && $action === 'list') {
    requirePermission('inventory.serials.view');
    $itemId = (int)($_GET['item_id'] ?? 0);
    if (!$itemId) jsonResponse(['error' => 'item_id required'], 400);

    $status = $_GET['status'] ?? '';
    $where  = ['sn.item_id = ?'];
    $params = [$itemId];
    if ($status && in_array($status, ['in_stock','deployed','retired'], true)) {
        $where[] = 'sn.status = ?';
        $params[] = $status;
    }

    $serials = dbFetchAll(
        "SELECT sn.*,
                c.name  AS customer_name, c.account_number,
                u.name  AS dispatched_by_name,
                ou.serial_number AS onu_serial
         FROM   inv_serial_numbers sn
         LEFT JOIN customers c            ON c.id  = sn.customer_id
         LEFT JOIN users u                ON u.id  = sn.dispatched_by
         LEFT JOIN onu_units ou           ON ou.id = sn.onu_unit_id
         WHERE  " . implode(' AND ', $where) . "
         ORDER BY sn.created_at DESC LIMIT 500",
        $params
    );
    jsonResponse(['serials' => $serials]);
}

// ── GET: global serial search ────────────────────────────────────────────────
if (method() === 'GET' && $action === 'search') {
    requirePermission('inventory.serials.view');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 3) jsonResponse(['results' => []]);

    $results = dbFetchAll(
        "SELECT sn.id, sn.serial, sn.status, sn.batch_number, sn.dispatched_at,
                i.name  AS item_name, i.id AS item_id,
                c.name  AS customer_name, c.account_number, c.id AS customer_id,
                ou.serial_number AS onu_serial, ou.status AS onu_status,
                ou.id   AS onu_id,
                u.name  AS dispatched_by_name
         FROM   inv_serial_numbers sn
         JOIN   inv_items i       ON i.id  = sn.item_id
         LEFT JOIN customers c    ON c.id  = sn.customer_id
         LEFT JOIN onu_units ou   ON ou.id = sn.onu_unit_id
         LEFT JOIN users u        ON u.id  = sn.dispatched_by
         WHERE  sn.serial LIKE ?
         ORDER BY sn.created_at DESC LIMIT 50",
        ["%{$q}%"]
    );
    jsonResponse(['results' => $results]);
}

// ── POST: add serials to an item ─────────────────────────────────────────────
if ($action === 'add') {
    requirePermission('inventory.serials.manage');
    $itemId = (int)($_POST['item_id'] ?? 0);
    if (!$itemId) jsonResponse(['error' => 'item_id required'], 400);

    $item = dbFetch("SELECT id FROM inv_items WHERE id = ?", [$itemId]);
    if (!$item) jsonResponse(['error' => 'Item not found'], 404);

    $rawSerials  = trim($_POST['serials'] ?? '');
    $batchNumber = trim($_POST['batch_number'] ?? '') ?: null;
    $notes       = trim($_POST['notes'] ?? '') ?: null;

    $lines   = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $rawSerials)));
    if (!$lines) jsonResponse(['error' => 'At least one serial number is required'], 400);

    $added = 0; $dupes = [];
    foreach ($lines as $serial) {
        $serial = strtoupper($serial);
        if (!$serial) continue;
        $existing = dbFetch("SELECT id FROM inv_serial_numbers WHERE serial = ?", [$serial]);
        if ($existing) { $dupes[] = $serial; continue; }
        dbRun(
            "INSERT INTO inv_serial_numbers (id, item_id, serial, batch_number, status, notes)
             VALUES (?, ?, ?, ?, 'in_stock', ?)",
            [newUuid(), $itemId, $serial, $batchNumber, $notes]
        );
        $added++;
    }
    auditLog('create', 'inv_serial_numbers', (string)$itemId, "Added $added serials");
    jsonResponse(['ok' => true, 'added' => $added, 'duplicates' => $dupes]);
}

// ── POST: dispatch a serial to a customer ────────────────────────────────────
if ($action === 'dispatch') {
    requirePermission('inventory.serials.manage');
    $id             = trim($_POST['id'] ?? '');
    $customerId     = trim($_POST['customer_id'] ?? '') ?: null;
    $installationId = trim($_POST['installation_id'] ?? '') ?: null;
    $onuUnitId      = trim($_POST['onu_unit_id'] ?? '') ?: null;
    $notes          = trim($_POST['notes'] ?? '') ?: null;

    if (!$id) jsonResponse(['error' => 'id required'], 400);

    $sn = dbFetch("SELECT * FROM inv_serial_numbers WHERE id = ?", [$id]);
    if (!$sn) jsonResponse(['error' => 'Serial not found'], 404);
    if ($sn['status'] !== 'in_stock') jsonResponse(['error' => 'Serial is already ' . $sn['status']], 409);

    $user = currentUser();
    dbRun(
        "UPDATE inv_serial_numbers
         SET status='deployed', customer_id=?, installation_id=?, onu_unit_id=?,
             dispatched_by=?, dispatched_at=NOW(), notes=COALESCE(?,notes)
         WHERE id=?",
        [$customerId, $installationId, $onuUnitId, $user['id'], $notes, $id]
    );

    // If linked to an ONU, stamp the item's serial onto the ONU record
    if ($onuUnitId) {
        dbRun("UPDATE onu_units SET customer_id = COALESCE(customer_id, ?) WHERE id = ?",
              [$customerId, $onuUnitId]);
    }

    auditLog('update', 'inv_serial_numbers', $id, 'Dispatched');
    jsonResponse(['ok' => true]);
}

// ── POST: retire a serial ────────────────────────────────────────────────────
if ($action === 'retire') {
    requirePermission('inventory.serials.manage');
    $id    = trim($_POST['id'] ?? '');
    $notes = trim($_POST['notes'] ?? '') ?: null;
    if (!$id) jsonResponse(['error' => 'id required'], 400);

    $sn = dbFetch("SELECT id FROM inv_serial_numbers WHERE id = ?", [$id]);
    if (!$sn) jsonResponse(['error' => 'Not found'], 404);

    dbRun("UPDATE inv_serial_numbers SET status='retired', notes=COALESCE(?,notes) WHERE id=?",
          [$notes, $id]);
    auditLog('update', 'inv_serial_numbers', $id, 'Retired');
    jsonResponse(['ok' => true]);
}

// ── POST: return to stock ────────────────────────────────────────────────────
if ($action === 'return') {
    requirePermission('inventory.serials.manage');
    $id = trim($_POST['id'] ?? '');
    if (!$id) jsonResponse(['error' => 'id required'], 400);

    dbRun(
        "UPDATE inv_serial_numbers
         SET status='in_stock', customer_id=NULL, installation_id=NULL, onu_unit_id=NULL,
             dispatched_by=NULL, dispatched_at=NULL
         WHERE id=?",
        [$id]
    );
    auditLog('update', 'inv_serial_numbers', $id, 'Returned to stock');
    jsonResponse(['ok' => true]);
}

// ── POST: delete (in_stock only) ─────────────────────────────────────────────
if ($action === 'delete') {
    requirePermission('inventory.serials.manage');
    $id = trim($_POST['id'] ?? '');
    if (!$id) jsonResponse(['error' => 'id required'], 400);

    $sn = dbFetch("SELECT status FROM inv_serial_numbers WHERE id = ?", [$id]);
    if (!$sn) jsonResponse(['error' => 'Not found'], 404);
    if ($sn['status'] !== 'in_stock') jsonResponse(['error' => 'Can only delete in-stock serials'], 409);

    dbRun("DELETE FROM inv_serial_numbers WHERE id = ?", [$id]);
    auditLog('delete', 'inv_serial_numbers', $id);
    jsonResponse(['ok' => true]);
}

jsonResponse(['error' => 'Unknown action'], 400);
