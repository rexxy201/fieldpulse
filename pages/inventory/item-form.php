<?php
require_once __DIR__ . '/../../config.php';
requireAuth();
requirePermission('inventory.items.manage');

$id      = (int)($_GET['id'] ?? 0);
$editing = $id > 0;
$item    = $editing ? dbFetch("SELECT * FROM inv_items WHERE id=?", [$id]) : null;
if ($editing && !$item) { header('Location: /inventory/items'); exit; }

$UNITS = ['Pcs','Box','Meter','Roll','Kg','Liter','Set','Pair','Pack','Unit'];

$errors = [];
if (method() === 'POST') {
    verifyCsrf();
    $name             = trim($_POST['name'] ?? '');
    $sku              = trim($_POST['sku']  ?? '') ?: null;
    $desc             = trim($_POST['description'] ?? '');
    $catId            = (int)($_POST['category_id'] ?? 0) ?: null;
    $itemType         = in_array($_POST['item_type'] ?? '', ['inventory','service','non_inventory']) ? $_POST['item_type'] : 'inventory';
    $unit             = trim($_POST['unit'] ?? 'Pcs') ?: 'Pcs';
    $qty              = max(0, (int)($_POST['quantity'] ?? 0));
    $reorderThreshold = max(0, (int)($_POST['reorder_threshold'] ?? 5));
    $reorderQty       = max(1, (int)($_POST['reorder_qty'] ?? 1));
    $purchasePrice    = strlen(trim($_POST['purchase_price'] ?? '')) ? (float)$_POST['purchase_price'] : null;
    $sellingPrice     = strlen(trim($_POST['selling_price']  ?? '')) ? (float)$_POST['selling_price']  : null;
    $vendorId         = trim($_POST['vendor_id'] ?? '') ?: null;
    $zohoItemId       = trim($_POST['zoho_item_id'] ?? '') ?: null;

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
            dbRun(
                "UPDATE inv_items SET name=?, sku=?, item_type=?, unit=?, category_id=?, description=?,
                 quantity=?, reorder_threshold=?, reorder_qty=?, purchase_price=?, selling_price=?,
                 vendor_id=?, zoho_item_id=?, image=?, updated_at=NOW() WHERE id=?",
                [$name, $sku, $itemType, $unit, $catId, $desc, $qty, $reorderThreshold,
                 $reorderQty, $purchasePrice, $sellingPrice, $vendorId, $zohoItemId, $imageName, $id]
            );
            auditLog('update','inv_item',(string)$id);
        } else {
            $last = dbFetch("SELECT unique_code FROM inv_items WHERE unique_code LIKE 'PRD-%' ORDER BY id DESC LIMIT 1");
            $next = 1;
            if ($last) { preg_match('/PRD-(\d+)/', $last['unique_code'], $m); $next = (int)($m[1] ?? 0) + 1; }
            $code = 'PRD-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
            $skuFinal = $sku ?: $code;
            dbRun(
                "INSERT INTO inv_items (unique_code,sku,name,item_type,unit,category_id,description,quantity,
                 reorder_threshold,reorder_qty,purchase_price,selling_price,vendor_id,zoho_item_id,image)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$code, $skuFinal, $name, $itemType, $unit, $catId, $desc, $qty,
                 $reorderThreshold, $reorderQty, $purchasePrice, $sellingPrice, $vendorId, $zohoItemId, $imageName]
            );
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

$cats    = dbFetchAll("SELECT id,name FROM inv_categories ORDER BY name");
$vendors = dbFetchAll("SELECT id,name FROM vendors WHERE status='active' ORDER BY name");
$val     = fn($k,$d='') => htmlspecialchars((string)($_POST[$k] ?? $item[$k] ?? $d));

$pageTitle = $editing ? 'Edit Item' : 'Add Item';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
  <a href="/inventory/items" class="text-muted text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to Stock Items</a>
  <h2 class="fw-bold mt-1 mb-0"><?= $editing ? 'Edit Stock Item' : 'Add Stock Item' ?></h2>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<div class="card-section" style="max-width:700px">
  <div class="card-header"><i class="bi bi-boxes me-1 text-primary"></i><?= $editing ? 'Item Details' : 'New Item' ?></div>
  <form method="POST" enctype="multipart/form-data" class="p-3">

    <!-- Core identity -->
    <div class="row g-3 mb-3">
      <div class="col-sm-8">
        <label class="form-label fw-semibold">Item Name <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control" required value="<?= $val('name') ?>" placeholder="e.g. Huawei HG8310M ONU">
      </div>
      <div class="col-sm-4">
        <label class="form-label fw-semibold">Item Type</label>
        <select name="item_type" class="form-select">
          <?php foreach (['inventory'=>'Inventory','service'=>'Service','non_inventory'=>'Non-Inventory'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= $val('item_type','inventory')===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-sm-4">
        <label class="form-label fw-semibold">SKU</label>
        <input type="text" name="sku" class="form-control" value="<?= $val('sku') ?>" placeholder="Auto-generated if blank">
        <div class="form-text">Used for Zoho sync.</div>
      </div>
      <div class="col-sm-4">
        <label class="form-label fw-semibold">Unit</label>
        <select name="unit" class="form-select">
          <?php foreach ($UNITS as $u): ?>
          <option value="<?= $u ?>" <?= $val('unit','Pcs')===$u?'selected':'' ?>><?= $u ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-4">
        <label class="form-label fw-semibold">Category</label>
        <select name="category_id" class="form-select">
          <option value="">— None —</option>
          <?php foreach ($cats as $c): $sel=(string)($_POST['category_id']??$item['category_id']??'')===(string)$c['id']; ?>
          <option value="<?= $c['id'] ?>" <?= $sel?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Pricing -->
    <div class="row g-3 mb-3">
      <div class="col-sm-6">
        <label class="form-label fw-semibold">Purchase Price (cost)</label>
        <div class="input-group">
          <span class="input-group-text">$</span>
          <input type="number" name="purchase_price" step="0.01" min="0" class="form-control"
                 value="<?= $item['purchase_price'] ?? '' ?>" placeholder="0.00">
        </div>
      </div>
      <div class="col-sm-6">
        <label class="form-label fw-semibold">Selling Price</label>
        <div class="input-group">
          <span class="input-group-text">$</span>
          <input type="number" name="selling_price" step="0.01" min="0" class="form-control"
                 value="<?= $item['selling_price'] ?? '' ?>" placeholder="0.00">
        </div>
      </div>
    </div>

    <!-- Stock levels -->
    <div class="row g-3 mb-3">
      <div class="col-sm-4">
        <label class="form-label fw-semibold">Quantity on Hand</label>
        <input type="number" name="quantity" min="0" class="form-control" value="<?= (int)($_POST['quantity'] ?? $item['quantity'] ?? 0) ?>">
        <?php if ($editing): ?><div class="form-text">Direct correction — use <a href="/inventory/refill?item=<?= $id ?>">Refill</a> for tracked restocking.</div><?php endif; ?>
      </div>
      <div class="col-sm-4">
        <label class="form-label fw-semibold">Reorder Point</label>
        <input type="number" name="reorder_threshold" min="0" class="form-control" value="<?= (int)($_POST['reorder_threshold'] ?? $item['reorder_threshold'] ?? 5) ?>">
        <div class="form-text">Alert when qty falls to this level.</div>
      </div>
      <div class="col-sm-4">
        <label class="form-label fw-semibold">Reorder Qty</label>
        <input type="number" name="reorder_qty" min="1" class="form-control" value="<?= (int)($_POST['reorder_qty'] ?? $item['reorder_qty'] ?? 1) ?>">
        <div class="form-text">Default qty when creating a PO.</div>
      </div>
    </div>

    <!-- Vendor + Zoho -->
    <div class="row g-3 mb-3">
      <div class="col-sm-6">
        <label class="form-label fw-semibold">Preferred Vendor</label>
        <select name="vendor_id" class="form-select">
          <option value="">— None —</option>
          <?php foreach ($vendors as $v): $sel=(string)($_POST['vendor_id']??$item['vendor_id']??'')===(string)$v['id']; ?>
          <option value="<?= htmlspecialchars($v['id']) ?>" <?= $sel?'selected':'' ?>><?= htmlspecialchars($v['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6">
        <label class="form-label fw-semibold">Zoho Item ID</label>
        <input type="text" name="zoho_item_id" class="form-control" value="<?= $val('zoho_item_id') ?>" placeholder="From Zoho Inventory">
        <div class="form-text">Leave blank if not yet synced.</div>
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label fw-semibold">Description</label>
      <textarea name="description" rows="3" class="form-control" placeholder="Optional notes…"><?= $val('description') ?></textarea>
    </div>

    <div class="mb-3">
      <label class="form-label fw-semibold">Image</label>
      <input type="file" name="image" class="form-control" accept="image/*">
      <div class="form-text">jpg, png, gif, webp. <?= $editing && !empty($item['image']) ? 'Leave blank to keep current.' : '' ?></div>
    </div>

    <div class="d-flex gap-2">
      <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Item</button>
      <a href="/inventory/items" class="btn btn-outline-secondary">Cancel</a>
    </div>
    <?php if (!$editing): ?><p class="small text-muted mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>Item code (PRD-XXXX) is generated automatically if SKU is blank.</p><?php endif; ?>
  </form>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
