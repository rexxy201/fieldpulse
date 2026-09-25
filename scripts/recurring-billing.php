<?php
/**
 * Recurring billing cron — run daily via cPanel cron:
 *   php /home/<user>/fieldpulse/scripts/recurring-billing.php
 *
 * Finds customers whose next_invoice_date <= today and billing_active=1,
 * generates an invoice from their billing_plan, then advances next_invoice_date.
 */
require_once __DIR__ . '/../config.php';

$today    = date('Y-m-d');
$cfg      = getAppConfig();
$co       = $cfg['companyName'] ?? 'FieldPulse';
$generated = 0;
$errors    = 0;

$customers = dbFetchAll(
    "SELECT c.*, bp.name AS plan_name, bp.amount AS plan_amount, bp.tax_rate AS plan_tax,
            bp.billing_cycle AS plan_cycle, bp.billing_day AS plan_billing_day
     FROM customers c
     JOIN billing_plans bp ON bp.id = c.billing_plan_id
     WHERE c.billing_active = 1
       AND c.next_invoice_date IS NOT NULL
       AND c.next_invoice_date <= ?
       AND bp.is_active = 1",
    [$today]
);

foreach ($customers as $cust) {
    try {
        // Idempotency: skip if an invoice for this customer was already created this cycle
        $cycleStart = date('Y-m-01');
        $existing = dbFetch(
            "SELECT id FROM invoices WHERE customer_id = ? AND issue_date >= ? AND source = 'recurring'",
            [$cust['id'], $cycleStart]
        );
        if ($existing) {
            // Advance date and skip
            advanceNextInvoiceDate($cust);
            continue;
        }

        $subtotal  = (float)$cust['plan_amount'];
        $taxRate   = (float)$cust['plan_tax'];
        $taxAmount = round($subtotal * $taxRate / 100, 2);
        $total     = round($subtotal + $taxAmount, 2);
        $dueDate   = date('Y-m-d', strtotime('+14 days'));

        // Invoice number
        $prefix = 'INV-' . date('Ym') . '-';
        $last   = dbFetch(
            "SELECT invoice_number FROM invoices WHERE invoice_number LIKE ? ORDER BY invoice_number DESC LIMIT 1",
            [$prefix . '%']
        );
        $seq    = $last ? ((int)substr($last['invoice_number'], strlen($prefix)) + 1) : 1;
        $invNum = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);

        $invId = newUuid();
        dbRun(
            "INSERT INTO invoices
             (id,invoice_number,customer_id,customer_name,customer_email,customer_phone,
              status,issue_date,due_date,subtotal,tax_rate,tax_amount,total,notes,source)
             VALUES (?,?,?,?,?,?,'sent',?,?,?,?,?,?,?,?,'recurring')",
            [$invId, $invNum, $cust['id'], $cust['name'], $cust['email'] ?? null,
             $cust['phone'] ?? null, $today, $dueDate,
             $subtotal, $taxRate, $taxAmount, $total,
             "Monthly subscription: " . $cust['plan_name'], ]
        );

        dbRun(
            "INSERT INTO invoice_items (invoice_id,description,qty,unit_price,line_total,sort_order)
             VALUES (?,?,1,?,?,0)",
            [$invId, $cust['plan_name'] . ' — ' . ucfirst($cust['plan_cycle']) . ' subscription',
             $subtotal, $subtotal]
        );

        auditLog('create', 'invoice', $invId, 'recurring-billing');

        // SMS notification
        if (!empty($cust['phone'])) {
            $msg = "Invoice $invNum for " . number_format($total, 2) . " has been raised and is due on " .
                   date('d M Y', strtotime($dueDate)) . ". — $co";
            sendSms($cust['phone'], $msg);
        }

        advanceNextInvoiceDate($cust);
        $generated++;

        echo "[OK] $invNum for {$cust['name']}\n";

    } catch (\Throwable $e) {
        $errors++;
        echo "[ERR] Customer {$cust['id']} ({$cust['name']}): " . $e->getMessage() . "\n";
        error_log("recurring-billing error customer={$cust['id']}: " . $e->getMessage());
    }
}

echo "Done. Generated: $generated, Errors: $errors\n";

function advanceNextInvoiceDate(array $cust): void {
    $next = match($cust['plan_cycle'] ?? 'monthly') {
        'quarterly' => date('Y-m-d', strtotime('+3 months', strtotime($cust['next_invoice_date']))),
        'annually'  => date('Y-m-d', strtotime('+1 year',   strtotime($cust['next_invoice_date']))),
        default     => date('Y-m-d', strtotime('+1 month',  strtotime($cust['next_invoice_date']))),
    };
    dbRun("UPDATE customers SET next_invoice_date = ? WHERE id = ?", [$next, $cust['id']]);
}
