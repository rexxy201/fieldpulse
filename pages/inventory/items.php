<?php
require_once __DIR__ . '/../../config.php';
requireAuth();
requirePermission('inventory.items.view');

$canManage  = hasPermission('inventory.items.manage');
$canRequest = hasPermission('inventory.requests.create');
$canRefill  = hasPermission('inventory.refill');

if (method() === 'POST' && isset($_POST['delete_id'])) {
    verifyCsrf();
    if (!$canManage) { header('Location: /inventory/items'); exit; }
    $did = (int)$_POST['delete_id'];
    $row = dbFetch("SELECT image FROM inv_items WHERE id=?", [$did]);
    if ($row && !empty($row['image']) && file_exists(INV_UPLOAD_DIR.$row['image'])) @unlink(INV_UPLOAD_DIR.$row['image']);
    dbRun("DELETE FROM inv_stock_movements WHERE item_id=?", [$did]);
    dbRun("DELETE FROM inv_stock_requests WHERE item_id=?", [$did]);
    dbRun("DELETE FROM inv_items WHERE id=?", [$did]);
    auditLog('delete','inv_item',(string)$did);
    header('Location: /inventory/items?deleted=1'); exit;
}

$search = trim($_GET['q'] ?? '');
$catF   = (int)($_GET['cat'] ?? 0);
$where = ['1=1']; $params = [];
if ($search) { $where[] = "(i.name LIKE ? OR i.unique_code LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($catF)   { $where[] = "i.category_id=?"; $params[] = $catF; }
$items = dbFetchAll(
    "SELECT i.*, c.name AS cat_name FROM inv_items i
     LEFT JOIN inv_categories c ON c.id=i.category_id
     WHERE " . implode(' AND ', $where) . " ORDER BY i.name",
    $params
);
$cats = dbFetchAll("SELECT id,name FROM inv_categories ORDER BY name");

$pageTitle = 'Stock Items';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div><h2 class="fw-bold mb-0">Stock Items</h2><div class="text-muted small"><?= count($items) ?> item<?= count($items)!==1?'s':'' ?></div></div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($canRequest): ?><a href="/inventory/request-new" class="btn btn-outline-secondary"><i class="bi bi-cart-plus me-1"></i>Request</a><?php endif; ?>
    <?php if ($canRefill): ?><a href="/inventory/refill" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-in-down me-1"></i>Refill</a><?php endif; ?>
    <?php if ($canManage): ?><a href="/inventory/item-form" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Item</a><?php endif; ?>
  </div>
</div>

<?php if (isset($_GET['deleted'])): ?><div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i>Item deleted.</div><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i>Item saved.</div><?php endif; ?>

<form method="GET" class="d-flex gap-2 mb-3 flex-wrap">
  <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="form-control" style="max-width:260px" placeholder="Search name or code…">
  <select name="cat" class="form-select" style="width:auto;min-width:150px">
    <option value="0">All Categories</option>
    <?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>" <?= $catF===(int)$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
  <?php if ($search || $catF): ?><a href="/inventory/items" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
</form>

<div class="card-section">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr>
        <th class="ps-3">Item</th><th>Code</th><th>Category</th><th class="text-center">Qty</th><th class="text-end pe-3">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (!$items): ?>
        <tr><td colspan="5" class="text-center text-muted py-5"><i class="bi bi-boxes fs-2 d-block mb-2 opacity-25"></i>No stock items found.</td></tr>
        <?php endif; ?>
        <?php foreach ($items as $it):
          $q = (int)$it['quantity'];
          $qcls = $q <= 0 ? 'text-bg-danger' : ($q <= 5 ? 'text-bg-warning' : 'text-bg-success');
        ?>
        <tr>
          <td class="ps-3">
            <div class="d-flex align-items-center gap-2">
              <?php if (!empty($it['image']) && file_exists(INV_UPLOAD_DIR.$it['image'])): ?>
              <img src="/uploads/products/<?= htmlspecialchars($it['image']) ?>" style="width:34px;height:34px;border-radius:6px;object-fit:cover;border:1px solid var(--border)">
              <?php else: ?>
              <div style="width:34px;height:34px;border-radius:6px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#94a3b8"><i class="bi bi-box"></i></div>
              <?php endif; ?>
              <div class="min-w-0">
                <div class="fw-semibold text-truncate" style="max-width:220px"><?= htmlspecialchars($it['name']) ?></div>
                <?php if ($it['description']): ?><div class="small text-muted text-truncate" style="max-width:220px"><?= htmlspecialchars(mb_substr($it['description'],0,50)) ?></div><?php endif; ?>
              </div>
            </div>
          </td>
          <td class="small font-monospace text-muted"><?= htmlspecialchars($it['unique_code'] ?? '—') ?></td>
          <td><?= $it['cat_name'] ? '<span class="badge text-bg-light border">'.htmlspecialchars($it['cat_name']).'</span>' : '—' ?></td>
          <td class="text-center"><span class="badge <?= $qcls ?>"><?= $q ?></span></td>
          <td class="text-end pe-3">
            <div class="d-inline-flex gap-1">
              <?php if ($canRequest): ?><a href="/inventory/request-new?item=<?= $it['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Request"><i class="bi bi-cart-plus"></i></a><?php endif; ?>
              <?php if ($canRefill): ?><a href="/inventory/refill?item=<?= $it['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Refill"><i class="bi bi-box-arrow-in-down"></i></a><?php endif; ?>
              <?php if ($canManage): ?>
              <a href="/inventory/item-form?id=<?= $it['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
              <form method="POST" class="d-inline" onsubmit="return confirm('Delete this item?')">
                <input type="hidden" name="delete_id" value="<?= $it['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash3"></i></button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
