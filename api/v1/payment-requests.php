<?php
/**
 * Integration API — Payment Requests. API-key auth (see includes/api-auth.php).
 *
 * GET  /api/v1/payment-requests                 list (paginated, optional ?status=)
 * GET  /api/v1/payment-requests?id=<id>          single record, with line items + payment history
 * POST /api/v1/payment-requests?id=<id>&resource=payments
 *      Record a payment against an approved (or already-partially-paid)
 *      request — the same bill-style partial-payment flow the Finance UI
 *      uses. Body: {"amount": 1000, "reference": "TXN123", "note": "..."}.
 *      This is the write path an external accounting platform (e.g. once a
 *      disbursement clears on their end) would call back into FieldPulse.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/api-auth.php';

function prOut(array $r, ?array $items = null, ?array $payments = null): array {
    $out = [
        'id' => $r['id'], 'requester_name' => $r['requester_name'], 'status' => $r['status'],
        'request_type' => $r['request_type'], 'category' => $r['category'], 'description' => $r['description'],
        'amount' => (float)$r['amount'], 'amount_paid' => (float)($r['amount_paid'] ?? 0),
        'balance' => round((float)$r['amount'] - (float)($r['amount_paid'] ?? 0), 2),
        'date_of_request' => $r['date_of_request'], 'created_at' => $r['created_at'],
    ];
    if ($items !== null)    $out['items'] = array_map(fn($i) => ['description'=>$i['description'],'qty'=>(float)$i['qty'],'unit_price'=>(float)$i['unit_price'],'line_total'=>(float)$i['line_total']], $items);
    if ($payments !== null) $out['payments'] = array_map(fn($p) => ['amount'=>(float)$p['amount'],'reference'=>$p['payment_reference'],'note'=>$p['note'],'paid_by_name'=>$p['paid_by_name'],'paid_at'=>$p['paid_at']], $payments);
    return $out;
}

$id       = trim($_GET['id'] ?? '');
$resource = trim($_GET['resource'] ?? '');

if (method() === 'GET') {
    $key = requireApiScope('payments.read');
    if ($id) {
        $r = dbFetch("SELECT * FROM payment_requests WHERE id = ?", [$id]);
        if (!$r) jsonResponse(['error' => 'Not found'], 404);
        $items = dbFetchAll("SELECT * FROM payment_request_items WHERE payment_request_id=? ORDER BY sort_order", [$id]);
        $payments = dbFetchAll("SELECT * FROM payment_request_payments WHERE payment_request_id=? ORDER BY paid_at", [$id]);
        jsonResponse(['data' => prOut($r, $items, $payments)]);
    }
    $status = trim($_GET['status'] ?? '');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(200, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $where = []; $params = [];
    if ($status) { $where[] = "status = ?"; $params[] = $status; }
    $whereSQL = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $total = (int)(dbFetch("SELECT COUNT(*) c FROM payment_requests" . $whereSQL, $params)['c'] ?? 0);
    $rows  = dbFetchAll("SELECT * FROM payment_requests" . $whereSQL . " ORDER BY created_at DESC LIMIT $limit OFFSET $offset", $params);
    jsonResponse(['data' => array_map(fn($r) => prOut($r), $rows), 'page' => $page, 'limit' => $limit, 'total' => $total]);
}

if (method() === 'POST' && $resource === 'payments') {
    $key = requireApiScope('payments.write');
    if (!$id) jsonResponse(['error' => '?id= is required'], 400);
    $b = getBody();
    $amount = $b['amount'] ?? null;
    if ($amount === null || !is_numeric($amount) || (float)$amount <= 0) {
        jsonResponse(['error' => 'amount must be a positive number'], 400);
    }
    $amount = round((float)$amount, 2);
    $ref  = trim($b['reference'] ?? '');
    $note = trim($b['note'] ?? '');

    $pr = dbFetch("SELECT amount, amount_paid FROM payment_requests WHERE id=? AND status IN ('approved','partially_disbursed')", [$id]);
    if (!$pr) jsonResponse(['error' => 'Request not found, or not in a payable state (must be approved or partially_disbursed)'], 409);
    $balance = round((float)$pr['amount'] - (float)$pr['amount_paid'], 2);
    if ($amount > $balance + 0.01) {
        jsonResponse(['error' => "Amount {$amount} exceeds the outstanding balance of {$balance}"], 400);
    }

    $paidByName = 'API: ' . $key['name'];
    dbRun("INSERT INTO payment_request_payments (id,payment_request_id,amount,payment_reference,note,paid_by,paid_by_name,paid_at) VALUES (?,?,?,?,?,?,?,NOW())",
        [newUuid(), $id, $amount, $ref ?: null, $note ?: null, null, $paidByName]);
    $newPaid = round((float)$pr['amount_paid'] + $amount, 2);
    $isFull  = $newPaid >= round((float)$pr['amount'] - 0.01, 2);
    if ($isFull) {
        dbRun("UPDATE payment_requests SET amount_paid=?, status='disbursed', paid_at=NOW(), payment_reference=?, disbursed_by_name=? WHERE id=?",
            [$newPaid, $ref ?: null, $paidByName, $id]);
    } else {
        dbRun("UPDATE payment_requests SET amount_paid=?, status='partially_disbursed', payment_reference=?, disbursed_by_name=? WHERE id=?",
            [$newPaid, $ref ?: null, $paidByName, $id]);
    }
    auditLog('api_record_payment', 'payment_request', $id);

    $updated = dbFetch("SELECT * FROM payment_requests WHERE id = ?", [$id]);
    if ($isFull) fireWebhooks('payment.disbursed', prOut($updated));
    jsonResponse(['data' => prOut($updated), 'full' => $isFull], 201);
}

jsonResponse(['error' => 'Method not allowed, or missing ?resource= for this method'], 405);
