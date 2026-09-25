<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$ticketId = $segments[1] ?? '';
if (!$ticketId) { header('Location: /tickets'); exit; }

$ticket = dbFetch("SELECT * FROM tickets WHERE id = ?", [$ticketId]);
if (!$ticket) { header('Location: /tickets'); exit; }

if (!hasPermission('tickets.view_all') &&
    !hasPermission('tickets.view_department') &&
    !($ticket['assigned_to'] === currentUser()['id'])) {
    http_response_code(403); exit('Access denied.');
}

$customer = $ticket['customer_id']
    ? dbFetch("SELECT name,phone,email,address,mailing_street,mailing_city,mailing_state,account_number FROM customers WHERE id=?", [$ticket['customer_id']])
    : null;

$assignee = $ticket['assigned_to']
    ? dbFetch("SELECT name,phone FROM users WHERE id=?", [$ticket['assigned_to']])
    : null;

$photos = dbFetchAll("SELECT id,original_name FROM ticket_photos WHERE ticket_id=? ORDER BY created_at DESC LIMIT 6", [$ticketId]);

$checkins = dbFetchAll(
    "SELECT ci.*, u.name AS tech_name FROM ticket_checkins ci LEFT JOIN users u ON u.id=ci.user_id WHERE ci.ticket_id=? ORDER BY ci.checked_in_at DESC",
    [$ticketId]
);

$appCfg = getAppConfig();
$companyName  = $appCfg['companyName'] ?? 'FieldPulse';
$companyLogo  = $appCfg['companyLogo'] ?? '';

$prioLabel = ['p1'=>'Critical','p2'=>'High','p3'=>'Medium','p4'=>'Low'];
$tn = $ticket['ticket_number'] ?? ('#' . substr($ticket['id'], 0, 8));
$addr = trim(implode(', ', array_filter([
    $customer['mailing_street'] ?: ($customer['address'] ?? ''),
    $customer['mailing_city'] ?? '',
    $customer['mailing_state'] ?? '',
])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Job Sheet — <?= htmlspecialchars($tn) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { font-family: 'Segoe UI', Arial, sans-serif; font-size: .88rem; color:#111; background:#fff; padding:1.5rem; }
h1 { font-size:1.3rem; font-weight:700; }
.section-head { font-weight:600; font-size:.78rem; text-transform:uppercase; letter-spacing:.05em; color:#666; border-bottom:1px solid #ddd; margin:1rem 0 .5rem; padding-bottom:.25rem; }
.sig-line { border-bottom:1px solid #333; margin-top:2rem; height:1px; }
.notes-lines { display:block; }
.notes-line { border-bottom:1px solid #ccc; height:1.8rem; margin-bottom:.1rem; }
.photo-grid { display:flex; flex-wrap:wrap; gap:.5rem; }
.photo-grid img { width:90px; height:90px; object-fit:cover; border:1px solid #ddd; border-radius:.3rem; }
@media print {
  .no-print { display:none !important; }
  body { padding:.5rem; }
  a { color:inherit; text-decoration:none; }
}
</style>
</head>
<body>

<!-- Header -->
<div class="d-flex align-items-center justify-content-between mb-3">
  <div class="d-flex align-items-center gap-3">
    <?php if ($companyLogo): ?>
    <img src="<?= htmlspecialchars($companyLogo) ?>" alt="Logo" style="height:40px">
    <?php endif; ?>
    <div>
      <div style="font-weight:700;font-size:1rem"><?= htmlspecialchars($companyName) ?></div>
      <div style="color:#555;font-size:.78rem">Job Sheet</div>
    </div>
  </div>
  <div class="text-end">
    <div style="font-size:1.1rem;font-weight:700"><?= htmlspecialchars($tn) ?></div>
    <div style="color:#555;font-size:.75rem">Printed: <?= date('M j, Y g:i A') ?></div>
  </div>
</div>

<hr style="border-color:#ccc;margin:.5rem 0 1rem">

<!-- Job Details -->
<div class="row g-3 mb-2">
  <div class="col-6">
    <div class="section-head">Job Details</div>
    <table class="table table-sm table-borderless mb-0" style="font-size:.83rem">
      <tr><td class="text-muted pe-2" style="width:90px">Status</td><td><strong><?= htmlspecialchars(ucfirst(str_replace('_',' ',$ticket['status']??''))) ?></strong></td></tr>
      <tr><td class="text-muted pe-2">Priority</td><td><strong><?= htmlspecialchars($prioLabel[$ticket['priority']??'p3']??'—') ?></strong></td></tr>
      <tr><td class="text-muted pe-2">Created</td><td><?= date('M j, Y g:i A', strtotime($ticket['created_at']??'now')) ?></td></tr>
      <?php if ($ticket['sla_breach_at']): ?>
      <tr><td class="text-muted pe-2">SLA Due</td><td><?= date('M j, Y g:i A', strtotime($ticket['sla_breach_at'])) ?></td></tr>
      <?php endif; ?>
      <?php if ($assignee): ?>
      <tr><td class="text-muted pe-2">Technician</td><td><?= htmlspecialchars($assignee['name']) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>

  <div class="col-6">
    <div class="section-head">Customer</div>
    <?php if ($customer): ?>
    <div style="font-weight:600"><?= htmlspecialchars($customer['name'] ?? '—') ?></div>
    <?php if ($customer['account_number']): ?><div class="text-muted">Acct: <?= htmlspecialchars($customer['account_number']) ?></div><?php endif; ?>
    <?php if ($customer['phone']): ?><div><i class="bi bi-telephone"></i> <?= htmlspecialchars($customer['phone']) ?></div><?php endif; ?>
    <?php if ($customer['email']): ?><div style="font-size:.78rem"><?= htmlspecialchars($customer['email']) ?></div><?php endif; ?>
    <?php if ($addr): ?><div class="mt-1" style="font-size:.78rem"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($addr) ?></div><?php endif; ?>
    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
  </div>
</div>

<!-- Description -->
<div class="section-head">Job Description</div>
<p style="min-height:2.5rem"><?= nl2br(htmlspecialchars($ticket['description'] ?? '')) ?></p>

<!-- Photos -->
<?php if ($photos): ?>
<div class="section-head">Photos</div>
<div class="photo-grid mb-2">
  <?php foreach ($photos as $p): ?>
  <img src="/api/ticket-photo?id=<?= $p['id'] ?>" alt="<?= htmlspecialchars($p['original_name'] ?? 'photo') ?>">
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- GPS Check-in History -->
<?php if ($checkins): ?>
<div class="section-head">GPS Check-in History</div>
<table class="table table-sm table-bordered mb-2" style="font-size:.78rem">
  <thead class="table-light"><tr><th>Technician</th><th>Checked In</th><th>Checked Out</th><th>Location</th></tr></thead>
  <tbody>
    <?php foreach ($checkins as $ci): ?>
    <tr>
      <td><?= htmlspecialchars($ci['tech_name'] ?? $ci['user_name']) ?></td>
      <td><?= date('M j g:i A', strtotime($ci['checked_in_at'])) ?></td>
      <td><?= $ci['checked_out_at'] ? date('M j g:i A', strtotime($ci['checked_out_at'])) : '<span class="badge bg-success">Active</span>' ?></td>
      <td style="font-size:.72rem"><?= round($ci['latitude'],5) ?>, <?= round($ci['longitude'],5) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<!-- Technician Notes -->
<div class="section-head">Technician Notes (on-site)</div>
<div class="notes-lines">
  <?php for ($i=0;$i<5;$i++): ?><div class="notes-line"></div><?php endfor; ?>
</div>

<!-- Signatures -->
<div class="row g-4 mt-3">
  <div class="col-6">
    <div class="sig-line"></div>
    <div class="mt-1 text-muted" style="font-size:.75rem">Technician Signature &amp; Date</div>
  </div>
  <div class="col-6">
    <div class="sig-line"></div>
    <div class="mt-1 text-muted" style="font-size:.75rem">Customer Signature &amp; Date</div>
  </div>
</div>

<!-- Print / Close buttons -->
<div class="no-print d-flex gap-2 mt-4">
  <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
  <button class="btn btn-outline-secondary btn-sm" onclick="window.close()">Close</button>
</div>

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
