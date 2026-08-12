<?php
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('customers.view');

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';

$msg = '';
if (method() === 'POST') {
    verifyCsrf();
    $b      = $_POST;
    $action = $b['_action'] ?? 'create';

    if ($action === 'delete' && hasPermission('customers.delete')) {
        dbRun("DELETE FROM customers WHERE id=?", [$b['id']]);
        $msg = 'Customer deleted.';
    } elseif ($action === 'create' && hasPermission('customers.create')) {
        $name = trim(($b['first_name']??'') . ' ' . ($b['last_name']??''));
        if (!$name) $name = $b['name'] ?? '';
        dbRun("INSERT INTO customers
            (id,name,first_name,last_name,account_number,email,phone,address,mailing_city,mailing_state,plan,status,expiration)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [newUuid(),$name,$b['first_name']??'',$b['last_name']??'',$b['account_number']??'',$b['email']??'',$b['phone']??'',$b['address']??'',$b['mailing_city']??'',$b['mailing_state']??'',$b['plan']??'',$b['status']??'active',$b['expiration']??null]);
        $msg = 'Customer added.';
    } elseif ($action === 'update' && hasPermission('customers.update')) {
        $name = trim(($b['first_name']??'') . ' ' . ($b['last_name']??''));
        if (!$name) $name = $b['name'] ?? '';
        dbRun("UPDATE customers SET name=?,first_name=?,last_name=?,account_number=?,email=?,phone=?,address=?,mailing_city=?,mailing_state=?,plan=?,status=?,expiration=? WHERE id=?",
            [$name,$b['first_name']??'',$b['last_name']??'',$b['account_number']??'',$b['email']??'',$b['phone']??'',$b['address']??'',$b['mailing_city']??'',$b['mailing_state']??'',$b['plan']??'',$b['status']??'active',$b['expiration']??null,$b['id']]);
        $msg = 'Customer updated.';
    }
    header('Location: /customers'); exit;
}

// Build query
$where = []; $params = [];
if ($search) {
    $like = "%$search%";
    $where[] = "(c.name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.account_number LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.mailing_city LIKE ?)";
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}
if ($status) {
    $where[] = "c.status = ?";
    $params[] = $status;
}
$whereSQL = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$total     = (int)(dbFetch("SELECT COUNT(*) AS c FROM customers c" . $whereSQL, $params)['c'] ?? 0);
$customers = dbFetchAll("SELECT c.* FROM customers c" . $whereSQL . " ORDER BY c.name LIMIT 300", $params);

$custCanCreate = hasPermission('customers.create');
$custCanEdit   = hasPermission('customers.update');
$sharedLocations = dbFetchAll("SELECT name FROM locations ORDER BY name");
$custCanDelete = hasPermission('customers.delete');
$custCanAct    = $custCanEdit || $custCanDelete;

$pageTitle = 'Customers';
require __DIR__ . '/../includes/header.php';
?>

<!-- Page heading -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h2 class="fw-bold mb-0">Customers</h2>
    <div class="text-muted small"><?= number_format($total) ?> total customer<?= $total !== 1 ? 's' : '' ?></div>
  </div>
  <div class="d-flex gap-2">
    <?php if (hasPermission('admin.access')): ?>
    <a href="/admin#tab-customer-data" class="btn btn-outline-secondary">
      <i class="bi bi-upload me-1"></i>Bulk Upload
    </a>
    <?php endif; ?>
    <?php if (hasPermission('customers.create')): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
      <i class="bi bi-plus-lg me-1"></i>Add Customer
    </button>
    <?php endif; ?>
  </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-success alert-dismissible py-2 mb-3">
  <i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($msg) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Search + filter bar -->
<div class="card-section mb-0" style="border-radius:.75rem .75rem 0 0;border-bottom:0">
  <div class="p-3 d-flex gap-2 flex-wrap">
    <div class="input-group flex-grow-1">
      <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
      <input type="text" id="searchInput" class="form-control border-start-0 ps-0"
        placeholder="Search by first name, last name, account #, phone, email, or city…"
        value="<?= htmlspecialchars($search) ?>"
        oninput="debounceSearch(this.value)">
      <?php if ($search): ?>
      <a href="/customers<?= $status ? '?status='.urlencode($status) : '' ?>" class="btn btn-outline-secondary">
        <i class="bi bi-x-lg"></i>
      </a>
      <?php endif; ?>
    </div>
    <select class="form-select" style="width:auto;min-width:150px" onchange="applyStatusFilter(this.value)">
      <option value="" <?= !$status ? 'selected' : '' ?>>All Statuses</option>
      <option value="active"    <?= $status==='active'    ? 'selected' : '' ?>>Active</option>
      <option value="suspended" <?= $status==='suspended' ? 'selected' : '' ?>>Suspended</option>
      <option value="inactive"  <?= $status==='inactive'  ? 'selected' : '' ?>>Inactive</option>
    </select>
  </div>
</div>

<!-- Table -->
<div class="card-section" style="border-radius:0 0 .75rem .75rem">
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0 align-middle" id="customersTable">
      <thead class="table-light">
        <tr>
          <th style="min-width:180px">Customer Name</th>
          <th>Account # (User name)</th>
          <th>Phone</th>
          <th>Email</th>
          <th>City</th>
          <th>Service</th>
          <th>Expiration</th>
          <th>Status</th>
          <?php if ($custCanAct): ?><th style="width:70px"></th><?php endif; ?>
        </tr>
        <tr id="colFilterRow">
          <th class="p-1"><input type="text" class="form-control form-control-sm col-filter" data-col="0" placeholder="Filter name…"></th>
          <th class="p-1"><input type="text" class="form-control form-control-sm col-filter" data-col="1" placeholder="Filter acct…"></th>
          <th class="p-1"><input type="text" class="form-control form-control-sm col-filter" data-col="2" placeholder="Filter phone…"></th>
          <th class="p-1"><input type="text" class="form-control form-control-sm col-filter" data-col="3" placeholder="Filter email…"></th>
          <th class="p-1"><input type="text" class="form-control form-control-sm col-filter" data-col="4" placeholder="Filter city…"></th>
          <th class="p-1"><input type="text" class="form-control form-control-sm col-filter" data-col="5" placeholder="Filter plan…"></th>
          <th class="p-1"><input type="text" class="form-control form-control-sm col-filter" data-col="6" placeholder="Filter date…"></th>
          <th class="p-1">
            <select class="form-select form-select-sm col-filter" data-col="7">
              <option value="">All</option>
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
              <option value="inactive">Inactive</option>
            </select>
          </th>
          <?php if ($custCanAct): ?><th class="p-1 text-center">
            <button class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="clearColFilters()" title="Clear column filters"><i class="bi bi-x-lg"></i></button>
          </th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (!$customers): ?>
        <tr><td colspan="9" class="text-center text-muted py-5">
          <i class="bi bi-people fs-2 d-block mb-2 opacity-25"></i>
          No customers found<?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>
        </td></tr>
        <?php endif; ?>
        <?php foreach ($customers as $c):
          $displayName = $c['name'] ?? ($c['first_name'].' '.$c['last_name']);
          $city = $c['mailing_city'] ?? '';
          $exp  = $c['expiration'] ?? '';
        ?>
        <tr>
          <td class="fw-semibold"><?= htmlspecialchars($displayName) ?></td>
          <td class="small font-monospace text-muted"><?= htmlspecialchars($c['account_number'] ?? '') ?></td>
          <td class="small"><?= htmlspecialchars($c['phone'] ?? '') ?></td>
          <td class="small text-truncate" style="max-width:180px"><?= htmlspecialchars($c['email'] ?? '') ?></td>
          <td class="small"><?= htmlspecialchars($city) ?></td>
          <td class="small"><?= htmlspecialchars($c['plan'] ?? '') ?></td>
          <td class="small text-nowrap"><?= $exp ? date('Y-m-d', strtotime($exp)) : '—' ?></td>
          <td>
            <span class="badge <?= match($c['status'] ?? 'active') {
              'active'    => 'bg-primary',
              'suspended' => 'bg-warning text-dark',
              'inactive'  => 'bg-secondary',
              default     => 'bg-secondary'
            } ?>"><?= ucfirst($c['status'] ?? 'active') ?></span>
          </td>
          <?php if ($custCanAct): ?>
          <td>
            <div class="d-flex gap-1">
              <?php if ($custCanEdit): ?>
              <button class="btn btn-sm btn-link p-0 text-muted" title="Edit"
                onclick="openEdit(<?= htmlspecialchars(json_encode($c)) ?>)">
                <i class="bi bi-pencil"></i>
              </button>
              <?php endif; ?>
              <?php if ($custCanDelete): ?>
              <form method="POST" class="d-inline" onsubmit="return confirm('Delete <?= addslashes(htmlspecialchars($displayName)) ?>?')">
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-sm btn-link p-0 text-danger" title="Delete">
                  <i class="bi bi-trash3"></i>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($customers) >= 300): ?>
  <div class="px-3 py-2 border-top text-muted small">Showing first 300 results — refine your search to see more.</div>
  <?php endif; ?>
</div>

<!-- Add Customer Modal -->
<?php if ($custCanCreate): ?>
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="_action" value="create">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-person-plus me-1 text-primary"></i>Add Customer</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
              <input type="text" name="first_name" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Last Name</label>
              <input type="text" name="last_name" class="form-control">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Account #</label>
              <input type="text" name="account_number" class="form-control" placeholder="e.g. 02_0100">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" name="phone" class="form-control" placeholder="08033065348">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="email" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Address</label>
              <input type="text" name="address" class="form-control">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">City</label>
              <input type="text" name="mailing_city" class="form-control" list="sharedLocationsList" placeholder="e.g. Iponri">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">State / Region</label>
              <input type="text" name="mailing_state" class="form-control">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Service Plan</label>
              <input type="text" name="plan" class="form-control" placeholder="e.g. 5, 10, 100 Mbps">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Expiration Date</label>
              <input type="date" name="expiration" class="form-control">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" class="form-select">
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Customer</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Edit Customer Modal -->
<?php if ($custCanEdit): ?>
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" id="editForm">
        <input type="hidden" name="_action" value="update">
        <input type="hidden" name="id" id="editId">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil me-1 text-primary"></i>Edit Customer</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label fw-semibold">First Name</label>
              <input type="text" name="first_name" id="editFirstName" class="form-control">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Last Name</label>
              <input type="text" name="last_name" id="editLastName" class="form-control">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Account #</label>
              <input type="text" name="account_number" id="editAccount" class="form-control">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" name="phone" id="editPhone" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="email" id="editEmail" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Address</label>
              <input type="text" name="address" id="editAddress" class="form-control">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">City</label>
              <input type="text" name="mailing_city" id="editCity" class="form-control" list="sharedLocationsList">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">State / Region</label>
              <input type="text" name="mailing_state" id="editState" class="form-control">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Service Plan</label>
              <input type="text" name="plan" id="editPlan" class="form-control">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Expiration Date</label>
              <input type="date" name="expiration" id="editExpiration" class="form-control">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="editStatus" class="form-select">
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-1"></i>Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
// ── Column filters ─────────────────────────────────────────────────────────
document.querySelectorAll('.col-filter').forEach(input => {
  input.addEventListener('input', applyColFilters);
  input.addEventListener('change', applyColFilters);
});

function applyColFilters() {
  const filters = {};
  document.querySelectorAll('.col-filter').forEach(inp => {
    const v = inp.value.trim().toLowerCase();
    if (v) filters[parseInt(inp.dataset.col)] = v;
  });

  let visible = 0;
  document.querySelectorAll('#customersTable tbody tr').forEach(row => {
    if (row.querySelector('td[colspan]')) return; // empty-state row
    const cells = row.querySelectorAll('td');
    const show = Object.entries(filters).every(([col, val]) => {
      const cell = cells[parseInt(col)];
      return cell && cell.textContent.trim().toLowerCase().includes(val);
    });
    row.style.display = show ? '' : 'none';
    if (show) visible++;
  });

  // Show match count
  let badge = document.getElementById('colFilterBadge');
  if (Object.keys(filters).length) {
    if (!badge) {
      badge = document.createElement('span');
      badge.id = 'colFilterBadge';
      badge.className = 'badge bg-primary ms-2';
      document.querySelector('#customersTable thead tr:first-child th:first-child').appendChild(badge);
    }
    badge.textContent = visible + ' match' + (visible !== 1 ? 'es' : '');
  } else if (badge) {
    badge.remove();
  }
}

function clearColFilters() {
  document.querySelectorAll('.col-filter').forEach(inp => {
    inp.value = '';
    inp.dispatchEvent(new Event('input'));
  });
}

// ── Search with debounce ───────────────────────────────────────────────────
let searchTimer;
function debounceSearch(val) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    const status = new URLSearchParams(window.location.search).get('status') || '';
    const params = new URLSearchParams();
    if (val.trim()) params.set('search', val.trim());
    if (status)     params.set('status', status);
    window.location.href = '/customers' + (params.toString() ? '?' + params.toString() : '');
  }, 600);
}

function applyStatusFilter(val) {
  const search = new URLSearchParams(window.location.search).get('search') || '';
  const params = new URLSearchParams();
  if (search) params.set('search', search);
  if (val)    params.set('status', val);
  window.location.href = '/customers' + (params.toString() ? '?' + params.toString() : '');
}

// ── Edit modal ─────────────────────────────────────────────────────────────
function openEdit(c) {
  document.getElementById('editId').value          = c.id || '';
  document.getElementById('editFirstName').value   = c.first_name || c.name?.split(' ')[0] || '';
  document.getElementById('editLastName').value    = c.last_name  || c.name?.split(' ').slice(1).join(' ') || '';
  document.getElementById('editAccount').value     = c.account_number || '';
  document.getElementById('editPhone').value       = c.phone || '';
  document.getElementById('editEmail').value       = c.email || '';
  document.getElementById('editAddress').value     = c.address || '';
  document.getElementById('editCity').value        = c.mailing_city || '';
  document.getElementById('editState').value       = c.mailing_state || '';
  document.getElementById('editPlan').value        = c.plan || '';
  document.getElementById('editExpiration').value  = c.expiration ? c.expiration.substring(0,10) : '';
  document.getElementById('editStatus').value      = c.status || 'active';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
}
</script>
<datalist id="sharedLocationsList">
  <?php foreach ($sharedLocations as $sl): ?>
  <option value="<?= htmlspecialchars($sl['name']) ?>">
  <?php endforeach; ?>
</datalist>

<?php require __DIR__ . '/../includes/footer.php'; ?>