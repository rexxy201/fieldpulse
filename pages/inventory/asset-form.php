<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/inventory_qr.php';
requireAuth();
requirePermission('inventory.assets.manage');

$id      = (int)($_GET['id'] ?? 0);
$editing = $id > 0;
$asset   = $editing ? dbFetch("SELECT * FROM inv_products WHERE id=?", [$id]) : null;
if ($editing && !$asset) { header('Location: /inventory/assets'); exit; }

$errors = [];
if (method() === 'POST') {
    verifyCsrf();
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $catId = (int)($_POST['category_id'] ?? 0) ?: null;
    if ($name === '') $errors[] = 'Asset name is required.';

    // Image upload (optional)
    $imageName = $asset['image'] ?? null;
    if (empty($errors) && !empty($_FILES['image']['name'])) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $errors[] = 'Invalid image type (jpg, png, gif, webp only).';
        } else {
            if (!is_dir(INV_UPLOAD_DIR)) @mkdir(INV_UPLOAD_DIR, 0775, true);
            $newName = 'prod_' . time() . '_' . rand(1000,9999) . '.' . $ext;
            if (move_uploaded_file($_FILES['image']['tmp_name'], INV_UPLOAD_DIR . $newName)) {
                if ($editing && !empty($asset['image']) && file_exists(INV_UPLOAD_DIR.$asset['image'])) @unlink(INV_UPLOAD_DIR.$asset['image']);
                $imageName = $newName;
            } else { $errors[] = 'Image upload failed.'; }
        }
    }

    if (empty($errors)) {
        if ($editing) {
            dbRun("UPDATE inv_products SET name=?, category_id=?, description=?, image=?, updated_at=NOW() WHERE id=?",
                [$name, $catId, $desc, $imageName, $id]);
            invEnsureAssetQr($id);
            auditLog('update','inv_asset',(string)$id);
        } else {
            $last = dbFetch("SELECT unique_code FROM inv_products WHERE unique_code LIKE 'AST-%' ORDER BY id DESC LIMIT 1");
            $next = 1;
            if ($last) { preg_match('/AST-(\d+)/', $last['unique_code'], $m); $next = (int)($m[1] ?? 0) + 1; }
            $code = 'AST-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
            dbRun("INSERT INTO inv_products (unique_code,name,category_id,description,image) VALUES (?,?,?,?,?)",
                [$code, $name, $catId, $desc, $imageName]);
            $newId = (int)db()->lastInsertId();
            $qf = 'qr_product_' . $newId . '.png';
            if (invGenerateQr(siteBaseUrl() . '/asset/' . $newId, $qf)) {
                dbRun("UPDATE inv_products SET qr_code=? WHERE id=?", [$qf, $newId]);
            }
            auditLog('create','inv_asset',(string)$newId);
        }
        header('Location: /inventory/assets?saved=1'); exit;
    }
}

$cats = dbFetchAll("SELECT id,name FROM inv_categories ORDER BY name");
$val = fn($k,$d='') => htmlspecialchars($_POST[$k] ?? $asset[$k] ?? $d);

$pageTitle = $editing ? 'Edit Asset' : 'Add Asset';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
  <a href="/inventory/assets" class="text-muted text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to Assets</a>
  <h2 class="fw-bold mt-1 mb-0"><?= $editing ? 'Edit Asset' : 'Add New Asset' ?></h2>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card-section">
      <div class="card-header"><i class="bi bi-pc-display me-1 text-primary"></i><?= $editing ? 'Asset Details' : 'New Asset' ?></div>
      <form method="POST" enctype="multipart/form-data" class="p-3">
        <div class="mb-3">
          <label class="form-label fw-semibold">Asset Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" required value="<?= $val('name') ?>" placeholder="e.g. Dell OptiPlex 7090">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Category</label>
          <select name="category_id" class="form-select">
            <option value="">— No category —</option>
            <?php foreach ($cats as $c): $sel = (string)($_POST['category_id'] ?? $asset['category_id'] ?? '') === (string)$c['id']; ?>
            <option value="<?= $c['id'] ?>" <?= $sel?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Description</label>
          <textarea name="description" rows="3" class="form-control" placeholder="Notes, specs, serial number…"><?= $val('description') ?></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Image</label>
          <input type="file" name="image" class="form-control" accept="image/*">
          <div class="form-text">jpg, png, gif, webp. <?= $editing && $asset['image'] ? 'Leave blank to keep current image.' : '' ?></div>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Asset</button>
          <a href="/inventory/assets" class="btn btn-outline-secondary">Cancel</a>
        </div>
        <?php if (!$editing): ?><p class="small text-muted mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>Asset code (AST-XXXX) and QR code are generated automatically.</p><?php endif; ?>
      </form>
    </div>
  </div>

  <?php if ($editing): ?>
  <div class="col-lg-5">
    <div class="card-section">
      <div class="card-header">Asset Info</div>
      <div class="p-3">
        <?php if (!empty($asset['image']) && file_exists(INV_UPLOAD_DIR.$asset['image'])): ?>
        <img src="/uploads/products/<?= htmlspecialchars($asset['image']) ?>" class="img-fluid rounded mb-3" style="max-height:200px">
        <?php endif; ?>
        <dl class="row small mb-0">
          <dt class="col-4 text-muted">Code</dt><dd class="col-8 font-monospace"><?= htmlspecialchars($asset['unique_code'] ?? '—') ?></dd>
          <dt class="col-4 text-muted">Created</dt><dd class="col-8"><?= date('d M Y', strtotime($asset['created_at'])) ?></dd>
        </dl>
        <?php if (!empty($asset['qr_code']) && file_exists(INV_QR_DIR.$asset['qr_code'])): ?>
        <hr>
        <div class="text-center">
          <img src="/uploads/qrcodes/<?= htmlspecialchars($asset['qr_code']) ?>" style="width:120px;height:120px;border:1px solid var(--border);border-radius:8px">
          <div class="mt-2"><a href="/uploads/qrcodes/<?= htmlspecialchars($asset['qr_code']) ?>" download class="btn btn-sm btn-outline-secondary"><i class="bi bi-download me-1"></i>Download QR</a></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
