<?php
require_once __DIR__ . '/../../config.php';
requireAuth();
requirePermission('inventory.serials.view');

$canManage = hasPermission('inventory.serials.manage');

// Pre-select item from query string
$itemId   = (int)($_GET['item_id'] ?? 0);
$itemName = '';
if ($itemId) {
    $item = dbFetch("SELECT id, name, unique_code FROM inv_items WHERE id = ?", [$itemId]);
    if ($item) $itemName = $item['name'];
    else        $itemId  = 0;
}

// Item list for the dropdown
$items = dbFetchAll(
    "SELECT i.id, i.name, i.unique_code,
            COALESCE(COUNT(sn.id),0)                                                   AS total_serials,
            COALESCE(SUM(sn.status='in_stock'),0)                                      AS in_stock,
            COALESCE(SUM(sn.status='deployed'),0)                                      AS deployed,
            COALESCE(SUM(sn.status='retired'),0)                                       AS retired
     FROM   inv_items i
     LEFT JOIN inv_serial_numbers sn ON sn.item_id = i.id
     GROUP BY i.id
     ORDER BY i.name"
);

$customers     = dbFetchAll("SELECT id, name, account_number FROM customers ORDER BY name LIMIT 1000");
$installations = dbFetchAll("SELECT id, installation_number, customer_name FROM installation_profiles ORDER BY created_at DESC LIMIT 200");
$onuUnits      = dbFetchAll(
    "SELECT ou.id, ou.serial_number, ou.olt_port, c.name AS customer_name
     FROM onu_units ou
     LEFT JOIN customers c ON c.id = ou.customer_id
     ORDER BY ou.serial_number LIMIT 1000"
);

$pageTitle = 'Serial Numbers';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h2 class="fw-bold mb-0">Serial Numbers</h2>
    <div class="text-muted small">Track CPE and equipment serials from stock to deployment</div>
  </div>
  <?php if ($canManage && $itemId): ?>
  <button class="btn btn-primary" onclick="openAddModal()">
    <i class="bi bi-plus-lg me-1"></i>Add Serials
  </button>
  <?php endif; ?>
</div>

<!-- Global serial search -->
<div class="card-section mb-4">
  <div class="d-flex gap-2 align-items-center mb-3">
    <i class="bi bi-search text-muted"></i>
    <input type="text" id="globalSearch" class="form-control" placeholder="Search any serial number…" style="max-width:360px" autocomplete="off">
    <span class="text-muted small" id="searchStatus"></span>
  </div>
  <div id="searchResults" class="d-none">
    <table class="table table-sm mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Serial</th><th>Item</th><th>Status</th><th>Customer</th><th>Dispatched</th></tr>
      </thead>
      <tbody id="searchResultsTbody"></tbody>
    </table>
  </div>
</div>

<!-- Item selector + serial table -->
<div class="row g-3">
  <!-- Item list sidebar -->
  <div class="col-md-4 col-lg-3">
    <div class="card-section h-100">
      <div class="fw-semibold small text-muted mb-2 text-uppercase" style="font-size:.7rem;letter-spacing:.08em">Items</div>
      <div style="max-height:520px;overflow-y:auto">
        <?php foreach ($items as $it): ?>
        <a href="/inventory/serials?item_id=<?= $it['id'] ?>"
           class="d-flex justify-content-between align-items-center gap-2 py-2 px-2 rounded text-decoration-none mb-1
                  <?= $itemId === (int)$it['id'] ? 'bg-primary text-white' : 'text-body hover-bg' ?>"
           style="<?= $itemId === (int)$it['id'] ? '' : 'hover:background:var(--bs-light)' ?>">
          <div style="min-width:0">
            <div class="fw-semibold text-truncate" style="font-size:.85rem"><?= htmlspecialchars($it['name']) ?></div>
            <?php if ($it['unique_code']): ?>
            <div class="<?= $itemId===(int)$it['id']?'text-white-50':'text-muted' ?>" style="font-size:.7rem"><?= htmlspecialchars($it['unique_code']) ?></div>
            <?php endif; ?>
          </div>
          <?php if ($it['total_serials'] > 0): ?>
          <span class="badge <?= $itemId===(int)$it['id']?'bg-light text-primary':'bg-secondary' ?> rounded-pill"
                style="font-size:.65rem"><?= $it['in_stock'] ?>/<?= $it['total_serials'] ?></span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php if (!$items): ?>
        <div class="text-muted small py-3 text-center">No items found</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Serial table -->
  <div class="col-md-8 col-lg-9">
    <?php if (!$itemId): ?>
    <div class="card-section text-center py-5 text-muted">
      <i class="bi bi-upc-scan" style="font-size:2rem"></i>
      <div class="mt-2">Select an item from the list to view its serial numbers</div>
    </div>
    <?php else: ?>
    <div class="card-section">
      <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
          <span class="fw-semibold"><?= htmlspecialchars($itemName) ?></span>
          <span class="text-muted small ms-2" id="serialCount"></span>
        </div>
        <div class="d-flex gap-2">
          <select id="statusFilter" class="form-select form-select-sm" style="width:auto" onchange="loadSerials()">
            <option value="">All statuses</option>
            <option value="in_stock">In Stock</option>
            <option value="deployed">Deployed</option>
            <option value="retired">Retired</option>
          </select>
          <?php if ($canManage): ?>
          <button class="btn btn-sm btn-primary" onclick="openAddModal()">
            <i class="bi bi-plus-lg me-1"></i>Add
          </button>
          <?php endif; ?>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>Serial</th>
              <th>Batch</th>
              <th>Status</th>
              <th>Customer</th>
              <th>Dispatched</th>
              <?php if ($canManage): ?><th></th><?php endif; ?>
            </tr>
          </thead>
          <tbody id="serialsTbody">
            <tr><td colspan="6" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($itemId && $canManage): ?>
<!-- Add Serials Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Add Serial Numbers — <?= htmlspecialchars($itemName) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">Serial Numbers <span class="text-danger">*</span></label>
          <textarea id="addSerials" class="form-control font-monospace" rows="6"
            placeholder="One per line, or comma-separated&#10;e.g. SN001&#10;SN002&#10;SN003"></textarea>
          <div class="form-text">Paste multiple serials — one per line or comma-separated. Duplicates are skipped.</div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Batch / Delivery Reference</label>
          <input type="text" id="addBatch" class="form-control" placeholder="e.g. PO-2026-001">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Notes</label>
          <input type="text" id="addNotes" class="form-control" placeholder="Optional">
        </div>
        <div id="addError" class="alert alert-danger d-none"></div>
        <div id="addSuccess" class="alert alert-success d-none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" onclick="submitAdd()" id="addBtn">Add Serials</button>
      </div>
    </div>
  </div>
</div>

<!-- Dispatch Modal -->
<div class="modal fade" id="dispatchModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Dispatch <span id="dispatchSerial" class="text-primary font-monospace"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="dispatchId">
        <div class="mb-3">
          <label class="form-label fw-semibold">Customer</label>
          <select id="dispatchCustomer" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($customers as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['account_number'] ?? '') ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Installation</label>
          <select id="dispatchInstallation" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($installations as $ins): ?>
            <option value="<?= htmlspecialchars($ins['id']) ?>"><?= htmlspecialchars($ins['installation_number'] . ' — ' . $ins['customer_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">ONU Unit (optional)</label>
          <select id="dispatchOnu" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($onuUnits as $ou): ?>
            <option value="<?= htmlspecialchars($ou['id']) ?>"><?= htmlspecialchars($ou['serial_number'] . ' / port ' . $ou['olt_port'] . ($ou['customer_name'] ? ' — '.$ou['customer_name'] : '')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Notes</label>
          <input type="text" id="dispatchNotes" class="form-control" placeholder="Optional">
        </div>
        <div id="dispatchError" class="alert alert-danger d-none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-success" onclick="submitDispatch()">Dispatch</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
const csrf    = document.querySelector('meta[name="csrf-token"]').content;
const itemId  = <?= $itemId ?: 'null' ?>;
const canManage = <?= $canManage ? 'true' : 'false' ?>;

const statusBadge = { in_stock: 'bg-success', deployed: 'bg-primary', retired: 'bg-secondary' };
const statusLabel = { in_stock: 'In Stock', deployed: 'Deployed', retired: 'Retired' };

// ── Load serials ──────────────────────────────────────────────────────────────
async function loadSerials() {
  if (!itemId) return;
  const status = document.getElementById('statusFilter')?.value ?? '';
  const tbody  = document.getElementById('serialsTbody');
  tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3"><span class="spinner-border spinner-border-sm"></span></td></tr>';

  const r = await fetch(`/api/serials?action=list&item_id=${itemId}&status=${status}`);
  const d = await r.json();
  const serials = d.serials || [];

  document.getElementById('serialCount').textContent = serials.length + ' serial' + (serials.length !== 1 ? 's' : '');

  if (!serials.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No serials found.</td></tr>';
    return;
  }

  tbody.innerHTML = serials.map(sn => `
    <tr>
      <td class="font-monospace fw-semibold small">${esc(sn.serial)}</td>
      <td class="text-muted small">${esc(sn.batch_number || '—')}</td>
      <td><span class="badge ${statusBadge[sn.status] || 'bg-secondary'}">${statusLabel[sn.status] || sn.status}</span></td>
      <td class="small">${sn.customer_name ? esc(sn.customer_name) + (sn.account_number ? '<br><span class="text-muted">' + esc(sn.account_number) + '</span>' : '') : '<span class="text-muted">—</span>'}</td>
      <td class="small text-muted">${sn.dispatched_at ? sn.dispatched_at.slice(0,10) + (sn.dispatched_by_name ? '<br>' + esc(sn.dispatched_by_name) : '') : '—'}</td>
      ${canManage ? `<td class="text-end">
        ${sn.status === 'in_stock' ? `<button class="btn btn-xs btn-outline-success py-0 me-1" onclick="openDispatch('${esc(sn.id)}','${esc(sn.serial)}')"><i class="bi bi-box-arrow-up"></i></button>` : ''}
        ${sn.status === 'deployed' ? `<button class="btn btn-xs btn-outline-secondary py-0 me-1" onclick="doReturn('${esc(sn.id)}')" title="Return to stock"><i class="bi bi-arrow-return-left"></i></button>` : ''}
        ${sn.status !== 'retired' ? `<button class="btn btn-xs btn-outline-warning py-0 me-1" onclick="doRetire('${esc(sn.id)}')" title="Retire"><i class="bi bi-x-circle"></i></button>` : ''}
        ${sn.status === 'in_stock' ? `<button class="btn btn-xs btn-outline-danger py-0" onclick="doDelete('${esc(sn.id)}', '${esc(sn.serial)}')" title="Delete"><i class="bi bi-trash"></i></button>` : ''}
      </td>` : ''}
    </tr>`).join('');
}

function esc(s) {
  const d = document.createElement('div');
  d.textContent = String(s ?? '');
  return d.innerHTML;
}

// ── Global search ──────────────────────────────────────────────────────────────
let searchTimer;
document.getElementById('globalSearch').addEventListener('input', function() {
  clearTimeout(searchTimer);
  const q = this.value.trim();
  if (q.length < 3) {
    document.getElementById('searchResults').classList.add('d-none');
    document.getElementById('searchStatus').textContent = '';
    return;
  }
  document.getElementById('searchStatus').textContent = 'Searching…';
  searchTimer = setTimeout(() => runSearch(q), 400);
});

async function runSearch(q) {
  const r = await fetch(`/api/serials?action=search&q=${encodeURIComponent(q)}`);
  const d = await r.json();
  const results = d.results || [];
  document.getElementById('searchStatus').textContent = results.length + ' result' + (results.length !== 1 ? 's' : '');
  const el = document.getElementById('searchResults');
  if (!results.length) { el.classList.add('d-none'); return; }
  el.classList.remove('d-none');
  document.getElementById('searchResultsTbody').innerHTML = results.map(r => `
    <tr>
      <td class="font-monospace fw-semibold small">${esc(r.serial)}</td>
      <td><a href="/inventory/serials?item_id=${r.item_id}" class="text-decoration-none">${esc(r.item_name)}</a></td>
      <td><span class="badge ${statusBadge[r.status] || 'bg-secondary'}">${statusLabel[r.status] || r.status}</span></td>
      <td>${r.customer_name ? esc(r.customer_name) + (r.account_number ? ' <span class="text-muted small">(' + esc(r.account_number) + ')</span>' : '') : '<span class="text-muted">—</span>'}</td>
      <td class="text-muted small">${r.dispatched_at ? r.dispatched_at.slice(0,10) + (r.dispatched_by_name ? ' by ' + esc(r.dispatched_by_name) : '') : '—'}</td>
    </tr>`).join('');
}

// ── Add serials ───────────────────────────────────────────────────────────────
const addModal = itemId ? new bootstrap.Modal(document.getElementById('addModal')) : null;
function openAddModal() {
  document.getElementById('addSerials').value = '';
  document.getElementById('addBatch').value   = '';
  document.getElementById('addNotes').value   = '';
  document.getElementById('addError').classList.add('d-none');
  document.getElementById('addSuccess').classList.add('d-none');
  addModal.show();
}

async function submitAdd() {
  const btn = document.getElementById('addBtn');
  btn.disabled = true;
  const fd = new FormData();
  fd.append('_csrf', csrf);
  fd.append('action', 'add');
  fd.append('item_id', itemId);
  fd.append('serials', document.getElementById('addSerials').value);
  fd.append('batch_number', document.getElementById('addBatch').value);
  fd.append('notes', document.getElementById('addNotes').value);

  const r = await fetch('/api/serials', { method: 'POST', body: fd });
  const d = await r.json();
  btn.disabled = false;

  if (!d.ok) {
    document.getElementById('addError').textContent = d.error || 'Failed';
    document.getElementById('addError').classList.remove('d-none');
    return;
  }

  let msg = `Added ${d.added} serial${d.added !== 1 ? 's' : ''}.`;
  if (d.duplicates?.length) msg += ` Skipped duplicates: ${d.duplicates.join(', ')}`;
  document.getElementById('addSuccess').textContent = msg;
  document.getElementById('addSuccess').classList.remove('d-none');
  document.getElementById('addError').classList.add('d-none');
  document.getElementById('addSerials').value = '';
  loadSerials();
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
const dispatchModal = itemId ? new bootstrap.Modal(document.getElementById('dispatchModal')) : null;
function openDispatch(id, serial) {
  document.getElementById('dispatchId').value = id;
  document.getElementById('dispatchSerial').textContent = serial;
  document.getElementById('dispatchCustomer').value = '';
  document.getElementById('dispatchInstallation').value = '';
  document.getElementById('dispatchOnu').value = '';
  document.getElementById('dispatchNotes').value = '';
  document.getElementById('dispatchError').classList.add('d-none');
  dispatchModal.show();
}

async function submitDispatch() {
  const fd = new FormData();
  fd.append('_csrf', csrf);
  fd.append('action', 'dispatch');
  fd.append('id', document.getElementById('dispatchId').value);
  fd.append('customer_id', document.getElementById('dispatchCustomer').value);
  fd.append('installation_id', document.getElementById('dispatchInstallation').value);
  fd.append('onu_unit_id', document.getElementById('dispatchOnu').value);
  fd.append('notes', document.getElementById('dispatchNotes').value);

  const r = await fetch('/api/serials', { method: 'POST', body: fd });
  const d = await r.json();

  if (!d.ok) {
    document.getElementById('dispatchError').textContent = d.error || 'Failed';
    document.getElementById('dispatchError').classList.remove('d-none');
    return;
  }
  dispatchModal.hide();
  loadSerials();
}

// ── Return / Retire / Delete ──────────────────────────────────────────────────
async function postAction(action, id, extra = {}) {
  const fd = new FormData();
  fd.append('_csrf', csrf);
  fd.append('action', action);
  fd.append('id', id);
  for (const [k, v] of Object.entries(extra)) fd.append(k, v);
  const r = await fetch('/api/serials', { method: 'POST', body: fd });
  const d = await r.json();
  if (!d.ok) { alert(d.error || 'Action failed'); return false; }
  loadSerials();
  return true;
}

async function doReturn(id) {
  if (confirm('Return this serial to stock?')) postAction('return', id);
}
async function doRetire(id) {
  const notes = prompt('Reason for retirement (optional):');
  if (notes === null) return;
  postAction('retire', id, { notes });
}
async function doDelete(id, serial) {
  if (confirm(`Delete serial ${serial}? This cannot be undone.`)) postAction('delete', id);
}

// Init
if (itemId) loadSerials();
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
