<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (method() === 'POST') verifyCsrf();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── GET: list ─────────────────────────────────────────────────────────────────
if (method() === 'GET' && $action === 'list') {
    requirePermission('inventory.po.view');
    $orders = [];
    try {
        $orders = dbFetchAll(
            "SELECT po.*, v.name AS vendor_name FROM inv_purchase_orders po
             LEFT JOIN vendors v ON v.id = po.vendor_id
             ORDER BY po.created_at DESC LIMIT 100"
        );
    } catch (\PDOException $e) {
        if (($e->errorInfo[1] ?? 0) !== 1146) throw $e;
    }
    jsonResponse(['orders' => $orders]);
}

// ── GET: lines for one PO ─────────────────────────────────────────────────────
if (method() === 'GET' && $action === 'lines') {
    requirePermission('inventory.po.view');
    $poId = trim($_GET['po_id'] ?? '');
    if (!$poId) jsonResponse(['error' => 'po_id required'], 400);
    $lines = dbFetchAll(
        "SELECT poi.*, i.name AS item_name, i.unit FROM inv_po_items poi
         JOIN inv_items i ON i.id = poi.item_id WHERE poi.po_id = ?",
        [$poId]
    );
    jsonResponse(['lines' => $lines]);
}

// ── POST: create_po ───────────────────────────────────────────────────────────
if ($action === 'create_po') {
    requirePermission('inventory.po.manage');

    $vendorId  = trim($_POST['vendor_id']  ?? '') ?: null;
    $orderedAt = trim($_POST['ordered_at'] ?? '') ?: null;
    $expectedAt= trim($_POST['expected_at']?? '') ?: null;
    $notes     = trim($_POST['notes']      ?? '') ?: null;

    $itemIds   = $_POST['item_id']    ?? [];
    $qtys      = $_POST['qty_ordered']?? [];
    $prices    = $_POST['unit_price'] ?? [];

    $lines = [];
    foreach ($itemIds as $i => $itemId) {
        $itemId = (int)$itemId;
        $qty    = max(1, (int)($qtys[$i] ?? 1));
        $price  = strlen(trim($prices[$i] ?? '')) ? (float)$prices[$i] : null;
        if ($itemId < 1) continue;
        $lines[] = ['item_id' => $itemId, 'qty_ordered' => $qty, 'unit_price' => $price];
    }
    if (!$lines) jsonResponse(['error' => 'At least one line item is required'], 400);

    // Generate PO number: PO-YYYYMM-NNNN
    $prefix = 'PO-' . date('Ym') . '-';
    $last   = dbFetch("SELECT po_number FROM inv_purchase_orders WHERE po_number LIKE ? ORDER BY po_number DESC LIMIT 1", [$prefix . '%']);
    $seq    = 1;
    if ($last) { preg_match('/-(\d+)$/', $last['po_number'], $m); $seq = (int)($m[1] ?? 0) + 1; }
    $poNumber = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

    $poId = newUuid();
    dbRun(
        "INSERT INTO inv_purchase_orders (id, po_number, vendor_id, status, ordered_at, expected_at, notes, created_by)
         VALUES (?,?,?,'draft',?,?,?,?)",
        [$poId, $poNumber, $vendorId, $orderedAt, $expectedAt, $notes, currentUser()['id']]
    );
    foreach ($lines as $line) {
        dbRun(
            "INSERT INTO inv_po_items (po_id, item_id, qty_ordered, unit_price) VALUES (?,?,?,?)",
            [$poId, $line['item_id'], $line['qty_ordered'], $line['unit_price']]
        );
    }
    auditLog('create', 'purchase_order', $poId);
    jsonResponse(['ok' => true, 'id' => $poId, 'po_number' => $poNumber]);
}

// ── POST: update_status ───────────────────────────────────────────────────────
if ($action === 'update_status') {
    requirePermission('inventory.po.manage');
    $poId      = trim($_POST['po_id'] ?? '');
    $newStatus = trim($_POST['status'] ?? '');
    $allowed   = ['draft','sent','cancelled'];
    if (!$poId || !in_array($newStatus, $allowed)) jsonResponse(['error' => 'Invalid request'], 400);
    $po = dbFetch("SELECT id,status FROM inv_purchase_orders WHERE id=?", [$poId]);
    if (!$po) jsonResponse(['error' => 'Not found'], 404);
    dbRun("UPDATE inv_purchase_orders SET status=?, updated_at=NOW() WHERE id=?", [$newStatus, $poId]);
    auditLog('update', 'purchase_order', $poId);
    jsonResponse(['ok' => true]);
}

// ── POST: receive ─────────────────────────────────────────────────────────────
if ($action === 'receive') {
    requirePermission('inventory.po.receive');
    $poId = trim($_POST['po_id'] ?? '');
    $po   = dbFetch("SELECT * FROM inv_purchase_orders WHERE id=?", [$poId]);
    if (!$po) jsonResponse(['error' => 'PO not found'], 404);
    if (!in_array($po['status'], ['sent','partial'])) jsonResponse(['error' => 'PO cannot be received in status: '.$po['status']], 400);

    $lineIds   = $_POST['line_id']      ?? [];
    $qtyRecvd  = $_POST['qty_received'] ?? [];

    $allReceived = true;
    foreach ($lineIds as $i => $lineId) {
        $lineId = (int)$lineId;
        $qty    = max(0, (int)($qtyRecvd[$i] ?? 0));
        if ($qty < 1) { $allReceived = false; continue; }

        $line = dbFetch("SELECT * FROM inv_po_items WHERE id=? AND po_id=?", [$lineId, $poId]);
        if (!$line) continue;

        $newRecvd = (int)$line['qty_received'] + $qty;
        dbRun("UPDATE inv_po_items SET qty_received=? WHERE id=?", [$newRecvd, $lineId]);

        // Add stock movement
        dbRun(
            "INSERT INTO inv_stock_movements (item_id,type,quantity,source,reference_id,notes,performed_by)
             VALUES (?,?,?,'purchase_order',?,?,?)",
            [$line['item_id'], 'inbound', $qty, null, 'Received via ' . $po['po_number'], currentUser()['id']]
        );
        // Update inv_items quantity
        dbRun("UPDATE inv_items SET quantity = quantity + ?, updated_at=NOW() WHERE id=?", [$qty, $line['item_id']]);

        if ($newRecvd < (int)$line['qty_ordered']) $allReceived = false;
    }

    $newPoStatus = $allReceived ? 'received' : 'partial';
    dbRun("UPDATE inv_purchase_orders SET status=?, received_at=IF(?='received',NOW(),received_at), updated_at=NOW() WHERE id=?",
          [$newPoStatus, $newPoStatus, $poId]);
    auditLog('update', 'purchase_order', $poId);
    jsonResponse(['ok' => true, 'status' => $newPoStatus]);
}

jsonResponse(['error' => 'Unknown action'], 400);
