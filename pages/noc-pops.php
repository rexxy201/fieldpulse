<?php
/**
 * NOC — POP Monitor (/noc/pops). One row per point of presence: its router's
 * public IP is pinged every minute (scripts/pop-check.php). Shows live status,
 * latency, uptime and outage history; noc.devices.manage adds/edits POPs.
 */
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('noc.view');

$canManage = hasPermission('noc.devices.manage');
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);

if (method() === 'POST') {
    verifyCsrf();
    $action = $_POST['_action'] ?? '';
    $flash  = null;
    if ($action === 'check') {
        $pop = dbFetch("SELECT id, name FROM noc_pops WHERE id = ?", [$_POST['id'] ?? '']);
        if ($pop) {
            nocCheckPops([$pop['id']]);
            $p = dbFetch("SELECT status, last_latency_ms, last_packet_loss, last_error FROM noc_pops WHERE id = ?", [$pop['id']]);
            $flash = ['info', "{$pop['name']}: " . ($p['last_packet_loss'] >= 100 ? 'no reply' . ($p['last_error'] ? " ({$p['last_error']})" : '')
                : "replied in {$p['last_latency_ms']} ms, {$p['last_packet_loss']}% loss")];
        }
    } elseif ($canManage && $action === 'save') {
        $id   = (string)($_POST['id'] ?? '');
        $name = trim((string)($_POST['name'] ?? ''));
        $ip   = trim((string)($_POST['ip_address'] ?? ''));
        $hub  = trim((string)($_POST['hub_id'] ?? '')) ?: null;
        if ($hub && !dbFetch("SELECT id FROM hubs WHERE id = ?", [$hub])) $hub = null;
        if ($name === '' || !nocPopValidHost($ip)) {
            $flash = ['danger', 'Enter a name and a valid public IP address or hostname.'];
        } else {
            $vals = [mb_substr($name, 0, 120), $hub, $ip, mb_substr(trim((string)($_POST['location'] ?? '')), 0, 255),
                     max(1, min(65535, (int)($_POST['tcp_port'] ?? 8291))), max(1, min(30, (int)($_POST['down_after'] ?? 3))),
                     ((int)($_POST['latency_warn_ms'] ?? 0)) > 0 ? (int)$_POST['latency_warn_ms'] : null, !empty($_POST['enabled']) ? 1 : 0];
            if ($id !== '' && dbFetch("SELECT id FROM noc_pops WHERE id = ?", [$id])) {
                dbRun("UPDATE noc_pops SET name=?, hub_id=?, ip_address=?, location=?, tcp_port=?, down_after=?, latency_warn_ms=?, enabled=? WHERE id=?", array_merge($vals, [$id]));
                auditLog('update', 'noc_pop', $id, "$name ($ip)");
                $flash = ['success', 'POP saved.'];
            } else {
                $id = newUuid();
                dbRun("INSERT INTO noc_pops (id,name,hub_id,ip_address,location,tcp_port,down_after,latency_warn_ms,enabled) VALUES (?,?,?,?,?,?,?,?,?)", array_merge([$id], $vals));
                auditLog('create', 'noc_pop', $id, "$name ($ip)");
                nocCheckPops([$id]);
                $flash = ['success', 'POP added and checked once. The every-minute cron keeps it current.'];
            }
        }
    } elseif ($canManage && $action === 'delete') {
        $pop = dbFetch("SELECT id, name FROM noc_pops WHERE id = ?", [$_POST['id'] ?? '']);
        if ($pop) {
            dbRun("DELETE FROM noc_pop_checks WHERE pop_id = ?", [$pop['id']]);
            dbRun("DELETE FROM noc_pop_outages WHERE pop_id = ?", [$pop['id']]);
            dbRun("DELETE FROM noc_pops WHERE id = ?", [$pop['id']]);
            auditLog('delete', 'noc_pop', $pop['id'], $pop['name']);
            $flash = ['success', 'POP removed.'];
        }
    }
    if ($flash) $_SESSION['pop_flash'] = $flash;
    header('Location: /noc/pops'); exit;
}

$flash = $_SESSION['pop_flash'] ?? null;
unset($_SESSION['pop_flash']);

$pops = dbFetchAll("SELECT p.*, h.name AS hub_name,
        (SELECT COUNT(*) FROM customers c WHERE c.hub_id = p.hub_id AND p.hub_id IS NOT NULL) AS customers
    FROM noc_pops p LEFT JOIN hubs h ON h.id = p.hub_id
    ORDER BY CASE p.status WHEN 'down' THEN 0 WHEN 'degraded' THEN 1 WHEN 'unknown' THEN 2 ELSE 3 END, p.name");
$up24 = nocPopUptime(date('Y-m-d H:i:s', time() - 86400));
$up7  = nocPopUptime(date('Y-m-d H:i:s', time() - 7 * 86400));
$outages = dbFetchAll("SELECT o.*, p.name FROM noc_pop_outages o JOIN noc_pops p ON p.id = o.pop_id ORDER BY o.started_at DESC LIMIT 25");
$hubs = dbFetchAll("SELECT id, name FROM hubs ORDER BY name");
$counts = array_count_values(array_map(fn($p) => $p['enabled'] ? $p['status'] : 'disabled', $pops));
$lastRun = dbFetch("SELECT MAX(last_check_at) AS t FROM noc_pops WHERE enabled = 1")['t'] ?? null;
$stale = $pops && $lastRun && strtotime($lastRun) < time() - 300;
$edit = $canManage && !empty($_GET['edit']) ? dbFetch("SELECT * FROM noc_pops WHERE id = ?", [$_GET['edit']]) : null;
$badge = ['up' => 'success', 'degraded' => 'warning', 'down' => 'danger', 'unknown' => 'secondary'];

$pageTitle = 'POP Monitor';
require __DIR__ . '/../includes/header.php';
?>
<script>setTimeout(function () { if (!document.hidden && !document.querySelector('form.pop-form input:focus')) location.reload(); }, 60000);</script>

<div class="d-flex justify-content-between align-items-end mb-3 flex-wrap gap-2">
  <div>
    <h2 class="fw-bold mb-0">POP Monitor</h2>
    <div class="text-muted small">Each POP's router is pinged every minute. A POP is marked down after its set number of failed checks in a row. Refreshes every minute.</div>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?= $h($flash[0]) ?> small"><?= $h($flash[1]) ?></div><?php endif; ?>
<?php if ($stale): ?><div class="alert alert-warning small">No checks for <?= $h(nocDuration(time() - strtotime($lastRun))) ?> — the POP check cron job may have stopped.</div><?php endif; ?>

<div class="row g-3 mb-3">
  <?php foreach ([['Online', $counts['up'] ?? 0, 'success'], ['Degraded', $counts['degraded'] ?? 0, 'warning'], ['Down', $counts['down'] ?? 0, 'danger'], ['Not checked / off', ($counts['unknown'] ?? 0) + ($counts['disabled'] ?? 0), 'secondary']] as [$l, $n, $c]): ?>
  <div class="col-6 col-md-3"><div class="stat-card h-100"><div class="small text-muted"><?= $l ?></div><div class="fs-3 fw-bold text-<?= $c ?>"><?= (int)$n ?></div></div></div>
  <?php endforeach; ?>
</div>

<div class="card-section mb-3">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>POP</th><th>Status</th><th>IP</th><th class="text-end">Latency</th><th class="text-end">Loss</th><th class="text-end">Uptime 24h</th><th class="text-end">7d</th><th>Last check</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($pops as $p): $st = $p['enabled'] ? $p['status'] : 'off'; ?>
        <tr class="<?= $st === 'down' ? 'table-danger' : '' ?>">
          <td><div class="fw-semibold"><?= $h($p['name']) ?></div>
            <div class="small text-muted"><?= $h($p['hub_name'] ?: 'No hub') ?><?= $p['location'] !== '' ? ' · ' . $h($p['location']) : '' ?><?= $p['customers'] ? ' · ' . (int)$p['customers'] . ' customers' : '' ?></div></td>
          <td><?php if ($st === 'off'): ?><span class="badge bg-light text-dark border">Disabled</span>
              <?php else: ?><span class="badge bg-<?= $badge[$st] ?? 'secondary' ?>"><?= $h(NOC_POP_STATUSES[$st] ?? $st) ?></span>
              <?php if ($st === 'down' && $p['down_since']): ?><div class="small text-danger">for <?= $h(nocDuration(time() - strtotime($p['down_since']))) ?></div><?php endif; ?>
              <?php if ($st !== 'down' && (int)$p['consecutive_failures'] > 0): ?><div class="small text-warning"><?= (int)$p['consecutive_failures'] ?> missed</div><?php endif; ?>
              <?php endif; ?></td>
          <td class="font-monospace small"><?= $h($p['ip_address']) ?></td>
          <td class="text-end"><?= $p['last_latency_ms'] !== null ? $h(round((float)$p['last_latency_ms'])) . ' ms' : '—' ?></td>
          <td class="text-end"><?= $p['last_packet_loss'] !== null ? $h((float)$p['last_packet_loss']) . '%' : '—' ?></td>
          <td class="text-end"><?= isset($up24[$p['id']]) ? $h($up24[$p['id']]) . '%' : '—' ?></td>
          <td class="text-end"><?= isset($up7[$p['id']]) ? $h($up7[$p['id']]) . '%' : '—' ?></td>
          <td class="small text-muted"><?= $p['last_check_at'] ? $h(date('d M H:i', strtotime($p['last_check_at']))) : 'Never' ?></td>
          <td class="text-end text-nowrap">
            <form method="POST" class="d-inline"><?= csrfField() ?><input type="hidden" name="_action" value="check"><input type="hidden" name="id" value="<?= $h($p['id']) ?>">
              <button class="btn btn-sm btn-outline-secondary py-0">Check now</button></form>
            <?php if ($canManage): ?>
            <a href="/noc/pops?edit=<?= $h($p['id']) ?>#popForm" class="btn btn-sm btn-outline-primary py-0">Edit</a>
            <form method="POST" class="d-inline" onsubmit="return confirm('Remove this POP and its history?')"><?= csrfField() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= $h($p['id']) ?>">
              <button class="btn btn-sm btn-outline-danger py-0">Remove</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$pops): ?><tr><td colspan="9" class="text-muted small p-3">No POPs yet.<?= $canManage ? ' Add your first one below.' : '' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="row g-3">
  <?php if ($canManage): ?>
  <div class="col-lg-5">
    <div class="card-section" id="popForm">
      <div class="card-header"><i class="bi bi-<?= $edit ? 'pencil' : 'plus-lg' ?> me-1 text-primary"></i><?= $edit ? 'Edit POP' : 'Add a POP' ?></div>
      <form method="POST" class="p-3 pop-form">
        <?= csrfField() ?><input type="hidden" name="_action" value="save"><input type="hidden" name="id" value="<?= $h($edit['id'] ?? '') ?>">
        <div class="row g-2">
          <div class="col-sm-6"><label class="form-label small fw-semibold mb-1">Name</label>
            <input name="name" class="form-control form-control-sm" required value="<?= $h($edit['name'] ?? '') ?>" placeholder="e.g. Lekki Phase 1 POP"></div>
          <div class="col-sm-6"><label class="form-label small fw-semibold mb-1">Router public IP</label>
            <input name="ip_address" class="form-control form-control-sm font-monospace" required value="<?= $h($edit['ip_address'] ?? '') ?>" placeholder="102.216.236.22"></div>
          <div class="col-sm-6"><label class="form-label small fw-semibold mb-1">Hub</label>
            <select name="hub_id" class="form-select form-select-sm"><option value="">— None —</option>
              <?php foreach ($hubs as $hb): ?><option value="<?= $h($hb['id']) ?>" <?= ($edit['hub_id'] ?? '') === $hb['id'] ? 'selected' : '' ?>><?= $h($hb['name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-6"><label class="form-label small fw-semibold mb-1">Location</label>
            <input name="location" class="form-control form-control-sm" value="<?= $h($edit['location'] ?? '') ?>"></div>
          <div class="col-4"><label class="form-label small fw-semibold mb-1">Down after</label>
            <div class="input-group input-group-sm"><input type="number" min="1" max="30" name="down_after" class="form-control" value="<?= (int)($edit['down_after'] ?? 3) ?>"><span class="input-group-text">misses</span></div></div>
          <div class="col-4"><label class="form-label small fw-semibold mb-1">Slow above</label>
            <div class="input-group input-group-sm"><input type="number" min="0" name="latency_warn_ms" class="form-control" value="<?= $h($edit['latency_warn_ms'] ?? '') ?>" placeholder="off"><span class="input-group-text">ms</span></div></div>
          <div class="col-4"><label class="form-label small fw-semibold mb-1">Fallback port</label>
            <input type="number" min="1" max="65535" name="tcp_port" class="form-control form-control-sm" value="<?= (int)($edit['tcp_port'] ?? 8291) ?>"></div>
          <div class="col-12 small text-muted">The fallback port is only tried if ping can't run. With 3 misses and a check every minute, a POP shows down about 3 minutes after it fails.</div>
          <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="enabled" id="popEn" value="1" <?= ($edit['enabled'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label small" for="popEn">Monitor this POP</label></div></div>
        </div>
        <div class="mt-3 d-flex gap-2 justify-content-end">
          <?php if ($edit): ?><a href="/noc/pops" class="btn btn-sm btn-light">Cancel</a><?php endif; ?>
          <button class="btn btn-sm btn-primary"><?= $edit ? 'Save' : 'Add POP' ?></button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
  <div class="col-lg-<?= $canManage ? 7 : 12 ?>">
    <div class="card-section">
      <div class="card-header"><i class="bi bi-clock-history me-1 text-primary"></i>Recent outages</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>POP</th><th>Started</th><th>Ended</th><th class="text-end">Duration</th></tr></thead>
          <tbody>
          <?php foreach ($outages as $o): ?>
            <tr><td><?= $h($o['name']) ?></td><td class="small"><?= $h(date('d M H:i', strtotime($o['started_at']))) ?></td>
              <td class="small"><?= $o['ended_at'] ? $h(date('d M H:i', strtotime($o['ended_at']))) : '<span class="badge bg-danger">ongoing</span>' ?></td>
              <td class="text-end small"><?= $h(nocDuration($o['duration_sec'] !== null ? (int)$o['duration_sec'] : time() - strtotime($o['started_at']))) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$outages): ?><tr><td colspan="4" class="text-muted small p-3">No outages recorded.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
