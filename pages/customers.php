<?php
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('customers.view');

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';

$msg     = '';
$msgType = 'success';

if (method() === 'POST') {
    verifyCsrf();
    $b      = $_POST;
    $action = $b['_action'] ?? 'create';

    if ($action === 'delete' && hasPermission('customers.delete')) {
        dbRun("DELETE FROM customers WHERE id=?", [$b['id']]);
        auditLog('delete', 'customer', $b['id'], 'Customer deleted');
        $msg = 'Customer deleted.';

    } elseif ($action === 'create' && hasPermission('customers.create')) {
        $name = trim(($b['first_name']??'') . ' ' . ($b['last_name']??''));
        if (!$name) $name = $b['name'] ?? '';
        $newCustId = newUuid();
        dbRun("INSERT INTO customers
            (id,name,first_name,last_name,account_number,email,phone,address,mailing_city,mailing_state,plan,status,expiration)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$newCustId,$name,$b['first_name']??'',$b['last_name']??'',$b['account_number']??'',$b['email']??'',$b['phone']??'',$b['address']??'',$b['mailing_city']??'',$b['mailing_state']??'',$b['plan']??'',$b['status']??'active',$b['expiration']??null]);
        fireWebhooks('customer.created', ['id'=>$newCustId,'name'=>$name,'account_number'=>$b['account_number']??'','email'=>$b['email']??'','phone'=>$b['phone']??'']);
        $msg = 'Customer added.';

    } elseif ($action === 'update' && hasPermission('customers.update')) {
        $name = trim(($b['first_name']??'') . ' ' . ($b['last_name']??''));
        if (!$name) $name = $b['name'] ?? '';
        dbRun("UPDATE customers SET name=?,first_name=?,last_name=?,account_number=?,email=?,phone=?,address=?,mailing_city=?,mailing_state=?,plan=?,status=?,expiration=? WHERE id=?",
            [$name,$b['first_name']??'',$b['last_name']??'',$b['account_number']??'',$b['email']??'',$b['phone']??'',$b['address']??'',$b['mailing_city']??'',$b['mailing_state']??'',$b['plan']??'',$b['status']??'active',$b['expiration']??null,$b['id']]);
        fireWebhooks('customer.updated', ['id'=>$b['id'],'name'=>$name,'account_number'=>$b['account_number']??'','email'=>$b['email']??'','phone'=>$b['phone']??'']);
        $msg = 'Customer updated.';

    } elseif ($action === 'bulk_status' && hasPermission('customers.bulk')) {
        $ids    = array_filter(array_map('trim', explode(',', $b['ids'] ?? '')));
        $newSt  = in_array($b['new_status']??'', ['active','suspended','inactive']) ? $b['new_status'] : null;
        if ($ids && $newSt) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([$newSt], $ids);
            dbRun("UPDATE customers SET status=? WHERE id IN ($ph)", $params);
            auditLog('bulk_status', 'customer', implode(',', $ids), "Bulk status → $newSt (" . count($ids) . " customers)");
            $msg = count($ids) . ' customer' . (count($ids)!==1?'s':'') . ' set to ' . ucfirst($newSt) . '.';
        }

    } elseif ($action === 'bulk_sms' && hasPermission('customers.bulk')) {
        $ids     = array_filter(array_map('trim', explode(',', $b['ids'] ?? '')));
        $message = trim($b['sms_message'] ?? '');
        if ($ids && $message) {
            $ph      = implode(',', array_fill(0, count($ids), '?'));
            $phones  = dbFetchAll("SELECT id, phone FROM customers WHERE id IN ($ph) AND phone != '' AND phone IS NOT NULL", $ids);
            $sent    = 0; $failed = 0;
            foreach ($phones as $r) {
                $res = sendSms($r['phone'], $message);
                if (!empty($res['success'])) $sent++; else $failed++;
            }
            auditLog('bulk_sms', 'customer', implode(',', $ids), "Bulk SMS sent: $sent ok, $failed failed");
            $msg = "SMS sent: $sent delivered" . ($failed ? ", $failed failed" : '') . '.';
            if ($failed) $msgType = 'warning';
        }

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

// CSV export — all filtered customers
if (($_GET['export'] ?? '') === 'csv' && hasPermission('customers.export')) {
    $all = dbFetchAll("SELECT c.* FROM customers c" . $whereSQL . " ORDER BY c.name", $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="customers-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Account #','First Name','Last Name','Full Name','Phone','Email','Address','City','State','Plan','Status','Expiration']);
    foreach ($all as $r) {
        fputcsv($out, [
            $r['account_number'] ?? '',
            $r['first_name'] ?? '',
            $r['last_name'] ?? '',
            $r['name'] ?? '',
            $r['phone'] ?? '',
            $r['email'] ?? '',
            $r['address'] ?? '',
            $r['mailing_city'] ?? '',
            $r['mailing_state'] ?? '',
            $r['plan'] ?? '',
            $r['status'] ?? '',
            $r['expiration'] ? date('Y-m-d', strtotime($r['expiration'])) : '',
        ]);
    }
    fclose($out);
    exit;
}

$total     = (int)(dbFetch("SELECT COUNT(*) AS c FROM customers c" . $whereSQL, $params)['c'] ?? 0);
$customers = dbFetchAll("SELECT c.* FROM customers c" . $whereSQL . " ORDER BY c.name LIMIT 300", $params);

$custCanCreate = hasPermission('customers.create');
$custCanEdit   = hasPermission('customers.update');
$sharedLocations = dbFetchAll("SELECT name FROM locations ORDER BY name");
$custCanDelete = hasPermission('customers.delete');
$custCanBulk   = hasPermission('customers.bulk');
$custCanExport = hasPermission('customers.export');
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
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($custCanExport): ?>
    <a href="?export=csv<?= $search ? '&search='.urlencode($search) : '' ?><?= $status ? '&status='.urlencode($status) : '' ?>"
       class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-download me-1"></i>Export CSV
    </a>
    <?php endif; ?>
    <?php if (hasPermission('admin.access')): ?>
    <a href="/admin#tab-customer-data" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-upload me-1"></i>Bulk Upload
    </a>
    <?php endif; ?>
    <?php if (hasPermission('customers.create')): ?>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
      <i class="bi bi-plus-lg me-1"></i>Add Customer
    </button>
    <?php endif; ?>
  </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'warning' ? 'warning' : 'success' ?> alert-dismissible py-2 mb-3">
  <i class="bi bi-<?= $msgType === 'warning' ? 'exclamation-triangle' : 'check-circle' ?> me-1"></i><?= htmlspecialchars($msg) ?>
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
          <?php if ($custCanBulk): ?>
          <th style="width:36px">
            <input type="checkbox" id="selectAll" class="form-check-input" title="Select all visible">
          </th>
          <?php endif; ?>
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
          <?php if ($custCanBulk): ?><th class="p-1"></th><?php endif; ?>
          <th class="p-1"><input type="text" class="form-control form-control-sm col-filter" data-col="<?= $custCanBulk?1:0 ?>" placeholder="Filter name…"></th>
          <th class="p-1"><input type="text" class="form-control form-control-sm col-filter" data-col="<?= $custCanBulk?2:1 ?>" placeholder="Filter acct…"></th>
          <th class="p-1"><input type="text" class="form-control form-control-sm col-filter" data-col="<?= $custCanBulk?3:2 ?>" placeholder="Filter phone…"></th>
          <th class="p-1"><input type="text" class="form-control form-control-sm col-filter" data-col="<?= $custCanBulk?4:3 ?>" placeholder="Filter email…"></th>
          <th class="p-1"><input type="text" class="form-control form-control-sm col-filter" data-col="<?= $custCanBulk?5:4 ?>" placeholder="Filter city…"></th>
          <th class="p-1"><input type="text" class="form-control form-control-sm col-filter" data-col="<?= $custCanBulk?6:5 ?>" placeholder="Filter plan…"></th>
          <th class="p-1"><input type="text" class="form-control form-control-sm col-filter" data-col="<?= $custCanBulk?7:6 ?>" placeholder="Filter date…"></th>
          <th class="p-1">
            <select class="form-select form-select-sm col-filter" data-col="<?= $custCanBulk?8:7 ?>">
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
        <tr><td colspan="<?= 9 + ($custCanBulk?1:0) ?>" class="text-center text-muted py-5">
          <i class="bi bi-people fs-2 d-block mb-2 opacity-25"></i>
          No customers found<?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>
        </td></tr>
        <?php endif; ?>
        <?php foreach ($customers as $c):
          $displayName = $c['name'] ?? ($c['first_name'].' '.$c['last_name']);
          $city = $c['mailing_city'] ?? '';
          $exp  = $c['expiration'] ?? '';
        ?>
        <tr data-id="<?= $c['id'] ?>">
          <?php if ($custCanBulk): ?>
          <td><input type="checkbox" class="form-check-input row-check" value="<?= $c['id'] ?>"></td>
          <?php endif; ?>
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
                <?= csrfField() ?>
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

<?php if ($custCanBulk): ?>
<!-- Bulk action bar -->
<div id="bulkBar" class="d-none position-fixed bottom-0 start-50 translate-middle-x mb-4" style="z-index:1050;min-width:340px">
  <div class="card shadow-lg border-0" style="border-radius:1rem">
    <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
      <span class="fw-semibold small" id="bulkCount">0 selected</span>
      <div class="vr"></div>
      <div class="d-flex gap-2 flex-wrap">
        <div class="dropdown">
          <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
            <i class="bi bi-toggle-on me-1"></i>Set Status
          </button>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="#" onclick="bulkSetStatus('active')"><span class="badge bg-primary me-1">Active</span></a></li>
            <li><a class="dropdown-item" href="#" onclick="bulkSetStatus('suspended')"><span class="badge bg-warning text-dark me-1">Suspended</span></a></li>
            <li><a class="dropdown-item" href="#" onclick="bulkSetStatus('inactive')"><span class="badge bg-secondary me-1">Inactive</span></a></li>
          </ul>
        </div>
        <button class="btn btn-sm btn-outline-primary" onclick="openBulkSms()">
          <i class="bi bi-chat-dots me-1"></i>Send SMS
        </button>
        <button class="btn btn-sm btn-outline-secondary" onclick="exportSelected()">
          <i class="bi bi-download me-1"></i>Export
        </button>
      </div>
      <button class="btn btn-sm btn-link text-muted p-0 ms-1" onclick="clearSelection()" title="Clear selection">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
  </div>
</div>

<!-- Bulk status form (hidden) -->
<form method="POST" id="bulkStatusForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="_action" value="bulk_status">
  <input type="hidden" name="ids" id="bulkStatusIds">
  <input type="hidden" name="new_status" id="bulkStatusValue">
</form>

<!-- Bulk SMS Modal -->
<div class="modal fade" id="bulkSmsModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" id="bulkSmsForm">
        <?= csrfField() ?>
        <input type="hidden" name="_action" value="bulk_sms">
        <input type="hidden" name="ids" id="bulkSmsIds">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-chat-dots me-1 text-primary"></i>Send Bulk SMS</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-3">Message will be sent to all selected customers with a phone number on file.</p>
          <div class="mb-1 d-flex justify-content-between">
            <label class="form-label fw-semibold mb-0">Message</label>
            <span class="small text-muted" id="smsCharCount">0 / 160</span>
          </div>
          <textarea name="sms_message" id="smsMessage" class="form-control" rows="4"
            maxlength="320" placeholder="Type your message…" oninput="updateSmsCount(this.value)" required></textarea>
          <div class="mt-2 small text-muted" id="smsBulkInfo"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Send SMS</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Add Customer Modal -->
<?php if ($custCanCreate): ?>
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
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
        <?= csrfField() ?>
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
    if (row.querySelector('td[colspan]')) return;
    const cells = row.querySelectorAll('td');
    const show = Object.entries(filters).every(([col, val]) => {
      const cell = cells[parseInt(col)];
      return cell && cell.textContent.trim().toLowerCase().includes(val);
    });
    row.style.display = show ? '' : 'none';
    if (show) visible++;
  });

  let badge = document.getElementById('colFilterBadge');
  if (Object.keys(filters).length) {
    if (!badge) {
      badge = document.createElement('span');
      badge.id = 'colFilterBadge';
      badge.className = 'badge bg-primary ms-2';
      document.querySelector('#customersTable thead tr:first-child th:nth-child(<?= $custCanBulk?3:2 ?>)').appendChild(badge);
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

<?php if ($custCanBulk): ?>
// ── Bulk selection ─────────────────────────────────────────────────────────
const bulkBar   = document.getElementById('bulkBar');
const bulkCount = document.getElementById('bulkCount');

function getSelectedIds() {
  return [...document.querySelectorAll('.row-check:checked')].map(c => c.value);
}

function updateBulkBar() {
  const ids = getSelectedIds();
  if (ids.length > 0) {
    bulkCount.textContent = ids.length + ' selected';
    bulkBar.classList.remove('d-none');
  } else {
    bulkBar.classList.add('d-none');
  }
}

document.getElementById('selectAll').addEventListener('change', function() {
  document.querySelectorAll('.row-check').forEach(cb => {
    const row = cb.closest('tr');
    if (!row || row.style.display === 'none') return;
    cb.checked = this.checked;
  });
  updateBulkBar();
});

document.querySelectorAll('.row-check').forEach(cb => {
  cb.addEventListener('change', updateBulkBar);
});

function clearSelection() {
  document.querySelectorAll('.row-check').forEach(cb => cb.checked = false);
  document.getElementById('selectAll').checked = false;
  updateBulkBar();
}

function bulkSetStatus(status) {
  const ids = getSelectedIds();
  if (!ids.length) return;
  if (!confirm('Set ' + ids.length + ' customer(s) to ' + status + '?')) return;
  document.getElementById('bulkStatusIds').value   = ids.join(',');
  document.getElementById('bulkStatusValue').value = status;
  document.getElementById('bulkStatusForm').submit();
}

function openBulkSms() {
  const ids = getSelectedIds();
  if (!ids.length) return;
  document.getElementById('bulkSmsIds').value = ids.join(',');
  document.getElementById('smsBulkInfo').textContent = ids.length + ' customer(s) selected';
  document.getElementById('smsMessage').value = '';
  document.getElementById('smsCharCount').textContent = '0 / 160';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('bulkSmsModal')).show();
}

function updateSmsCount(val) {
  const len = val.length;
  const el  = document.getElementById('smsCharCount');
  el.textContent = len + ' / 160';
  el.className   = 'small ' + (len > 160 ? 'text-warning' : 'text-muted');
}

function exportSelected() {
  const rows  = [...document.querySelectorAll('.row-check:checked')].map(cb => cb.closest('tr'));
  const lines = ['Account #,Name,Phone,Email,City,Plan,Status,Expiration'];
  rows.forEach(row => {
    const cells = row.querySelectorAll('td');
    const off   = 1; // skip checkbox cell
    const esc   = v => '"' + (v||'').replace(/"/g,'""') + '"';
    lines.push([
      esc(cells[off+1]?.textContent.trim()),  // account
      esc(cells[off]?.textContent.trim()),    // name
      esc(cells[off+2]?.textContent.trim()),  // phone
      esc(cells[off+3]?.textContent.trim()),  // email
      esc(cells[off+4]?.textContent.trim()),  // city
      esc(cells[off+5]?.textContent.trim()),  // plan
      esc(cells[off+7]?.textContent.trim()),  // status
      esc(cells[off+6]?.textContent.trim()),  // expiration
    ].join(','));
  });
  const blob = new Blob([lines.join('\n')], {type:'text/csv'});
  const a    = document.createElement('a');
  a.href     = URL.createObjectURL(blob);
  a.download = 'customers-selected-' + new Date().toISOString().slice(0,10) + '.csv';
  a.click();
}
<?php endif; ?>
</script>
<datalist id="sharedLocationsList">
  <?php foreach ($sharedLocations as $sl): ?>
  <option value="<?= htmlspecialchars($sl['name']) ?>">
  <?php endforeach; ?>
</datalist>

<?php require __DIR__ . '/../includes/footer.php'; ?>
