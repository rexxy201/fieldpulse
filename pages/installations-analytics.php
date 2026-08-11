<?php
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('installations.view');

// Vendor-performance breakdowns are management-only — vendors only have
// installations.view and shouldn't see cross-vendor SLA comparisons.
$canEdit = hasPermission('installations.create') || hasPermission('installations.update');
if (!$canEdit) { header('Location: /installations'); exit; }

$vendorFilter = $_GET['vendor'] ?? '';
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');

$conds = []; $params = [];
if ($vendorFilter) { $conds[] = "p.vendor_id = ?"; $params[] = $vendorFilter; }
if ($from) { $conds[] = "p.payment_confirmed_at >= ?"; $params[] = $from . ' 00:00:00'; }
if ($to)   { $conds[] = "p.payment_confirmed_at <= ?"; $params[] = $to   . ' 23:59:59'; }
$andSql   = $conds ? ' AND ' . implode(' AND ', $conds) : '';

// Date-only conditions (no vendor filter) — used in the vendor-performance
// breakdown, which compares vendors and shouldn't be pre-filtered to one
$dateConds = []; $dateParams = [];
if ($from) { $dateConds[] = "p.payment_confirmed_at >= ?"; $dateParams[] = $from . ' 00:00:00'; }
if ($to)   { $dateConds[] = "p.payment_confirmed_at <= ?"; $dateParams[] = $to   . ' 23:59:59'; }
$dateAndSql = $dateConds ? ' AND ' . implode(' AND ', $dateConds) : '';

$_diffPaymentToCompleted = dbSecondsDiff('p.payment_confirmed_at', 'p.completed_at');
$_diffCreatedToCompleted = dbSecondsDiff('p.created_at', 'p.completed_at');
// Terminal statuses (connected, refunded) — excluded from "pending/overdue" everywhere below.
$_terminalIn = "'" . implode("','", INSTALLATION_TERMINAL_STATUSES) . "'";

// ─── Overview stats ────────────────────────────────────────────────────────
$pendingSummary = dbFetch(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN p.payment_confirmed_at IS NOT NULL AND p.sla_due_at < NOW() THEN 1 ELSE 0 END) AS overdue,
            SUM(CASE WHEN p.payment_confirmed_at IS NULL THEN 1 ELSE 0 END) AS awaiting_payment
     FROM installation_profiles p
     WHERE p.status NOT IN ({$_terminalIn}){$andSql}",
    $params
);

$completedSummary = dbFetch(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN p.sla_due_at IS NOT NULL AND p.completed_at <= p.sla_due_at THEN 1 ELSE 0 END) AS on_time,
            SUM(CASE WHEN p.sla_due_at IS NOT NULL THEN 1 ELSE 0 END) AS with_sla,
            ROUND(AVG(CASE WHEN p.payment_confirmed_at IS NOT NULL THEN ({$_diffPaymentToCompleted})/3600.0 END), 1) AS avg_hours_payment,
            ROUND(AVG(({$_diffCreatedToCompleted})/3600.0), 1) AS avg_hours_created
     FROM installation_profiles p
     WHERE p.status = 'connected'{$andSql}",
    $params
);
$slaComplianceRate = ($completedSummary && $completedSummary['with_sla'] > 0)
    ? round($completedSummary['on_time'] / $completedSummary['with_sla'] * 100, 1) : null;

// ─── Currently pending, sorted most overdue first ─────────────────────────
$pendingRows = dbFetchAll(
    "SELECT p.id, p.name, p.status, p.payment_confirmed_at, p.sla_due_at, v.name AS vendor_name
     FROM installation_profiles p LEFT JOIN vendors v ON v.id = p.vendor_id
     WHERE p.status NOT IN ({$_terminalIn}){$andSql}
     ORDER BY (p.sla_due_at IS NULL), p.sla_due_at ASC
     LIMIT 200",
    $params
);

// ─── Vendor performance (always compares all vendors — date range applies, vendor filter does not) ─
$vendorRows = dbFetchAll(
    "SELECT v.id, v.name,
            COUNT(p.id) AS total_assigned,
            SUM(CASE WHEN p.status='connected' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN p.status NOT IN ({$_terminalIn}) AND p.payment_confirmed_at IS NOT NULL AND p.sla_due_at < NOW() THEN 1 ELSE 0 END) AS overdue_now,
            ROUND(AVG(CASE WHEN p.status='connected' AND p.payment_confirmed_at IS NOT NULL THEN ({$_diffPaymentToCompleted})/3600.0 END), 1) AS avg_hours,
            SUM(CASE WHEN p.status='connected' AND p.sla_due_at IS NOT NULL AND p.completed_at <= p.sla_due_at THEN 1 ELSE 0 END) AS on_time,
            SUM(CASE WHEN p.status='connected' AND p.sla_due_at IS NOT NULL THEN 1 ELSE 0 END) AS with_sla
     FROM vendors v
     LEFT JOIN installation_profiles p ON p.vendor_id = v.id{$dateAndSql}
     WHERE v.type = 'installation'
     GROUP BY v.id, v.name
     ORDER BY total_assigned DESC",
    $dateParams
);

// Reassignment counts (times a vendor was pulled off a job — a "failed to deliver" signal)
$reassignCounts = dbFetchAll(
    "SELECT old_vendor_id, COUNT(*) AS c FROM installation_vendor_history WHERE old_vendor_id IS NOT NULL GROUP BY old_vendor_id"
);
$reassignByVendor = array_column($reassignCounts, 'c', 'old_vendor_id');

$allVendors = dbFetchAll("SELECT id,name FROM vendors WHERE type='installation' ORDER BY name");

$pageTitle = 'Installation SLA Analytics';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h2 class="fw-bold mb-0">Installation SLA Analytics</h2>
    <div class="text-muted small">SLA clock starts at payment confirmation — target: <?= INSTALLATION_SLA_WORKING_DAYS ?> working days to completion.</div>
  </div>
  <a href="/installations" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Installations</a>
</div>

<!-- Filters -->
<div class="card-section mb-3">
  <form class="p-3 d-flex flex-wrap gap-2 align-items-end" method="GET">
    <div>
      <label class="form-label small fw-semibold mb-1">Vendor</label>
      <select name="vendor" class="form-select form-select-sm">
        <option value="">All Vendors</option>
        <?php foreach ($allVendors as $v): ?>
        <option value="<?= $v['id'] ?>" <?= $vendorFilter===$v['id']?'selected':'' ?>><?= htmlspecialchars($v['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label small fw-semibold mb-1">Payment Confirmed From</label>
      <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="form-control form-control-sm">
    </div>
    <div>
      <label class="form-label small fw-semibold mb-1">Payment Confirmed To</label>
      <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="form-control form-control-sm">
    </div>
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    <?php if ($vendorFilter || $from || $to): ?><a href="/installations/analytics" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
  </form>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-label">Pending Installations</div>
      <div class="stat-value"><?= (int)($pendingSummary['total'] ?? 0) ?></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-label">Overdue (Past SLA)</div>
      <div class="stat-value <?= (int)($pendingSummary['overdue'] ?? 0) > 0 ? 'text-danger' : '' ?>"><?= (int)($pendingSummary['overdue'] ?? 0) ?></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-label">SLA Compliance (Completed)</div>
      <div class="stat-value <?= $slaComplianceRate !== null && $slaComplianceRate < 80 ? 'text-danger' : 'text-success' ?>"><?= $slaComplianceRate !== null ? $slaComplianceRate.'%' : '—' ?></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-label">Avg Install Time (Payment → Done)</div>
      <div class="stat-value"><?= $completedSummary['avg_hours_payment'] ?? '—' ?><small class="fs-6 fw-normal text-muted"> hrs</small></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="card-section">
      <div class="card-header"><i class="bi bi-hourglass-split me-1 text-danger"></i>Pending Installations by SLA Status</div>
      <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
          <thead class="table-light">
            <tr><th class="ps-3">Customer</th><th>Status</th><th>Vendor</th><th>Payment Confirmed</th><th>SLA Due</th><th class="pe-3">SLA</th></tr>
          </thead>
          <tbody>
            <?php if (!$pendingRows): ?><tr><td colspan="6" class="text-center text-muted py-4">No pending installations in this range</td></tr><?php endif; ?>
            <?php foreach ($pendingRows as $p): $sla = installationSlaBadge($p); ?>
            <tr>
              <td class="ps-3"><a href="/installations?detail=<?= $p['id'] ?>" class="text-decoration-none fw-semibold" style="color:var(--primary)"><?= htmlspecialchars($p['name']) ?></a></td>
              <td class="small text-muted"><?= str_replace('_',' ',ucfirst($p['status'])) ?></td>
              <td class="small"><?= htmlspecialchars($p['vendor_name'] ?? 'Unassigned') ?></td>
              <td class="small text-muted"><?= $p['payment_confirmed_at'] ? date('d M Y', strtotime($p['payment_confirmed_at'])) : '—' ?></td>
              <td class="small text-muted"><?= $p['sla_due_at'] ? date('d M Y', strtotime($p['sla_due_at'])) : '—' ?></td>
              <td class="pe-3"><span class="badge bg-<?= $sla['class'] ?>"><?= $sla['label'] ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card-section">
  <div class="card-header"><i class="bi bi-truck me-1 text-primary"></i>Vendor Performance</div>
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0">
      <thead class="table-light">
        <tr><th class="ps-3">Vendor</th><th>Assigned</th><th>Completed</th><th>Overdue Now</th><th>Avg Hours</th><th>On-Time %</th><th class="pe-3">Reassigned Away</th></tr>
      </thead>
      <tbody>
        <?php if (!$vendorRows): ?><tr><td colspan="7" class="text-center text-muted py-4">No installation vendors found</td></tr><?php endif; ?>
        <?php foreach ($vendorRows as $v):
          $onTimePct = $v['with_sla'] > 0 ? round($v['on_time'] / $v['with_sla'] * 100, 1) : null;
          $reassignedAway = (int)($reassignByVendor[$v['id']] ?? 0);
        ?>
        <tr>
          <td class="ps-3 fw-semibold"><?= htmlspecialchars($v['name']) ?></td>
          <td><?= (int)$v['total_assigned'] ?></td>
          <td><?= (int)$v['completed'] ?></td>
          <td class="<?= (int)$v['overdue_now'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= (int)$v['overdue_now'] ?></td>
          <td><?= $v['avg_hours'] ?? '—' ?></td>
          <td><?= $onTimePct !== null ? $onTimePct.'%' : '—' ?></td>
          <td class="pe-3"><?= $reassignedAway > 0 ? '<span class="badge bg-danger">'.$reassignedAway.'</span>' : '0' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
