<?php
require_once __DIR__ . '/../../config.php';
requireAuth();
requirePermission('inventory.items.manage');

$id      = (int)($_GET['id'] ?? 0);
$editing = $id > 0;
$item    = $editing ? dbFetch("SELECT * FROM inv_items WHERE id=?", [$id]) : null;
if ($editing && !$item) { header('Location: /inventory/items'); exit; }

$errors = [];
if (method() === 'POST') {
    verifyCsrf();
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $catId = (int)($_POST['category_id'] ?? 0) ?: null;
    $qty  = max(0, (int)($_POST['quantity'] ?? 0));
    $reorderThreshold = max(0, (int)($_POST['reorder_threshold'] ?? 5));
    if ($name === '') $errors[] = 'Item name is required.';

    $imageName = $item['image'] ?? null;
    if (empty($errors) && !empty($_FILES['image']['name'])) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) { $errors[] = 'Invalid image type.'; }
        else {
            if (!is_dir(INV_UPLOAD_DIR)) @mkdir(INV_UPLOAD_DIR, 0775, true);
            $newName = 'item_' . time() . '_' . rand(1000,9999) . '.' . $ext;
            if (move_uploaded_file($_FILES['image']['tmp_name'], INV_UPLOAD_DIR . $newName)) {
                if ($editing && !empty($item['image']) && file_exists(INV_UPLOAD_DIR.$item['image'])) @unlink(INV_UPLOAD_DIR.$item['image']);
                $imageName = $newName;
            } else { $errors[] = 'Image upload failed.'; }
        }
    }

    if (empty($errors)) {
        if ($editing) {
            // Note: editing quantity here is a direct correction (no ledger entry). Use Refill for tracked inbound.
            dbRun("UPDATE inv_items SET name=?, category_id=?, description=?, quantity=?, reorder_threshold=?, image=?, updated_at=NOW() WHERE id=?",
                [$name, $catId, $desc, $qty, $reorderThreshold, $imageName, $id]);
            auditLog('update','inv_item',(string)$id);
        } else {
            $last = dbFetch("SELECT unique_code FROM inv_items WHERE unique_code LIKE 'PRD-%' ORDER BY id DESC LIMIT 1");
            $next = 1;
            if ($last) { preg_match('/PRD-(\d+)/', $last['unique_code'], $m); $next = (int)($m[1] ?? 0) + 1; }
            $code = 'PRD-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
            dbRun("INSERT INTO inv_items (unique_code,name,category_id,description,quantity,reorder_threshold,image) VALUES (?,?,?,?,?,?,?)",
                [$code, $name, $catId, $desc, $qty, $reorderThreshold, $imageName]);
            $newId = (int)db()->lastInsertId();
            if ($qty > 0) {
                dbRun("INSERT INTO inv_stock_movements (item_id,type,quantity,source,notes,performed_by) VALUES (?,?,?,?,?,?)",
                    [$newId,'inbound',$qty,'initial','Opening balance', currentUser()['id']]);
            }
            auditLog('create','inv_item',(string)$newId);
        }
        header('Location: /inventory/items?saved=1'); exit;
    }
}

$cats = dbFetchAll("SELECT id,name FROM inv_categories ORDER BY name");
$val = fn($k,$d='') => htmlspecialchars($_POST[$k] ?? $item[$k] ?? $d);

$pageTitle = $editing ? 'Edit Item' : 'Add Item';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
  <a href="/inventory/items" class="text-muted text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to Stock Items</a>
  <h2 class="fw-bold mt-1 mb-0"><?= $editing ? 'Edit Stock Item' : 'Add Stock Item' ?></h2>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<div class="card-section" style="max-width:640px">
  <div class="card-header"><i class="bi bi-boxes me-1 text-primary"></i><?= $editing ? 'Item Details' : 'New Item' ?></div>
  <form method="POST" enctype="multipart/form-data" class="p-3">
    <div class="mb-3">
      <label class="form-label fw-semibold">Item Name <span class="text-danger">*</span></label>
      <input type="text" name="name" class="form-control" required value="<?= $val('name') ?>" placeholder="e.g. AA Batteries">
    </div>
    <div class="row g-3">
      <div class="col-sm-6 mb-3">
        <label class="form-label fw-semibold">Category</label>
        <select name="category_id" class="form-select">
          <option value="">— None —</option>
          <?php foreach ($cats as $c): $sel = (string)($_POST['category_id'] ?? $item['category_id'] ?? '') === (string)$c['id']; ?>
          <option value="<?= $c['id'] ?>" <?= $sel?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6 mb-3">
        <label class="form-label fw-semibold">Quantity</label>
        <input type="number" name="quantity" min="0" class="form-control" value="<?= (int)($_POST['quantity'] ?? $item['quantity'] ?? 0) ?>">
        <?php if ($editing): ?><div class="form-text">Direct correction — for tracked restocking use <a href="/inventory/refill?item=<?= $id ?>">Refill</a>.</div><?php endif; ?>
      </div>
      <div class="col-sm-6 mb-3">
        <label class="form-label fw-semibold">Reorder Threshold</label>
        <input type="number" name="reorder_threshold" min="0" class="form-control" value="<?= (int)($_POST['reorder_threshold'] ?? $item['reorder_threshold'] ?? 5) ?>">
        <div class="form-text">Flagged as low stock at or below this quantity. A router and a cable tie don't need the same number.</div>
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label fw-semibold">Description</label>
      <textarea name="description" rows="3" class="form-control" placeholder="Optional notes…"><?= $val('description') ?></textarea>
    </div>
    <div class="mb-3">
      <label class="form-label fw-semibold">Image</label>
      <input type="file" name="image" class="form-control" accept="image/*">
      <div class="form-text">jpg, png, gif, webp. <?= $editing && $item['image'] ? 'Leave blank to keep current.' : '' ?></div>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Item</button>
      <a href="/inventory/items" class="btn btn-outline-secondary">Cancel</a>
    </div>
    <?php if (!$editing): ?><p class="small text-muted mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>Item code (PRD-XXXX) is generated automatically.</p><?php endif; ?>
  </form>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
