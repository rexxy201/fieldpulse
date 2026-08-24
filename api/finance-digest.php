<?php
/**
 * Automated Finance Digest — call via cron (weekly is typical)
 * e.g. cPanel cron schedule "weekly":
 *   curl -s "https://fieldpulse.mangonetonline.com/api/finance-digest?token=YOUR_TOKEN"
 *
 * Gathers payment request + installation payment aggregates for the period,
 * has AI turn it into a short narrative, and emails it to the recipients
 * configured in Admin -> AI Assistant -> Automated Finance Digest. If AI
 * isn't enabled/fails, falls back to a plain numbers-only email.
 *
 * Same optional token-guard pattern as api/sla-check.php / api/ops-digest.php.
 */
require_once __DIR__ . '/../config.php';

$cfg        = getAppConfig();
$guardToken = trim($cfg['slaCheckToken'] ?? '');
if ($guardToken && ($_GET['token'] ?? '') !== $guardToken) {
    http_response_code(403);
    exit('Forbidden');
}

if (($cfg['financeDigestEnabled'] ?? '0') !== '1') {
    jsonResponse(['ok' => true, 'sent' => false, 'reason' => 'Finance digest is disabled in Admin settings.']);
}
$recipients = array_filter(array_map('trim', explode(',', $cfg['financeDigestEmails'] ?? '')));
if (!$recipients) {
    jsonResponse(['ok' => true, 'sent' => false, 'reason' => 'No recipient emails configured.']);
}

$days = max(1, (int)($_GET['days'] ?? 7));
$_ivPeriod = dbNowMinusInterval($days, 'DAY');

// ── Payment Requests ─────────────────────────────────────────────────────
// 4-stage flow: pending -> authorized -> approved -> [finance check] -> disbursed,
// or returned (sent back to requester for edits) / rejected (Authorize/Approve stage only).
$prPeriod = dbFetch(
    "SELECT COUNT(*) AS submitted,
            SUM(status IN ('authorized','approved','partially_disbursed')) AS approved,
            SUM(status='returned') AS returned,
            SUM(status='rejected') AS rejected,
            SUM(status='disbursed') AS paid
     FROM payment_requests WHERE created_at >= {$_ivPeriod}"
);
$prPeriod['paid_amount'] = (float)(dbFetch(
    "SELECT SUM(amount) AS total FROM payment_request_payments WHERE paid_at >= {$_ivPeriod}"
)['total'] ?? 0);
$prOutstanding = dbFetch(
    "SELECT SUM(status='pending') AS pending_count,
            SUM(CASE WHEN status='pending' THEN amount ELSE 0 END) AS pending_amount,
            SUM(status IN ('authorized','approved','partially_disbursed')) AS approved_count,
            SUM(CASE WHEN status IN ('authorized','approved','partially_disbursed') THEN amount - amount_paid ELSE 0 END) AS approved_amount
     FROM payment_requests"
);
$topVendorsOwed = dbFetchAll(
    "SELECT v.name, SUM(pr.amount) AS total
     FROM payment_requests pr JOIN vendors v ON v.id = pr.vendor_id
     WHERE pr.status IN ('pending','authorized','approved')
     GROUP BY v.id, v.name ORDER BY total DESC LIMIT 5"
);

// ── Installation payments ────────────────────────────────────────────────
$installMoney = dbFetch(
    "SELECT SUM(amount_paid) AS total_amount_paid,
            SUM(CASE WHEN payment_confirmed_at >= {$_ivPeriod} THEN amount_paid ELSE 0 END) AS paid_this_period,
            SUM(installation_paid='No') AS unpaid_count
     FROM installation_profiles"
);

$summary = [
    'period_days'               => $days,
    'payment_requests_submitted'=> (int)($prPeriod['submitted'] ?? 0),
    'payment_requests_approved' => (int)($prPeriod['approved'] ?? 0),
    'payment_requests_returned' => (int)($prPeriod['returned'] ?? 0),
    'payment_requests_rejected' => (int)($prPeriod['rejected'] ?? 0),
    'payment_requests_paid'     => (int)($prPeriod['paid'] ?? 0),
    'amount_paid_this_period'   => (float)($prPeriod['paid_amount'] ?? 0),
    'pending_count'             => (int)($prOutstanding['pending_count'] ?? 0),
    'pending_amount'            => (float)($prOutstanding['pending_amount'] ?? 0),
    'approved_unpaid_count'     => (int)($prOutstanding['approved_count'] ?? 0),
    'approved_unpaid_amount'    => (float)($prOutstanding['approved_amount'] ?? 0),
    'top_vendors_owed'          => array_map(fn($v) => "{$v['name']} (₦" . number_format((float)$v['total']) . ")", $topVendorsOwed),
    'total_installation_amount_paid' => (float)($installMoney['total_amount_paid'] ?? 0),
    'installation_amount_paid_this_period' => (float)($installMoney['paid_this_period'] ?? 0),
    'installations_not_yet_paid' => (int)($installMoney['unpaid_count'] ?? 0),
];

// ── Narrative (AI if available, plain numbers otherwise) ────────────────
$narrative = null;
if (aiEnabled()) {
    $system = "You write a short, plain-language weekly finance summary for an internet service provider's "
            . "finance/procurement team, from the payment request and installation payment stats given as JSON. "
            . "3-5 short paragraphs or a tight bulleted list — whichever reads better. Call out anything that "
            . "looks concerning (large pending/approved-unpaid balances, vendors owed a lot) plainly, and anything "
            . "that looks good too. No fluff, no generic advice, just what the numbers say. Plain text only, no "
            . "markdown headers. Amounts are in Nigerian Naira (₦).";
    $narrative = aiChat($system, json_encode($summary, JSON_PRETTY_PRINT));
}

$appCfg = getAppConfig();
$co = htmlspecialchars($appCfg['companyName'] ?? 'FieldPulse');
$periodLabel = "{$days}-day";

if ($narrative) {
    $bodyHtml = "<p>" . nl2br(htmlspecialchars($narrative)) . "</p>";
} else {
    // Fallback: plain numbers, no AI narrative
    $bodyHtml = "<ul>
        <li>Payment requests submitted: {$summary['payment_requests_submitted']}, approved: {$summary['payment_requests_approved']}, returned to requester: {$summary['payment_requests_returned']}, rejected: {$summary['payment_requests_rejected']}, paid: {$summary['payment_requests_paid']}</li>
        <li>Paid this period: ₦" . number_format($summary['amount_paid_this_period']) . "</li>
        <li>Currently pending: {$summary['pending_count']} (₦" . number_format($summary['pending_amount']) . "), approved but unpaid: {$summary['approved_unpaid_count']} (₦" . number_format($summary['approved_unpaid_amount']) . ")</li>
        <li>Installation payments received this period: ₦" . number_format($summary['installation_amount_paid_this_period']) . " — installations not yet paid: {$summary['installations_not_yet_paid']}</li>
      </ul>";
}

$subject = "{$co} Finance Digest — Last {$days} Days";
$fullHtml = "<h2 style='color:#0ea5e9'>{$co} Finance Digest</h2>
             <p style='color:#64748b;font-size:.85rem'>{$periodLabel} summary, generated " . date('d M Y H:i') . "</p>
             {$bodyHtml}
             <p style='color:#94a3b8;font-size:.8rem;margin-top:2rem'>Generated automatically by FieldPulse.</p>";

$sentCount = 0;
foreach ($recipients as $email) {
    if (sendEmail($email, 'Finance', $subject, $fullHtml)) $sentCount++;
}

jsonResponse([
    'ok'        => true,
    'sent'      => $sentCount > 0,
    'recipients'=> count($recipients),
    'delivered' => $sentCount,
    'ai_used'   => $narrative !== null,
    'summary'   => $summary,
]);
