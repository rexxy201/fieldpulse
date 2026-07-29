<?php
require_once __DIR__ . '/../../config.php';
requireAuth();
requirePermission('inventory.assets.view');

$canManage = hasPermission('inventory.assets.manage');

// Delete
if (method() === 'POST' && isset($_POST['delete_id'])) {
    verifyCsrf();
    if (!$canManage) { header('Location: /inventory/assets'); exit; }
    $did = (int)$_POST['delete_id'];
    $row = dbFetch("SELECT image, qr_code FROM inv_products WHERE id=?", [$did]);
    if ($row) {
        if (!empty($row['image'])  && file_exists(INV_UPLOAD_DIR . $row['image']))  @unlink(INV_UPLOAD_DIR . $row['image']);
        if (!empty($row['qr_code'])&& file_exists(INV_QR_DIR . $row['qr_code']))     @unlink(INV_QR_DIR . $row['qr_code']);
        dbRun("DELETE FROM inv_cabinet_items WHERE product_id=?", [$did]);
        dbRun("DELETE FROM inv_products WHERE id=?", [$did]);
        auditLog('delete','inv_asset',(string)$did);
    }
    header('Location: /inventory/assets?deleted=1'); exit;
}

$search = trim($_GET['q'] ?? '');
$catF   = (int)($_GET['cat'] ?? 0);
$where = ['1=1']; $params = [];
if ($search) { $where[] = "(p.name LIKE ? OR p.unique_code LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($catF)   { $where[] = "p.category_id=?"; $params[] = $catF; }
$assets = dbFetchAll(
    "SELECT p.*, c.name AS cat_name FROM inv_products p
     LEFT JOIN inv_categories c ON c.id=p.category_id
     WHERE " . implode(' AND ', $where) . " ORDER BY p.created_at DESC",
    $params
);
$cats = dbFetchAll("SELECT id,name FROM inv_categories ORDER BY name");

$pageTitle = 'Assets';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div><h2 class="fw-bold mb-0">Assets</h2><div class="text-muted small"><?= count($assets) ?> asset<?= count($assets)!==1?'s':'' ?></div></div>
  <?php if ($canManage): ?>
  <a href="/inventory/asset-form" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Asset</a>
  <?php endif; ?>
</div>

<?php if (isset($_GET['deleted'])): ?><div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i>Asset deleted.</div><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i>Asset saved.</div><?php endif; ?>

<form method="GET" class="d-flex gap-2 mb-3 flex-wrap">
  <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="form-control" style="max-width:260px" placeholder="Search name or code…">
  <select name="cat" class="form-select" style="width:auto;min-width:150px">
    <option value="0">All Categories</option>
    <?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>" <?= $catF===(int)$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
  <?php if ($search || $catF): ?><a href="/inventory/assets" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
</form>

<div class="card-section">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr>
        <th class="ps-3">Asset</th><th>Code</th><th>Category</th><th>QR</th><th>Added</th><th class="text-end pe-3">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (!$assets): ?>
        <tr><td colspan="6" class="text-center text-muted py-5"><i class="bi bi-pc-display fs-2 d-block mb-2 opacity-25"></i>No assets found.</td></tr>
        <?php endif; ?>
        <?php foreach ($assets as $p): ?>
        <tr>
          <td class="ps-3">
            <div class="d-flex align-items-center gap-2">
              <?php if (!empty($p['image']) && file_exists(INV_UPLOAD_DIR.$p['image'])): ?>
              <img src="/uploads/products/<?= htmlspecialchars($p['image']) ?>" style="width:34px;height:34px;border-radius:6px;object-fit:cover;border:1px solid var(--border)">
              <?php else: ?>
              <div style="width:34px;height:34px;border-radius:6px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#94a3b8"><i class="bi bi-pc-display"></i></div>
              <?php endif; ?>
              <div class="min-w-0">
                <div class="fw-semibold text-truncate" style="max-width:200px"><?= htmlspecialchars($p['name']) ?></div>
                <?php if ($p['description']): ?><div class="small text-muted text-truncate" style="max-width:200px"><?= htmlspecialchars(mb_substr($p['description'],0,50)) ?></div><?php endif; ?>
              </div>
            </div>
          </td>
          <td class="small font-monospace text-muted"><?= htmlspecialchars($p['unique_code'] ?? '—') ?></td>
          <td><?= $p['cat_name'] ? '<span class="badge text-bg-light border">'.htmlspecialchars($p['cat_name']).'</span>' : '—' ?></td>
          <td>
            <?php if (!empty($p['qr_code']) && file_exists(INV_QR_DIR.$p['qr_code'])): ?>
            <a href="/uploads/qrcodes/<?= htmlspecialchars($p['qr_code']) ?>" download title="Download QR">
              <img src="/uploads/qrcodes/<?= htmlspecialchars($p['qr_code']) ?>" style="width:34px;height:34px;border:1px solid var(--border);border-radius:4px">
            </a>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="small text-muted text-nowrap"><?= date('d M Y', strtotime($p['created_at'])) ?></td>
          <td class="text-end pe-3">
            <div class="d-inline-flex gap-1">
              <a href="/asset/<?= $p['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Public view"><i class="bi bi-eye"></i></a>
              <?php if ($canManage): ?>
              <a href="/inventory/asset-form?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
              <form method="POST" class="d-inline" onsubmit="return confirm('Delete this asset?')">
                <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
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
