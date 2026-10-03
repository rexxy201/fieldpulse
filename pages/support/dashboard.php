<?php
/**
 * Customer Support — supervisor dashboard (/support/dashboard), support.view_all.
 * Live agent status plus today's (or the last 7 days') calls, WhatsApp
 * response times and logged contacts. Refreshes itself every minute.
 */
require_once __DIR__ . '/../../config.php';
requireAuth();
requirePermission('support.view_all');

$h      = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$period = ($_GET['period'] ?? '') === '7d' ? '7d' : 'today';
$since  = $period === '7d' ? date('Y-m-d 00:00:00', strtotime('-6 days')) : date('Y-m-d 00:00:00');
$mmss   = fn($s) => $s === null ? '—' : sprintf('%d:%02d', intdiv((int)$s, 60), (int)$s % 60);
$dur    = function ($s) { if ($s === null) return '—'; $s = (int)round($s); return $s < 60 ? "{$s}s" : ($s < 3600 ? round($s / 60) . ' min' : round($s / 3600, 1) . ' h'); };

$calls = dbFetch("SELECT
        SUM(CASE WHEN direction='inbound' THEN 1 ELSE 0 END) AS inbound,
        SUM(CASE WHEN direction='inbound' AND status='completed' THEN 1 ELSE 0 END) AS answered,
        SUM(CASE WHEN direction='inbound' AND status='missed' THEN 1 ELSE 0 END) AS missed,
        SUM(CASE WHEN direction='inbound' AND status='voicemail' THEN 1 ELSE 0 END) AS voicemail,
        SUM(CASE WHEN direction='inbound' AND status='callback' THEN 1 ELSE 0 END) AS callback,
        SUM(CASE WHEN direction='outbound' THEN 1 ELSE 0 END) AS outbound,
        AVG(CASE WHEN status='completed' THEN duration_sec END) AS avg_talk
    FROM cs_calls WHERE started_at >= ?", [$since]);
$inbound    = (int)$calls['inbound'];
$unanswered = (int)$calls['missed'] + (int)$calls['voicemail'] + (int)$calls['callback'];
$missRate   = $inbound ? round(100 * $unanswered / $inbound) : null;

$wa       = csWaResponseTimes($since);
$waTimes  = $wa['times']; sort($waTimes);
$waMedian = $waTimes ? $waTimes[intdiv(count($waTimes), 2)] : null;
$waQueue  = dbFetch("SELECT SUM(CASE WHEN assigned_to IS NULL THEN 1 ELSE 0 END) AS unassigned, COUNT(*) AS open, MIN(CASE WHEN unread > 0 THEN last_inbound_at END) AS oldest
                     FROM cs_wa_conversations WHERE status = 'open'");
$overdue  = (int)(dbFetch("SELECT COUNT(*) AS n FROM cs_followups WHERE status = 'open' AND due_at < ?", [date('Y-m-d H:i:s')])['n'] ?? 0);

$byChannel = dbFetchAll("SELECT channel, COUNT(*) AS n FROM cs_interactions WHERE created_at >= ? GROUP BY channel ORDER BY n DESC", [$since]);
$totalInteractions = array_sum(array_column($byChannel, 'n'));

// Everyone who can take contacts, plus anyone who has set a status.
$agents = dbFetchAll("SELECT DISTINCT u.id, u.name FROM users u
    LEFT JOIN role_permissions rp ON rp.role = u.role AND rp.permission = 'support.view'
    LEFT JOIN cs_agent_status st ON st.user_id = u.id
    WHERE u.status = 'active' AND u.role <> 'admin' AND (rp.permission IS NOT NULL OR st.user_id IS NOT NULL)
    ORDER BY u.name");
$per = fn(string $sql, array $p = []) => array_column(dbFetchAll($sql, $p), 'n', 'k');
$stat      = [];
foreach (dbFetchAll("SELECT user_id, status, changed_at FROM cs_agent_status") as $r) $stat[$r['user_id']] = $r;
$aCalls    = $per("SELECT agent_id AS k, COUNT(*) AS n FROM cs_calls WHERE started_at >= ? AND status = 'completed' AND agent_id IS NOT NULL GROUP BY agent_id", [$since]);
$aTalk     = $per("SELECT agent_id AS k, SUM(duration_sec) AS n FROM cs_calls WHERE started_at >= ? AND status = 'completed' AND agent_id IS NOT NULL GROUP BY agent_id", [$since]);
$aWa       = $per("SELECT agent_id AS k, COUNT(*) AS n FROM cs_wa_messages WHERE created_at >= ? AND direction = 'out' AND agent_id IS NOT NULL GROUP BY agent_id", [$since]);
$aLogged   = $per("SELECT agent_id AS k, COUNT(*) AS n FROM cs_interactions WHERE created_at >= ? GROUP BY agent_id", [$since]);
$aWaOpen   = $per("SELECT assigned_to AS k, COUNT(*) AS n FROM cs_wa_conversations WHERE status = 'open' AND assigned_to IS NOT NULL GROUP BY assigned_to");
$aFollow   = $per("SELECT assigned_to AS k, COUNT(*) AS n FROM cs_followups WHERE status = 'open' AND due_at < ? GROUP BY assigned_to", [date('Y-m-d H:i:s')]);
$statusColors = ['available' => 'success', 'on_call' => 'danger', 'wrap_up' => 'warning', 'break' => 'secondary', 'offline' => 'dark'];
$voiceCfg = csVoiceSettings();
$hours    = $voiceCfg['hours'];
// Live hold queue and today's calls per menu option.
$queueNow = csQueueSnapshot();
$teams    = csQueueMembers();
$free     = csFreeAgentIds();
$byChoice = [];
foreach (dbFetchAll("SELECT COALESCE(queue_digit, '') AS d, COUNT(*) AS n, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS answered
                     FROM cs_calls WHERE direction = 'inbound' AND started_at >= ? GROUP BY COALESCE(queue_digit, '')", [$since]) as $r) $byChoice[$r['d']] = $r;
$queueRows = ['' => 'No menu choice'] + $voiceCfg['ivr_options'];

$pageTitle = 'Supervisor Dashboard';
require __DIR__ . '/../../includes/header.php';
?>
<script>setTimeout(function () { if (!document.hidden) location.reload(); else document.addEventListener("visibilitychange", function () { location.reload(); }, { once: true }); }, 60000);</script>

<div class="d-flex justify-content-between align-items-end mb-3 flex-wrap gap-2">
  <div>
    <h2 class="fw-bold mb-0">Supervisor Dashboard</h2>
    <div class="text-muted small">Live agent status and <?= $period === '7d' ? 'the last 7 days' : 'today' ?>. Updated <?= date('H:i') ?>; refreshes every minute.
      Line is <strong><?= !$hours ? 'always open' : (csSupportOpen($hours) ? 'open' : 'closed') ?></strong> now.</div>
  </div>
  <div class="btn-group btn-group-sm">
    <a href="/support/dashboard" class="btn btn-<?= $period === 'today' ? 'primary' : 'outline-secondary' ?>">Today</a>
    <a href="/support/dashboard?period=7d" class="btn btn-<?= $period === '7d' ? 'primary' : 'outline-secondary' ?>">Last 7 days</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <?php foreach ([
      ['Inbound calls', $inbound, "{$calls['outbound']} outbound", '/support/calls'],
      ['Missed, voicemail, callback', $unanswered, $missRate === null ? 'no calls' : "$missRate% of inbound", '/support/calls?status=missed'],
      ['Avg talk time', $mmss($calls['avg_talk'] === null ? null : (int)round($calls['avg_talk'])), (int)$calls['answered'] . ' answered', null],
      ['WhatsApp first reply', $dur($waMedian), $waTimes ? 'median of ' . count($waTimes) . ' · worst ' . $dur(max($waTimes)) : 'no replies yet', '/support/whatsapp'],
      ['WhatsApp waiting', (int)$waQueue['unassigned'], (int)$waQueue['open'] . ' open' . ($waQueue['oldest'] ? ' · oldest unread ' . $dur(time() - strtotime($waQueue['oldest'])) : ''), '/support/whatsapp'],
      ['Overdue follow-ups', $overdue, "$totalInteractions contacts logged", '/support/followups'],
  ] as [$label, $value, $sub, $link]): ?>
  <div class="col-6 col-lg-2">
    <<?= $link ? 'a href="' . $link . '"' : 'div' ?> class="stat-card d-block text-decoration-none text-reset h-100">
      <div class="small text-muted"><?= $h($label) ?></div>
      <div class="fs-3 fw-bold"><?= $h($value) ?></div>
      <div class="small text-muted"><?= $h($sub) ?></div>
    </<?= $link ? 'a' : 'div' ?>>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card-section">
      <div class="card-header"><i class="bi bi-people me-1 text-primary"></i>Agents</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th>Agent</th><th>Status</th><th class="text-end">Calls</th><th class="text-end">Talk</th><th class="text-end">WA replies</th><th class="text-end">WA open</th><th class="text-end">Logged</th><th class="text-end">Overdue</th></tr></thead>
          <tbody>
          <?php foreach ($agents as $a): $st = $stat[$a['id']] ?? null; $status = $st['status'] ?? 'offline';
                // A status older than 12h is stale (the agent never signed out).
                if ($st && strtotime($st['changed_at']) < time() - 12 * 3600) $status = 'offline'; ?>
            <tr>
              <td><?= $h($a['name']) ?></td>
              <td><span class="badge bg-<?= $statusColors[$status] ?? 'dark' ?>"><?= $h(CS_AGENT_STATUSES[$status] ?? $status) ?></span>
                <?php if ($st && $status !== 'offline'): ?><span class="small text-muted ms-1"><?= $dur(time() - strtotime($st['changed_at'])) ?></span><?php endif; ?></td>
              <td class="text-end"><?= (int)($aCalls[$a['id']] ?? 0) ?></td>
              <td class="text-end"><?= $mmss(isset($aTalk[$a['id']]) ? (int)$aTalk[$a['id']] : null) ?></td>
              <td class="text-end"><?= (int)($aWa[$a['id']] ?? 0) ?></td>
              <td class="text-end"><?= (int)($aWaOpen[$a['id']] ?? 0) ?></td>
              <td class="text-end"><?= (int)($aLogged[$a['id']] ?? 0) ?></td>
              <td class="text-end <?= ($aFollow[$a['id']] ?? 0) ? 'text-danger fw-semibold' : '' ?>"><?= (int)($aFollow[$a['id']] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$agents): ?><tr><td colspan="8" class="text-muted small p-3">No support agents yet. Give staff a role with Customer Support access.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card-section mb-3">
      <div class="card-header"><i class="bi bi-hourglass-split me-1 text-primary"></i>Call queues</div>
      <table class="table table-sm align-middle mb-0 small">
        <thead><tr><th class="ps-3">Menu option</th><th class="text-end">On hold</th><th class="text-end">Longest</th><th class="text-end">Free</th><th class="text-end pe-3">Answered</th></tr></thead>
        <tbody>
        <?php foreach ($queueRows as $d => $label): $q = $queueNow[$d] ?? null; $c = $byChoice[$d] ?? null;
              if ($d === '' && !$q && !$c) continue;
              $team = $teams[$d] ?? []; $freeHere = $team ? count(array_intersect($free, $team)) : count($free); ?>
          <tr>
            <td class="ps-3"><?= $h($d !== '' ? "$d · $label" : $label) ?></td>
            <td class="text-end <?= $q ? 'text-danger fw-semibold' : '' ?>"><?= (int)($q['waiting'] ?? 0) ?></td>
            <td class="text-end"><?= $q ? $mmss($q['longest']) : '—' ?></td>
            <td class="text-end"><?= $freeHere ?></td>
            <td class="text-end pe-3"><?= $c ? (int)$c['answered'] . '/' . (int)$c['n'] : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-section">
      <div class="card-header"><i class="bi bi-bar-chart me-1 text-primary"></i>Contacts by channel</div>
      <div class="p-3">
        <?php foreach ($byChannel as $c): $pct = $totalInteractions ? round(100 * $c['n'] / $totalInteractions) : 0; ?>
        <div class="d-flex justify-content-between small"><span><?= $h(CS_CHANNELS[$c['channel']] ?? $c['channel']) ?></span><span><?= (int)$c['n'] ?></span></div>
        <div class="progress mb-2" style="height:6px"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div>
        <?php endforeach; ?>
        <?php if (!$byChannel): ?><div class="text-muted small">Nothing logged yet.</div><?php endif; ?>
        <a href="/support/interactions" class="small">Top contact reasons →</a>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
