<?php
require_once __DIR__ . '/../../config.php';
requireAuth();
requirePermission('inventory.refill');

$me = currentUser();
$errors = []; $success = '';

if (method() === 'POST') {
    verifyCsrf();
    $itemId = (int)($_POST['item_id'] ?? 0);
    $qty    = max(1, (int)($_POST['quantity'] ?? 1));
    $notes  = trim($_POST['notes'] ?? '');
    if ($itemId <= 0) $errors[] = 'Please select an item.';
    if (empty($errors)) {
        $item = dbFetch("SELECT id,name,quantity FROM inv_items WHERE id=?", [$itemId]);
        if (!$item) { $errors[] = 'Item not found.'; }
        else {
            $newQty = (int)$item['quantity'] + $qty;
            try {
                db()->beginTransaction();
                dbRun("UPDATE inv_items SET quantity=? WHERE id=?", [$newQty, $itemId]);
                dbRun("INSERT INTO inv_stock_movements (item_id,type,quantity,source,notes,performed_by) VALUES (?,?,?,?,?,?)",
                    [$itemId,'inbound',$qty,'manual_refill',$notes, $me['id']]);
                db()->commit();
                $success = "Added <strong>$qty</strong> unit(s) to <strong>".htmlspecialchars($item['name'])."</strong>. New stock: <strong>$newQty</strong>.";
            } catch (\Throwable $e) { db()->rollBack(); $errors[] = 'Transaction failed.'; }
        }
    }
}

$preItem = (int)($_GET['item'] ?? 0);
$items = dbFetchAll("SELECT id,name,unique_code,quantity FROM inv_items ORDER BY name");
$recent = dbFetchAll(
    "SELECT sm.quantity,sm.created_at,i.name AS item_name,u.name AS who
     FROM inv_stock_movements sm JOIN inv_items i ON i.id=sm.item_id
     LEFT JOIN users u ON u.id=sm.performed_by
     WHERE sm.type='inbound' AND sm.source='manual_refill' ORDER BY sm.created_at DESC LIMIT 8"
);
$low = dbFetchAll("SELECT id,name,quantity FROM inv_items WHERE quantity<=5 ORDER BY quantity ASC, name LIMIT 8");

$pageTitle = 'Refill Stock';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
  <a href="/inventory/movements" class="text-muted text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to Movements</a>
  <h2 class="fw-bold mt-1 mb-0">Refill Stock</h2>
</div>

<?php if ($success): ?><div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i><?= $success ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card-section">
      <div class="card-header"><i class="bi bi-box-arrow-in-down me-1 text-primary"></i>Add Stock</div>
      <form method="POST" class="p-3">
        <div class="mb-3">
          <label class="form-label fw-semibold">Stock Item <span class="text-danger">*</span></label>
          <select name="item_id" id="itemSel" class="form-select" required onchange="updateInfo()">
            <option value="">— Select an item —</option>
            <?php foreach ($items as $it): ?>
            <option value="<?= $it['id'] ?>" data-qty="<?= $it['quantity'] ?>" <?= $preItem===(int)$it['id']?'selected':'' ?>>
              <?= htmlspecialchars($it['name']) ?><?= $it['unique_code']?' ('.htmlspecialchars($it['unique_code']).')':'' ?> — Qty: <?= $it['quantity'] ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label fw-semibold">Units to Add <span class="text-danger">*</span></label><input type="number" name="quantity" id="qtyInp" min="1" value="<?= (int)($_POST['quantity'] ?? 1) ?>" class="form-control" oninput="updateInfo()" required></div>
        <div class="alert alert-success py-2 d-none" id="preview"></div>
        <div class="mb-3"><label class="form-label fw-semibold">Notes / Supplier / Reference</label><input type="text" name="notes" value="<?= htmlspecialchars($_POST['notes'] ?? '') ?>" class="form-control" placeholder="e.g. PO #123, Received from Supplier X…"></div>
        <div class="d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-box-arrow-in-down me-1"></i>Confirm Refill</button><a href="/inventory/items" class="btn btn-outline-secondary">Cancel</a></div>
      </form>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card-section mb-3">
      <div class="card-header">Recent Refills</div>
      <div class="p-2">
        <?php if (!$recent): ?><p class="text-muted small text-center py-3 mb-0">No refills yet.</p><?php endif; ?>
        <?php foreach ($recent as $r): ?>
        <div class="d-flex align-items-center gap-2 px-2 py-2 border-bottom">
          <span class="badge text-bg-success"><i class="bi bi-plus-lg"></i></span>
          <div class="flex-grow-1 min-w-0"><div class="small fw-semibold text-truncate"><?= htmlspecialchars($r['item_name']) ?></div><div class="text-muted" style="font-size:.72rem">+<?= $r['quantity'] ?> · <?= htmlspecialchars($r['who'] ?? 'System') ?> · <?= date('d M', strtotime($r['created_at'])) ?></div></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card-section">
      <div class="card-header">Low Stock</div>
      <div class="p-2">
        <?php if (!$low): ?><p class="text-success small text-center py-3 mb-0"><i class="bi bi-check-circle me-1"></i>All items well stocked.</p><?php endif; ?>
        <?php foreach ($low as $r): $cls=$r['quantity']<=0?'text-bg-danger':'text-bg-warning'; ?>
        <div class="d-flex align-items-center gap-2 px-2 py-2 border-bottom">
          <span class="badge <?= $cls ?>"><?= $r['quantity'] ?></span>
          <a href="?item=<?= $r['id'] ?>" class="small flex-grow-1 text-truncate text-decoration-none text-dark"><?= htmlspecialchars($r['name']) ?></a>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script>
function updateInfo(){
  const o=document.getElementById('itemSel').selectedOptions[0],p=document.getElementById('preview');
  if(!o||!o.value){p.classList.add('d-none');return;}
  const cur=parseInt(o.dataset.qty||0),add=Math.max(0,parseInt(document.getElementById('qtyInp').value||0));
  if(add<=0){p.classList.add('d-none');return;}
  p.classList.remove('d-none');
  p.innerHTML='<i class="bi bi-arrow-up me-1"></i>New stock level: <strong>'+cur+'</strong> → <strong>'+(cur+add)+'</strong> (+'+add+')';
}
document.addEventListener('DOMContentLoaded', updateInfo);
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
