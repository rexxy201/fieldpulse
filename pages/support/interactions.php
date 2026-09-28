<?php
/**
 * Customer Support — interaction log (/support/interactions).
 * Agents see their own contacts; support.view_all (supervisors) see everyone's.
 * Filterable, with headline figures for the filtered set and CSV export.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/date-range.php';
requireAuth();
requirePermission('support.view');

$me      = currentUser();
$viewAll = hasPermission('support.view_all');
$h       = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);

// Default view: this month so far (the filter then shows what's applied).
['range' => $range, 'from' => $from, 'to' => $to] = resolveDateRange(isset($_GET['dateRange']) || isset($_GET['dateFrom']) || isset($_GET['dateTo']) ? $_GET : ['dateRange' => 'month_to_date']);
$fChannel = $_GET['channel'] ?? '';
$fOutcome = $_GET['outcome'] ?? '';
$fWrap    = $_GET['wrap'] ?? '';
$fAgent   = $viewAll ? ($_GET['agent'] ?? '') : $me['id'];
$fSearch  = trim($_GET['q'] ?? '');

$conds = []; $params = [];
if ($from !== '')                 { $conds[] = 'i.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '')                   { $conds[] = 'i.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
if (isset(CS_CHANNELS[$fChannel])) { $conds[] = 'i.channel = ?';     $params[] = $fChannel; }
if (isset(CS_OUTCOMES[$fOutcome])) { $conds[] = 'i.outcome = ?';     $params[] = $fOutcome; }
if ($fWrap !== '')                { $conds[] = 'i.wrap_code_id = ?'; $params[] = $fWrap; }
if ($fAgent !== '')               { $conds[] = 'i.agent_id = ?';     $params[] = $fAgent; }
if ($fSearch !== '') {
    $conds[] = '(i.contact_name LIKE ? OR i.contact_phone LIKE ? OR i.summary LIKE ? OR c.account_number LIKE ?)';
    array_push($params, "%$fSearch%", "%$fSearch%", "%$fSearch%", "%$fSearch%");
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
$from_sql = "FROM cs_interactions i
             LEFT JOIN cs_wrap_codes w ON w.id = i.wrap_code_id
             LEFT JOIN customers c ON c.id = i.customer_id
             LEFT JOIN tickets t ON t.id = i.ticket_id";

if (($_GET['export'] ?? '') === 'csv') {
    $rows = dbFetchAll("SELECT i.*, w.category, w.name AS wrap_name, c.account_number, t.ticket_number $from_sql $where ORDER BY i.created_at DESC", $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="interactions-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Agent', 'Channel', 'Direction', 'Contact', 'Phone', 'Account', 'Category', 'Reason', 'Outcome', 'Ticket', 'Handle time (s)', 'Summary']);
    foreach ($rows as $r) {
        // Leading = + - @ would be run as a formula by Excel; neutralise it.
        $safe = fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : (string)$v;
        fputcsv($out, [$r['created_at'], $safe($r['agent_name']), CS_CHANNELS[$r['channel']] ?? $r['channel'], $r['direction'],
            $safe($r['contact_name']), $safe($r['contact_phone']), $safe($r['account_number'] ?? ''), $r['category'] ?? '', $r['wrap_name'] ?? '',
            CS_OUTCOMES[$r['outcome']] ?? $r['outcome'], $r['ticket_number'] ?? '', $r['duration_sec'] ?? '', $safe($r['summary'])]);
    }
    fclose($out);
    exit;
}

$stats = dbFetch("SELECT COUNT(*) AS total,
        SUM(CASE WHEN i.outcome = 'resolved' THEN 1 ELSE 0 END) AS resolved,
        SUM(CASE WHEN i.outcome = 'ticket_created' THEN 1 ELSE 0 END) AS tickets,
        SUM(CASE WHEN i.outcome = 'escalated' THEN 1 ELSE 0 END) AS escalated,
        AVG(i.duration_sec) AS avg_handle
    $from_sql $where", $params);
$byReason = dbFetchAll("SELECT w.category, w.name, COUNT(*) AS n $from_sql $where GROUP BY w.category, w.name ORDER BY n DESC LIMIT 8", $params);

$perPage = 50;
$page    = max(1, (int)($_GET['p'] ?? 1));
$total   = (int)($stats['total'] ?? 0);
$offset  = ($page - 1) * $perPage;   // ints, interpolated: execute() binds every value as a string, and MariaDB rejects LIMIT '50'
$rows    = dbFetchAll("SELECT i.*, w.category, w.name AS wrap_name, c.account_number, t.ticket_number $from_sql $where ORDER BY i.created_at DESC LIMIT $perPage OFFSET $offset", $params);

$agents    = $viewAll ? dbFetchAll("SELECT DISTINCT agent_id, agent_name FROM cs_interactions ORDER BY agent_name") : [];
$wrapCodes = csWrapCodesGrouped(false);
$fmtDur    = fn($s) => $s === null || $s === '' ? '—' : sprintf('%d:%02d', intdiv((int)$s, 60), (int)$s % 60);
$qs        = fn(array $extra = []) => '?' . http_build_query(array_filter(array_merge($_GET, $extra), fn($v) => $v !== null && $v !== ''));

$pageTitle = 'Interactions';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
  <div>
    <h2 class="fw-bold mb-0">Interactions</h2>
    <div class="text-muted small"><?= $viewAll ? 'Every logged customer contact.' : 'Customer contacts you have logged.' ?></div>
  </div>
  <a href="<?= $h($qs(['export' => 'csv', 'p' => null])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download me-1"></i>Export CSV</a>
</div>

<div class="card-section mb-3">
  <div class="p-3">
    <?php renderDateRangeFilter('/support/interactions', $range, $from, $to, $_GET); ?>
    <form method="GET" class="d-flex flex-wrap gap-2 align-items-end mt-2">
      <?php foreach (['dateRange' => $range, 'dateFrom' => $from, 'dateTo' => $to] as $k => $v): ?>
      <input type="hidden" name="<?= $k ?>" value="<?= $h($v) ?>">
      <?php endforeach; ?>
      <input type="text" name="q" value="<?= $h($fSearch) ?>" class="form-control form-control-sm" style="width:180px" placeholder="Name, phone, account, text">
      <select name="channel" class="form-select form-select-sm" style="width:auto">
        <option value="">All channels</option>
        <?php foreach (CS_CHANNELS as $k => $l): ?><option value="<?= $k ?>" <?= $fChannel === $k ? 'selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?>
      </select>
      <select name="outcome" class="form-select form-select-sm" style="width:auto">
        <option value="">All outcomes</option>
        <?php foreach (CS_OUTCOMES as $k => $l): ?><option value="<?= $k ?>" <?= $fOutcome === $k ? 'selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?>
      </select>
      <select name="wrap" class="form-select form-select-sm" style="width:auto">
        <option value="">All reasons</option>
        <?php foreach ($wrapCodes as $cat => $codes): ?><optgroup label="<?= $h($cat) ?>">
          <?php foreach ($codes as $c): ?><option value="<?= $h($c['id']) ?>" <?= $fWrap === $c['id'] ? 'selected' : '' ?>><?= $h($c['name']) ?></option><?php endforeach; ?>
        </optgroup><?php endforeach; ?>
      </select>
      <?php if ($viewAll): ?>
      <select name="agent" class="form-select form-select-sm" style="width:auto">
        <option value="">All agents</option>
        <?php foreach ($agents as $a): ?><option value="<?= $h($a['agent_id']) ?>" <?= $fAgent === $a['agent_id'] ? 'selected' : '' ?>><?= $h($a['agent_name']) ?></option><?php endforeach; ?>
      </select>
      <?php endif; ?>
      <button class="btn btn-sm btn-primary">Filter</button>
    </form>
  </div>
</div>

<div class="row g-3 mb-3">
  <?php
  $pct = fn($n) => $total ? round(100 * (int)$n / $total) . '%' : '—';
  $tiles = [
      ['Contacts', number_format($total), 'bi-telephone'],
      ['Resolved on contact', $pct($stats['resolved'] ?? 0), 'bi-check2-circle'],
      ['Became tickets', $pct($stats['tickets'] ?? 0), 'bi-ticket-perforated'],
      ['Escalated', $pct($stats['escalated'] ?? 0), 'bi-arrow-up-right-circle'],
      ['Avg handle time', $fmtDur($stats['avg_handle'] !== null ? (int)round((float)$stats['avg_handle']) : null), 'bi-stopwatch'],
  ];
  foreach ($tiles as [$label, $value, $icon]): ?>
  <div class="col-6 col-md">
    <div class="card-section p-3 h-100">
      <div class="text-muted small"><i class="bi <?= $icon ?> me-1"></i><?= $h($label) ?></div>
      <div class="fs-4 fw-bold"><?= $h($value) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($byReason): ?>
<div class="card-section mb-3">
  <div class="card-header"><i class="bi bi-bar-chart me-1 text-primary"></i>Top contact reasons</div>
  <div class="p-3 small">
    <?php foreach ($byReason as $r): $w = $total ? round(100 * $r['n'] / $total) : 0; ?>
    <div class="d-flex align-items-center gap-2 mb-1">
      <div style="width:240px" class="text-truncate"><?= $h(($r['category'] ?? '—') . ' › ' . ($r['name'] ?? '—')) ?></div>
      <div class="flex-grow-1 bg-light rounded" style="height:10px"><div class="bg-primary rounded" style="height:10px;width:<?= $w ?>%"></div></div>
      <div style="width:70px" class="text-end"><?= (int)$r['n'] ?> (<?= $w ?>%)</div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card-section">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0 small">
      <thead class="table-light"><tr><th>When</th><th>Contact</th><th>Channel</th><th>Reason</th><th>Outcome</th><th>Ticket</th><th>Handle</th><?php if ($viewAll): ?><th>Agent</th><?php endif; ?><th>Summary</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No interactions match these filters.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-nowrap"><?= $h(date('d M H:i', strtotime($r['created_at']))) ?></td>
          <td>
            <?php if ($r['customer_id']): ?><a href="/support?customer=<?= $h($r['customer_id']) ?>"><?= $h($r['contact_name']) ?></a><?php else: ?><?= $h($r['contact_name'] ?: '—') ?><?php endif; ?>
            <div class="text-muted"><?= $h($r['contact_phone']) ?><?= $r['account_number'] ? ' · ' . $h($r['account_number']) : '' ?></div>
          </td>
          <td class="text-nowrap"><?= $h(CS_CHANNELS[$r['channel']] ?? $r['channel']) ?><div class="text-muted"><?= $h($r['direction']) ?></div></td>
          <td><?= $h($r['wrap_name'] ?? '—') ?><div class="text-muted"><?= $h($r['category'] ?? '') ?></div></td>
          <td><?= $h(CS_OUTCOMES[$r['outcome']] ?? $r['outcome']) ?></td>
          <td><?php if ($r['ticket_number']): ?><a href="/ticket/<?= $h($r['ticket_id']) ?>"><?= $h($r['ticket_number']) ?></a><?php endif; ?></td>
          <td><?= $h($fmtDur($r['duration_sec'])) ?></td>
          <?php if ($viewAll): ?><td><?= $h($r['agent_name']) ?></td><?php endif; ?>
          <td style="max-width:320px"><?= $h(mb_strimwidth((string)$r['summary'], 0, 140, '…')) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php $pages = max(1, (int)ceil($total / $perPage)); if ($pages > 1): ?>
  <div class="p-2 d-flex justify-content-between small">
    <span class="text-muted">Page <?= $page ?> of <?= $pages ?></span>
    <span>
      <?php if ($page > 1): ?><a href="<?= $h($qs(['p' => $page - 1])) ?>">‹ Prev</a><?php endif; ?>
      <?php if ($page < $pages): ?><a class="ms-3" href="<?= $h($qs(['p' => $page + 1])) ?>">Next ›</a><?php endif; ?>
    </span>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
