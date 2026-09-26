<?php
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('analytics.view');

$byStatus   = dbFetchAll("SELECT status, COUNT(*) AS count FROM tickets GROUP BY status ORDER BY count DESC");
$byPriority = dbFetchAll("SELECT priority, COUNT(*) AS count FROM tickets GROUP BY priority ORDER BY priority");
$_dateDay   = dbDate('created_at');
$_dateFmt   = dbDateFormat('created_at', '%b %d');
$_iv30      = dbNowMinusInterval(30, 'DAY');
$_tsDiff    = dbSecondsDiff('created_at', 'resolved_at');
$trend      = dbFetchAll("SELECT {$_dateFmt} AS day, COUNT(*) AS count FROM tickets WHERE created_at >= {$_iv30} GROUP BY day, {$_dateDay} ORDER BY {$_dateDay}");
$mttr       = dbFetch("SELECT ROUND(AVG({$_tsDiff}/3600.0), 1) AS avg_hours FROM tickets WHERE resolved_at IS NOT NULL");
$sla        = dbFetch("SELECT COUNT(*) AS total, SUM(CASE WHEN sla_breach_at IS NOT NULL AND (resolved_at < sla_breach_at OR (status NOT IN ('resolved','closed') AND sla_breach_at > NOW())) THEN 1 ELSE 0 END) AS ok FROM tickets WHERE sla_breach_at IS NOT NULL");
$leaderboard= dbFetchAll("SELECT u.name, COUNT(t.id) AS resolved FROM users u LEFT JOIN tickets t ON t.assigned_to=u.id AND t.status IN ('resolved','closed') WHERE u.role='engineer' GROUP BY u.name ORDER BY resolved DESC LIMIT 10");

$trendJson = json_encode(array_values($trend));
$statusJson = json_encode(array_values($byStatus));
$prioJson   = json_encode(array_values($byPriority));

$slaRate = ($sla && $sla['total'] > 0) ? round($sla['ok']/$sla['total']*100,1) : 0;

// ── Technician performance data ────────────────────────────────────────────
$_tsDiff2   = dbSecondsDiff('t.created_at', 't.resolved_at');
$techStats  = dbFetchAll(
    "SELECT
       u.id,
       u.name,
       u.role,
       COUNT(DISTINCT t.id)                                                          AS assigned_total,
       COUNT(DISTINCT CASE WHEN t.status IN ('resolved','closed') THEN t.id END)    AS resolved_total,
       COUNT(DISTINCT CASE WHEN t.status NOT IN ('resolved','closed') AND t.created_at >= {$_iv30} THEN t.id END) AS open_30d,
       COUNT(DISTINCT CASE WHEN t.status IN ('resolved','closed') AND t.created_at >= {$_iv30} THEN t.id END)     AS resolved_30d,
       ROUND(AVG(CASE WHEN t.resolved_at IS NOT NULL THEN {$_tsDiff2}/3600.0 END), 1) AS avg_hrs,
       COUNT(DISTINCT CASE WHEN t.sla_breach_at IS NOT NULL AND
             (t.resolved_at < t.sla_breach_at OR (t.status NOT IN ('resolved','closed') AND t.sla_breach_at > NOW()))
             THEN t.id END)                                                          AS sla_ok,
       COUNT(DISTINCT CASE WHEN t.sla_breach_at IS NOT NULL THEN t.id END)           AS sla_total
     FROM users u
     LEFT JOIN tickets t ON t.assigned_to = u.id
     WHERE u.role IN ('engineer','noc_engineer','vendor')
     GROUP BY u.id, u.name, u.role
     ORDER BY resolved_total DESC"
);

// Check-in metrics per user (join separately for clarity)
$_iv30ci = dbNowMinusInterval(30, 'DAY');
$ciStats = dbFetchAll(
    "SELECT
       ci.user_id,
       COUNT(*)                                                                             AS checkin_count,
       ROUND(AVG(CASE WHEN ci.checked_out_at IS NOT NULL
                 THEN TIMESTAMPDIFF(MINUTE, ci.checked_in_at, ci.checked_out_at) END), 0)  AS avg_onsite_min,
       COUNT(DISTINCT ci.ticket_id)                                                        AS unique_tickets_visited
     FROM ticket_checkins ci
     WHERE ci.checked_in_at >= {$_iv30ci}
     GROUP BY ci.user_id"
);
$ciMap = [];
foreach ($ciStats as $c) { $ciMap[$c['user_id']] = $c; }

// CSV export — summary snapshot
$_tab = $_GET['tab'] ?? 'overview';
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    if ($_tab === 'technicians') {
        header('Content-Disposition: attachment; filename="technician-performance-' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Technician','Role','Assigned (total)','Resolved (total)','Resolved (30d)','Open (30d)',
                       'Avg Resolution Hrs','SLA Compliance %','Check-ins (30d)','Unique Tickets Visited (30d)','Avg On-site Min (30d)']);
        foreach ($techStats as $r) {
            $ci = $ciMap[$r['id']] ?? [];
            $slaR = ($r['sla_total'] > 0) ? round($r['sla_ok']/$r['sla_total']*100,1) : '—';
            fputcsv($out, [
                $r['name'], $r['role'], $r['assigned_total'], $r['resolved_total'], $r['resolved_30d'], $r['open_30d'],
                $r['avg_hrs'] ?? '—', $slaR,
                $ci['checkin_count'] ?? 0, $ci['unique_tickets_visited'] ?? 0, $ci['avg_onsite_min'] ?? '—',
            ]);
        }
        fclose($out);
    } else {
        header('Content-Disposition: attachment; filename="analytics-' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Section', 'Label', 'Value']);
        fputcsv($out, ['Summary', 'MTTR (avg hrs)', $mttr['avg_hours'] ?? '']);
        fputcsv($out, ['Summary', 'SLA Compliance %', $slaRate]);
        fputcsv($out, ['Summary', '30-Day Ticket Count', array_sum(array_column($trend, 'count'))]);
        fputcsv($out, []);
        fputcsv($out, ['30-Day Trend', 'Day', 'Count']);
        foreach ($trend as $r) { fputcsv($out, ['', $r['day'], $r['count']]); }
        fputcsv($out, []);
        fputcsv($out, ['By Status', 'Status', 'Count']);
        foreach ($byStatus as $r) { fputcsv($out, ['', $r['status'], $r['count']]); }
        fputcsv($out, []);
        fputcsv($out, ['By Priority', 'Priority', 'Count']);
        foreach ($byPriority as $r) { fputcsv($out, ['', $r['priority'], $r['count']]); }
        fputcsv($out, []);
        fputcsv($out, ['Leaderboard', 'Engineer', 'Resolved']);
        foreach ($leaderboard as $r) { fputcsv($out, ['', $r['name'], $r['resolved']]); }
        fclose($out);
    }
    exit;
}

$pageTitle = 'Analytics';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
  <div>
    <h2 class="fw-bold mb-0">Analytics</h2>
    <div class="text-muted small">30-day ticket trends, SLA compliance, and team performance.</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="?tab=<?= htmlspecialchars($_tab) ?>&export=csv" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download me-1"></i>Export CSV
    </a>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
      <i class="bi bi-printer me-1"></i>Print / PDF
    </button>
  </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" id="analyticsTab" role="tablist">
  <li class="nav-item" role="presentation">
    <a class="nav-link <?= $_tab !== 'technicians' ? 'active' : '' ?>" href="?tab=overview" role="tab">
      <i class="bi bi-graph-up me-1"></i>Overview
    </a>
  </li>
  <li class="nav-item" role="presentation">
    <a class="nav-link <?= $_tab === 'technicians' ? 'active' : '' ?>" href="?tab=technicians" role="tab">
      <i class="bi bi-person-gear me-1"></i>Technician Performance
    </a>
  </li>
</ul>

<style>
@media print {
  #sidebar, .topbar, .btn, .nav-tabs { display: none !important; }
  #main { margin: 0 !important; padding: 0 !important; }
  .page-content { padding: 0 !important; }
  canvas { max-width: 100% !important; }
}
.perf-bar { height:6px; border-radius:3px; background:#e2e8f0; overflow:hidden; min-width:60px; display:inline-block; vertical-align:middle; }
.perf-bar-fill { height:100%; border-radius:3px; }
</style>

<?php if ($_tab === 'technicians'): ?>

<?php
// Summary KPIs for tech tab header row
$totalTechs  = count($techStats);
$totalResolved30 = array_sum(array_column($techStats, 'resolved_30d'));
$totalCheckins30 = array_sum(array_column($ciStats, 'checkin_count'));
$maxResolved = max(array_column($techStats, 'resolved_total') ?: [1]);
?>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-label">Field Staff</div>
      <div class="stat-value"><?= $totalTechs ?></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-label">Resolved (30d, team)</div>
      <div class="stat-value"><?= $totalResolved30 ?></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-label">GPS Check-ins (30d)</div>
      <div class="stat-value"><?= $totalCheckins30 ?></div>
    </div>
  </div>
</div>

<div class="card-section">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-person-gear me-1 text-primary"></i>Technician Performance — 30-Day Window</span>
    <span class="text-muted" style="font-size:.75rem">Check-in stats cover last 30 days; ticket totals are all-time</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Technician</th>
          <th>Role</th>
          <th title="Total resolved all-time">Resolved</th>
          <th title="Resolved in last 30 days">Resolved (30d)</th>
          <th title="Currently open tickets">Open</th>
          <th title="Average hours from created to resolved">Avg Resolution</th>
          <th title="% of SLA-tracked tickets resolved on time">SLA</th>
          <th title="GPS check-ins in last 30 days">Check-ins (30d)</th>
          <th title="Average minutes on-site per check-in (30d)">Avg On-site</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($techStats as $tech):
            $ci = $ciMap[$tech['id']] ?? [];
            $slaR = ($tech['sla_total'] > 0) ? round($tech['sla_ok']/$tech['sla_total']*100,1) : null;
            $slaClass = $slaR === null ? '' : ($slaR >= 90 ? 'text-success' : ($slaR >= 70 ? 'text-warning' : 'text-danger'));
            $barPct = $maxResolved > 0 ? round($tech['resolved_total']/$maxResolved*100) : 0;
            $avgOnsite = isset($ci['avg_onsite_min']) && $ci['avg_onsite_min'] !== null
                ? (($ci['avg_onsite_min'] >= 60)
                    ? round($ci['avg_onsite_min']/60,1) . ' hr'
                    : $ci['avg_onsite_min'] . ' min')
                : '—';
        ?>
        <tr>
          <td class="fw-semibold">
            <?= htmlspecialchars($tech['name']) ?>
            <div class="perf-bar ms-2" style="width:<?= max(40,$barPct) ?>px">
              <div class="perf-bar-fill bg-primary" style="width:<?= $barPct ?>%"></div>
            </div>
          </td>
          <td><span class="badge bg-light text-dark border" style="font-size:.7rem"><?= htmlspecialchars($tech['role']) ?></span></td>
          <td><?= (int)$tech['resolved_total'] ?></td>
          <td><strong><?= (int)$tech['resolved_30d'] ?></strong></td>
          <td><?= (int)$tech['open_30d'] ?></td>
          <td><?= $tech['avg_hrs'] !== null ? $tech['avg_hrs'] . ' hrs' : '—' ?></td>
          <td class="<?= $slaClass ?> fw-semibold"><?= $slaR !== null ? $slaR . '%' : '—' ?></td>
          <td>
            <?php if (!empty($ci['checkin_count'])): ?>
            <span class="badge bg-success"><?= (int)$ci['checkin_count'] ?></span>
            <?php if (!empty($ci['unique_tickets_visited'])): ?>
            <span class="text-muted" style="font-size:.72rem"> / <?= (int)$ci['unique_tickets_visited'] ?> tickets</span>
            <?php endif; ?>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($avgOnsite) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$techStats): ?>
        <tr><td colspan="9" class="text-center text-muted py-3">No field staff found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: ?>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-label">MTTR (avg)</div>
      <div class="stat-value"><?= $mttr['avg_hours'] ?? '—' ?><small class="fs-6 fw-normal text-muted"> hrs</small></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-label">SLA Compliance</div>
      <div class="stat-value <?= $slaRate < 80 ? 'text-danger' : 'text-success' ?>"><?= $slaRate ?>%</div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-label">Total Tickets (30d)</div>
      <div class="stat-value"><?= array_sum(array_column($trend,'count')) ?></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-label">Engineers</div>
      <div class="stat-value"><?= count($leaderboard) ?></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-8">
    <div class="card-section">
      <div class="card-header"><i class="bi bi-graph-up me-1 text-primary"></i>30-Day Ticket Trend</div>
      <div class="p-3"><canvas id="trendChart" height="80"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card-section">
      <div class="card-header"><i class="bi bi-pie-chart me-1 text-primary"></i>By Status</div>
      <div class="p-3"><canvas id="statusChart" height="180"></canvas></div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card-section">
      <div class="card-header"><i class="bi bi-bar-chart me-1 text-primary"></i>By Priority</div>
      <div class="p-3"><canvas id="prioChart" height="100"></canvas></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card-section">
      <div class="card-header"><i class="bi bi-trophy me-1 text-warning"></i>Engineer Leaderboard</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>#</th><th>Engineer</th><th>Resolved</th></tr></thead>
          <tbody>
            <?php foreach ($leaderboard as $i => $e): ?>
            <tr>
              <td class="text-muted small"><?= $i+1 ?></td>
              <td class="fw-semibold"><?= htmlspecialchars($e['name']) ?></td>
              <td><span class="badge bg-success"><?= (int)$e['resolved'] ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
const trend = <?= $trendJson ?>;
const statuses = <?= $statusJson ?>;
const prios = <?= $prioJson ?>;

new Chart(document.getElementById('trendChart'),{
  type:'line',
  data:{
    labels:trend.map(r=>r.day),
    datasets:[{label:'Tickets',data:trend.map(r=>+r.count),borderColor:'#0ea5e9',backgroundColor:'rgba(14,165,233,.1)',fill:true,tension:.3,pointRadius:3}]
  },
  options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
});

const statusColors = {open:'#3b82f6',in_progress:'#8b5cf6',resolved:'#22c55e',closed:'#94a3b8',pending_confirmation:'#f59e0b'};
new Chart(document.getElementById('statusChart'),{
  type:'doughnut',
  data:{
    labels:statuses.map(s=>s.status.replace(/_/g,' ')),
    datasets:[{data:statuses.map(s=>+s.count),backgroundColor:statuses.map(s=>statusColors[s.status]||'#ccc')}]
  },
  options:{responsive:true,plugins:{legend:{position:'bottom'}}}
});

const prioColors = {p1:'#dc2626',p2:'#ea580c',p3:'#3b82f6',p4:'#22c55e'};
new Chart(document.getElementById('prioChart'),{
  type:'bar',
  data:{
    labels:prios.map(p=>p.priority.toUpperCase()),
    datasets:[{data:prios.map(p=>+p.count),backgroundColor:prios.map(p=>prioColors[p.priority]||'#ccc'),borderRadius:4}]
  },
  options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
});
</script>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
