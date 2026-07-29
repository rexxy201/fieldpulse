<?php
require_once __DIR__ . '/../../config.php';
requireAuth();
requirePermission('inventory.requests.create');

$me = currentUser();
$errors = []; $success = '';

if (method() === 'POST') {
    verifyCsrf();
    $itemId = (int)($_POST['item_id'] ?? 0);
    $qty    = max(1, (int)($_POST['quantity'] ?? 1));
    $purpose = trim($_POST['purpose'] ?? '');
    $notes   = trim($_POST['notes'] ?? '');
    if ($itemId <= 0) $errors[] = 'Please select an item.';
    if (empty($errors)) {
        $item = dbFetch("SELECT id FROM inv_items WHERE id=?", [$itemId]);
        if (!$item) { $errors[] = 'Selected item not found.'; }
        else {
            dbRun("INSERT INTO inv_stock_requests (item_id,requested_by,quantity,purpose,notes,status) VALUES (?,?,?,?,?,'pending')",
                [$itemId, $me['id'], $qty, $purpose, $notes]);
            // Notify approvers in-app
            $approverRoles = [];
            foreach (ROLES as $r) {
                if (in_array('inventory.requests.approve', getPermissionsForRole($r), true)) $approverRoles[] = $r;
            }
            if ($approverRoles) {
                $iname = dbFetch("SELECT name FROM inv_items WHERE id=?", [$itemId])['name'] ?? 'item';
                notifyRoles($approverRoles, 'New Stock Request', "{$me['name']} requested {$qty} × {$iname}", '/inventory/requests?status=pending');
            }
            $success = 'Request submitted successfully. Awaiting approval.';
        }
    }
}

$preItem = (int)($_GET['item'] ?? 0);
$items = dbFetchAll("SELECT id,name,unique_code,quantity FROM inv_items ORDER BY name");
$myReq = dbFetchAll("SELECT sr.id,i.name AS item_name,sr.quantity,sr.status,sr.created_at FROM inv_stock_requests sr JOIN inv_items i ON i.id=sr.item_id WHERE sr.requested_by=? ORDER BY sr.created_at DESC LIMIT 5", [$me['id']]);

$pageTitle = 'New Stock Request';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
  <a href="/inventory/requests" class="text-muted text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to Requests</a>
  <h2 class="fw-bold mt-1 mb-0">New Stock Request</h2>
</div>

<?php if ($success): ?><div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($success) ?> <a href="/inventory/requests" class="fw-semibold">View requests →</a></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card-section">
      <div class="card-header"><i class="bi bi-cart-plus me-1 text-primary"></i>Request Details</div>
      <form method="POST" class="p-3">
        <div class="mb-3">
          <label class="form-label fw-semibold">Stock Item <span class="text-danger">*</span></label>
          <select name="item_id" id="itemSel" class="form-select" required onchange="updateHint()">
            <option value="">— Select an item —</option>
            <?php foreach ($items as $it): ?>
            <option value="<?= $it['id'] ?>" data-qty="<?= $it['quantity'] ?>" <?= $preItem===(int)$it['id']?'selected':'' ?>>
              <?= htmlspecialchars($it['name']) ?><?= $it['unique_code'] ? ' ('.htmlspecialchars($it['unique_code']).')' : '' ?> — Qty: <?= $it['quantity'] ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text" id="stockHint"></div>
        </div>
        <div class="mb-3"><label class="form-label fw-semibold">Quantity Requested <span class="text-danger">*</span></label><input type="number" name="quantity" min="1" value="<?= (int)($_POST['quantity'] ?? 1) ?>" class="form-control" required></div>
        <div class="mb-3"><label class="form-label fw-semibold">Purpose / Reason</label><input type="text" name="purpose" value="<?= htmlspecialchars($_POST['purpose'] ?? '') ?>" class="form-control" placeholder="e.g. Field repair, Office use…"></div>
        <div class="mb-3"><label class="form-label fw-semibold">Additional Notes</label><textarea name="notes" rows="3" class="form-control"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea></div>
        <div class="d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-send me-1"></i>Submit Request</button><a href="/inventory/requests" class="btn btn-outline-secondary">Cancel</a></div>
      </form>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card-section">
      <div class="card-header">Your Recent Requests</div>
      <div class="p-2">
        <?php if (!$myReq): ?><p class="text-muted small text-center py-3 mb-0">No requests yet.</p><?php endif; ?>
        <?php foreach ($myReq as $r): $sc=['pending'=>'text-bg-warning','approved'=>'text-bg-success','rejected'=>'text-bg-danger'][$r['status']]??'text-bg-secondary'; ?>
        <div class="d-flex align-items-center gap-2 px-2 py-2 border-bottom">
          <div class="flex-grow-1 min-w-0"><div class="small fw-semibold text-truncate"><?= htmlspecialchars($r['item_name']) ?></div><div class="text-muted" style="font-size:.72rem">Qty <?= $r['quantity'] ?> · <?= date('d M', strtotime($r['created_at'])) ?></div></div>
          <span class="badge <?= $sc ?>"><?= ucfirst($r['status']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script>
function updateHint(){
  const o=document.getElementById('itemSel').selectedOptions[0],h=document.getElementById('stockHint');
  if(!o||!o.value){h.textContent='';return;}
  const q=parseInt(o.dataset.qty||0);
  h.innerHTML = q<=0 ? '<span class="text-danger">Out of stock — will be reviewed before approval.</span>'
              : q<=5 ? '<span class="text-warning">Low stock: '+q+' remaining.</span>'
              : '<span class="text-success">In stock: '+q+' available.</span>';
}
document.addEventListener('DOMContentLoaded', updateHint);
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
