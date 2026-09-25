<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (method() === 'POST') verifyCsrf();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── GET: invoice list ─────────────────────────────────────────────────────────
if (method() === 'GET' && $action === 'list') {
    requirePermission('billing.invoices.view');
    $status = $_GET['status'] ?? '';
    $where  = $status ? "WHERE i.status = " . db()->quote($status) : '';
    // Mark overdue on the fly
    db()->exec("UPDATE invoices SET status='overdue'
                WHERE status='sent' AND due_date < CURDATE() AND amount_paid < total");
    $rows = dbFetchAll(
        "SELECT i.id, i.invoice_number, i.customer_name, i.status,
                i.issue_date, i.due_date, i.total, i.amount_paid,
                (i.total - i.amount_paid) AS balance_due
         FROM invoices i
         $where
         ORDER BY i.created_at DESC
         LIMIT 200"
    );
    jsonResponse(['invoices' => $rows]);
}

// ── GET: single invoice with lines ───────────────────────────────────────────
if (method() === 'GET' && $action === 'get') {
    requirePermission('billing.invoices.view');
    $id = trim($_GET['id'] ?? '');
    if (!$id) jsonResponse(['error' => 'id required'], 400);
    $inv = dbFetch("SELECT * FROM invoices WHERE id = ?", [$id]);
    if (!$inv) jsonResponse(['error' => 'Not found'], 404);
    $lines = dbFetchAll("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id", [$id]);
    $payments = dbFetchAll("SELECT * FROM invoice_payments WHERE invoice_id = ? ORDER BY paid_at", [$id]);
    jsonResponse(['invoice' => $inv, 'lines' => $lines, 'payments' => $payments]);
}

// ── POST actions ──────────────────────────────────────────────────────────────
requirePermission('billing.invoices.view');

// ── create_invoice ────────────────────────────────────────────────────────────
if ($action === 'create_invoice') {
    requirePermission('billing.invoices.manage');

    $customerId   = trim($_POST['customer_id'] ?? '') ?: null;
    $customerName = trim($_POST['customer_name'] ?? '');
    $customerEmail= trim($_POST['customer_email'] ?? '') ?: null;
    $customerPhone= trim($_POST['customer_phone'] ?? '') ?: null;
    $customerAddr = trim($_POST['customer_addr'] ?? '') ?: null;
    $issueDate    = trim($_POST['issue_date'] ?? '') ?: date('Y-m-d');
    $dueDate      = trim($_POST['due_date'] ?? '') ?: null;
    $taxRate      = max(0, (float)($_POST['tax_rate'] ?? 0));
    $notes        = trim($_POST['notes'] ?? '') ?: null;
    $terms        = trim($_POST['terms'] ?? '') ?: null;

    if (!$customerName) jsonResponse(['error' => 'Customer name is required'], 400);

    // Parse line items (posted as JSON string)
    $linesRaw = json_decode($_POST['lines'] ?? '[]', true);
    if (!is_array($linesRaw) || empty($linesRaw)) {
        jsonResponse(['error' => 'At least one line item is required'], 400);
    }

    // Calculate totals
    $subtotal = 0;
    $lines = [];
    foreach ($linesRaw as $i => $l) {
        $desc  = trim($l['description'] ?? '');
        $qty   = max(0.001, (float)($l['qty'] ?? 1));
        $price = max(0, (float)($l['unit_price'] ?? 0));
        if (!$desc) continue;
        $lineTotal = round($qty * $price, 2);
        $subtotal += $lineTotal;
        $lines[] = ['description' => $desc, 'qty' => $qty, 'unit_price' => $price,
                    'line_total' => $lineTotal, 'sort_order' => $i];
    }
    if (empty($lines)) jsonResponse(['error' => 'At least one valid line item is required'], 400);

    $taxAmount = round($subtotal * $taxRate / 100, 2);
    $total     = round($subtotal + $taxAmount, 2);

    // Generate invoice number INV-YYYYMM-NNNN
    $prefix = 'INV-' . date('Ym') . '-';
    $last = dbFetch(
        "SELECT invoice_number FROM invoices WHERE invoice_number LIKE ? ORDER BY invoice_number DESC LIMIT 1",
        [$prefix . '%']
    );
    $seq = $last ? ((int)substr($last['invoice_number'], strlen($prefix)) + 1) : 1;
    $invoiceNumber = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);

    $id = newUuid();
    dbRun(
        "INSERT INTO invoices
         (id,invoice_number,customer_id,customer_name,customer_email,customer_phone,
          customer_addr,status,issue_date,due_date,subtotal,tax_rate,tax_amount,total,notes,terms,created_by)
         VALUES (?,?,?,?,?,?,?,'draft',?,?,?,?,?,?,?,?,?)",
        [$id, $invoiceNumber, $customerId, $customerName, $customerEmail, $customerPhone,
         $customerAddr, $issueDate, $dueDate, $subtotal, $taxRate, $taxAmount, $total,
         $notes, $terms, currentUser()['id']]
    );

    foreach ($lines as $line) {
        dbRun(
            "INSERT INTO invoice_items (invoice_id,description,qty,unit_price,line_total,sort_order)
             VALUES (?,?,?,?,?,?)",
            [$id, $line['description'], $line['qty'], $line['unit_price'],
             $line['line_total'], $line['sort_order']]
        );
    }

    auditLog('create', 'invoice', $id);
    jsonResponse(['ok' => true, 'id' => $id, 'invoice_number' => $invoiceNumber]);
}

// ── update_status ─────────────────────────────────────────────────────────────
if ($action === 'update_status') {
    requirePermission('billing.invoices.manage');
    $id        = trim($_POST['id'] ?? '');
    $newStatus = trim($_POST['status'] ?? '');
    $allowed   = ['draft', 'sent', 'void', 'cancelled'];
    if (!$id || !in_array($newStatus, $allowed, true)) {
        jsonResponse(['error' => 'Invalid id or status'], 400);
    }
    $inv = dbFetch("SELECT id, status FROM invoices WHERE id = ?", [$id]);
    if (!$inv) jsonResponse(['error' => 'Not found'], 404);

    $extra = $newStatus === 'sent' ? ", issue_date = COALESCE(issue_date, CURDATE())" : '';
    dbRun("UPDATE invoices SET status=? $extra WHERE id=?", [$newStatus, $id]);
    auditLog('update', 'invoice', $id, "status→$newStatus");
    jsonResponse(['ok' => true]);
}

// ── record_payment ────────────────────────────────────────────────────────────
if ($action === 'record_payment') {
    requirePermission('billing.invoices.record_payment');
    $id        = trim($_POST['id'] ?? '');
    $amount    = (float)($_POST['amount'] ?? 0);
    $method    = trim($_POST['method'] ?? 'cash');
    $reference = trim($_POST['reference'] ?? '') ?: null;
    $paidAt    = trim($_POST['paid_at'] ?? '') ?: date('Y-m-d H:i:s');
    $notes     = trim($_POST['notes'] ?? '') ?: null;
    $validMethods = ['cash','bank_transfer','mobile_money','cheque','card','other'];

    if (!$id || $amount <= 0 || !in_array($method, $validMethods, true)) {
        jsonResponse(['error' => 'id, positive amount, and valid method required'], 400);
    }

    $inv = dbFetch("SELECT * FROM invoices WHERE id = ?", [$id]);
    if (!$inv) jsonResponse(['error' => 'Invoice not found'], 404);
    if (in_array($inv['status'], ['void', 'cancelled'])) {
        jsonResponse(['error' => 'Cannot record payment on a void/cancelled invoice'], 400);
    }

    dbRun(
        "INSERT INTO invoice_payments (invoice_id,amount,method,reference,paid_at,notes,recorded_by)
         VALUES (?,?,?,?,?,?,?)",
        [$id, $amount, $method, $reference, $paidAt, $notes, currentUser()['id']]
    );

    // Recalculate amount_paid and update status
    $totalPaid = (float)dbFetch(
        "SELECT SUM(amount) AS s FROM invoice_payments WHERE invoice_id = ?", [$id]
    )['s'];

    $newStatus = $inv['status'];
    if ($totalPaid >= (float)$inv['total']) {
        $newStatus = 'paid';
    } elseif ($totalPaid > 0) {
        $newStatus = 'partial';
    }

    $paidAtFull = ($newStatus === 'paid') ? ", paid_at = NOW()" : '';
    dbRun(
        "UPDATE invoices SET amount_paid = ?, status = ? $paidAtFull WHERE id = ?",
        [$totalPaid, $newStatus, $id]
    );

    auditLog('create', 'invoice_payment', $id, "amount=$amount method=$method");
    jsonResponse(['ok' => true, 'new_status' => $newStatus, 'amount_paid' => $totalPaid]);
}

// ── delete_invoice ────────────────────────────────────────────────────────────
if ($action === 'delete_invoice') {
    requirePermission('billing.invoices.manage');
    $id = trim($_POST['id'] ?? '');
    if (!$id) jsonResponse(['error' => 'id required'], 400);
    $inv = dbFetch("SELECT status FROM invoices WHERE id = ?", [$id]);
    if (!$inv) jsonResponse(['error' => 'Not found'], 404);
    if (!in_array($inv['status'], ['draft', 'void', 'cancelled'])) {
        jsonResponse(['error' => 'Only draft, void, or cancelled invoices can be deleted'], 400);
    }
    dbRun("DELETE FROM invoice_items WHERE invoice_id = ?", [$id]);
    dbRun("DELETE FROM invoice_payments WHERE invoice_id = ?", [$id]);
    dbRun("DELETE FROM invoices WHERE id = ?", [$id]);
    auditLog('delete', 'invoice', $id);
    jsonResponse(['ok' => true]);
}

jsonResponse(['error' => 'Unknown action'], 400);
