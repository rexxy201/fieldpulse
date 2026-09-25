<?php
requirePermission('billing.invoices.view');

$invId = trim($_GET['id'] ?? '');
if (!$invId) { header('Location: /billing/invoices'); exit; }

$inv = dbFetch("SELECT * FROM invoices WHERE id = ?", [$invId]);
if (!$inv) { header('Location: /billing/invoices'); exit; }

$lines    = dbFetchAll("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id", [$invId]);
$payments = dbFetchAll("SELECT * FROM invoice_payments WHERE invoice_id = ? ORDER BY paid_at", [$invId]);

$cfg  = getAppConfig();
$co   = $cfg['companyName'] ?? 'FieldPulse';
$logo = $cfg['companyLogo'] ?? '';
$primary = $cfg['primaryColor'] ?? '#0ea5e9';

$statusLabels = [
    'draft'     => ['Draft',     '#6b7280'],
    'sent'      => ['Sent',      '#2563eb'],
    'paid'      => ['Paid',      '#16a34a'],
    'partial'   => ['Partial',   '#d97706'],
    'overdue'   => ['Overdue',   '#dc2626'],
    'void'      => ['Void',      '#9ca3af'],
    'cancelled' => ['Cancelled', '#9ca3af'],
];
[$statusLabel, $statusColor] = $statusLabels[$inv['status']] ?? ['Unknown', '#6b7280'];

$balance = (float)$inv['total'] - (float)$inv['amount_paid'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?= htmlspecialchars($inv['invoice_number']) ?> – <?= htmlspecialchars($co) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; font-size: 13px; color: #1e293b; background: #f1f5f9; }
.page { max-width: 820px; margin: 2rem auto; background: #fff; border-radius: 8px; box-shadow: 0 4px 24px rgba(0,0,0,.08); overflow: hidden; }
.header { padding: 2rem 2.5rem 1.5rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; }
.header-left { flex: 1; }
.company-name { font-size: 1.25rem; font-weight: 700; color: <?= htmlspecialchars($primary) ?>; margin-bottom: .2rem; }
.invoice-title { font-size: 1.6rem; font-weight: 700; color: #0f172a; letter-spacing: -.5px; }
.invoice-number { font-size: .85rem; color: #64748b; margin-top: .15rem; }
.status-badge { display: inline-block; padding: .3rem .75rem; border-radius: 20px; font-size: .75rem; font-weight: 600; letter-spacing: .4px; text-transform: uppercase; color: #fff; background: <?= htmlspecialchars($statusColor) ?>; }
.meta { padding: 1.5rem 2.5rem; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1.5rem; border-bottom: 1px solid #e2e8f0; }
.meta-block label { display: block; font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: #94a3b8; margin-bottom: .3rem; }
.meta-block .val { font-weight: 500; }
.meta-block address { font-style: normal; line-height: 1.6; }
.bill-to { padding: 1.5rem 2.5rem; border-bottom: 1px solid #e2e8f0; }
.bill-to label { display: block; font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: #94a3b8; margin-bottom: .35rem; }
.bill-to .name { font-size: 1rem; font-weight: 600; color: #0f172a; }
.bill-to .contact { color: #64748b; margin-top: .2rem; font-size: .82rem; }
table.items { width: 100%; border-collapse: collapse; }
.items-wrap { padding: 0 2.5rem; }
table.items thead th { padding: .75rem 1rem; background: #f8fafc; font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: #64748b; text-align: left; border-bottom: 2px solid #e2e8f0; }
table.items thead th.num { text-align: right; }
table.items tbody td { padding: .75rem 1rem; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
table.items tbody td.num { text-align: right; font-variant-numeric: tabular-nums; }
table.items tbody tr:last-child td { border-bottom: none; }
.totals { display: flex; justify-content: flex-end; padding: 1rem 2.5rem 1.5rem; border-top: 1px solid #e2e8f0; }
.totals-table { width: 260px; }
.totals-table td { padding: .35rem 0; font-size: .85rem; }
.totals-table td:last-child { text-align: right; font-variant-numeric: tabular-nums; }
.totals-table .total-row td { font-size: 1rem; font-weight: 700; border-top: 2px solid #e2e8f0; padding-top: .6rem; }
.totals-table .balance-row td { color: <?= $balance > 0 ? '#dc2626' : '#16a34a' ?>; font-weight: 700; }
.payments { padding: 0 2.5rem 1.5rem; }
.payments h3 { font-size: .8rem; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: #94a3b8; margin-bottom: .75rem; }
table.pay-tbl { width: 100%; border-collapse: collapse; font-size: .82rem; }
table.pay-tbl th { text-align: left; padding: .4rem .6rem; background: #f8fafc; color: #64748b; font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
table.pay-tbl td { padding: .4rem .6rem; border-bottom: 1px solid #f1f5f9; }
table.pay-tbl td:last-child { text-align: right; }
.notes { padding: 1rem 2.5rem 1.5rem; border-top: 1px solid #e2e8f0; }
.notes h3 { font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: #94a3b8; margin-bottom: .4rem; }
.notes p { color: #475569; line-height: 1.6; font-size: .82rem; }
.footer { padding: 1rem 2.5rem; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
.footer small { color: #94a3b8; font-size: .72rem; }

/* Print toolbar — hidden on actual print */
.print-bar { position: fixed; top: 0; left: 0; right: 0; background: #0f172a; color: #fff; padding: .6rem 1.5rem; display: flex; align-items: center; justify-content: space-between; z-index: 1000; gap: .75rem; font-size: .875rem; }
.print-bar a { color: #94a3b8; text-decoration: none; font-size: .8rem; }
.print-bar a:hover { color: #fff; }
.print-bar .btn-print { background: <?= htmlspecialchars($primary) ?>; color: #fff; border: none; border-radius: 6px; padding: .4rem 1.1rem; font-size: .825rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: .4rem; }
body { padding-top: 44px; }

@media print {
  body { background: #fff; padding-top: 0; }
  .print-bar { display: none; }
  .page { margin: 0; border-radius: 0; box-shadow: none; }
  @page { margin: 1.2cm; }
}
</style>
</head>
<body>

<div class="print-bar">
  <a href="/billing/invoices">← Invoices</a>
  <span><?= htmlspecialchars($inv['invoice_number']) ?> &nbsp;·&nbsp; <?= htmlspecialchars($co) ?></span>
  <button class="btn-print" onclick="window.print()">
    <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M5 1a2 2 0 0 0-2 2v1h10V3a2 2 0 0 0-2-2H5zm6 8H5a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1z"/><path d="M0 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-1v-2a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v2H2a2 2 0 0 1-2-2V7zm2.5 1a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/></svg>
    Print / Save PDF
  </button>
</div>

<div class="page">

  <!-- Header -->
  <div class="header">
    <div class="header-left">
      <?php if ($logo): ?>
        <img src="<?= htmlspecialchars($logo) ?>" alt="<?= htmlspecialchars($co) ?>" style="height:36px;margin-bottom:.6rem;display:block">
      <?php else: ?>
        <div class="company-name"><?= htmlspecialchars($co) ?></div>
      <?php endif; ?>
      <div class="invoice-title">INVOICE</div>
      <div class="invoice-number"><?= htmlspecialchars($inv['invoice_number']) ?></div>
    </div>
    <div style="text-align:right">
      <div class="status-badge"><?= htmlspecialchars($statusLabel) ?></div>
      <?php if (!empty($inv['due_date'])): ?>
        <div style="margin-top:.6rem;font-size:.78rem;color:#64748b">Due <?= htmlspecialchars(date('M j, Y', strtotime($inv['due_date']))) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Bill To + Dates -->
  <div class="meta">
    <div class="meta-block">
      <label>Bill To</label>
      <div class="val" style="font-weight:600;font-size:.95rem"><?= htmlspecialchars($inv['customer_name']) ?></div>
      <?php if (!empty($inv['customer_email'])): ?>
        <div style="color:#64748b;font-size:.82rem"><?= htmlspecialchars($inv['customer_email']) ?></div>
      <?php endif; ?>
      <?php if (!empty($inv['customer_phone'])): ?>
        <div style="color:#64748b;font-size:.82rem"><?= htmlspecialchars($inv['customer_phone']) ?></div>
      <?php endif; ?>
      <?php if (!empty($inv['customer_addr'])): ?>
        <div style="color:#64748b;font-size:.82rem;margin-top:.2rem;white-space:pre-line"><?= htmlspecialchars($inv['customer_addr']) ?></div>
      <?php endif; ?>
    </div>
    <div class="meta-block">
      <label>Invoice Date</label>
      <div class="val"><?= $inv['issue_date'] ? htmlspecialchars(date('M j, Y', strtotime($inv['issue_date']))) : '—' ?></div>
      <?php if (!empty($inv['due_date'])): ?>
        <div style="margin-top:.75rem"><label>Due Date</label>
        <div class="val"><?= htmlspecialchars(date('M j, Y', strtotime($inv['due_date']))) ?></div></div>
      <?php endif; ?>
    </div>
    <div class="meta-block">
      <label>Amount Due</label>
      <div class="val" style="font-size:1.3rem;font-weight:700;color:<?= $balance > 0 ? '#dc2626' : '#16a34a' ?>">
        <?= number_format($balance, 2) ?>
      </div>
      <div style="color:#94a3b8;font-size:.75rem;margin-top:.1rem">of <?= number_format((float)$inv['total'], 2) ?> total</div>
    </div>
  </div>

  <!-- Line Items -->
  <div class="items-wrap" style="padding-top:1.5rem">
    <table class="items">
      <thead>
        <tr>
          <th style="width:50%">Description</th>
          <th class="num" style="width:12%">Qty</th>
          <th class="num" style="width:18%">Unit Price</th>
          <th class="num" style="width:20%">Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $line): ?>
        <tr>
          <td><?= htmlspecialchars($line['description']) ?></td>
          <td class="num"><?= rtrim(rtrim(number_format((float)$line['qty'], 4), '0'), '.') ?></td>
          <td class="num"><?= number_format((float)$line['unit_price'], 2) ?></td>
          <td class="num" style="font-weight:500"><?= number_format((float)$line['line_total'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Totals -->
  <div class="totals">
    <table class="totals-table">
      <tr>
        <td style="color:#64748b">Subtotal</td>
        <td><?= number_format((float)$inv['subtotal'], 2) ?></td>
      </tr>
      <?php if ((float)$inv['tax_rate'] > 0): ?>
      <tr>
        <td style="color:#64748b">Tax (<?= number_format((float)$inv['tax_rate'], 2) ?>%)</td>
        <td><?= number_format((float)$inv['tax_amount'], 2) ?></td>
      </tr>
      <?php endif; ?>
      <tr class="total-row">
        <td>Total</td>
        <td><?= number_format((float)$inv['total'], 2) ?></td>
      </tr>
      <?php if ((float)$inv['amount_paid'] > 0): ?>
      <tr>
        <td style="color:#64748b">Amount Paid</td>
        <td style="color:#16a34a">(<?= number_format((float)$inv['amount_paid'], 2) ?>)</td>
      </tr>
      <tr class="balance-row">
        <td>Balance Due</td>
        <td><?= number_format($balance, 2) ?></td>
      </tr>
      <?php endif; ?>
    </table>
  </div>

  <?php if (!empty($payments)): ?>
  <!-- Payment History -->
  <div class="payments">
    <h3>Payment History</h3>
    <table class="pay-tbl">
      <thead>
        <tr>
          <th>Date</th>
          <th>Method</th>
          <th>Reference</th>
          <th>Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($payments as $p): ?>
        <tr>
          <td><?= htmlspecialchars(date('M j, Y', strtotime($p['paid_at']))) ?></td>
          <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $p['method']))) ?></td>
          <td style="color:#64748b"><?= $p['reference'] ? htmlspecialchars($p['reference']) : '—' ?></td>
          <td style="text-align:right;font-weight:500"><?= number_format((float)$p['amount'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if (!empty($inv['notes']) || !empty($inv['terms'])): ?>
  <div class="notes">
    <?php if (!empty($inv['notes'])): ?>
      <h3>Notes</h3>
      <p style="margin-bottom:<?= !empty($inv['terms']) ? '.75rem' : '0' ?>"><?= nl2br(htmlspecialchars($inv['notes'])) ?></p>
    <?php endif; ?>
    <?php if (!empty($inv['terms'])): ?>
      <h3>Terms &amp; Conditions</h3>
      <p><?= nl2br(htmlspecialchars($inv['terms'])) ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Footer -->
  <div class="footer">
    <small><?= htmlspecialchars($co) ?></small>
    <small>Generated <?= date('M j, Y') ?> &nbsp;·&nbsp; <?= htmlspecialchars($inv['invoice_number']) ?></small>
  </div>

</div>
</body>
</html>
