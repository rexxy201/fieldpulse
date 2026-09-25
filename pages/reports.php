<?php
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('reports.view');

if (method() === 'POST') {
    verifyCsrf();
    $b = $_POST;
    $action = $b['_action'] ?? '';
    if ($action === 'add_subscription') {
        $rType = $b['report_type'] ?? '';
        $cadence = in_array($b['cadence'] ?? '', ['daily','weekly','monthly'], true) ? $b['cadence'] : 'weekly';
        $recipients = trim($b['recipients'] ?? '');
        if (array_key_exists($rType, REPORT_SUBSCRIPTION_TYPES) && $recipients !== '') {
            $u = currentUser();
            dbRun("INSERT INTO report_subscriptions (id,report_type,cadence,recipients,created_by,created_by_name) VALUES (?,?,?,?,?,?)",
                [newUuid(), $rType, $cadence, $recipients, $u['id'] ?? null, $u['name'] ?? null]);
            auditLog('create', 'report_subscription', '');
        }
    }
    if ($action === 'delete_subscription' && !empty($b['id'])) {
        dbRun("DELETE FROM report_subscriptions WHERE id=?", [$b['id']]);
        auditLog('delete', 'report_subscription', $b['id']);
    }
    header('Location: /reports?type=' . urlencode($b['report_type'] ?? 'department') . '#subscriptions'); exit;
}

$type = $_GET['type'] ?? 'department';
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');

// ─── Shared scope + date-range filter, reused across every report query ───────
[$scopeSql, $scopeParams] = ticketScopeSql('t');
$dateConds = []; $dateParams = [];
if ($from) { $dateConds[] = "t.created_at >= ?"; $dateParams[] = $from . ' 00:00:00'; }
if ($to)   { $dateConds[] = "t.created_at <= ?"; $dateParams[] = $to   . ' 23:59:59'; }

$extraConds  = array_merge($scopeSql ? [$scopeSql] : [], $dateConds);
$extraParams = array_merge($scopeParams, $dateParams);
$andSql   = $extraConds ? ' AND ' . implode(' AND ', $extraConds) : '';   // append to an existing WHERE/ON
$whereSql = $extraConds ? ' WHERE ' . implode(' AND ', $extraConds) : ''; // stand-alone WHERE

$_diffCreated   = dbSecondsDiff('t.created_at', 't.resolved_at');
$_diffEscalated = dbSecondsDiff('COALESCE(t.escalated_at, t.created_at)', 't.resolved_at');
$hoursCreated   = "ROUND(AVG(CASE WHEN t.resolved_at IS NOT NULL THEN ({$_diffCreated})/3600.0 END), 1)";
$hoursEscalated = "ROUND(AVG(CASE WHEN t.resolved_at IS NOT NULL THEN ({$_diffEscalated})/3600.0 END), 1)";

$deptLabels = ['fiber' => 'Fiber', 'noc' => 'NOC', 'installation' => 'Installation', 'cx' => 'CX'];

$rows = [];

if ($type === 'department') {
    $rows = dbFetchAll(
        "SELECT COALESCE(NULLIF(ft.route_to,''), 'unassigned') AS dept,
                COUNT(*) AS total,
                SUM(CASE WHEN t.status IN ('resolved','closed') THEN 1 ELSE 0 END) AS resolved,
                $hoursCreated AS avg_hours
         FROM tickets t LEFT JOIN fault_types ft ON ft.id = t.fault_type_id
         $whereSql
         GROUP BY dept ORDER BY total DESC",
        $extraParams
    );
} elseif ($type === 'engineer') {
    $rows = dbFetchAll(
        "SELECT u.id, u.name, u.role,
                COUNT(t.id) AS total,
                SUM(CASE WHEN t.status IN ('resolved','closed') THEN 1 ELSE 0 END) AS resolved,
                $hoursCreated AS avg_hours
         FROM users u
         LEFT JOIN tickets t ON t.assigned_to = u.id{$andSql}
         WHERE u.role IN ('engineer','noc_engineer')
         GROUP BY u.id, u.name, u.role ORDER BY total DESC",
        $extraParams
    );
} elseif ($type === 'olt') {
    $rows = dbFetchAll(
        "SELECT t.olt, COUNT(*) AS total,
                SUM(CASE WHEN t.status IN ('resolved','closed') THEN 1 ELSE 0 END) AS resolved,
                $hoursCreated AS avg_hours
         FROM tickets t
         WHERE t.olt IS NOT NULL AND t.olt <> ''{$andSql}
         GROUP BY t.olt ORDER BY total DESC",
        $extraParams
    );
} elseif ($type === 'vendor') {
    $rows = dbFetchAll(
        "SELECT v.id, v.name,
                COUNT(t.id) AS total,
                SUM(CASE WHEN t.status IN ('resolved','closed') THEN 1 ELSE 0 END) AS resolved,
                $hoursCreated AS avg_hours
         FROM vendors v
         LEFT JOIN tickets t ON t.vendor_id = v.id{$andSql}
         GROUP BY v.id, v.name ORDER BY total DESC",
        $extraParams
    );
} elseif ($type === 'issue') {
    $rows = dbFetchAll(
        "SELECT ft.id, ft.name, ft.category,
                COUNT(t.id) AS total,
                SUM(CASE WHEN t.status IN ('resolved','closed') THEN 1 ELSE 0 END) AS resolved,
                $hoursCreated AS avg_hours
         FROM fault_types ft
         LEFT JOIN tickets t ON t.fault_type_id = ft.id{$andSql}
         GROUP BY ft.id, ft.name, ft.category ORDER BY total DESC",
        $extraParams
    );
} elseif ($type === 'recurring') {
    $rows = dbFetchAll(
        "SELECT c.id AS customer_id, c.name AS customer_name,
                ft.id AS fault_type_id, COALESCE(ft.name,'(no issue type)') AS issue_name,
                COUNT(*) AS occurrences, MAX(t.created_at) AS last_reported
         FROM tickets t
         JOIN customers c ON c.id = t.customer_id
         LEFT JOIN fault_types ft ON ft.id = t.fault_type_id
         WHERE t.customer_id IS NOT NULL{$andSql}
         GROUP BY c.id, c.name, ft.id, ft.name
         HAVING COUNT(*) > 1
         ORDER BY occurrences DESC, last_reported DESC
         LIMIT 100",
        $extraParams
    );
} elseif ($type === 'resolution') {
    $summary = dbFetch(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN t.escalated_at IS NOT NULL THEN 1 ELSE 0 END) AS escalated_count,
                $hoursEscalated AS avg_hours_since_escalation,
                $hoursCreated AS avg_hours_since_created
         FROM tickets t
         WHERE t.resolved_at IS NOT NULL{$andSql}",
        $extraParams
    );
    $rows = dbFetchAll(
        "SELECT t.id, t.ticket_number, t.customer_name,
                t.created_at, t.escalated_at, t.resolved_at,
                ROUND(({$_diffEscalated})/3600.0, 1) AS hours_to_resolve
         FROM tickets t
         WHERE t.resolved_at IS NOT NULL{$andSql}
         ORDER BY hours_to_resolve DESC
         LIMIT 200",
        $extraParams
    );
}

$reportTabs = [
    'department' => ['label' => 'By Department',        'icon' => 'bi-diagram-3'],
    'engineer'   => ['label' => 'By Engineer',           'icon' => 'bi-person-gear'],
    'olt'        => ['label' => 'By OLT / Equipment',    'icon' => 'bi-hdd-network'],
    'vendor'     => ['label' => 'By Vendor',             'icon' => 'bi-truck'],
    'issue'      => ['label' => 'By Issue Type',         'icon' => 'bi-tag'],
    'recurring'  => ['label' => 'Recurring Customers',   'icon' => 'bi-arrow-repeat'],
    'resolution' => ['label' => 'Resolution Time',       'icon' => 'bi-stopwatch'],
];
if (!isset($reportTabs[$type])) { $type = 'department'; }

// ── CSV export — output before any HTML ──────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $csvHeaders = match ($type) {
        'department' => ['Department', 'Total', 'Resolved', 'Avg Resolution (hrs)'],
        'engineer'   => ['Engineer', 'Role', 'Total', 'Resolved', 'Avg Resolution (hrs)'],
        'olt'        => ['OLT / Equipment', 'Total', 'Resolved', 'Avg Resolution (hrs)'],
        'vendor'     => ['Vendor', 'Total', 'Resolved', 'Avg Resolution (hrs)'],
        'issue'      => ['Issue Type', 'Category', 'Total', 'Resolved', 'Avg Resolution (hrs)'],
        'recurring'  => ['Customer', 'Issue', 'Occurrences', 'Last Reported'],
        'resolution' => ['Ticket', 'Customer', 'Escalated?', 'Escalated/Created', 'Resolved', 'Hours to Resolve'],
        default      => ['Data'],
    };
    $csvRows = array_map(function ($r) use ($type, $deptLabels) {
        return match ($type) {
            'department' => [$deptLabels[$r['dept']] ?? ucfirst($r['dept']), $r['total'], $r['resolved'], $r['avg_hours'] ?? ''],
            'engineer'   => [$r['name'], $r['role'], $r['total'], $r['resolved'], $r['avg_hours'] ?? ''],
            'olt'        => [$r['olt'], $r['total'], $r['resolved'], $r['avg_hours'] ?? ''],
            'vendor'     => [$r['name'], $r['total'], $r['resolved'], $r['avg_hours'] ?? ''],
            'issue'      => [$r['name'], $r['category'], $r['total'], $r['resolved'], $r['avg_hours'] ?? ''],
            'recurring'  => [$r['customer_name'], $r['issue_name'], $r['occurrences'], $r['last_reported']],
            'resolution' => [$r['ticket_number'], $r['customer_name'] ?? '', !empty($r['escalated_at']) ? 'Yes' : 'No',
                             date('d M Y H:i', strtotime($r['escalated_at'] ?? $r['created_at'])),
                             date('d M Y H:i', strtotime($r['resolved_at'])),
                             $r['hours_to_resolve']],
            default      => [],
        };
    }, $rows);

    $fname = 'report-' . $type . ($from ? '-' . $from : '') . ($to ? '-to-' . $to : '') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $csvHeaders);
    foreach ($csvRows as $cr) { fputcsv($out, $cr); }
    fclose($out);
    exit;
}

$subscriptions = dbFetchAll("SELECT * FROM report_subscriptions ORDER BY created_at DESC");

$pageTitle = 'Reports';
require __DIR__ . '/../includes/header.php';
?>
<style>
@media print {
  #sidebar, .topbar, .btn, .nav-tabs, .card-section:has(form), #subscriptions,
  #subscribeModal, .modal, script { display: none !important; }
  #main { margin: 0 !important; padding: 0 !important; }
  .page-content { padding: 0 !important; }
  .table { font-size: .8rem; }
  a[href]::after { content: none !important; }
}
</style>
<?php // Re-open PHP after header include — the style block above is HTML

function rqs(array $extra = []) {
    global $type, $from, $to;
    return '?' . http_build_query(array_filter(array_merge(['type' => $type, 'from' => $from, 'to' => $to], $extra)));
}
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h2 class="fw-bold mb-0">Reports</h2>
    <div class="text-muted small">Drill down into ticket volume, resolution time, and recurring issues.</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= htmlspecialchars(rqs(['export' => 'csv'])) ?>" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download me-1"></i>Export CSV
    </a>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
      <i class="bi bi-printer me-1"></i>Print / PDF
    </button>
    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#subscribeModal">
      <i class="bi bi-envelope-paper me-1"></i>Subscribe
    </button>
  </div>
</div>

<!-- Tabs -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php foreach ($reportTabs as $key => $t): ?>
  <a href="<?= htmlspecialchars(rqs(['type' => $key])) ?>"
     class="btn btn-sm <?= $type === $key ? 'btn-primary' : 'btn-outline-secondary' ?>">
    <i class="bi <?= $t['icon'] ?> me-1"></i><?= $t['label'] ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Date range filter -->
<div class="card-section mb-3">
  <form class="p-3 d-flex flex-wrap gap-2 align-items-end" method="GET">
    <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
    <div>
      <label class="form-label small fw-semibold mb-1">From</label>
      <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="form-control form-control-sm">
    </div>
    <div>
      <label class="form-label small fw-semibold mb-1">To</label>
      <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="form-control form-control-sm">
    </div>
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    <?php if ($from || $to): ?>
    <a href="<?= htmlspecialchars(rqs(['from' => null, 'to' => null])) ?>" class="btn btn-sm btn-outline-secondary">Clear dates</a>
    <?php endif; ?>
  </form>
</div>

<?php if (aiEnabled()): ?>
<div class="card-section mb-3">
  <div class="p-3">
    <label class="form-label small fw-semibold mb-1"><i class="bi bi-stars text-primary me-1"></i>Ask a question about this report</label>
    <div class="d-flex gap-2 flex-wrap">
      <input type="text" id="askDataInput" class="form-control form-control-sm" style="max-width:480px"
        placeholder="e.g. which department has the worst resolution time?">
      <button type="button" class="btn btn-sm btn-primary" id="askDataBtn" onclick="askAboutReport()">
        <i class="bi bi-send me-1"></i>Ask
      </button>
    </div>
    <div class="small mt-2" id="askDataAnswer"></div>
  </div>
</div>
<?php endif; ?>

<div class="card-section">
  <div class="table-responsive">

  <?php if ($type === 'department'): ?>
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th class="ps-3">Department</th><th>Total</th><th>Resolved</th><th>Avg Resolution (hrs)</th><th class="pe-3 text-end">Drill down</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">No data</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="ps-3 fw-semibold"><?= htmlspecialchars($deptLabels[$r['dept']] ?? ucfirst($r['dept'])) ?></td>
          <td><?= (int)$r['total'] ?></td>
          <td><?= (int)$r['resolved'] ?></td>
          <td><?= $r['avg_hours'] ?? '—' ?></td>
          <td class="pe-3 text-end">
            <?php if ($r['dept'] !== 'unassigned'): ?>
            <a class="btn btn-sm btn-link" href="/tickets?<?= htmlspecialchars(http_build_query(['department' => $r['dept']])) ?>">View tickets</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

  <?php elseif ($type === 'engineer'): ?>
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th class="ps-3">Engineer</th><th>Role</th><th>Total</th><th>Resolved</th><th>Avg Resolution (hrs)</th><th class="pe-3 text-end">Drill down</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No data</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="ps-3 fw-semibold"><?= htmlspecialchars($r['name']) ?></td>
          <td class="text-muted small"><?= htmlspecialchars($r['role']) ?></td>
          <td><?= (int)$r['total'] ?></td>
          <td><?= (int)$r['resolved'] ?></td>
          <td><?= $r['avg_hours'] ?? '—' ?></td>
          <td class="pe-3 text-end"><a class="btn btn-sm btn-link" href="/tickets?<?= htmlspecialchars(http_build_query(['engineer' => $r['id']])) ?>">View tickets</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

  <?php elseif ($type === 'olt'): ?>
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th class="ps-3">OLT / Network Equipment</th><th>Total</th><th>Resolved</th><th>Avg Resolution (hrs)</th><th class="pe-3 text-end">Drill down</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">No tickets have an OLT recorded yet</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="ps-3 fw-semibold"><?= htmlspecialchars($r['olt']) ?></td>
          <td><?= (int)$r['total'] ?></td>
          <td><?= (int)$r['resolved'] ?></td>
          <td><?= $r['avg_hours'] ?? '—' ?></td>
          <td class="pe-3 text-end"><a class="btn btn-sm btn-link" href="/tickets?<?= htmlspecialchars(http_build_query(['olt' => $r['olt']])) ?>">View tickets</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

  <?php elseif ($type === 'vendor'): ?>
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th class="ps-3">Vendor</th><th>Total</th><th>Resolved</th><th>Avg Resolution (hrs)</th><th class="pe-3 text-end">Drill down</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">No vendors found</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="ps-3 fw-semibold"><?= htmlspecialchars($r['name']) ?></td>
          <td><?= (int)$r['total'] ?></td>
          <td><?= (int)$r['resolved'] ?></td>
          <td><?= $r['avg_hours'] ?? '—' ?></td>
          <td class="pe-3 text-end"><a class="btn btn-sm btn-link" href="/tickets?<?= htmlspecialchars(http_build_query(['vendor' => $r['id']])) ?>">View tickets</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

  <?php elseif ($type === 'issue'): ?>
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th class="ps-3">Issue Type</th><th>Category</th><th>Total</th><th>Resolved</th><th>Avg Resolution (hrs)</th><th class="pe-3 text-end">Drill down</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No fault types found</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="ps-3 fw-semibold"><?= htmlspecialchars($r['name']) ?></td>
          <td class="text-muted small"><?= htmlspecialchars($r['category']) ?></td>
          <td><?= (int)$r['total'] ?></td>
          <td><?= (int)$r['resolved'] ?></td>
          <td><?= $r['avg_hours'] ?? '—' ?></td>
          <td class="pe-3 text-end"><a class="btn btn-sm btn-link" href="/tickets?<?= htmlspecialchars(http_build_query(['faultType' => $r['id']])) ?>">View tickets</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

  <?php elseif ($type === 'recurring'): ?>
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th class="ps-3">Customer</th><th>Issue</th><th>Occurrences</th><th>Last Reported</th><th class="pe-3 text-end">Drill down</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">No customer has reported the same issue more than once</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="ps-3 fw-semibold"><?= htmlspecialchars($r['customer_name']) ?></td>
          <td><?= htmlspecialchars($r['issue_name']) ?></td>
          <td><span class="badge bg-warning text-dark"><?= (int)$r['occurrences'] ?></span></td>
          <td class="small text-muted"><?= date('d M Y', strtotime($r['last_reported'])) ?></td>
          <td class="pe-3 text-end">
            <a class="btn btn-sm btn-link" href="/tickets?<?= htmlspecialchars(http_build_query(array_filter(['customerId' => $r['customer_id'], 'faultType' => $r['fault_type_id']]))) ?>">View tickets</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

  <?php elseif ($type === 'resolution'): ?>
    <div class="row g-3 p-3">
      <div class="col-sm-6 col-lg-3">
        <div class="stat-card"><div class="stat-label">Resolved Tickets</div><div class="stat-value"><?= (int)($summary['total'] ?? 0) ?></div></div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="stat-card"><div class="stat-label">Ever Escalated</div><div class="stat-value"><?= (int)($summary['escalated_count'] ?? 0) ?></div></div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="stat-card"><div class="stat-label">Avg Hrs (Escalation → Resolved)</div><div class="stat-value"><?= $summary['avg_hours_since_escalation'] ?? '—' ?></div></div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="stat-card"><div class="stat-label">Avg Hrs (Created → Resolved)</div><div class="stat-value"><?= $summary['avg_hours_since_created'] ?? '—' ?></div></div>
      </div>
    </div>
    <div class="px-3 pb-2 small text-muted">
      "Escalation → Resolved" uses the ticket's escalation time when it was escalated; otherwise it falls back to the creation time.
    </div>
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th class="ps-3">Ticket</th><th>Customer</th><th>Escalated?</th><th>Escalated / Created</th><th>Resolved</th><th class="pe-3">Hours to Resolve</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No resolved tickets in this range</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="ps-3"><a href="/ticket/<?= $r['id'] ?>" class="text-decoration-none fw-semibold" style="color:var(--primary)"><?= htmlspecialchars($r['ticket_number']) ?></a></td>
          <td class="small"><?= htmlspecialchars($r['customer_name'] ?? '—') ?></td>
          <td><?= !empty($r['escalated_at']) ? '<span class="badge bg-danger">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
          <td class="small text-muted"><?= date('d M Y H:i', strtotime($r['escalated_at'] ?? $r['created_at'])) ?></td>
          <td class="small text-muted"><?= date('d M Y H:i', strtotime($r['resolved_at'])) ?></td>
          <td class="pe-3 fw-semibold"><?= $r['hours_to_resolve'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  </div>
</div>

<?php if (aiEnabled()): ?>
<script>
// Exactly what's already rendered on screen for this user in this request —
// the AI endpoint never re-queries the database, it only reasons over this.
const REPORT_SNAPSHOT = <?= json_encode(['type' => $type, 'from' => $from, 'to' => $to, 'rows' => $rows, 'summary' => $summary ?? null]) ?>;

function askAboutReport() {
  const input = document.getElementById('askDataInput');
  const btn = document.getElementById('askDataBtn');
  const answerBox = document.getElementById('askDataAnswer');
  const question = input.value.trim();
  if (!question) return;
  btn.disabled = true;
  answerBox.innerHTML = '<span class="text-muted">Thinking…</span>';

  fetch('/api/ai-ask-report', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' },
    body: JSON.stringify({ question, snapshot: REPORT_SNAPSHOT })
  })
    .then(r => r.json().then(data => ({ ok: r.ok, data })))
    .then(({ ok, data }) => {
      if (!ok || data.error) {
        answerBox.innerHTML = '<span class="text-danger">' + (data.error || 'Could not answer that.') + '</span>';
        return;
      }
      answerBox.innerHTML = '<i class="bi bi-stars text-primary me-1"></i>' + data.answer.replace(/\n/g, '<br>');
    })
    .catch(() => { answerBox.innerHTML = '<span class="text-danger">Request failed — try again.</span>'; })
    .finally(() => { btn.disabled = false; });
}
document.getElementById('askDataInput')?.addEventListener('keydown', function (e) {
  if (e.key === 'Enter') { e.preventDefault(); askAboutReport(); }
});
</script>
<?php endif; ?>

<!-- ── Report Subscriptions ─────────────────────────────────────────────────── -->
<div class="card-section mt-3" id="subscriptions">
  <div class="card-header"><i class="bi bi-envelope-paper me-1 text-primary"></i>Report Subscriptions</div>
  <div class="p-3">
    <?php if (!$subscriptions): ?>
    <p class="text-muted small mb-0">No subscriptions yet — use "Subscribe to this report" above to get any report emailed on a schedule.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead class="table-light"><tr><th>Report</th><th>Cadence</th><th>Recipients</th><th>Last Sent</th><th>By</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($subscriptions as $s): ?>
          <tr>
            <td class="small fw-semibold"><?= htmlspecialchars(REPORT_SUBSCRIPTION_TYPES[$s['report_type']] ?? $s['report_type']) ?></td>
            <td class="small text-capitalize"><?= htmlspecialchars($s['cadence']) ?></td>
            <td class="small text-muted"><?= htmlspecialchars($s['recipients']) ?></td>
            <td class="small text-muted"><?= $s['last_sent_at'] ? date('d M Y H:i', strtotime($s['last_sent_at'])) : 'Never' ?></td>
            <td class="small text-muted"><?= htmlspecialchars($s['created_by_name'] ?? '—') ?></td>
            <td class="text-end">
              <form method="POST" onsubmit="return confirm('Remove this subscription?')">
                <input type="hidden" name="_action" value="delete_subscription">
                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                <?= csrfField() ?>
                <button type="submit" class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Subscribe Modal -->
<div class="modal fade" id="subscribeModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <input type="hidden" name="_action" value="add_subscription">
      <?= csrfField() ?>
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-envelope-paper me-1 text-primary"></i>Subscribe to a Report</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">Report</label>
          <select name="report_type" class="form-select form-select-sm">
            <?php foreach (REPORT_SUBSCRIPTION_TYPES as $rtKey => $rtLabel): ?>
            <option value="<?= $rtKey ?>" <?= $type===$rtKey?'selected':'' ?>><?= htmlspecialchars($rtLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Cadence</label>
          <select name="cadence" class="form-select form-select-sm">
            <option value="daily">Daily</option>
            <option value="weekly" selected>Weekly</option>
            <option value="monthly">Monthly</option>
          </select>
          <div class="form-text">Each send covers the period since the last one (1 / 7 / 30 days).</div>
        </div>
        <div class="mb-0">
          <label class="form-label fw-semibold">Recipients</label>
          <textarea name="recipients" class="form-control form-control-sm" rows="2" placeholder="comma-separated emails" required></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Subscribe</button>
      </div>
    </form>
  </div></div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
