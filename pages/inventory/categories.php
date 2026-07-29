<?php
require_once __DIR__ . '/../../config.php';
requireAuth();
requirePermission('inventory.categories.view');

$canManage = hasPermission('inventory.categories.manage');

// AJAX handler
if (method() === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    verifyCsrf();
    if (!$canManage) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? ''); $desc = trim($_POST['description'] ?? '');
        if ($name === '') { echo json_encode(['ok'=>false,'msg'=>'Name required']); exit; }
        try {
            dbRun("INSERT INTO inv_categories (name,description) VALUES (?,?)", [$name,$desc]);
            echo json_encode(['ok'=>true]);
        } catch (\Throwable $e) { echo json_encode(['ok'=>false,'msg'=>'Duplicate name or error']); }
        exit;
    }
    if ($action === 'edit') {
        $cid=(int)$_POST['id']; $name=trim($_POST['name']??''); $desc=trim($_POST['description']??'');
        if ($name === '') { echo json_encode(['ok'=>false,'msg'=>'Name required']); exit; }
        dbRun("UPDATE inv_categories SET name=?, description=? WHERE id=?", [$name,$desc,$cid]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'delete') {
        $cid=(int)$_POST['id'];
        dbRun("UPDATE inv_products SET category_id=NULL WHERE category_id=?", [$cid]);
        dbRun("UPDATE inv_items SET category_id=NULL WHERE category_id=?", [$cid]);
        dbRun("DELETE FROM inv_categories WHERE id=?", [$cid]);
        echo json_encode(['ok'=>true]); exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

$cats = dbFetchAll(
    "SELECT c.*,
            (SELECT COUNT(*) FROM inv_products p WHERE p.category_id=c.id) AS asset_count,
            (SELECT COUNT(*) FROM inv_items i WHERE i.category_id=c.id) AS item_count
     FROM inv_categories c ORDER BY c.name"
);

$pageTitle = 'Categories';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div><h2 class="fw-bold mb-0">Categories</h2><div class="text-muted small"><?= count($cats) ?> categor<?= count($cats)!==1?'ies':'y' ?></div></div>
  <?php if ($canManage): ?>
  <button class="btn btn-primary" onclick="openCat()"><i class="bi bi-plus-lg me-1"></i>Add Category</button>
  <?php endif; ?>
</div>

<div class="card-section">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th class="ps-3">Name</th><th>Description</th><th class="text-center">Assets</th><th class="text-center">Stock</th><?php if ($canManage): ?><th class="text-end pe-3">Actions</th><?php endif; ?></tr></thead>
      <tbody id="catBody">
        <?php if (!$cats): ?><tr><td colspan="5" class="text-center text-muted py-5"><i class="bi bi-tags fs-2 d-block mb-2 opacity-25"></i>No categories yet.</td></tr><?php endif; ?>
        <?php foreach ($cats as $c): ?>
        <tr id="catRow-<?= $c['id'] ?>">
          <td class="ps-3 fw-semibold"><?= htmlspecialchars($c['name']) ?></td>
          <td class="small text-muted text-truncate" style="max-width:280px"><?= htmlspecialchars($c['description'] ?? '—') ?></td>
          <td class="text-center"><span class="badge text-bg-light border"><?= $c['asset_count'] ?></span></td>
          <td class="text-center"><span class="badge text-bg-light border"><?= $c['item_count'] ?></span></td>
          <?php if ($canManage): ?>
          <td class="text-end pe-3">
            <div class="d-inline-flex gap-1">
              <button class="btn btn-sm btn-outline-secondary" onclick='openCat(<?= htmlspecialchars(json_encode(["id"=>$c["id"],"name"=>$c["name"],"description"=>$c["description"]??""]), ENT_QUOTES) ?>)'><i class="bi bi-pencil"></i></button>
              <button class="btn btn-sm btn-outline-danger" onclick="delCat(<?= $c['id'] ?>)"><i class="bi bi-trash3"></i></button>
            </div>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
<!-- Category Modal -->
<div class="modal fade" id="catModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="catModalTitle">Add Category</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="alert alert-danger py-2 d-none" id="catErr"></div>
    <input type="hidden" id="catId">
    <div class="mb-3"><label class="form-label fw-semibold">Name <span class="text-danger">*</span></label><input type="text" id="catName" class="form-control" placeholder="e.g. Electronics"></div>
    <div class="mb-0"><label class="form-label fw-semibold">Description</label><input type="text" id="catDesc" class="form-control" placeholder="Optional"></div>
  </div>
  <div class="modal-footer"><button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary btn-sm" onclick="saveCat()">Save</button></div>
</div></div></div>

<script>
function catModalEl(){ return bootstrap.Modal.getOrCreateInstance(document.getElementById('catModal')); }
function openCat(c){
  document.getElementById('catModalTitle').textContent = c ? 'Edit Category' : 'Add Category';
  document.getElementById('catId').value   = c ? c.id : '';
  document.getElementById('catName').value = c ? c.name : '';
  document.getElementById('catDesc').value = c ? (c.description||'') : '';
  document.getElementById('catErr').classList.add('d-none');
  catModalEl().show();
}
async function saveCat(){
  const id=document.getElementById('catId').value, name=document.getElementById('catName').value.trim(),
        desc=document.getElementById('catDesc').value.trim(), err=document.getElementById('catErr');
  if(!name){ err.textContent='Name is required.'; err.classList.remove('d-none'); return; }
  const fd=new FormData(); fd.append('ajax','1'); fd.append('action',id?'edit':'add'); if(id)fd.append('id',id); fd.append('name',name); fd.append('description',desc);
  const d=await(await fetch('',{method:'POST',body:fd})).json();
  if(!d.ok){ err.textContent=d.msg||'Error'; err.classList.remove('d-none'); return; }
  location.reload();
}
async function delCat(id){
  if(!confirm('Delete this category? Assets and items will be unlinked.'))return;
  const fd=new FormData(); fd.append('ajax','1'); fd.append('action','delete'); fd.append('id',id);
  const d=await(await fetch('',{method:'POST',body:fd})).json();
  if(d.ok) document.getElementById('catRow-'+id)?.remove();
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
