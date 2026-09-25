<?php
/**
 * Paystack webhook receiver — /api/paystack-webhook
 * Paystack signs every delivery with X-Paystack-Signature (HMAC-SHA512 of raw body using secret key).
 * We verify, then record payment against the matching invoice (metadata.invoice_id).
 */
require_once __DIR__ . '/../config.php';

$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
$pk  = paystackConfig();

// Reject if Paystack not configured or signature invalid
if (!$pk['enabled'] || !$pk['secret_key']) {
    http_response_code(400); exit;
}
if (!hash_equals(hash_hmac('sha512', $raw, $pk['secret_key']), $sig)) {
    http_response_code(401); exit;
}

$payload = json_decode($raw, true);
$event   = $payload['event'] ?? '';

// We only care about successful charges
if ($event !== 'charge.success') {
    http_response_code(200); echo 'ok'; exit;
}

$data      = $payload['data'] ?? [];
$reference = $data['reference']  ?? '';
$amountKobo = (int)($data['amount'] ?? 0);
$amount    = $amountKobo / 100; // Paystack amounts are in kobo/pesewas
$metaInvId = $data['metadata']['invoice_id'] ?? ($data['metadata']['custom_fields'][0]['value'] ?? '');
$email     = $data['customer']['email'] ?? '';

if (!$reference || $amount <= 0) {
    http_response_code(200); echo 'ok'; exit;
}

// Idempotency: skip if this reference was already recorded
$existing = dbFetch("SELECT id FROM invoice_payments WHERE reference = ?", ['PS-' . $reference]);
if ($existing) { http_response_code(200); echo 'ok'; exit; }

// Find invoice — by explicit ID in metadata, or by customer email + unpaid balance
$inv = null;
if ($metaInvId) {
    $inv = dbFetch("SELECT * FROM invoices WHERE id = ?", [$metaInvId]);
}
if (!$inv && $email) {
    $inv = dbFetch(
        "SELECT * FROM invoices WHERE customer_email = ? AND status IN ('sent','partial','overdue')
         ORDER BY created_at DESC LIMIT 1",
        [$email]
    );
}

if (!$inv) {
    // Can't match invoice — log and acknowledge so Paystack doesn't retry
    error_log("Paystack webhook: no invoice found for ref=$reference email=$email metaInvId=$metaInvId");
    http_response_code(200); echo 'ok'; exit;
}

$invId = $inv['id'];
dbRun(
    "INSERT INTO invoice_payments (invoice_id,amount,method,reference,paid_at,notes,recorded_by)
     VALUES (?,?,?,?,?,?,?)",
    [$invId, $amount, 'card', 'PS-' . $reference,
     date('Y-m-d H:i:s', $data['paid_at'] ? strtotime($data['paid_at']) : time()),
     'Paystack online payment', null]
);

$totalPaid = (float)dbFetch("SELECT SUM(amount) AS s FROM invoice_payments WHERE invoice_id = ?", [$invId])['s'];
$newStatus = $totalPaid >= (float)$inv['total'] ? 'paid' : 'partial';
$paidCol   = $newStatus === 'paid' ? ', paid_at = NOW()' : '';
dbRun("UPDATE invoices SET amount_paid=?, status=? $paidCol WHERE id=?", [$totalPaid, $newStatus, $invId]);
auditLog('create', 'invoice_payment', $invId, "paystack ref=$reference amount=$amount");

// SMS confirmation to customer
if (!empty($inv['customer_phone'])) {
    $cfg   = getAppConfig();
    $co    = $cfg['companyName'] ?? 'FieldPulse';
    $msg   = "Payment of " . number_format($amount, 2) . " received for invoice " .
             $inv['invoice_number'] . ". Thank you! — $co";
    sendSms($inv['customer_phone'], $msg);
}

http_response_code(200);
echo 'ok';
