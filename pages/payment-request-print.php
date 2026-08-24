<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$user = currentUser();
$role = $user['role'];
$id   = $_GET['id'] ?? '';

$r = $id ? dbFetch(
    "SELECT pr.*, v.name AS vendor_name, h.name AS hub_name
     FROM payment_requests pr
     LEFT JOIN vendors v ON v.id = pr.vendor_id
     LEFT JOIN hubs h ON h.id = pr.hub_id
     WHERE pr.id = ?", [$id]
) : null;
if (!$r) { http_response_code(404); exit('Not found'); }

// Same authorization as the module itself and its document downloads.
$allowed = hasPermission('payment_requests.view') || hasPermission('payment_requests.authorize')
    || hasPermission('payment_requests.approve') || hasPermission('payment_requests.finance_check')
    || $r['requester_id'] === $user['id']
    || ($role === 'vendor' && !empty($user['vendor_id']) && $r['vendor_id'] === $user['vendor_id']);
if (!$allowed) { http_response_code(403); exit('Forbidden'); }

$items = dbFetchAll("SELECT * FROM payment_request_items WHERE payment_request_id=? ORDER BY sort_order", [$id]);

$appCfg = getAppConfig();
$co = htmlspecialchars($appCfg['companyName'] ?? 'MangoNet Integrated Technologies Limited');
$categories = ['Operational','Deployment/Expansion','Fiber Cut Restoration','Equipment','Inventory/Materials'];
$fmtDate = fn($d) => $d ? date('d M Y', strtotime($d)) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Request Voucher — <?= htmlspecialchars($r['requester_name'] ?? '') ?></title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; color:#111; margin:0; padding:2rem; font-size:13px; }
  .toolbar { margin-bottom:1.5rem; }
  .toolbar button { padding:.5rem 1.2rem; font-size:.9rem; background:#0ea5e9; color:#fff; border:none; border-radius:.4rem; cursor:pointer; }
  h1 { font-size:1.1rem; text-align:center; letter-spacing:.05em; margin:.25rem 0 1rem; }
  .company { text-align:center; font-weight:bold; font-size:1rem; margin-bottom:.25rem; }
  .finance-box { border:1px solid #333; padding:.5rem .75rem; float:right; width:230px; font-size:11px; }
  .finance-box div { margin-bottom:.35rem; border-bottom:1px dotted #999; padding-bottom:.15rem; }
  .clear { clear:both; }
  table { border-collapse: collapse; width:100%; margin-bottom:1rem; }
  th, td { border:1px solid #333; padding:.35rem .5rem; font-size:12px; text-align:left; vertical-align:top; }
  .section-title { font-weight:bold; background:#f1f5f9; padding:.3rem .5rem; margin:1rem 0 .5rem; border:1px solid #333; }
  .field-row { display:flex; gap:1rem; margin-bottom:.6rem; }
  .field { flex:1; }
  .field label { display:block; font-weight:bold; font-size:11px; margin-bottom:.15rem; }
  .field .value { border-bottom:1px solid #333; min-height:1.1rem; padding-bottom:.1rem; }
  .checkbox-group span { margin-right:1rem; white-space:nowrap; }
  .checkbox-group .checked { font-weight:bold; }
  .sign-table th, .sign-table td { text-align:center; height:2.2rem; }
  .status-badge { display:inline-block; padding:.2rem .7rem; border-radius:1rem; font-size:11px; font-weight:bold; color:#fff; }
  @media print {
    .toolbar { display:none; }
    body { padding: 0.5in; }
  }
</style>
</head>
<body>
  <div class="toolbar"><button onclick="window.print()">🖨 Print / Save as PDF</button></div>

  <div class="company"><?= $co ?></div>
  <h1>REQUEST VOUCHER
    <?php
    $statusColors = ['pending'=>'#f59e0b','authorized'=>'#0ea5e9','approved'=>'#3b82f6','returned'=>'#64748b','disbursed'=>'#10b981','rejected'=>'#ef4444'];
    $statusLabels = ['pending'=>'PENDING','authorized'=>'AUTHORIZED','approved'=>'APPROVED','returned'=>'RETURNED','disbursed'=>'DISBURSED','rejected'=>'REJECTED'];
    ?>
    <span class="status-badge" style="background:<?= $statusColors[$r['status']] ?? '#64748b' ?>"><?= $statusLabels[$r['status']] ?? strtoupper($r['status']) ?></span>
  </h1>

  <div class="finance-box">
    <strong>For Finance Use Only:</strong>
    <div>Voucher No.: <?= htmlspecialchars(substr($r['id'], 0, 8)) ?></div>
    <div>Date Received: <?= $fmtDate($r['created_at']) ?></div>
    <div>Recorded By (Finance): <?= htmlspecialchars($r['disbursed_by_name'] ?? $r['returned_by_name'] ?? '') ?></div>
    <div>Bank: __________________________</div>
    <div>Posted: <?= $r['status']==='disbursed' ? $fmtDate($r['paid_at']) : '' ?></div>
    <div>Reconciliation: <?= htmlspecialchars($r['payment_reference'] ?? '') ?></div>
  </div>
  <div class="clear"></div>

  <div class="section-title">Requester &amp; Customer / Job Details</div>
  <div class="field-row">
    <div class="field"><label>Date of Request</label><div class="value"><?= $fmtDate($r['date_of_request']) ?></div></div>
    <div class="field"><label>Requested By</label><div class="value"><?= htmlspecialchars($r['requester_name'] ?? '') ?></div></div>
    <div class="field"><label>Department</label><div class="value"><?= htmlspecialchars($r['department'] ?? '') ?></div></div>
  </div>
  <div class="field-row">
    <div class="field"><label>Customer Name</label><div class="value"><?= htmlspecialchars($r['customer_name'] ?? '') ?></div></div>
    <div class="field"><label>Customer User ID</label><div class="value"><?= htmlspecialchars($r['customer_user_id'] ?? '') ?></div></div>
  </div>
  <div class="field-row">
    <div class="field"><label>Location / City</label><div class="value"><?= htmlspecialchars($r['location'] ?? '') ?></div></div>
    <div class="field"><label>POP</label><div class="value"><?= htmlspecialchars($r['hub_name'] ?? '') ?></div></div>
  </div>
  <div class="field-row">
    <div class="field checkbox-group">
      <label>Category</label>
      <?php foreach ($categories as $c): ?>
      <span class="<?= $r['category']===$c?'checked':'' ?>"><?= $r['category']===$c?'☑':'☐' ?> <?= $c ?></span>
      <?php endforeach; ?>
      <span class="<?= $r['category']==='Other'?'checked':'' ?>"><?= $r['category']==='Other'?'☑':'☐' ?> Other: <?= htmlspecialchars($r['category_other'] ?? '') ?></span>
    </div>
  </div>
  <div class="field-row">
    <div class="field checkbox-group">
      <label>Capex / Opex</label>
      <span class="<?= $r['capex_opex']==='Capex'?'checked':'' ?>"><?= $r['capex_opex']==='Capex'?'☑':'☐' ?> Capex</span>
      <span class="<?= $r['capex_opex']==='Opex'?'checked':'' ?>"><?= $r['capex_opex']==='Opex'?'☑':'☐' ?> Opex</span>
    </div>
    <div class="field checkbox-group">
      <label>Priority</label>
      <?php foreach (['High','Medium','Low'] as $p): ?>
      <span class="<?= $r['priority']===$p?'checked':'' ?>"><?= $r['priority']===$p?'☑':'☐' ?> <?= $p ?></span>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="field-row">
    <div class="field"><label>Reason / Description of Request</label><div class="value"><?= nl2br(htmlspecialchars($r['description'] ?? '')) ?></div></div>
  </div>

  <div class="section-title">Request Breakdown</div>
  <table>
    <thead><tr><th>Description</th><th style="width:70px">Qty</th><th style="width:130px">Unit Price (NGN)</th><th style="width:130px">Total (NGN)</th></tr></thead>
    <tbody>
      <?php if (!$items): ?><tr><td colspan="4" style="text-align:center;color:#888">No line items recorded</td></tr><?php endif; ?>
      <?php foreach ($items as $it): ?>
      <tr>
        <td><?= htmlspecialchars($it['description']) ?></td>
        <td><?= rtrim(rtrim(number_format((float)$it['qty'], 2), '0'), '.') ?></td>
        <td><?= number_format((float)$it['unit_price'], 2) ?></td>
        <td><?= number_format((float)$it['line_total'], 2) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr><td colspan="3" style="text-align:right"><strong>Grand Total (NGN)</strong></td><td><strong><?= number_format((float)$r['amount'], 2) ?></strong></td></tr>
    </tbody>
  </table>

  <div class="field-row">
    <div class="field"><label>Receiver</label><div class="value"><?= htmlspecialchars($r['receiver'] ?? '') ?></div></div>
    <?php if ($r['vendor_name']): ?><div class="field"><label>Vendor</label><div class="value"><?= htmlspecialchars($r['vendor_name']) ?></div></div><?php endif; ?>
  </div>

  <div class="section-title">Approval / Sign-off</div>
  <table class="sign-table">
    <thead><tr><th style="width:120px"></th><th>Name</th><th>Signature</th><th style="width:120px">Date</th></tr></thead>
    <tbody>
      <tr>
        <td><strong>Authorized</strong></td>
        <td><?= htmlspecialchars($r['authorized_by_name'] ?? '') ?></td>
        <td></td>
        <td><?= $fmtDate($r['authorized_at']) ?></td>
      </tr>
      <tr>
        <td><strong>Approved</strong></td>
        <td><?= htmlspecialchars($r['approved_by_name'] ?? '') ?></td>
        <td></td>
        <td><?= $fmtDate($r['approved_at']) ?></td>
      </tr>
      <tr>
        <td><strong>Disbursed</strong></td>
        <td><?= htmlspecialchars($r['disbursed_by_name'] ?? '') ?></td>
        <td></td>
        <td><?= $r['status']==='disbursed' ? $fmtDate($r['paid_at']) : '' ?></td>
      </tr>
      <?php if ($r['status'] === 'returned'): ?>
      <tr>
        <td><strong>Returned to Requester</strong></td>
        <td><?= htmlspecialchars($r['returned_by_name'] ?? '') ?></td>
        <td colspan="2"><?= htmlspecialchars($r['return_notes'] ?? '') ?> — <?= $fmtDate($r['returned_at']) ?></td>
      </tr>
      <?php endif; ?>
      <?php if ($r['status'] === 'rejected'): ?>
      <tr>
        <td><strong>Rejected</strong></td>
        <td><?= htmlspecialchars($r['reviewed_by_name'] ?? '') ?></td>
        <td colspan="2"><?= htmlspecialchars($r['review_notes'] ?? '') ?> — <?= $fmtDate($r['reviewed_at']) ?></td>
      </tr>
      <?php endif; ?>
    </tbody>
  </table>
</body>
</html>
