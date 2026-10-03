<?php
/**
 * Customer Support — call log (/support/calls). Agents see their own calls;
 * support.view_all sees every call, including missed calls and voicemails.
 * Recordings play through the permission-checked /api/support-recording.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/date-range.php';
requireAuth();
requirePermission('support.view');

$me      = currentUser();
$viewAll = hasPermission('support.view_all');
$h       = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);

['range' => $range, 'from' => $from, 'to' => $to] = resolveDateRange(isset($_GET['dateRange']) || isset($_GET['dateFrom']) || isset($_GET['dateTo']) ? $_GET : ['dateRange' => 'month_to_date']);
$fStatus = isset(CS_CALL_STATUSES[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$fDir    = in_array($_GET['direction'] ?? '', ['inbound', 'outbound'], true) ? $_GET['direction'] : '';

$conds = []; $params = [];
if ($from !== '') { $conds[] = 'c.started_at >= ?'; $params[] = "$from 00:00:00"; }
if ($to !== '')   { $conds[] = 'c.started_at <= ?'; $params[] = "$to 23:59:59"; }
if ($fStatus)     { $conds[] = 'c.status = ?';      $params[] = $fStatus; }
if ($fDir)        { $conds[] = 'c.direction = ?';   $params[] = $fDir; }
if (!$viewAll)    { $conds[] = 'c.agent_id = ?';    $params[] = $me['id']; }
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$stats = dbFetch("SELECT COUNT(*) AS total,
    SUM(CASE WHEN c.direction='inbound' THEN 1 ELSE 0 END) AS inbound,
    SUM(CASE WHEN c.status='missed' THEN 1 ELSE 0 END) AS missed,
    SUM(CASE WHEN c.status='voicemail' THEN 1 ELSE 0 END) AS voicemail,
    AVG(CASE WHEN c.status='completed' THEN c.duration_sec END) AS avg_talk
    FROM cs_calls c $where", $params);
$rows = dbFetchAll("SELECT c.*, cu.name AS customer_name, u.name AS agent_name,
        (SELECT COUNT(*) FROM cs_interactions i WHERE i.call_id = c.id) AS logged
    FROM cs_calls c LEFT JOIN customers cu ON cu.id = c.customer_id LEFT JOIN users u ON u.id = c.agent_id
    $where ORDER BY c.started_at DESC LIMIT 200", $params);
$badge = ['completed' => 'success', 'missed' => 'danger', 'voicemail' => 'warning', 'ringing' => 'secondary', 'in_progress' => 'info', 'queued' => 'secondary', 'callback' => 'warning'];
$mmss  = fn($s) => $s === null ? '—' : sprintf('%d:%02d', intdiv((int)$s, 60), (int)$s % 60);

$pageTitle = 'Calls';
require __DIR__ . '/../../includes/header.php';
?>
<div class="mb-3">
  <h2 class="fw-bold mb-0">Calls</h2>
  <div class="text-muted small"><?= $viewAll ? 'Every call through the support line.' : 'Calls you handled.' ?><?= csVoiceEnabled() ? '' : ' Voice is not configured yet (Support Settings → Telephony).' ?></div>
</div>

<div class="card-section mb-3"><div class="p-3">
  <?php renderDateRangeFilter('/support/calls', $range, $from, $to, $_GET); ?>
  <form method="GET" class="d-flex flex-wrap gap-2 mt-2">
    <?php foreach (['dateRange' => $range, 'dateFrom' => $from, 'dateTo' => $to] as $k => $v): ?><input type="hidden" name="<?= $k ?>" value="<?= $h($v) ?>"><?php endforeach; ?>
    <select name="direction" class="form-select form-select-sm" style="width:auto">
      <option value="">In and out</option><option value="inbound" <?= $fDir === 'inbound' ? 'selected' : '' ?>>Inbound</option><option value="outbound" <?= $fDir === 'outbound' ? 'selected' : '' ?>>Outbound</option>
    </select>
    <select name="status" class="form-select form-select-sm" style="width:auto">
      <option value="">All statuses</option>
      <?php foreach (CS_CALL_STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-primary">Filter</button>
  </form>
</div></div>

<div class="row g-3 mb-3">
  <?php foreach ([['Calls', (int)$stats['total']], ['Inbound', (int)$stats['inbound']], ['Missed', (int)$stats['missed']], ['Voicemails', (int)$stats['voicemail']],
                  ['Avg talk time', $mmss($stats['avg_talk'] !== null ? (int)round((float)$stats['avg_talk']) : null)]] as [$l, $v]): ?>
  <div class="col-6 col-md"><div class="card-section p-3 h-100"><div class="text-muted small"><?= $h($l) ?></div><div class="fs-4 fw-bold"><?= $h($v) ?></div></div></div>
  <?php endforeach; ?>
</div>

<div class="card-section"><div class="table-responsive">
  <table class="table table-sm align-middle mb-0 small">
    <thead class="table-light"><tr><th>When</th><th>Direction</th><th>Number</th><th>Customer</th><th>Menu choice</th><th>Status</th><th>Talk</th><?php if ($viewAll): ?><th>Agent</th><?php endif; ?><th>Recording</th><th></th></tr></thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4">No calls in this period.</td></tr><?php endif; ?>
      <?php foreach ($rows as $c): $num = $c['direction'] === 'inbound' ? $c['from_number'] : $c['to_number']; ?>
      <tr>
        <td class="text-nowrap"><?= $h(date('d M H:i', strtotime($c['started_at']))) ?></td>
        <td><i class="bi bi-telephone-<?= $c['direction'] === 'inbound' ? 'inbound' : 'outbound' ?>"></i> <?= $h($c['direction']) ?></td>
        <td class="text-nowrap"><?= $h($num) ?></td>
        <td><?php if ($c['customer_id']): ?><a href="/support?customer=<?= $h($c['customer_id']) ?>"><?= $h($c['customer_name']) ?></a><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
        <td><?= $h($c['ivr_choice'] ?? '') ?><?php if (!empty($c['transferred_to'])): ?><div class="small text-muted">→ <?= $h($c['transferred_to']) ?></div><?php endif; ?></td>
        <td><span class="badge bg-<?= $badge[$c['status']] ?? 'secondary' ?>"><?= $h(CS_CALL_STATUSES[$c['status']] ?? $c['status']) ?></span></td>
        <td><?= $h($mmss($c['duration_sec'])) ?></td>
        <?php if ($viewAll): ?><td><?= $h($c['agent_name'] ?? '—') ?></td><?php endif; ?>
        <td>
          <?php if ($c['recording_deleted_at']): ?><span class="text-muted">Deleted (retention)</span>
          <?php elseif ($c['recording_path'] || $c['recording_url']): ?><audio controls preload="none" src="/api/support-recording?call=<?= $h($c['id']) ?>" style="height:28px;max-width:220px"></audio>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td class="text-nowrap">
          <?php if (!$c['logged'] && in_array($c['status'], ['completed', 'missed', 'voicemail', 'callback'], true)): ?>
          <a class="btn btn-sm btn-outline-primary py-0" href="/support?<?= $c['customer_id'] ? 'customer=' . urlencode($c['customer_id']) : 'new=1&amp;caller=' . urlencode($num) ?>&amp;call=<?= $h($c['id']) ?>">Log</a>
          <?php elseif ($c['logged']): ?><span class="text-success small"><i class="bi bi-check2"></i> Logged</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
