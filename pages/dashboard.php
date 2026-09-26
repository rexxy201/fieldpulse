<?php
require_once __DIR__ . '/../config.php';
requireAuth();

// Vendor-type users (installation vendor and maintenance-vendor team members)
// get the same dashboard as staff — tickets/installations already scope to
// their own company (and hub, for a vendor-mtce team member) via
// ticketScopeSql()/installationScopeSql(), same as every other query here.
$isVendorViewer = in_array(currentUser()['role'], ['vendor', 'vendor-mtce'], true);

// Finance roles have no dashboard widgets either — send them to the Finance
// dashboard IF they can access it (same redirect-loop guard as above).
if (in_array(currentUser()['role'], ['accountant','accounts_receivable','accounts_payable'], true) && hasPermission('finance.view')) {
    header('Location: /finance'); exit;
}

// ── Ticket visibility scope (department supervisors see only their dept) ──────
[$_tScope, $_tParams] = ticketScopeSql('');
$_tWhere = $_tScope ? "WHERE $_tScope" : '';          // for queries with no other WHERE
$_tAnd   = $_tScope ? "AND ($_tScope)" : '';          // for queries that already have WHERE

// ── Stat cards ────────────────────────────────────────────────────────────────
$_tsDiff   = dbSecondsDiff('created_at', 'resolved_at');
$_today    = dbDate('resolved_at') . " = CURRENT_DATE";
$_pending  = "status NOT IN ('closed','resolved','completed')";

$stats = dbFetch("
    SELECT
        SUM(CASE WHEN status NOT IN ('closed','resolved') THEN 1 ELSE 0 END)                            AS active_tickets,
        SUM(CASE WHEN $_today THEN 1 ELSE 0 END)                                                         AS resolved_today,
        SUM(CASE WHEN sla_breach_at < NOW() AND status NOT IN ('closed','resolved') THEN 1 ELSE 0 END)  AS sla_breaches,
        ROUND(AVG(CASE WHEN resolved_at IS NOT NULL THEN {$_tsDiff}/3600.0 ELSE NULL END), 1)            AS mttr_hours
    FROM tickets $_tWhere
", $_tParams);

$_installTerminalIn = "'" . implode("','", INSTALLATION_TERMINAL_STATUSES) . "'";
[$_iScope, $_iParams] = installationScopeSql('');
$_iAnd = $_iScope ? "AND ($_iScope)" : '';
$pendingInstalls = dbFetch("SELECT COUNT(*) AS cnt FROM installation_profiles WHERE status NOT IN ({$_installTerminalIn}) {$_iAnd}", $_iParams);

// ── 4-hour bucket chart: ticket influx vs resolved ────────────────────────────
$_iv24 = dbNowMinusInterval(24, 'HOUR');
$_bucket_c = "FLOOR(" . dbHour('created_at')  . "/4)";
$_bucket_r = "FLOOR(" . dbHour('resolved_at') . "/4)";

$influxRaw   = dbFetchAll("SELECT {$_bucket_c} AS bucket, COUNT(*) AS cnt FROM tickets WHERE created_at  >= {$_iv24} {$_tAnd} GROUP BY bucket ORDER BY bucket", $_tParams);
$resolvedRaw = dbFetchAll("SELECT {$_bucket_r} AS bucket, COUNT(*) AS cnt FROM tickets WHERE resolved_at >= {$_iv24} {$_tAnd} GROUP BY bucket ORDER BY bucket", $_tParams);

// Fill all 6 buckets (0-5 → 00-03, 04-07, 08-11, 12-15, 16-19, 20-23)
$influxArr = $resolvedArr = array_fill(0, 6, 0);
foreach ($influxRaw   as $r) if (isset($influxArr[(int)$r['bucket']]))   $influxArr[(int)$r['bucket']]   = (int)$r['cnt'];
foreach ($resolvedRaw as $r) if (isset($resolvedArr[(int)$r['bucket']])) $resolvedArr[(int)$r['bucket']] = (int)$r['cnt'];
$bucketLabels = ['00–03','04–07','08–11','12–15','16–19','20–23'];

// ── Vendor performance ────────────────────────────────────────────────────────
// Cross-company comparison — not shown to a vendor-type viewer, who has no
// business seeing another company's numbers, only their own (already
// covered by the stat cards/charts above, all correctly scoped to them).
$vendors = $isVendorViewer ? [] : dbFetchAll("
    SELECT v.name,
           COUNT(ip.id)                                                          AS total,
           SUM(CASE WHEN ip.status = 'connected' THEN 1 ELSE 0 END)             AS completed,
           SUM(CASE WHEN ip.status NOT IN ({$_installTerminalIn}) THEN 1 ELSE 0 END) AS in_progress
    FROM vendors v
    LEFT JOIN installation_profiles ip ON ip.vendor_id = v.id
    GROUP BY v.id, v.name
    ORDER BY total DESC
    LIMIT 6
");

// ── Status & priority distribution ───────────────────────────────────────────
$statusDist   = dbFetchAll("SELECT status, COUNT(*) AS cnt FROM tickets $_tWhere GROUP BY status ORDER BY cnt DESC", $_tParams);
$priorityDist = dbFetchAll("SELECT priority, COUNT(*) AS cnt FROM tickets $_tWhere GROUP BY priority ORDER BY priority", $_tParams);

// ── Recent tickets (scoped to what the user may see) ──────────────────────────
[$_recScope, $_recParams] = ticketScopeSql('');
$recent = dbFetchAll("
    SELECT id, ticket_number, description, type, status, priority, customer_name, created_at
    FROM tickets " . ($_recScope ? "WHERE $_recScope " : '') . "ORDER BY created_at DESC LIMIT 8
", $_recParams);

$pageTitle = 'Dashboard';
require __DIR__ . '/../includes/header.php';
?>

<!-- ── Stat cards ──────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <a href="/tickets" class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-label">Active Tickets</div>
          <div class="stat-value"><?= (int)($stats['active_tickets']??0) ?></div>
        </div>
        <div style="font-size:1.75rem;color:#3b82f6"><i class="bi bi-activity"></i></div>
      </div>
    </a>
  </div>
  <div class="col-sm-6 col-xl-3">
    <a href="/tickets?status=resolved" class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-label">Resolved Today</div>
          <div class="stat-value"><?= (int)($stats['resolved_today']??0) ?></div>
        </div>
        <div style="font-size:1.75rem;color:#10b981"><i class="bi bi-check-circle"></i></div>
      </div>
    </a>
  </div>
  <div class="col-sm-6 col-xl-3">
    <a href="/tickets?sla=breached" class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-label">SLA Breaches</div>
          <div class="stat-value text-danger"><?= (int)($stats['sla_breaches']??0) ?></div>
        </div>
        <div style="font-size:1.75rem;color:#ef4444"><i class="bi bi-exclamation-triangle"></i></div>
      </div>
    </a>
  </div>
  <div class="col-sm-6 col-xl-3">
    <a href="/installations" class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-label">Pending Installs</div>
          <div class="stat-value"><?= (int)($pendingInstalls['cnt']??0) ?></div>
        </div>
        <div style="font-size:1.75rem;color:#0ea5e9"><i class="bi bi-lightning-charge"></i></div>
      </div>
    </a>
  </div>
</div>

<!-- ── Charts row ─────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-3">

  <!-- Ticket Volume & Resolution chart -->
  <div class="<?= $isVendorViewer ? 'col-lg-12' : 'col-lg-8' ?>">
    <div class="card-section h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-graph-up me-1 text-primary"></i>Ticket Volume &amp; Resolution (24h)</span>
        <small class="text-muted">MTTR <?= $stats['mttr_hours'] ?? '—' ?> hrs &nbsp;|&nbsp; Ticket influx vs resolved — last 24 hours in 4-hour windows</small>
      </div>
      <div class="p-3"><canvas id="ticketChart" height="90"></canvas></div>
    </div>
  </div>

  <?php if (!$isVendorViewer): ?>
  <!-- Vendor Performance -->
  <div class="col-lg-4">
    <div class="card-section h-100">
      <div class="card-header"><i class="bi bi-bar-chart-steps me-1 text-primary"></i>Vendor Performance</div>
      <div class="p-3">
        <?php if (!$vendors || array_sum(array_column($vendors,'total')) === 0): ?>
          <div class="text-muted small py-4 text-center">
            <i class="bi bi-inbox fs-3 d-block mb-2 text-secondary"></i>
            No installation data yet.<br>Vendors appear here once assigned to installations.
          </div>
        <?php else: ?>
          <?php foreach ($vendors as $v):
            $total     = max(1, (int)$v['total']);
            $completed = (int)$v['completed'];
            $pct       = round($completed / $total * 100);
            $bar       = $pct >= 75 ? 'bg-success' : ($pct >= 40 ? 'bg-warning' : 'bg-danger');
          ?>
          <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <span class="small fw-semibold text-truncate" style="max-width:160px"><?= htmlspecialchars($v['name']) ?></span>
              <span class="small text-muted"><?= $completed ?>/<?= $v['total'] ?> done &nbsp;<span class="badge <?= str_replace('bg-','text-bg-',$bar) ?>"><?= $pct ?>%</span></span>
            </div>
            <div class="progress" style="height:7px">
              <div class="progress-bar <?= $bar ?>" style="width:<?= $pct ?>%"></div>
            </div>
          </div>
          <?php endforeach; ?>
          <div class="border-top pt-2 mt-1">
            <canvas id="vendorChart" height="100"></canvas>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>

<!-- ── Status / Priority distribution ────────────────────────────────────── -->
<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="card-section">
      <div class="card-header"><i class="bi bi-pie-chart me-1 text-primary"></i>Ticket Status Breakdown</div>
      <div class="p-3"><canvas id="statusChart" height="100"></canvas></div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card-section">
      <div class="card-header"><i class="bi bi-bar-chart me-1 text-primary"></i>Priority Distribution</div>
      <div class="p-3"><canvas id="priorityChart" height="100"></canvas></div>
    </div>
  </div>
</div>

<!-- ── Recent Tickets ──────────────────────────────────────────────────────── -->
<div class="card-section mb-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-clock-history me-1 text-primary"></i>Recent Tickets</span>
    <a href="/tickets" class="btn btn-sm btn-outline-primary">View All</a>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th class="ps-3">Ticket ID</th>
          <th>Customer</th>
          <th>Type</th>
          <th>Status</th>
          <th>Priority</th>
          <th>Created</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recent as $t): ?>
        <tr onclick="location.href='/ticket/<?= $t['id'] ?>'" style="cursor:pointer">
          <td class="ps-3">
            <span class="fw-semibold text-primary"><?= htmlspecialchars($t['ticket_number']??'') ?></span><br>
            <small class="text-muted text-truncate d-block" style="max-width:180px"><?= htmlspecialchars(substr($t['description']??'',0,45)) ?></small>
          </td>
          <td><?= htmlspecialchars($t['customer_name']??'—') ?></td>
          <td><span class="badge text-bg-secondary"><?= htmlspecialchars($t['type']??'') ?></span></td>
          <td><span class="badge badge-<?= htmlspecialchars($t['status'] ?? '') ?>"><?= htmlspecialchars(str_replace('_',' ',ucfirst($t['status']??''))) ?></span></td>
          <td><span class="badge badge-<?= htmlspecialchars($t['priority'] ?? '') ?>"><?= htmlspecialchars(strtoupper($t['priority']??'')) ?></span></td>
          <td class="small text-muted"><?= date('d M H:i', strtotime($t['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$recent): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No tickets yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function(){
  const influx   = <?= json_encode(array_values($influxArr)) ?>;
  const resolved = <?= json_encode(array_values($resolvedArr)) ?>;
  const labels   = <?= json_encode($bucketLabels) ?>;

  // ── Ticket Volume chart ─────────────────────────────────────────
  new Chart(document.getElementById('ticketChart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label:'Created',  data:influx,   backgroundColor:'rgba(14,165,233,.75)', borderRadius:4 },
        { label:'Resolved', data:resolved, backgroundColor:'rgba(16,185,129,.75)', borderRadius:4 },
      ]
    },
    options:{
      responsive:true,
      plugins:{ legend:{ position:'bottom', labels:{ boxWidth:12, padding:16 } } },
      scales:{ y:{ beginAtZero:true, ticks:{ stepSize:1 } } }
    }
  });

  // ── Vendor performance bar ──────────────────────────────────────
  const vEl = document.getElementById('vendorChart');
  if (vEl) {
    const vNames     = <?= json_encode(array_column($vendors,'name')) ?>;
    const vCompleted = <?= json_encode(array_map('intval', array_column($vendors,'completed'))) ?>;
    const vTotal     = <?= json_encode(array_map('intval', array_column($vendors,'total'))) ?>;
    new Chart(vEl, {
      type:'bar',
      data:{
        labels: vNames,
        datasets:[
          { label:'Completed', data:vCompleted, backgroundColor:'rgba(16,185,129,.8)', borderRadius:3 },
          { label:'Assigned',  data:vTotal.map((t,i)=>t-vCompleted[i]), backgroundColor:'rgba(14,165,233,.4)', borderRadius:3 },
        ]
      },
      options:{
        responsive:true,
        plugins:{ legend:{ position:'bottom', labels:{ boxWidth:10, padding:12, font:{size:11} } } },
        scales:{ x:{ stacked:true, ticks:{font:{size:11}} }, y:{ stacked:true, beginAtZero:true, ticks:{stepSize:1} } }
      }
    });
  }

  // ── Status donut ────────────────────────────────────────────────
  const statusData  = <?= json_encode(array_map('intval', array_column($statusDist,'cnt'))) ?>;
  const statusLabels = <?= json_encode(array_column($statusDist,'status')) ?>.map(s => s.replace('_',' ').replace(/\b\w/g,c=>c.toUpperCase()));
  const statusColors = ['#3b82f6','#f59e0b','#10b981','#ef4444','#8b5cf6','#6b7280'];
  new Chart(document.getElementById('statusChart'), {
    type:'doughnut',
    data:{ labels:statusLabels, datasets:[{ data:statusData, backgroundColor:statusColors, borderWidth:2 }] },
    options:{ responsive:true, plugins:{ legend:{ position:'right', labels:{ boxWidth:12, padding:12, font:{size:12} } } } }
  });

  // ── Priority bar ────────────────────────────────────────────────
  const priData   = <?= json_encode(array_map('intval', array_column($priorityDist,'cnt'))) ?>;
  const priLabels = <?= json_encode(array_column($priorityDist,'priority')) ?>;
  const priColors = priLabels.map(p => p==='p1'?'#ef4444':p==='p2'?'#f59e0b':'#10b981');
  new Chart(document.getElementById('priorityChart'), {
    type:'bar',
    data:{ labels:priLabels.map(p=>p.toUpperCase()), datasets:[{ label:'Tickets', data:priData, backgroundColor:priColors, borderRadius:4 }] },
    options:{ responsive:true, plugins:{ legend:{display:false} }, scales:{ y:{ beginAtZero:true, ticks:{stepSize:1} } } }
  });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
