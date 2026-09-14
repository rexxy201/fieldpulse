<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$user       = currentUser();
$search     = trim($_GET['search'] ?? '');
$status     = $_GET['status'] ?? '';
$prio       = $_GET['priority'] ?? '';
$engineerId = $_GET['engineer'] ?? '';
$createdBy  = $_GET['createdBy'] ?? '';
// Presets resolve to concrete dates server-side; any explicit from/to without a
// preset (e.g. a direct link) still displays as "Custom" in the dropdown,
// rather than looking unselected — see includes/date-range.php.
['range' => $dateRange, 'from' => $dateFrom, 'to' => $dateTo] = resolveDateRange($_GET);
// Drill-down filters (mostly linked to from /reports; faultType also has a
// visible dropdown below — not exposed as visible dropdowns otherwise)
$department = $_GET['department'] ?? '';
$vendorId   = $_GET['vendor'] ?? '';
$faultType  = $_GET['faultType'] ?? '';
$olt        = $_GET['olt'] ?? '';
$customerId = $_GET['customerId'] ?? '';

$engineers  = dbFetchAll("SELECT id, name FROM users WHERE role IN ('engineer','noc_engineer') ORDER BY name");
// All fault types (not just enabled=1) — old tickets may reference one that's
// since been disabled, and filtering should still find them.
$faultTypes = dbFetchAll("SELECT id, name, category FROM fault_types ORDER BY category, name");

$where = []; $params = [];
// Scope by permission: view_all (everything), view_department (own dept), else own only
[$scopeSql, $scopeParams] = ticketScopeSql('t');
if ($scopeSql !== '') { $where[] = $scopeSql; $params = array_merge($params, $scopeParams); }

// Creators dropdown — scoped the same way as the list itself, so the options
// offered always match who could actually have created a ticket you can see.
$creators = dbFetchAll(
    "SELECT DISTINCT u.id, u.name FROM tickets t JOIN users u ON u.id = t.created_by"
    . ($scopeSql !== '' ? " WHERE $scopeSql" : '') . " ORDER BY u.name",
    $scopeParams
);

if ($status)     { $where[] = "t.status = ?"; $params[] = $status; }
if ($prio)       { $where[] = "t.priority = ?"; $params[] = $prio; }
if ($engineerId) { $where[] = "t.assigned_to = ?"; $params[] = $engineerId; }
if ($createdBy)  { $where[] = "t.created_by = ?"; $params[] = $createdBy; }
if ($dateFrom)   { $where[] = "t.created_at >= ?"; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo)     { $where[] = "t.created_at <= ?"; $params[] = $dateTo . ' 23:59:59'; }
if ($vendorId)   { $where[] = "t.vendor_id = ?"; $params[] = $vendorId; }
if ($faultType)  { $where[] = "t.fault_type_id = ?"; $params[] = $faultType; }
if ($olt)        { $where[] = "t.olt = ?"; $params[] = $olt; }
if ($customerId) { $where[] = "t.customer_id = ?"; $params[] = $customerId; }
if ($department) { $where[] = "t.fault_type_id IN (SELECT id FROM fault_types WHERE route_to = ?)"; $params[] = $department; }
if ($search) {
    $like = "%$search%";
    $where[] = "(t.description LIKE ? OR t.ticket_number LIKE ? OR t.customer_name LIKE ? OR cu.mailing_city LIKE ?)";
    array_push($params, $like, $like, $like, $like);
}

$whereSQL = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total    = (int)(dbFetch("SELECT COUNT(*) AS c FROM tickets t LEFT JOIN customers cu ON cu.id = t.customer_id" . $whereSQL, $params)['c'] ?? 0);
$tickets  = dbFetchAll(
    "SELECT t.*, u.name AS assigned_name, cb.name AS created_by_name, cu.mailing_city AS customer_mailing_city,
            mv.name AS maintenance_vendor_name
     FROM tickets t
     LEFT JOIN users u      ON u.id  = t.assigned_to
     LEFT JOIN users cb     ON cb.id = t.created_by
     LEFT JOIN customers cu ON cu.id = t.customer_id
     LEFT JOIN vendors mv   ON mv.id = t.maintenance_vendor_id
     $whereSQL
     ORDER BY t.created_at DESC LIMIT 500",
    $params
);

$canBulkUpdate = hasPermission('tickets.update');
$canBulkAssign = hasPermission('tickets.assign');

$pageTitle = 'Tickets';
require __DIR__ . '/../includes/header.php';
?>

<!-- Page heading -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h2 class="fw-bold mb-0">Tickets</h2>
    <div class="text-muted small">Manage and track all service requests.</div>
  </div>
  <?php if (hasPermission('tickets.create')): ?>
  <a href="/create-ticket" class="btn btn-primary">
    <i class="bi bi-plus-lg me-1"></i>New Ticket
  </a>
  <?php endif; ?>
</div>

<!-- Filter bar -->
<div class="card-section mb-0" style="border-radius:.75rem .75rem 0 0;border-bottom:0">
  <div class="p-3 d-flex gap-2 flex-wrap align-items-center">
    <div class="input-group flex-grow-1">
      <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
      <input type="text" id="searchInput" class="form-control border-start-0 ps-0"
        placeholder="Search tickets by ID, customer, or city…"
        value="<?= htmlspecialchars($search) ?>"
        oninput="debounceSearch(this.value)">
      <?php
      $_carryOver = array_filter(['status'=>$status,'priority'=>$prio,'engineer'=>$engineerId,'createdBy'=>$createdBy,'dateRange'=>$dateRange,'dateFrom'=>$dateFrom,'dateTo'=>$dateTo,'department'=>$department,'vendor'=>$vendorId,'faultType'=>$faultType,'olt'=>$olt,'customerId'=>$customerId]);
      ?>
      <?php if ($search): ?>
      <a href="/tickets<?= $_carryOver ? '?'.http_build_query($_carryOver) : '' ?>" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
      <?php endif; ?>
    </div>
    <select class="form-select" style="width:auto;min-width:150px" onchange="applyFilter('status',this.value)">
      <option value="" <?= !$status?'selected':'' ?>>All Status</option>
      <?php foreach(['new'=>'New','open'=>'Open','assigned'=>'Assigned','in_progress'=>'In Progress','pending_confirmation'=>'Pending','resolved'=>'Resolved','closed'=>'Closed'] as $v=>$l): ?>
      <option value="<?=$v?>" <?=$status===$v?'selected':''?>><?=$l?></option>
      <?php endforeach; ?>
    </select>
    <select class="form-select" style="width:auto;min-width:130px" onchange="applyFilter('priority',this.value)">
      <option value="" <?= !$prio?'selected':'' ?>>All Priorities</option>
      <?php foreach(['p1'=>'P1 – Critical','p2'=>'P2 – High','p3'=>'P3 – Medium','p4'=>'P4 – Low'] as $v=>$l): ?>
      <option value="<?=$v?>" <?=$prio===$v?'selected':''?>><?=$l?></option>
      <?php endforeach; ?>
    </select>
    <select class="form-select" style="width:auto;min-width:160px" onchange="applyFilter('engineer',this.value)">
      <option value="" <?= !$engineerId?'selected':'' ?>>All Engineers</option>
      <?php foreach ($engineers as $eng): ?>
      <option value="<?= $eng['id'] ?>" <?= $engineerId===$eng['id']?'selected':'' ?>><?= htmlspecialchars($eng['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="form-select" style="width:auto;min-width:160px" onchange="applyFilter('createdBy',this.value)">
      <option value="" <?= !$createdBy?'selected':'' ?>>All Creators</option>
      <?php foreach ($creators as $cr): ?>
      <option value="<?= $cr['id'] ?>" <?= $createdBy===$cr['id']?'selected':'' ?>><?= htmlspecialchars($cr['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="form-select" style="width:auto;min-width:180px" onchange="applyFilter('faultType',this.value)">
      <option value="" <?= !$faultType?'selected':'' ?>>All Fault Types</option>
      <?php $_ftCat = null; foreach ($faultTypes as $ft): if ($ft['category'] !== $_ftCat): if ($_ftCat !== null) echo '</optgroup>'; $_ftCat = $ft['category']; ?>
      <optgroup label="<?= htmlspecialchars($_ftCat ?: 'Other') ?>">
      <?php endif; ?>
      <option value="<?= $ft['id'] ?>" <?= $faultType===$ft['id']?'selected':'' ?>><?= htmlspecialchars($ft['name']) ?></option>
      <?php endforeach; if ($faultTypes) echo '</optgroup>'; ?>
    </select>
    <a href="/api/tickets-export<?= ($search||$_carryOver)?'?'.http_build_query(array_merge($_carryOver, array_filter(['search'=>$search]))):'' ?>"
       class="btn btn-outline-secondary text-nowrap">
      <i class="bi bi-download me-1"></i>Export CSV
    </a>
  </div>
  <div class="px-3 pb-3 border-top pt-3">
    <?php renderDateRangeFilter('/tickets', $dateRange, $dateFrom, $dateTo, $_carryOver); ?>
  </div>
</div>

<?php if ($canBulkUpdate || $canBulkAssign): ?>
<!-- Bulk action toolbar — hidden until at least one row is selected -->
<div id="bulkToolbar" class="d-none align-items-center gap-2 flex-wrap p-2 mb-2 rounded" style="background:#eff6ff;border:1px solid #93c5fd">
  <span class="small fw-semibold ms-1" id="bulkCount">0 selected</span>
  <?php if ($canBulkUpdate): ?>
  <select class="form-select form-select-sm" style="width:auto" id="bulkStatus">
    <option value="">Set status…</option>
    <option value="open">Open</option>
    <option value="in_progress">In Progress</option>
    <option value="pending_confirmation">Pending Confirmation</option>
  </select>
  <select class="form-select form-select-sm" style="width:auto" id="bulkPriority">
    <option value="">Set priority…</option>
    <option value="p1">P1 — Critical</option>
    <option value="p2">P2 — High</option>
    <option value="p3">P3 — Medium</option>
    <option value="p4">P4 — Low</option>
  </select>
  <?php endif; ?>
  <?php if ($canBulkAssign): ?>
  <select class="form-select form-select-sm" style="width:auto" id="bulkAssignee">
    <option value="">Assign to…</option>
    <?php foreach ($engineers as $e): ?>
    <option value="<?= htmlspecialchars($e['id']) ?>"><?= htmlspecialchars($e['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <button type="button" class="btn btn-sm btn-primary" onclick="applyBulkAction()" id="bulkApplyBtn">Apply</button>
  <span class="small text-muted" id="bulkStatusMsg"></span>
  <span class="text-muted small ms-auto" style="cursor:pointer" onclick="clearBulkSelection()"><i class="bi bi-x-lg me-1"></i>Clear selection</span>
  <div class="small text-muted w-100 ms-1">Resolving or closing isn't available in bulk — RCA is required per ticket, so update those individually.</div>
</div>
<?php endif; ?>

<!-- Tickets table -->
<div class="card-section" style="border-radius:0 0 .75rem .75rem">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle" id="ticketsTable">
      <thead style="background:#f8fafc;border-bottom:2px solid #e2e8f0">
        <tr>
          <th style="width:36px;padding-left:1rem">
            <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleAll(this)">
          </th>
          <th class="text-muted fw-semibold small text-uppercase" style="font-size:.72rem;letter-spacing:.05em">Ticket ID</th>
          <th class="text-muted fw-semibold small text-uppercase" style="font-size:.72rem;letter-spacing:.05em">Customer</th>
          <th class="text-muted fw-semibold small text-uppercase" style="font-size:.72rem;letter-spacing:.05em">City</th>
          <th class="text-muted fw-semibold small text-uppercase" style="font-size:.72rem;letter-spacing:.05em">Status</th>
          <th class="text-muted fw-semibold small text-uppercase" style="font-size:.72rem;letter-spacing:.05em">Priority</th>
          <th class="text-muted fw-semibold small text-uppercase" style="font-size:.72rem;letter-spacing:.05em">Assigned To</th>
          <th class="text-muted fw-semibold small text-uppercase" style="font-size:.72rem;letter-spacing:.05em">Created By</th>
          <th class="text-muted fw-semibold small text-uppercase" style="font-size:.72rem;letter-spacing:.05em">Created</th>
          <th class="text-muted fw-semibold small text-uppercase" style="font-size:.72rem;letter-spacing:.05em">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$tickets): ?>
        <tr><td colspan="10" class="text-center text-muted py-5">
          <i class="bi bi-ticket-perforated fs-2 d-block mb-2 opacity-25"></i>
          No tickets found<?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>
        </td></tr>
        <?php endif; ?>
        <?php foreach ($tickets as $t):
          // Status badge style
          $statusStyle = match($t['status'] ?? 'new') {
            'new'                  => 'color:#d97706;background:#fffbeb;border:1px solid #fcd34d',
            'open'                 => 'color:#3b82f6;background:#eff6ff;border:1px solid #93c5fd',
            'assigned'             => 'color:#2563eb;background:#eff6ff;border:1px solid #93c5fd',
            'in_progress'          => 'color:#7c3aed;background:#f5f3ff;border:1px solid #c4b5fd',
            'pending_confirmation' => 'color:#d97706;background:#fffbeb;border:1px solid #fcd34d',
            'resolved'             => 'color:#fff;background:#22c55e;border:1px solid #22c55e',
            'closed'               => 'color:#fff;background:#64748b;border:1px solid #64748b',
            default                => 'color:#64748b;background:#f8fafc;border:1px solid #e2e8f0',
          };
          $statusLabel = match($t['status'] ?? 'new') {
            'in_progress'         => 'IN PROGRESS',
            'pending_confirmation'=> 'PENDING',
            default               => strtoupper($t['status'] ?? 'NEW'),
          };
          // Priority colour
          $prioColor = match($t['priority'] ?? 'p3') {
            'p1' => '#dc2626',
            'p2' => '#ea580c',
            'p3' => '#3b82f6',
            'p4' => '#22c55e',
            default => '#64748b',
          };
          $created = $t['created_at'] ? date('n/j/Y', strtotime($t['created_at'])) : '—';
          // City/hub-scoped tickets carry their area name in customer_name
          // already (set at creation — see create-ticket.php); customer-scoped
          // tickets don't, so pull it from the linked customer's mailing_city.
          $cityDisplay = in_array($t['ticket_scope'] ?? 'customer', ['city', 'hub'], true)
              ? ($t['customer_name'] ?? '')
              : ($t['customer_mailing_city'] ?? '');
        ?>
        <tr>
          <td style="padding-left:1rem">
            <input type="checkbox" class="form-check-input row-check" value="<?= $t['id'] ?>">
          </td>
          <td>
            <a href="/ticket/<?= $t['id'] ?>" class="fw-semibold text-decoration-none" style="color:var(--primary)">
              <?= htmlspecialchars($t['ticket_number'] ?? '') ?>
            </a>
          </td>
          <td class="fw-semibold">
            <?php if (($t['ticket_scope'] ?? 'customer') === 'city'): ?>
              <i class="bi bi-map text-warning me-1" title="City / Area Outage"></i>
            <?php elseif (($t['ticket_scope'] ?? 'customer') === 'hub'): ?>
              <i class="bi bi-hdd-network text-primary me-1" title="Hub Outage"></i>
            <?php endif; ?>
            <?= htmlspecialchars($t['customer_name'] ?? '—') ?>
          </td>
          <td class="small text-muted" style="max-width:180px">
            <div class="text-truncate"><?= htmlspecialchars($cityDisplay !== '' ? $cityDisplay : '—') ?></div>
          </td>
          <td>
            <span style="<?= $statusStyle ?>;font-size:.72rem;font-weight:700;padding:.25rem .6rem;border-radius:.25rem;letter-spacing:.03em;white-space:nowrap">
              <?= $statusLabel ?>
            </span>
          </td>
          <td>
            <span class="fw-bold" style="color:<?= $prioColor ?>;font-size:.85rem">
              <?= strtoupper($t['priority'] ?? '') ?>
            </span>
          </td>
          <td class="small text-muted">
            <?php if ($t['assigned_name']): ?>
              <?= htmlspecialchars($t['assigned_name']) ?>
            <?php elseif ($t['maintenance_vendor_name']): ?>
              <span class="badge bg-light text-dark border" title="Routed to this vendor's whole team"><i class="bi bi-people-fill me-1"></i><?= htmlspecialchars($t['maintenance_vendor_name']) ?></span>
            <?php else: ?>
              Unassigned
            <?php endif; ?>
          </td>
          <td class="small text-muted"><?= htmlspecialchars($t['created_by_name'] ?? '—') ?></td>
          <td class="small text-muted text-nowrap"><?= $created ?></td>
          <td>
            <a href="/ticket/<?= $t['id'] ?>" class="btn btn-sm btn-link p-0 fw-semibold" style="color:var(--primary)">View</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="px-4 py-2 border-top d-flex align-items-center justify-content-between" style="background:#f8fafc">
    <span class="small text-muted" id="selectionInfo">
      <?= number_format($total) ?> ticket<?= $total !== 1 ? 's' : '' ?> total
      <?= count($tickets) >= 500 ? ' — showing first 500, refine your search for more' : '' ?>
    </span>
  </div>
</div>

<script>
// ── Debounced search ───────────────────────────────────────────────────────
let searchTimer;
function debounceSearch(val) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    const params = new URLSearchParams(window.location.search);
    val.trim() ? params.set('search', val.trim()) : params.delete('search');
    window.location.href = '/tickets' + (params.toString() ? '?' + params.toString() : '');
  }, 600);
}

function applyFilter(key, val) {
  const params = new URLSearchParams(window.location.search);
  val ? params.set(key, val) : params.delete(key);
  window.location.href = '/tickets' + (params.toString() ? '?' + params.toString() : '');
}

// Date Range filter lives in /assets/date-range.js (shared with Installations).

// ── Bulk checkbox ──────────────────────────────────────────────────────────
function toggleAll(master) {
  document.querySelectorAll('.row-check').forEach(cb => cb.checked = master.checked);
  updateSelectionInfo();
}
document.querySelectorAll('.row-check').forEach(cb => {
  cb.addEventListener('change', updateSelectionInfo);
});
function updateSelectionInfo() {
  const selected = document.querySelectorAll('.row-check:checked').length;
  const total    = document.querySelectorAll('.row-check').length;
  document.getElementById('selectionInfo').textContent = selected
    ? selected + ' ticket' + (selected !== 1 ? 's' : '') + ' selected'
    : '<?= number_format($total) ?> ticket<?= $total !== 1 ? "s" : "" ?> total';
  document.getElementById('selectAll').indeterminate = selected > 0 && selected < total;

  const toolbar = document.getElementById('bulkToolbar');
  if (toolbar) {
    toolbar.classList.toggle('d-none', selected === 0);
    toolbar.classList.toggle('d-flex', selected > 0);
    document.getElementById('bulkCount').textContent = selected + ' selected';
  }
}

function clearBulkSelection() {
  document.querySelectorAll('.row-check').forEach(cb => cb.checked = false);
  document.getElementById('selectAll').checked = false;
  updateSelectionInfo();
}

async function applyBulkAction() {
  const ids = Array.from(document.querySelectorAll('.row-check:checked')).map(cb => cb.value);
  if (!ids.length) return;
  const status     = document.getElementById('bulkStatus')?.value || '';
  const priority   = document.getElementById('bulkPriority')?.value || '';
  const assignedTo = document.getElementById('bulkAssignee')?.value || '';
  if (!status && !priority && !assignedTo) {
    document.getElementById('bulkStatusMsg').textContent = 'Pick something to change first.';
    return;
  }
  const btn = document.getElementById('bulkApplyBtn');
  btn.disabled = true;
  document.getElementById('bulkStatusMsg').textContent = 'Applying…';
  try {
    const res = await fetch('/api/tickets/bulk-update', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ids, status, priority, assignedTo }),
    });
    const data = await res.json();
    if (!res.ok) {
      document.getElementById('bulkStatusMsg').textContent = data.error || 'Something went wrong.';
      btn.disabled = false;
      return;
    }
    window.location.reload();
  } catch (e) {
    document.getElementById('bulkStatusMsg').textContent = 'Network error — please try again.';
    btn.disabled = false;
  }
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>