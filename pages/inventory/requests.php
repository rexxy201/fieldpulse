<?php
require_once __DIR__ . '/../../config.php';
requireAuth();

$canView    = hasPermission('inventory.requests.view');
$canCreate  = hasPermission('inventory.requests.create');
$canApprove = hasPermission('inventory.requests.approve');
if (!$canView && !$canCreate) { header('Location: /dashboard'); exit; }

$me = currentUser();

// AJAX approve / reject
if (method() === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    verifyCsrf();
    if (!$canApprove) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }
    $action = $_POST['action'] ?? '';
    $reqId  = (int)($_POST['req_id'] ?? 0);
    $notes  = trim($_POST['review_notes'] ?? '');

    if ($action === 'approve') {
        $req = dbFetch("SELECT * FROM inv_stock_requests WHERE id=? AND status='pending'", [$reqId]);
        if (!$req) { echo json_encode(['ok'=>false,'msg'=>'Request not found or already processed']); exit; }
        $item = dbFetch("SELECT id,name,quantity FROM inv_items WHERE id=?", [$req['item_id']]);
        if (!$item) { echo json_encode(['ok'=>false,'msg'=>'Item not found']); exit; }
        $newQty = max(0, (int)$item['quantity'] - (int)$req['quantity']);
        try {
            db()->beginTransaction();
            dbRun("UPDATE inv_items SET quantity=? WHERE id=?", [$newQty, $item['id']]);
            dbRun("UPDATE inv_stock_requests SET status='approved', reviewed_by=?, reviewed_at=NOW(), review_notes=? WHERE id=?", [$me['id'], $notes, $reqId]);
            dbRun("INSERT INTO inv_stock_movements (item_id,type,quantity,source,reference_id,notes,performed_by) VALUES (?,?,?,?,?,?,?)",
                [$item['id'],'outbound',$req['quantity'],'request_approved',$reqId,'Approved request #'.$reqId, $me['id']]);
            db()->commit();
            echo json_encode(['ok'=>true,'new_qty'=>$newQty]);
        } catch (\Throwable $e) {
            db()->rollBack();
            echo json_encode(['ok'=>false,'msg'=>'Transaction failed']);
        }
        exit;
    }
    if ($action === 'reject') {
        dbRun("UPDATE inv_stock_requests SET status='rejected', reviewed_by=?, reviewed_at=NOW(), review_notes=? WHERE id=? AND status='pending'", [$me['id'], $notes, $reqId]);
        echo json_encode(['ok'=>true]); exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

$status = $_GET['status'] ?? 'all';
if (!in_array($status, ['all','pending','approved','rejected'], true)) $status = 'all';

// Scope: viewers see all; creators-only see their own
$where = []; $params = [];
if (!$canView) { $where[] = "sr.requested_by = ?"; $params[] = $me['id']; }
if ($status !== 'all') { $where[] = "sr.status = ?"; $params[] = $status; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$requests = dbFetchAll(
    "SELECT sr.*, i.name AS item_name, i.unique_code AS item_code, i.quantity AS current_qty,
            ru.name AS req_name, vu.name AS rev_name
     FROM inv_stock_requests sr
     JOIN inv_items i ON i.id = sr.item_id
     LEFT JOIN users ru ON ru.id = sr.requested_by
     LEFT JOIN users vu ON vu.id = sr.reviewed_by
     $whereSql ORDER BY sr.created_at DESC",
    $params
);

// Stats (same scope, ignoring status filter)
$statWhere = []; $statParams = [];
if (!$canView) { $statWhere[] = "requested_by = ?"; $statParams[] = $me['id']; }
$sw = $statWhere ? ' WHERE ' . implode(' AND ', $statWhere) : '';
$stats = dbFetch(
    "SELECT SUM(status='pending') pending, SUM(status='approved') approved, SUM(status='rejected') rejected
     FROM inv_stock_requests" . $sw, $statParams
);

$pageTitle = 'Stock Requests';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div><h2 class="fw-bold mb-0">Stock Requests</h2><div class="text-muted small"><?= $canView ? 'All requests' : 'Your requests' ?></div></div>
  <?php if ($canCreate): ?><a href="/inventory/request-new" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New Request</a><?php endif; ?>
</div>

<div class="row g-2 mb-3">
  <?php foreach ([
    ['Pending','#f59e0b',(int)($stats['pending']??0),'pending'],
    ['Approved','#10b981',(int)($stats['approved']??0),'approved'],
    ['Rejected','#ef4444',(int)($stats['rejected']??0),'rejected'],
    ['All','#64748b',(int)(($stats['pending']??0)+($stats['approved']??0)+($stats['rejected']??0)),'all'],
  ] as [$lbl,$clr,$val,$sf]): ?>
  <div class="col-6 col-md-3">
    <a href="?status=<?= $sf ?>" class="stat-card py-3 d-block text-decoration-none <?= $status===$sf?'border-primary':'' ?>">
      <div class="stat-value" style="font-size:1.5rem;color:<?= $clr ?>"><?= number_format($val) ?></div>
      <div class="stat-label"><?= $lbl ?></div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="card-section">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr>
        <th class="ps-3">#</th><th>Item</th><th class="text-center">Qty</th><th>Purpose</th><th>Requested By</th><th>Date</th><th class="text-center">Status</th>
        <?php if ($canApprove): ?><th class="text-end pe-3">Actions</th><?php endif; ?>
      </tr></thead>
      <tbody>
        <?php if (!$requests): ?>
        <tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-clipboard-check fs-2 d-block mb-2 opacity-25"></i>No requests found.</td></tr>
        <?php endif; ?>
        <?php foreach ($requests as $r):
          $sc = ['pending'=>'text-bg-warning','approved'=>'text-bg-success','rejected'=>'text-bg-danger'][$r['status']] ?? 'text-bg-secondary';
        ?>
        <tr id="req-<?= $r['id'] ?>">
          <td class="ps-3 small font-monospace text-muted">#<?= $r['id'] ?></td>
          <td>
            <div class="fw-semibold small"><?= htmlspecialchars($r['item_name']) ?></div>
            <div class="small text-muted">Stock: <strong><?= (int)$r['current_qty'] ?></strong></div>
          </td>
          <td class="text-center"><span class="badge text-bg-light border"><?= $r['quantity'] ?></span></td>
          <td class="small"><?= $r['purpose'] ? htmlspecialchars($r['purpose']) : '<span class="text-muted">—</span>' ?></td>
          <td>
            <div class="small fw-semibold"><?= htmlspecialchars($r['req_name'] ?? '—') ?></div>
            <?php if ($r['rev_name']): ?><div class="small text-muted">by <?= htmlspecialchars($r['rev_name']) ?></div><?php endif; ?>
            <?php if ($r['review_notes']): ?><div class="small text-muted fst-italic text-truncate" style="max-width:160px" title="<?= htmlspecialchars($r['review_notes']) ?>">“<?= htmlspecialchars(mb_substr($r['review_notes'],0,40)) ?>”</div><?php endif; ?>
          </td>
          <td class="small text-muted text-nowrap"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
          <td class="text-center"><span class="badge <?= $sc ?>"><?= ucfirst($r['status']) ?></span></td>
          <?php if ($canApprove): ?>
          <td class="text-end pe-3">
            <?php if ($r['status']==='pending'): ?>
            <div class="d-inline-flex gap-1">
              <button class="btn btn-sm btn-success" onclick="review(<?= $r['id'] ?>,'approve')"><i class="bi bi-check-lg"></i></button>
              <button class="btn btn-sm btn-danger" onclick="review(<?= $r['id'] ?>,'reject')"><i class="bi bi-x-lg"></i></button>
            </div>
            <?php else: ?><span class="small text-muted">Done</span><?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canApprove): ?>
<div class="modal fade" id="reviewModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="rmTitle">Review Request</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="alert alert-danger py-2 d-none" id="rmErr"></div>
    <input type="hidden" id="rmId"><input type="hidden" id="rmAction">
    <label class="form-label fw-semibold">Note to requester (optional)</label>
    <textarea id="rmNotes" rows="3" class="form-control" placeholder="Add a comment or reason…"></textarea>
  </div>
  <div class="modal-footer"><button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary btn-sm" id="rmConfirm" onclick="confirmReview()">Confirm</button></div>
</div></div></div>

<script>
function reviewModalEl(){ return bootstrap.Modal.getOrCreateInstance(document.getElementById('reviewModal')); }
function review(id, action){
  document.getElementById('rmId').value=id; document.getElementById('rmAction').value=action;
  document.getElementById('rmNotes').value=''; document.getElementById('rmErr').classList.add('d-none');
  document.getElementById('rmTitle').textContent=(action==='approve'?'Approve':'Reject')+' Request #'+id;
  const b=document.getElementById('rmConfirm'); b.className='btn btn-sm '+(action==='approve'?'btn-success':'btn-danger'); b.textContent=action==='approve'?'Approve':'Reject';
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
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
