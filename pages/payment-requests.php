<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$user       = currentUser();
$role       = $user['role'];
$canCreate  = hasPermission('payment_requests.create');
$canView    = hasPermission('payment_requests.view');
$canApprove = hasPermission('payment_requests.approve');
if (!$canCreate && !$canView) { header('Location: /dashboard'); exit; }

$isVendor = $role === 'vendor';

const PR_CATEGORIES = ['Operational','Deployment/Expansion','Fiber Cut Restoration','Equipment','Inventory/Materials','Other'];

// ─── AJAX authorize / approve / reject / disburse ───────────────────────────
if (method() === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    verifyCsrf();
    if (!$canApprove) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }
    $action = $_POST['action'] ?? '';
    $reqId  = $_POST['req_id'] ?? '';
    $notes  = trim($_POST['review_notes'] ?? '');

    if ($action === 'authorize') {
        dbRun("UPDATE payment_requests SET status='authorized', authorized_by=?, authorized_by_name=?, authorized_at=NOW() WHERE id=? AND status='pending'",
            [$user['id'], $user['name'], $reqId]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'approve') {
        dbRun("UPDATE payment_requests SET status='approved', reviewed_by=?, reviewed_by_name=?, reviewed_at=NOW(), review_notes=? WHERE id=? AND status='authorized'",
            [$user['id'], $user['name'], $notes, $reqId]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'reject') {
        if ($notes === '') { echo json_encode(['ok'=>false,'msg'=>'A reason is required to reject.']); exit; }
        dbRun("UPDATE payment_requests SET status='rejected', reviewed_by=?, reviewed_by_name=?, reviewed_at=NOW(), review_notes=? WHERE id=? AND status IN ('pending','authorized','approved')",
            [$user['id'], $user['name'], $notes, $reqId]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'disburse') {
        $ref = trim($_POST['payment_reference'] ?? '');
        dbRun("UPDATE payment_requests SET status='disbursed', paid_at=NOW(), payment_reference=?, disbursed_by=?, disbursed_by_name=? WHERE id=? AND status='approved'",
            [$ref ?: null, $user['id'], $user['name'], $reqId]);
        echo json_encode(['ok'=>true]); exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

// ─── Create ─────────────────────────────────────────────────────────────────
$err = '';
if (method() === 'POST' && !isset($_POST['ajax'])) {
    verifyCsrf();
    $b = $_POST;
    if (($b['_action'] ?? '') === 'create') {
        $desc   = trim($b['description'] ?? '');
        $linkedType = in_array($b['linked_type'] ?? '', ['installation','ticket'], true) ? $b['linked_type'] : null;
        $linkedId   = $linkedType ? trim($b['linked_id'] ?? '') : null;
        $category   = in_array($b['category'] ?? '', PR_CATEGORIES, true) ? $b['category'] : '';
        $categoryOther = trim($b['category_other'] ?? '');
        $capexOpex  = in_array($b['capex_opex'] ?? '', ['Capex','Opex'], true) ? $b['capex_opex'] : '';
        $priority   = in_array($b['priority'] ?? '', ['High','Medium','Low'], true) ? $b['priority'] : '';
        $dateOfReq  = trim($b['date_of_request'] ?? '') ?: date('Y-m-d');
        $docCheck   = validatePaymentRequestDocuments($_FILES['documents'] ?? []);

        // Build + validate line items — empty rows (no description and no price) are dropped.
        $itemDescs  = $b['item_description'] ?? [];
        $itemQtys   = $b['item_qty'] ?? [];
        $itemPrices = $b['item_unit_price'] ?? [];
        $items = [];
        $grandTotal = 0.0;
        foreach ($itemDescs as $i => $d) {
            $d = trim($d);
            $qty   = is_numeric($itemQtys[$i] ?? '') ? (float)$itemQtys[$i] : 0;
            $price = is_numeric($itemPrices[$i] ?? '') ? (float)$itemPrices[$i] : 0;
            if ($d === '' && $qty == 0 && $price == 0) continue;
            $qty = $qty > 0 ? $qty : 1;
            $lineTotal = round($qty * $price, 2);
            $items[] = ['description' => $d, 'qty' => $qty, 'unit_price' => $price, 'line_total' => $lineTotal];
            $grandTotal += $lineTotal;
        }
        $grandTotal = round($grandTotal, 2);

        if ($desc === '') {
            $err = 'Reason / Description of Request is required.';
        } elseif (!$items) {
            $err = 'Add at least one line item with a description and amount.';
        } elseif ($grandTotal <= 0) {
            $err = 'Grand Total must be greater than zero.';
        } elseif ($category === '') {
            $err = 'Category is required.';
        } elseif ($category === 'Other' && $categoryOther === '') {
            $err = 'Please specify the category under "Other".';
        } elseif ($capexOpex === '') {
            $err = 'Capex / Opex is required.';
        } elseif ($priority === '') {
            $err = 'Priority is required.';
        } elseif ($linkedType && !$linkedId) {
            $err = 'Select the linked record, or set Link Type back to None.';
        } elseif (!$docCheck['ok']) {
            $err = $docCheck['error'];
        } else {
            // Vendors always attach their own company; staff may optionally attach
            // a vendor if the expense was incurred on that vendor's behalf.
            $vendorId = $isVendor ? ($user['vendor_id'] ?? null) : (trim($b['vendor_id'] ?? '') ?: null);
            // Ownership check on the linked record — vendors may only link their own jobs/tickets.
            if ($isVendor && $linkedType === 'installation') {
                $p = dbFetch("SELECT vendor_id FROM installation_profiles WHERE id=?", [$linkedId]);
                if (!$p || $p['vendor_id'] !== $vendorId) { $linkedId = null; $linkedType = null; }
            }
            if ($isVendor && $linkedType === 'ticket') {
                $t = dbFetch("SELECT vendor_id FROM tickets WHERE id=?", [$linkedId]);
                if (!$t || $t['vendor_id'] !== $vendorId) { $linkedId = null; $linkedType = null; }
            }
            $hubId = trim($b['hub_id'] ?? '') ?: getHubIdForCity($b['location'] ?? '');
            $newPrId = newUuid();
            dbRun("INSERT INTO payment_requests
                    (id,requester_id,requester_name,vendor_id,linked_type,linked_id,amount,description,status,
                     date_of_request,department,customer_name,customer_user_id,location,hub_id,category,category_other,
                     capex_opex,receiver,priority)
                   VALUES (?,?,?,?,?,?,?,?,'pending',?,?,?,?,?,?,?,?,?,?,?)",
                [$newPrId, $user['id'], $user['name'], $vendorId, $linkedType, $linkedId, $grandTotal, $desc,
                 $dateOfReq, trim($b['department']??'')?:null, trim($b['customer_name']??'')?:null, trim($b['customer_user_id']??'')?:null,
                 trim($b['location']??'')?:null, $hubId?:null, $category, $category==='Other'?$categoryOther:null,
                 $capexOpex, trim($b['receiver']??'')?:null, $priority]);
            foreach ($items as $idx => $it) {
                dbRun("INSERT INTO payment_request_items (id,payment_request_id,description,qty,unit_price,line_total,sort_order) VALUES (?,?,?,?,?,?,?)",
                    [newUuid(), $newPrId, $it['description'], $it['qty'], $it['unit_price'], $it['line_total'], $idx]);
            }
            if (!empty($_FILES['documents'])) savePaymentRequestDocuments($_FILES['documents'], $newPrId, $user['id']);
            auditLog('create','payment_request', $newPrId);
            header('Location: /payment-requests'); exit;
        }
    }
}

$status = $_GET['status'] ?? 'all';
if (!in_array($status, ['all','pending','authorized','approved','disbursed','rejected'], true)) $status = 'all';

// Scope: approvers/viewers see everyone's; vendors see their own vendor_id;
// everyone else sees only what they personally submitted.
$where = []; $params = [];
if (!$canView) {
    if ($isVendor && !empty($user['vendor_id'])) {
        $where[] = "pr.vendor_id = ?"; $params[] = $user['vendor_id'];
    } else {
        $where[] = "pr.requester_id = ?"; $params[] = $user['id'];
    }
}
if ($status !== 'all') { $where[] = "pr.status = ?"; $params[] = $status; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$requests = dbFetchAll(
    "SELECT pr.*, v.name AS vendor_name, h.name AS hub_name
     FROM payment_requests pr
     LEFT JOIN vendors v ON v.id = pr.vendor_id
     LEFT JOIN hubs h ON h.id = pr.hub_id
     $whereSql ORDER BY pr.created_at DESC LIMIT 500",
    $params
);

// Resolve linked-record display labels
$installIds = array_values(array_unique(array_filter(array_map(fn($r) => $r['linked_type']==='installation' ? $r['linked_id'] : null, $requests))));
$ticketIds  = array_values(array_unique(array_filter(array_map(fn($r) => $r['linked_type']==='ticket' ? $r['linked_id'] : null, $requests))));
$installLabels = [];
if ($installIds) {
    $ph = implode(',', array_fill(0, count($installIds), '?'));
    foreach (dbFetchAll("SELECT id,name FROM installation_profiles WHERE id IN ($ph)", $installIds) as $ip) $installLabels[$ip['id']] = $ip['name'];
}
$ticketLabels = [];
if ($ticketIds) {
    $ph = implode(',', array_fill(0, count($ticketIds), '?'));
    foreach (dbFetchAll("SELECT id,ticket_number,customer_name FROM tickets WHERE id IN ($ph)", $ticketIds) as $tk) $ticketLabels[$tk['id']] = $tk['ticket_number'].' — '.$tk['customer_name'];
}

// Attached documents for the listed requests
$docsByRequest = [];
if ($requests) {
    $reqIds = array_column($requests, 'id');
    $ph = implode(',', array_fill(0, count($reqIds), '?'));
    foreach (dbFetchAll("SELECT id,payment_request_id,original_name,mime_type FROM payment_request_documents WHERE payment_request_id IN ($ph) ORDER BY created_at", $reqIds) as $d) {
        $docsByRequest[$d['payment_request_id']][] = $d;
    }
}

// Stats (same scope, ignoring status filter)
$sw = []; $sp = [];
if (!$canView) {
    if ($isVendor && !empty($user['vendor_id'])) { $sw[] = "vendor_id = ?"; $sp[] = $user['vendor_id']; }
    else { $sw[] = "requester_id = ?"; $sp[] = $user['id']; }
}
$swSql = $sw ? ' WHERE ' . implode(' AND ', $sw) : '';
$stats = dbFetch("SELECT SUM(status='pending') pending, SUM(status='authorized') authorized, SUM(status='approved') approved, SUM(status='disbursed') disbursed, SUM(status='rejected') rejected FROM payment_requests" . $swSql, $sp);

// Records available to link, scoped to the current user
if ($isVendor && !empty($user['vendor_id'])) {
    $linkInstalls = dbFetchAll("SELECT id,name FROM installation_profiles WHERE vendor_id=? ORDER BY created_at DESC", [$user['vendor_id']]);
    $linkTickets  = dbFetchAll("SELECT id,ticket_number,customer_name FROM tickets WHERE vendor_id=? ORDER BY created_at DESC", [$user['vendor_id']]);
} else {
    $linkInstalls = $canCreate ? dbFetchAll("SELECT id,name FROM installation_profiles ORDER BY created_at DESC LIMIT 500") : [];
    $linkTickets  = $canCreate ? dbFetchAll("SELECT id,ticket_number,customer_name FROM tickets ORDER BY created_at DESC LIMIT 500") : [];
}
$allVendors = (!$isVendor && $canCreate) ? dbFetchAll("SELECT id,name FROM vendors ORDER BY name") : [];
$hubs      = $canCreate ? dbFetchAll("SELECT id,name FROM hubs ORDER BY name") : [];
$locations = $canCreate ? dbFetchAll("SELECT name FROM locations ORDER BY name") : [];

$STATUS_LABELS = ['pending'=>'Pending','authorized'=>'Authorized','approved'=>'Approved','disbursed'=>'Disbursed','rejected'=>'Rejected'];
$STATUS_COLORS = ['pending'=>'text-bg-warning','authorized'=>'text-bg-info','approved'=>'text-bg-primary','disbursed'=>'text-bg-success','rejected'=>'text-bg-danger'];

$pageTitle = 'Payment Requests';
require __DIR__ . '/../includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h2 class="fw-bold mb-0">Payment Requests</h2>
    <div class="text-muted small"><?= $canView ? 'All requests' : 'Your requests' ?></div>
  </div>
  <div class="d-flex gap-2">
    <a href="/payment-request-template" target="_blank" class="btn btn-outline-secondary">
      <i class="bi bi-printer me-1"></i>Blank Voucher (Print/Download)
    </a>
    <?php if ($canCreate): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newRequestModal">
      <i class="bi bi-plus-lg me-1"></i>New Payment Request
    </button>
    <?php endif; ?>
  </div>
</div>

<div class="row g-2 mb-3">
  <?php foreach ([
    ['Pending','#f59e0b',(int)($stats['pending']??0),'pending'],
    ['Authorized','#0ea5e9',(int)($stats['authorized']??0),'authorized'],
    ['Approved','#3b82f6',(int)($stats['approved']??0),'approved'],
    ['Disbursed','#10b981',(int)($stats['disbursed']??0),'disbursed'],
    ['Rejected','#ef4444',(int)($stats['rejected']??0),'rejected'],
    ['All','#64748b',(int)(($stats['pending']??0)+($stats['authorized']??0)+($stats['approved']??0)+($stats['disbursed']??0)+($stats['rejected']??0)),'all'],
  ] as [$lbl,$clr,$val,$sf]): ?>
  <div class="col-6 col-md-4 col-xl-2">
    <a href="?status=<?= $sf ?>" class="stat-card py-2 d-block text-center text-decoration-none <?= $status===$sf?'border-primary':'' ?>">
      <div class="fw-bold fs-5" style="color:<?= $clr ?>"><?= number_format($val) ?></div>
      <div style="font-size:.7rem;color:#64748b"><?= $lbl ?></div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="card-section">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr>
        <th class="ps-3">Requested By</th><th>Vendor</th><th>Category</th><th>Priority</th><th>Linked To</th><th class="text-end">Amount</th>
        <th>Docs</th><th>Date</th><th class="text-center">Status</th><th></th>
        <?php if ($canApprove): ?><th class="text-end pe-3">Actions</th><?php endif; ?>
      </tr></thead>
      <tbody>
        <?php if (!$requests): ?>
        <tr><td colspan="11" class="text-center text-muted py-5"><i class="bi bi-cash-coin fs-2 d-block mb-2 opacity-25"></i>No payment requests found.</td></tr>
        <?php endif; ?>
        <?php foreach ($requests as $r):
          $sc = $STATUS_COLORS[$r['status']] ?? 'text-bg-secondary';
          $linkLabel = $r['linked_type']==='installation' ? ($installLabels[$r['linked_id']] ?? null)
                     : ($r['linked_type']==='ticket' ? ($ticketLabels[$r['linked_id']] ?? null) : null);
          $reqDocs = $docsByRequest[$r['id']] ?? [];
          $prioColor = ['High'=>'text-danger','Medium'=>'text-warning','Low'=>'text-muted'][$r['priority']] ?? 'text-muted';
        ?>
        <tr id="pr-<?= $r['id'] ?>">
          <td class="ps-3 small fw-semibold"><?= htmlspecialchars($r['requester_name'] ?? '—') ?></td>
          <td class="small"><?= htmlspecialchars($r['vendor_name'] ?? '—') ?></td>
          <td class="small"><?= htmlspecialchars($r['category'] ?: '—') ?></td>
          <td class="small fw-semibold <?= $prioColor ?>"><?= htmlspecialchars($r['priority'] ?: '—') ?></td>
          <td class="small">
            <?php if ($linkLabel): ?>
            <span class="badge bg-light text-dark border"><i class="bi bi-<?= $r['linked_type']==='installation'?'wifi':'ticket-perforated' ?> me-1"></i><?= htmlspecialchars($linkLabel) ?></span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="text-end fw-semibold">₦<?= number_format((float)$r['amount'], 2) ?></td>
          <td class="small">
            <?php if ($reqDocs): ?>
            <?php foreach ($reqDocs as $d): ?>
            <a href="/api/payment-request-document?id=<?= $d['id'] ?>" target="_blank" rel="noopener" class="d-block text-truncate" style="max-width:120px" title="<?= htmlspecialchars($d['original_name']) ?>">
              <i class="bi bi-<?= $d['mime_type']==='application/pdf'?'file-earmark-pdf':'file-earmark-image' ?> me-1"></i><?= htmlspecialchars($d['original_name']) ?>
            </a>
            <?php endforeach; ?>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="small text-muted text-nowrap"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
          <td class="text-center"><span class="badge <?= $sc ?>"><?= $STATUS_LABELS[$r['status']] ?? ucfirst($r['status']) ?></span></td>
          <td><a href="/payment-request-print?id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0" title="Print / Download this record"><i class="bi bi-printer"></i></a></td>
          <?php if ($canApprove): ?>
          <td class="text-end pe-3">
            <?php if ($r['status']==='pending'): ?>
            <div class="d-inline-flex gap-1">
              <button class="btn btn-sm btn-info text-white" onclick="review('<?= $r['id'] ?>','authorize')" title="Authorize"><i class="bi bi-check-lg"></i> Authorize</button>
              <button class="btn btn-sm btn-danger" onclick="review('<?= $r['id'] ?>','reject')" title="Reject"><i class="bi bi-x-lg"></i></button>
            </div>
            <?php elseif ($r['status']==='authorized'): ?>
            <div class="d-inline-flex gap-1">
              <button class="btn btn-sm btn-primary" onclick="review('<?= $r['id'] ?>','approve')" title="Approve"><i class="bi bi-check-lg"></i> Approve</button>
              <button class="btn btn-sm btn-danger" onclick="review('<?= $r['id'] ?>','reject')" title="Reject"><i class="bi bi-x-lg"></i></button>
            </div>
            <?php elseif ($r['status']==='approved'): ?>
            <div class="d-inline-flex gap-1">
              <button class="btn btn-sm btn-outline-success" onclick="markDisbursed('<?= $r['id'] ?>')" title="Disburse"><i class="bi bi-cash-stack me-1"></i>Disburse</button>
              <button class="btn btn-sm btn-danger" onclick="review('<?= $r['id'] ?>','reject')" title="Reject"><i class="bi bi-x-lg"></i></button>
            </div>
            <?php else: ?><span class="small text-muted">Done</span><?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php if ($r['review_notes']): ?>
        <tr class="<?= $sc==='text-bg-danger'?'table-danger':'' ?>">
          <td></td>
          <td colspan="<?= $canApprove?'10':'9' ?>" class="small text-muted fst-italic py-1">
            <i class="bi bi-chat-left-quote me-1"></i><?= htmlspecialchars($r['reviewed_by_name'] ?? '') ?>: “<?= htmlspecialchars($r['review_notes']) ?>”
          </td>
        </tr>
        <?php endif; ?>
        <?php if ($r['status']==='disbursed'): ?>
        <tr class="table-success">
          <td></td>
          <td colspan="<?= $canApprove?'10':'9' ?>" class="small text-muted py-1">
            <i class="bi bi-check-circle me-1"></i>Disbursed <?= date('d M Y', strtotime($r['paid_at'])) ?><?= $r['payment_reference'] ? ' — Ref: '.htmlspecialchars($r['payment_reference']) : '' ?>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canCreate): ?>
<!-- ── New Payment Request Modal ──────────────────────────────────────────── -->
<div class="modal fade" id="newRequestModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="_action" value="create">
      <?= csrfField() ?>
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-cash-coin me-1 text-primary"></i>New Payment Request Voucher</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if ($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <p class="small fw-semibold text-muted text-uppercase mb-2">Requester &amp; Job Details</p>
        <div class="row g-2 mb-3">
          <div class="col-6"><label class="form-label small fw-semibold">Date of Request</label>
            <input type="date" name="date_of_request" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">Department</label><input type="text" name="department" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small fw-semibold">Customer Name</label><input type="text" name="customer_name" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small fw-semibold">Customer User ID</label><input type="text" name="customer_user_id" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small fw-semibold">Location / City</label>
            <input type="text" name="location" class="form-control form-control-sm" list="prLocationsList" onchange="autoSelectHub(this.value,'prHubId')">
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">POP (Hub)</label>
            <select name="hub_id" id="prHubId" class="form-select form-select-sm">
              <option value="">— Auto-detect or select —</option>
              <?php foreach ($hubs as $h): ?><option value="<?=$h['id']?>"><?= htmlspecialchars($h['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">Category <span class="text-danger">*</span></label>
            <select name="category" id="prCategory" class="form-select form-select-sm" onchange="document.getElementById('prCategoryOtherWrap').classList.toggle('d-none', this.value!=='Other')" required>
              <option value="">— Select —</option>
              <?php foreach (PR_CATEGORIES as $c): ?><option value="<?=$c?>"><?=$c?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 d-none" id="prCategoryOtherWrap"><label class="form-label small fw-semibold">Category — Other</label><input type="text" name="category_other" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small fw-semibold">Capex / Opex <span class="text-danger">*</span></label>
            <select name="capex_opex" class="form-select form-select-sm" required>
              <option value="">— Select —</option>
              <option value="Capex">Capex</option>
              <option value="Opex">Opex</option>
            </select>
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">Priority <span class="text-danger">*</span></label>
            <select name="priority" class="form-select form-select-sm" required>
              <option value="">— Select —</option>
              <option value="High">High</option>
              <option value="Medium" selected>Medium</option>
              <option value="Low">Low</option>
            </select>
          </div>
          <div class="col-12"><label class="form-label small fw-semibold">Reason / Description of Request <span class="text-danger">*</span></label>
            <textarea name="description" class="form-control form-control-sm" rows="2" required></textarea>
          </div>
        </div>

        <p class="small fw-semibold text-muted text-uppercase mb-2">Request Breakdown</p>
        <table class="table table-sm mb-2" id="itemsTable">
          <thead class="table-light"><tr><th>Description</th><th style="width:90px">Qty</th><th style="width:130px">Unit Price (₦)</th><th style="width:130px" class="text-end">Total (₦)</th><th style="width:36px"></th></tr></thead>
          <tbody id="itemsBody">
            <tr>
              <td><input type="text" name="item_description[]" class="form-control form-control-sm"></td>
              <td><input type="number" step="0.01" min="0" name="item_qty[]" class="form-control form-control-sm item-qty" value="1" oninput="recalcItems()"></td>
              <td><input type="number" step="0.01" min="0" name="item_unit_price[]" class="form-control form-control-sm item-price" oninput="recalcItems()"></td>
              <td class="text-end small item-total pt-2">0.00</td>
              <td><button type="button" class="btn btn-sm btn-outline-danger py-0" onclick="removeItemRow(this)"><i class="bi bi-x"></i></button></td>
            </tr>
          </tbody>
        </table>
        <button type="button" class="btn btn-sm btn-outline-secondary mb-3" onclick="addItemRow()"><i class="bi bi-plus-lg me-1"></i>Add Line</button>
        <div class="text-end fw-bold mb-3">Grand Total: ₦<span id="grandTotalDisplay">0.00</span></div>

        <div class="row g-2 mb-3">
          <div class="col-6"><label class="form-label small fw-semibold">Receiver</label><input type="text" name="receiver" class="form-control form-control-sm" placeholder="Who will receive this payment/item?"></div>
          <?php if (!$isVendor && $allVendors): ?>
          <div class="col-6"><label class="form-label small fw-semibold">On behalf of Vendor <span class="text-muted fw-normal">(optional)</span></label>
            <select name="vendor_id" class="form-select form-select-sm">
              <option value="">— None —</option>
              <?php foreach ($allVendors as $v): ?><option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="col-6"><label class="form-label small fw-semibold">Link Type</label>
            <select name="linked_type" id="linkType" class="form-select form-select-sm" onchange="toggleLinkPicker(this.value)">
              <option value="">— None —</option>
              <option value="installation">Installation Job</option>
              <option value="ticket">Ticket</option>
            </select>
          </div>
          <div class="col-6 d-none" id="linkInstallWrap">
            <label class="form-label small fw-semibold">Installation Job</label>
            <select name="linked_id" id="linkInstallSelect" class="form-select form-select-sm" disabled>
              <option value="">— Select —</option>
              <?php foreach ($linkInstalls as $ip): ?><option value="<?= $ip['id'] ?>"><?= htmlspecialchars($ip['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 d-none" id="linkTicketWrap">
            <label class="form-label small fw-semibold">Ticket</label>
            <select name="linked_id" id="linkTicketSelect" class="form-select form-select-sm" disabled>
              <option value="">— Select —</option>
              <?php foreach ($linkTickets as $tk): ?><option value="<?= $tk['id'] ?>"><?= htmlspecialchars($tk['ticket_number'].' — '.$tk['customer_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small fw-semibold">Backing Documents <span class="text-muted fw-normal">(optional — up to 5, PDF/JPG/PNG)</span></label>
            <input type="file" name="documents[]" id="docsInput" class="form-control form-control-sm" multiple accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" onchange="checkDocsCount(this)">
            <div class="form-text text-danger d-none" id="docsCountWarn">You can attach at most 5 documents.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Submit Request</button>
      </div>
    </form>
  </div></div>
</div>
<datalist id="prLocationsList">
  <?php foreach ($locations as $l): ?><option value="<?= htmlspecialchars($l['name']) ?>"><?php endforeach; ?>
</datalist>
<?php endif; ?>

<?php if ($canApprove): ?>
<div class="modal fade" id="reviewModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="rmTitle">Review Request</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="alert alert-danger py-2 d-none" id="rmErr"></div>
    <input type="hidden" id="rmId"><input type="hidden" id="rmAction">
    <label class="form-label fw-semibold small" id="rmNotesLabel">Note (optional)</label>
    <textarea id="rmNotes" rows="3" class="form-control form-control-sm" placeholder="Add a comment or reason…"></textarea>
  </div>
  <div class="modal-footer"><button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary btn-sm" id="rmConfirm" onclick="confirmReview()">Confirm</button></div>
</div></div></div>

<div class="modal fade" id="paidModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Mark as Disbursed</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="alert alert-danger py-2 d-none" id="pmErr"></div>
    <input type="hidden" id="pmId">
    <label class="form-label fw-semibold small">Payment Reference <span class="text-muted fw-normal">(optional — transaction ID, cheque #, etc.)</span></label>
    <input type="text" id="pmRef" class="form-control form-control-sm">
  </div>
  <div class="modal-footer"><button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-sm btn-success" onclick="confirmMarkDisbursed()">Confirm Disbursed</button></div>
</div></div></div>

<script>
function reviewModalEl(){ return bootstrap.Modal.getOrCreateInstance(document.getElementById('reviewModal')); }
const ACTION_LABELS = { authorize:'Authorize', approve:'Approve', reject:'Reject' };
function review(id, action){
  document.getElementById('rmId').value=id; document.getElementById('rmAction').value=action;
  document.getElementById('rmNotes').value=''; document.getElementById('rmErr').classList.add('d-none');
  document.getElementById('rmTitle').textContent=(ACTION_LABELS[action]||action)+' Payment Request';
  document.getElementById('rmNotesLabel').textContent = action==='reject' ? 'Reason (required)' : 'Note (optional)';
  const b=document.getElementById('rmConfirm');
  b.className='btn btn-sm '+(action==='reject'?'btn-danger':'btn-primary');
  b.textContent=ACTION_LABELS[action]||action;
  reviewModalEl().show();
}
async function confirmReview(){
  const id=document.getElementById('rmId').value,action=document.getElementById('rmAction').value,notes=document.getElementById('rmNotes').value.trim(),err=document.getElementById('rmErr');
  const fd=new FormData();fd.append('ajax','1');fd.append('action',action);fd.append('req_id',id);fd.append('review_notes',notes);
  const d=await(await fetch('',{method:'POST',body:fd})).json();
  if(!d.ok){err.textContent=d.msg||'Error';err.classList.remove('d-none');return;}
  reviewModalEl().hide();
  location.reload();
}
function paidModalEl(){ return bootstrap.Modal.getOrCreateInstance(document.getElementById('paidModal')); }
function markDisbursed(id){
  document.getElementById('pmId').value=id; document.getElementById('pmRef').value='';
  document.getElementById('pmErr').classList.add('d-none');
  paidModalEl().show();
}
async function confirmMarkDisbursed(){
  const id=document.getElementById('pmId').value,ref=document.getElementById('pmRef').value.trim(),err=document.getElementById('pmErr');
  const fd=new FormData();fd.append('ajax','1');fd.append('action','disburse');fd.append('req_id',id);fd.append('payment_reference',ref);
  const d=await(await fetch('',{method:'POST',body:fd})).json();
  if(!d.ok){err.textContent=d.msg||'Error';err.classList.remove('d-none');return;}
  paidModalEl().hide();
  location.reload();
}
</script>
<?php endif; ?>

<?php if ($canCreate): ?>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
let linkInstallTS = null, linkTicketTS = null;
document.addEventListener('DOMContentLoaded', function () {
  linkInstallTS = new TomSelect('#linkInstallSelect', { create:false, sortField:{field:'text',direction:'asc'} });
  linkTicketTS  = new TomSelect('#linkTicketSelect',  { create:false, sortField:{field:'text',direction:'asc'} });
  <?php if ($err): ?>
  bootstrap.Modal.getOrCreateInstance(document.getElementById('newRequestModal')).show();
  <?php endif; ?>
});
function toggleLinkPicker(v) {
  // Both selects share name="linked_id" — only the visible one should be
  // submitted, so disable the hidden one (disabled inputs are excluded from
  // form submission) or it would silently overwrite the chosen value.
  document.getElementById('linkInstallWrap').classList.toggle('d-none', v !== 'installation');
  document.getElementById('linkInstallSelect').disabled = v !== 'installation';
  document.getElementById('linkTicketWrap').classList.toggle('d-none', v !== 'ticket');
  document.getElementById('linkTicketSelect').disabled = v !== 'ticket';
}
function checkDocsCount(input) {
  const warn = document.getElementById('docsCountWarn');
  const tooMany = input.files.length > 5;
  warn.classList.toggle('d-none', !tooMany);
  input.classList.toggle('is-invalid', tooMany);
}
var PR_CITY_HUB_MAP = <?= json_encode(array_column(dbFetchAll("SELECT LOWER(TRIM(city_name)) AS city_name, hub_id FROM hub_city_mappings"), 'hub_id', 'city_name')) ?>;
function autoSelectHub(cityValue, selectId) {
  var hubId = PR_CITY_HUB_MAP[(cityValue || '').trim().toLowerCase()];
  if (hubId) document.getElementById(selectId).value = hubId;
}

// ── Line-item breakdown ──────────────────────────────────────────────────
function addItemRow() {
  const tbody = document.getElementById('itemsBody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input type="text" name="item_description[]" class="form-control form-control-sm"></td>
    <td><input type="number" step="0.01" min="0" name="item_qty[]" class="form-control form-control-sm item-qty" value="1" oninput="recalcItems()"></td>
    <td><input type="number" step="0.01" min="0" name="item_unit_price[]" class="form-control form-control-sm item-price" oninput="recalcItems()"></td>
    <td class="text-end small item-total pt-2">0.00</td>
    <td><button type="button" class="btn btn-sm btn-outline-danger py-0" onclick="removeItemRow(this)"><i class="bi bi-x"></i></button></td>`;
  tbody.appendChild(tr);
}
function removeItemRow(btn) {
  const tbody = document.getElementById('itemsBody');
  if (tbody.rows.length > 1) btn.closest('tr').remove();
  recalcItems();
}
function recalcItems() {
  let grand = 0;
  document.querySelectorAll('#itemsBody tr').forEach(function (tr) {
    const qty = parseFloat(tr.querySelector('.item-qty')?.value) || 0;
    const price = parseFloat(tr.querySelector('.item-price')?.value) || 0;
    const total = qty * price;
    tr.querySelector('.item-total').textContent = total.toFixed(2);
    grand += total;
  });
  document.getElementById('grandTotalDisplay').textContent = grand.toFixed(2);
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
