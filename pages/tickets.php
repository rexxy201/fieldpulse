<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$user   = currentUser();
$role   = $user['role'];
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$prio   = $_GET['priority'] ?? '';

$where = []; $params = [];
if ($role === 'engineer') { $where[] = "t.assigned_to = ?"; $params[] = $user['id']; }
if ($status) { $where[] = "t.status = ?"; $params[] = $status; }
if ($prio)   { $where[] = "t.priority = ?"; $params[] = $prio; }
if ($search) {
    $like = "%$search%";
    $where[] = "(t.description LIKE ? OR t.ticket_number LIKE ? OR t.customer_name LIKE ? OR t.address LIKE ?)";
    array_push($params, $like, $like, $like, $like);
}

$whereSQL = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total    = (int)(dbFetch("SELECT COUNT(*) AS c FROM tickets t" . $whereSQL, $params)['c'] ?? 0);
$tickets  = dbFetchAll(
    "SELECT t.*, u.name AS assigned_name, cb.name AS created_by_name
     FROM tickets t
     LEFT JOIN users u  ON u.id  = t.assigned_to
     LEFT JOIN users cb ON cb.id = t.created_by
     $whereSQL
     ORDER BY t.created_at DESC LIMIT 500",
    $params
);

$pageTitle = 'Tickets';
require __DIR__ . '/../includes/header.php';
?>

<!-- Page heading -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h2 class="fw-bold mb-0">Tickets</h2>
    <div class="text-muted small">Manage and track all service requests.</div>
  </div>
  <?php if (in_array($role, ['admin','project_admin','supervisor-fiber','supervisor-noc','cx_supervisor'])): ?>
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
        placeholder="Search tickets by ID, customer, or address…"
        value="<?= htmlspecialchars($search) ?>"
        oninput="debounceSearch(this.value)">
      <?php if ($search): ?>
      <a href="/tickets<?= ($status||$prio)?'?'.http_build_query(array_filter(['status'=>$status,'priority'=>$prio])):'' ?>" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
      <?php endif; ?>
    </div>
    <select class="form-select" style="width:auto;min-width:150px" onchange="applyFilter('status',this.value)">
      <option value="" <?= !$status?'selected':'' ?>>All Statuses</option>
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
    <a href="/api/tickets-export<?= ($search||$status||$prio)?'?'.http_build_query(array_filter(['search'=>$search,'status'=>$status,'priority'=>$prio])):'' ?>"
       class="btn btn-outline-secondary text-nowrap">
      <i class="bi bi-download me-1"></i>Export CSV
    </a>
  </div>
</div>

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
          <th class="text-muted fw-semibold small text-uppercase" style="font-size:.72rem;letter-spacing:.05em">Address</th>
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
          <td class="fw-semibold"><?= htmlspecialchars($t['customer_name'] ?? '—') ?></td>
          <td class="small text-muted" style="max-width:180px">
            <div class="text-truncate"><?= htmlspecialchars($t['address'] ?? '—') ?></div>
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
          <td class="small text-muted"><?= htmlspecialchars($t['assigned_name'] ?? 'Unassigned') ?></td>
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
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
