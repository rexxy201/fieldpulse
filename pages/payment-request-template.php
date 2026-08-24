<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (!hasPermission('payment_requests.create') && !hasPermission('payment_requests.view')) { header('Location: /dashboard'); exit; }

$appCfg = getAppConfig();
$co = htmlspecialchars($appCfg['companyName'] ?? 'MangoNet Integrated Technologies Limited');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Request Voucher — Blank Template</title>
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
  .field .line { border-bottom:1px solid #333; height:1.1rem; }
  .checkbox-group span { margin-right:1rem; white-space:nowrap; }
  .breakdown-table td { height:1.6rem; }
  .sign-table th, .sign-table td { text-align:center; height:2.2rem; }
  @media print {
    .toolbar { display:none; }
    body { padding: 0.5in; }
  }
</style>
</head>
<body>
  <div class="toolbar"><button onclick="window.print()">🖨 Print / Save as PDF</button></div>

  <div class="company"><?= $co ?></div>
  <h1>REQUEST VOUCHER</h1>

  <div class="finance-box">
    <strong>For Finance Use Only:</strong>
    <div>Voucher No.: ___________________</div>
    <div>Date Received: _________________</div>
    <div>Recorded By (Finance): __________</div>
    <div>Bank: __________________________</div>
    <div>Posted: ________________________</div>
    <div>Reconciliation: _________________</div>
  </div>
  <div class="clear"></div>

  <div class="section-title">Requester &amp; Customer / Job Details</div>
  <div class="field-row">
    <div class="field"><label>Date of Request</label><div class="line"></div></div>
    <div class="field"><label>Requested By</label><div class="line"></div></div>
    <div class="field"><label>Department</label><div class="line"></div></div>
  </div>
  <div class="field-row">
    <div class="field checkbox-group"><label>Request Type</label><span>☐ Operational</span><span>☐ Expansion</span><span>☐ Deployment</span><span>☐ Admin Requests</span></div>
  </div>
  <div class="field-row">
    <div class="field"><label>Customer(s) <span style="font-weight:normal">(required for Operational type)</span></label><div class="line"></div></div>
  </div>
  <div class="field-row">
    <div class="field"><label>Location / City</label><div class="line"></div></div>
    <div class="field"><label>POP</label><div class="line"></div></div>
  </div>
  <div class="field-row">
    <div class="field checkbox-group">
      <label>Category</label>
      <span>☐ Operational</span><span>☐ Deployment/Expansion</span><span>☐ Fiber Cut Restoration</span><br>
      <span>☐ Equipment</span><span>☐ Inventory/Materials</span><span>☐ Other: ___________</span>
    </div>
  </div>
  <div class="field-row">
    <div class="field checkbox-group"><label>Capex / Opex</label><span>☐ Capex</span><span>☐ Opex</span></div>
    <div class="field checkbox-group"><label>Priority</label><span>☐ High</span><span>☐ Medium</span><span>☐ Low</span></div>
  </div>
  <div class="field-row">
    <div class="field"><label>Reason / Description of Request</label>
      <div class="line"></div><div class="line"></div>
    </div>
  </div>

  <div class="section-title">Request Breakdown</div>
  <table class="breakdown-table">
    <thead><tr><th>Description</th><th style="width:70px">Qty</th><th style="width:130px">Unit Price (NGN)</th><th style="width:130px">Total (NGN)</th></tr></thead>
    <tbody>
      <?php for ($i = 0; $i < 6; $i++): ?>
      <tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>
      <?php endfor; ?>
      <tr><td colspan="3" style="text-align:right"><strong>Grand Total (NGN)</strong></td><td>&nbsp;</td></tr>
    </tbody>
  </table>

  <div class="field-row">
    <div class="field"><label>Receiver</label><div class="line"></div></div>
  </div>

  <div class="section-title">Approval / Sign-off</div>
  <table class="sign-table">
    <thead><tr><th style="width:120px"></th><th>Name</th><th>Signature</th><th style="width:120px">Date</th></tr></thead>
    <tbody>
      <tr><td><strong>Authorized</strong></td><td></td><td></td><td></td></tr>
      <tr><td><strong>Approved</strong></td><td></td><td></td><td></td></tr>
      <tr><td><strong>Disbursed</strong></td><td></td><td></td><td></td></tr>
    </tbody>
  </table>
</body>
</html>
