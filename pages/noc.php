<?php
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('noc.view');

$tab = $_GET['tab'] ?? 'status';

// ── Lookups ───────────────────────────────────────────────────────────────────
$hubs    = dbFetchAll("SELECT id, name FROM hubs ORDER BY name");
$hubsMap = array_column($hubs, 'name', 'id');

// ── Status board data ─────────────────────────────────────────────────────────
$filterHub    = $_GET['hub']    ?? '';
$filterStatus = $_GET['status'] ?? '';

$where = []; $params = [];
if ($filterHub)    { $where[] = "o.olt_device_id IN (SELECT id FROM network_devices WHERE hub_id = ?)"; $params[] = $filterHub; }
if ($filterStatus) { $where[] = "o.status = ?"; $params[] = $filterStatus; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$onus = dbFetchAll(
    "SELECT o.*, nd.name AS olt_name, nd.hub_id,
            c.name AS customer_name, c.account_number
     FROM onu_units o
     JOIN network_devices nd ON nd.id = o.olt_device_id
     LEFT JOIN customers c ON c.id = o.customer_id
     $whereSql
     ORDER BY
       FIELD(o.status,'offline','los','lof','dying_gasp','unknown','working'),
       o.last_polled_at DESC",
    $params
);

// Summary counts
$counts = dbFetchAll(
    "SELECT o.status, COUNT(*) AS cnt
     FROM onu_units o
     JOIN network_devices nd ON nd.id = o.olt_device_id
     GROUP BY o.status"
);
$summary = array_column($counts, 'cnt', 'status');

// Devices list
$devices = dbFetchAll(
    "SELECT nd.*, h.name AS hub_name,
            (SELECT COUNT(*) FROM onu_units WHERE olt_device_id = nd.id) AS onu_count,
            (SELECT COUNT(*) FROM onu_units WHERE olt_device_id = nd.id AND status = 'working') AS onu_online,
            (SELECT COUNT(*) FROM onu_units WHERE olt_device_id = nd.id AND status IN ('offline','los','lof','dying_gasp')) AS onu_fault
     FROM network_devices nd
     LEFT JOIN hubs h ON h.id = nd.hub_id
     ORDER BY h.name, nd.name"
);

// Recent fault events (last 50)
$events = dbFetchAll(
    "SELECT e.*, o.serial_number, o.olt_port,
            nd.name AS olt_name, nd.hub_id,
            c.name AS customer_name,
            t.ticket_number
     FROM onu_status_events e
     JOIN onu_units o ON o.id = e.onu_id
     JOIN network_devices nd ON nd.id = o.olt_device_id
     LEFT JOIN customers c ON c.id = o.customer_id
     LEFT JOIN tickets t ON t.id = e.auto_ticket_id
     ORDER BY e.detected_at DESC
     LIMIT 50"
);

$STATUS_LABELS = [
    'working'     => 'Online',
    'offline'     => 'Offline',
    'los'         => 'LOS',
    'dying_gasp'  => 'Dying Gasp',
    'lof'         => 'LOF',
    'unknown'     => 'Unknown',
];
$STATUS_CLASS = [
    'working'     => 'success',
    'offline'     => 'danger',
    'los'         => 'danger',
    'dying_gasp'  => 'warning',
    'lof'         => 'warning',
    'unknown'     => 'secondary',
];

$pageTitle = 'Network Status';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-broadcast me-2 text-primary"></i>Network Operations Centre</h4>
  <?php if (hasPermission('noc.devices.manage')): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#deviceModal">
    <i class="bi bi-plus-lg me-1"></i>Add Device
  </button>
  <?php endif; ?>
</div>

<!-- Stat tiles -->
<div class="row g-3 mb-3">
  <?php
  $tiles = [
    ['Online',      $summary['working']    ?? 0, 'success', 'bi-wifi'],
    ['Offline',     $summary['offline']    ?? 0, 'danger',  'bi-wifi-off'],
    ['LOS',         $summary['los']        ?? 0, 'danger',  'bi-exclamation-triangle'],
    ['Dying Gasp',  $summary['dying_gasp'] ?? 0, 'warning', 'bi-lightning'],
    ['LOF',         $summary['lof']        ?? 0, 'warning', 'bi-broadcast'],
    ['Unknown',     $summary['unknown']    ?? 0, 'secondary','bi-question-circle'],
  ];
  foreach ($tiles as [$label, $cnt, $color, $icon]):
  ?>
  <div class="col-6 col-sm-4 col-md-2">
    <div class="card text-center h-100 border-0 shadow-sm">
      <div class="card-body py-2 px-2">
        <div class="text-<?= $color ?> fs-4 fw-bold"><?= number_format((int)$cnt) ?></div>
        <div class="text-muted" style="font-size:.75rem"><i class="bi <?= $icon ?> me-1"></i><?= $label ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'status' ? 'active' : '' ?>" href="?tab=status">
      <i class="bi bi-table me-1"></i>ONU Status Board
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'devices' ? 'active' : '' ?>" href="?tab=devices">
      <i class="bi bi-hdd-network me-1"></i>Devices
      <span class="badge bg-secondary ms-1"><?= count($devices) ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'events' ? 'active' : '' ?>" href="?tab=events">
      <i class="bi bi-clock-history me-1"></i>Fault Events
    </a>
  </li>
</ul>

<!-- ── TAB: ONU STATUS BOARD ─────────────────────────────────────────────── -->
<?php if ($tab === 'status'): ?>
<div class="d-flex gap-2 mb-3 flex-wrap">
  <form class="d-flex gap-2 flex-wrap" method="get">
    <input type="hidden" name="tab" value="status">
    <select name="hub" class="form-select form-select-sm" style="width:160px" onchange="this.form.submit()">
      <option value="">All Hubs</option>
      <?php foreach ($hubs as $h): ?>
      <option value="<?= htmlspecialchars($h['id']) ?>" <?= $filterHub === $h['id'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($h['name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <select name="status" class="form-select form-select-sm" style="width:140px" onchange="this.form.submit()">
      <option value="">All Statuses</option>
      <?php foreach ($STATUS_LABELS as $val => $lbl): ?>
      <option value="<?= $val ?>" <?= $filterStatus === $val ? 'selected' : '' ?>><?= $lbl ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($filterHub || $filterStatus): ?>
    <a href="?tab=status" class="btn btn-outline-secondary btn-sm">Clear</a>
    <?php endif; ?>
  </form>
  <span class="ms-auto text-muted small align-self-center"><?= count($onus) ?> ONUs</span>
</div>

<?php if (empty($onus)): ?>
<div class="text-center text-muted py-5">
  <i class="bi bi-inbox display-5 d-block mb-2"></i>
  <?= $filterHub || $filterStatus ? 'No ONUs match these filters.' : 'No ONUs registered. Add a device and run the poller to populate.' ?>
</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-sm table-hover align-middle">
  <thead class="table-light">
    <tr>
      <th>Status</th>
      <th>Serial</th>
      <th>OLT / Port</th>
      <th>Hub</th>
      <th>Customer</th>
      <th>Rx (dBm)</th>
      <th>Last Online</th>
      <th>Last Polled</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($onus as $o): ?>
    <tr>
      <td>
        <span class="badge text-bg-<?= $STATUS_CLASS[$o['status']] ?? 'secondary' ?>">
          <?= htmlspecialchars($STATUS_LABELS[$o['status']] ?? $o['status']) ?>
        </span>
      </td>
      <td class="font-monospace small"><?= htmlspecialchars($o['serial_number']) ?></td>
      <td class="small text-muted"><?= htmlspecialchars($o['olt_name'] . ($o['olt_port'] ? ' / '.$o['olt_port'] : '')) ?></td>
      <td class="small"><?= htmlspecialchars($hubsMap[$o['hub_id']] ?? '—') ?></td>
      <td class="small">
        <?php if ($o['customer_id']): ?>
          <a href="/customers?id=<?= htmlspecialchars($o['customer_id']) ?>" class="text-decoration-none">
            <?= htmlspecialchars($o['customer_name'] ?? $o['customer_id']) ?>
          </a>
          <?php if ($o['account_number']): ?>
            <span class="text-muted">(<?= htmlspecialchars($o['account_number']) ?>)</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="text-muted">—</span>
        <?php endif; ?>
      </td>
      <td class="small <?= ($o['rx_power_dbm'] !== null && $o['rx_power_dbm'] < -27) ? 'text-warning fw-bold' : '' ?>">
        <?= $o['rx_power_dbm'] !== null ? htmlspecialchars($o['rx_power_dbm']) . ' dBm' : '—' ?>
      </td>
      <td class="small text-muted"><?= $o['last_online_at'] ? htmlspecialchars(date('d M H:i', strtotime($o['last_online_at']))) : '—' ?></td>
      <td class="small text-muted"><?= $o['last_polled_at'] ? htmlspecialchars(date('d M H:i', strtotime($o['last_polled_at']))) : 'Never' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<!-- ── TAB: DEVICES ───────────────────────────────────────────────────────── -->
<?php elseif ($tab === 'devices'): ?>
<?php if (empty($devices)): ?>
<div class="text-center text-muted py-5">
  <i class="bi bi-hdd-network display-5 d-block mb-2"></i>
  No devices registered yet.
  <?php if (hasPermission('noc.devices.manage')): ?>
  <div class="mt-2"><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#deviceModal">Add your first device</button></div>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-sm table-hover align-middle">
  <thead class="table-light">
    <tr>
      <th>Device</th>
      <th>Type</th>
      <th>Hub</th>
      <th>IP Address</th>
      <th>Protocol</th>
      <th>ONUs</th>
      <th>Last Polled</th>
      <th>Status</th>
      <?php if (hasPermission('noc.devices.manage')): ?><th></th><?php endif; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($devices as $d): ?>
    <tr>
      <td class="fw-semibold"><?= htmlspecialchars($d['name']) ?></td>
      <td><span class="badge bg-secondary"><?= strtoupper($d['device_type']) ?></span></td>
      <td class="small"><?= htmlspecialchars($d['hub_name'] ?? '—') ?></td>
      <td class="font-monospace small"><?= htmlspecialchars($d['ip_address']) ?></td>
      <td class="small text-muted"><?= htmlspecialchars($d['protocol']) ?><?= $d['device_type'] === 'mikrotik' ? ':'.$d['api_port'] : '' ?></td>
      <td class="small">
        <?php if ((int)$d['onu_count'] > 0): ?>
          <span class="text-success fw-semibold"><?= (int)$d['onu_online'] ?></span>
          / <?= (int)$d['onu_count'] ?>
          <?php if ((int)$d['onu_fault'] > 0): ?>
            <span class="text-danger ms-1">(<?= (int)$d['onu_fault'] ?> fault)</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="text-muted">—</span>
        <?php endif; ?>
      </td>
      <td class="small text-muted"><?= $d['last_polled_at'] ? htmlspecialchars(date('d M H:i', strtotime($d['last_polled_at']))) : 'Never' ?></td>
      <td>
        <?php if (!$d['enabled']): ?>
          <span class="badge bg-secondary">Disabled</span>
        <?php elseif ($d['poll_error']): ?>
          <span class="badge bg-danger" title="<?= htmlspecialchars($d['poll_error']) ?>">Error</span>
        <?php elseif ($d['last_seen_at']): ?>
          <span class="badge bg-success">Reachable</span>
        <?php else: ?>
          <span class="badge bg-warning text-dark">Untested</span>
        <?php endif; ?>
      </td>
      <?php if (hasPermission('noc.devices.manage')): ?>
      <td>
        <button class="btn btn-sm btn-outline-secondary"
                onclick="editDevice(<?= htmlspecialchars(json_encode($d)) ?>)">
          <i class="bi bi-pencil"></i>
        </button>
        <button class="btn btn-sm btn-outline-primary ms-1" title="Test connection"
                onclick="testDevice('<?= htmlspecialchars($d['id']) ?>', this)">
          <i class="bi bi-plug"></i>
        </button>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<!-- ── TAB: FAULT EVENTS ──────────────────────────────────────────────────── -->
<?php elseif ($tab === 'events'): ?>
<?php if (empty($events)): ?>
<div class="text-center text-muted py-5">
  <i class="bi bi-clock-history display-5 d-block mb-2"></i>
  No fault events recorded yet.
</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-sm table-hover align-middle">
  <thead class="table-light">
    <tr>
      <th>Time</th>
      <th>ONU Serial</th>
      <th>OLT / Port</th>
      <th>Change</th>
      <th>Customer</th>
      <th>Auto Ticket</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($events as $ev): ?>
    <tr>
      <td class="small text-muted"><?= htmlspecialchars(date('d M H:i:s', strtotime($ev['detected_at']))) ?></td>
      <td class="font-monospace small"><?= htmlspecialchars($ev['serial_number']) ?></td>
      <td class="small text-muted">
        <?= htmlspecialchars($ev['olt_name']) ?>
        <?= $ev['olt_port'] ? '/ ' . htmlspecialchars($ev['olt_port']) : '' ?>
      </td>
      <td class="small">
        <span class="badge text-bg-<?= $STATUS_CLASS[$ev['from_status']] ?? 'secondary' ?>">
          <?= htmlspecialchars($STATUS_LABELS[$ev['from_status']] ?? ($ev['from_status'] ?? '?')) ?>
        </span>
        <i class="bi bi-arrow-right mx-1 text-muted"></i>
        <span class="badge text-bg-<?= $STATUS_CLASS[$ev['to_status']] ?? 'secondary' ?>">
          <?= htmlspecialchars($STATUS_LABELS[$ev['to_status']] ?? $ev['to_status']) ?>
        </span>
      </td>
      <td class="small"><?= htmlspecialchars($ev['customer_name'] ?? '—') ?></td>
      <td class="small">
        <?php if ($ev['auto_ticket_id'] && $ev['ticket_number']): ?>
          <a href="/ticket/<?= htmlspecialchars($ev['auto_ticket_id']) ?>">#<?= htmlspecialchars($ev['ticket_number']) ?></a>
        <?php else: ?>
          <span class="text-muted">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ── Add / Edit Device Modal ────────────────────────────────────────────── -->
<?php if (hasPermission('noc.devices.manage')): ?>
<div class="modal fade" id="deviceModal" tabindex="-1">
  <div class="modal-dialog">
    <form id="deviceForm" class="modal-content">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="action" value="save_device" id="deviceAction" value="save_device">
      <input type="hidden" name="device_id" id="deviceId">

      <div class="modal-header">
        <h5 class="modal-title" id="deviceModalTitle">Add Network Device</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">Hub <span class="text-danger">*</span></label>
          <select name="hub_id" id="fHub" class="form-select" required>
            <option value="">— Select hub —</option>
            <?php foreach ($hubs as $h): ?>
            <option value="<?= htmlspecialchars($h['id']) ?>"><?= htmlspecialchars($h['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-8">
            <label class="form-label fw-semibold">Device Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="fName" class="form-control" placeholder="e.g. Hub-A OLT 1" required>
          </div>
          <div class="col-4">
            <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
            <select name="device_type" id="fType" class="form-select" onchange="toggleProtocolFields()">
              <option value="olt">OLT (Huawei GPON)</option>
              <option value="mikrotik">Mikrotik Router</option>
              <option value="switch">Switch</option>
            </select>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold">IP Address <span class="text-danger">*</span></label>
          <input type="text" name="ip_address" id="fIp" class="form-control font-monospace"
                 placeholder="192.168.1.1" required pattern="^[\d\.]+$">
        </div>

        <!-- SNMP fields (OLT / switch) -->
        <div id="snmpFields">
          <div class="mb-3">
            <label class="form-label fw-semibold">SNMP Community String</label>
            <input type="text" name="snmp_community" id="fCommunity" class="form-control font-monospace"
                   placeholder="public" autocomplete="off">
            <div class="form-text">Stored encrypted. Leave blank to keep existing value on edit.</div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">SNMP Version</label>
            <select name="snmp_version" id="fSnmpVer" class="form-select">
              <option value="2c">SNMPv2c</option>
              <option value="1">SNMPv1</option>
              <option value="3">SNMPv3</option>
            </select>
          </div>
        </div>

        <!-- RouterOS API fields (Mikrotik) -->
        <div id="apiFields" style="display:none">
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label fw-semibold">RouterOS Username</label>
              <input type="text" name="api_user" id="fApiUser" class="form-control" placeholder="admin" autocomplete="off">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">RouterOS Password</label>
              <input type="password" name="api_pass" id="fApiPass" class="form-control" autocomplete="new-password">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">API Port</label>
            <input type="number" name="api_port" id="fApiPort" class="form-control" value="8728">
          </div>
          <div class="form-text mb-3">Credentials stored encrypted. Leave password blank to keep existing on edit.</div>
        </div>

        <div class="form-check">
          <input type="checkbox" class="form-check-input" name="enabled" id="fEnabled" value="1" checked>
          <label class="form-check-label" for="fEnabled">Enabled (include in polling)</label>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto d-none" id="deleteDeviceBtn">
          <i class="bi bi-trash"></i> Delete
        </button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save Device</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

function toggleProtocolFields() {
  const type = document.getElementById('fType').value;
  document.getElementById('snmpFields').style.display = type !== 'mikrotik' ? '' : 'none';
  document.getElementById('apiFields').style.display  = type === 'mikrotik' ? '' : 'none';
}

function editDevice(d) {
  const modal = document.getElementById('deviceModal');
  document.getElementById('deviceModalTitle').textContent = 'Edit Device';
  document.getElementById('deviceId').value    = d.id;
  document.getElementById('fHub').value        = d.hub_id;
  document.getElementById('fName').value       = d.name;
  document.getElementById('fType').value       = d.device_type;
  document.getElementById('fIp').value         = d.ip_address;
  document.getElementById('fCommunity').value  = '';  // never pre-fill creds
  document.getElementById('fSnmpVer').value    = d.snmp_version || '2c';
  document.getElementById('fApiUser').value    = '';
  document.getElementById('fApiPass').value    = '';
  document.getElementById('fApiPort').value    = d.api_port || 8728;
  document.getElementById('fEnabled').checked  = !!+d.enabled;
  document.getElementById('deleteDeviceBtn').classList.remove('d-none');
  document.getElementById('deleteDeviceBtn').onclick = () => deleteDevice(d.id);
  toggleProtocolFields();
  bootstrap.Modal.getOrCreateInstance(modal).show();
}

document.getElementById('deviceModal')?.addEventListener('hidden.bs.modal', () => {
  document.getElementById('deviceId').value = '';
  document.getElementById('deviceModalTitle').textContent = 'Add Network Device';
  document.getElementById('deleteDeviceBtn')?.classList.add('d-none');
  document.getElementById('fType').value = 'olt';
  toggleProtocolFields();
});

document.getElementById('deviceForm')?.addEventListener('submit', async e => {
  e.preventDefault();
  const data = new FormData(e.target);
  const res  = await fetch('/api/noc', { method: 'POST', body: data });
  const json = await res.json();
  if (json.ok) location.href = '?tab=devices';
  else alert(json.error ?? 'Save failed');
});

async function deleteDevice(id) {
  if (!confirm('Delete this device? All associated ONU records will also be removed.')) return;
  const fd = new FormData();
  fd.append('_csrf', CSRF); fd.append('action', 'delete_device'); fd.append('device_id', id);
  const res  = await fetch('/api/noc', { method: 'POST', body: fd });
  const json = await res.json();
  if (json.ok) location.href = '?tab=devices';
  else alert(json.error ?? 'Delete failed');
}

async function testDevice(id, btn) {
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
  const fd = new FormData();
  fd.append('_csrf', CSRF); fd.append('action', 'test_device'); fd.append('device_id', id);
  try {
    const res  = await fetch('/api/noc', { method: 'POST', body: fd });
    const json = await res.json();
    alert(json.ok ? '✓ ' + (json.message ?? 'Connection OK') : '✗ ' + (json.error ?? 'Failed'));
  } catch(err) { alert('Request failed: ' + err); }
  btn.disabled = false;
  btn.innerHTML = '<i class="bi bi-plug"></i>';
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
