<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (!in_array(currentUser()['role'],['admin','project_admin','supervisor-fiber','supervisor-noc'])) { header('Location: /dashboard'); exit; }

$byStatus   = dbFetchAll("SELECT status, COUNT(*) AS count FROM tickets GROUP BY status ORDER BY count DESC");
$byPriority = dbFetchAll("SELECT priority, COUNT(*) AS count FROM tickets GROUP BY priority ORDER BY priority");
$_dateDay   = DB_TYPE === 'pgsql' ? "created_at::date" : "DATE(created_at)";
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

$pageTitle = 'Analytics';
require __DIR__ . '/../includes/header.php';
?>

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

<?php require __DIR__ . '/../includes/footer.php'; ?>
