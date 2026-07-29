<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/inventory_qr.php';
requireAuth();
requirePermission('inventory.cabinets.view');

$canManage = hasPermission('inventory.cabinets.manage');

// AJAX
if (method() === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $writes = ['add','edit','delete','assign','unassign','add_custom','remove_custom'];
    if (in_array($action, $writes, true) && !$canManage) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? ''); $desc = trim($_POST['description'] ?? '');
        if ($name === '') { echo json_encode(['ok'=>false,'msg'=>'Name required']); exit; }
        dbRun("INSERT INTO inv_cabinets (name,description) VALUES (?,?)", [$name,$desc]);
        $cid = (int)db()->lastInsertId();
        $qf = 'qr_cabinet_' . $cid . '.png';
        if (invGenerateQr(siteBaseUrl() . '/cabinet/' . $cid, $qf)) dbRun("UPDATE inv_cabinets SET qr_code=? WHERE id=?", [$qf,$cid]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'edit') {
        $cid=(int)$_POST['id']; $name=trim($_POST['name']??''); $desc=trim($_POST['description']??'');
        if ($name === '') { echo json_encode(['ok'=>false,'msg'=>'Name required']); exit; }
        dbRun("UPDATE inv_cabinets SET name=?, description=? WHERE id=?", [$name,$desc,$cid]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'delete') {
        $cid=(int)$_POST['id'];
        $row = dbFetch("SELECT qr_code FROM inv_cabinets WHERE id=?", [$cid]);
        if ($row && !empty($row['qr_code']) && file_exists(INV_QR_DIR.$row['qr_code'])) @unlink(INV_QR_DIR.$row['qr_code']);
        dbRun("DELETE FROM inv_cabinet_items WHERE cabinet_id=?", [$cid]);
        dbRun("DELETE FROM inv_cabinet_custom_items WHERE cabinet_id=?", [$cid]);
        dbRun("DELETE FROM inv_cabinets WHERE id=?", [$cid]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'assign') {
        dbInsertIgnore("INSERT INTO inv_cabinet_items (cabinet_id,product_id) VALUES (?,?)", [(int)$_POST['cabinet_id'],(int)$_POST['product_id']]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'unassign') {
        dbRun("DELETE FROM inv_cabinet_items WHERE cabinet_id=? AND product_id=?", [(int)$_POST['cabinet_id'],(int)$_POST['product_id']]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'add_custom') {
        $label = trim($_POST['label'] ?? '');
        if ($label === '') { echo json_encode(['ok'=>false,'msg'=>'Label required']); exit; }
        dbRun("INSERT INTO inv_cabinet_custom_items (cabinet_id,label) VALUES (?,?)", [(int)$_POST['cabinet_id'],$label]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'remove_custom') {
        dbRun("DELETE FROM inv_cabinet_custom_items WHERE id=?", [(int)$_POST['item_id']]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'get_contents') {
        $cid=(int)$_POST['cabinet_id'];
        $linked = dbFetchAll("SELECT p.id,p.name,p.unique_code FROM inv_cabinet_items ci JOIN inv_products p ON p.id=ci.product_id WHERE ci.cabinet_id=? ORDER BY p.name", [$cid]);
        $custom = dbFetchAll("SELECT id,label FROM inv_cabinet_custom_items WHERE cabinet_id=? ORDER BY created_at", [$cid]);
        echo json_encode(['ok'=>true,'linked'=>$linked,'custom'=>$custom]); exit;
    }
    if ($action === 'search_products') {
        $q='%'.trim($_POST['q']??'').'%'; $cid=(int)$_POST['cabinet_id'];
        $rows = dbFetchAll(
            "SELECT p.id,p.name,p.unique_code,
                    (SELECT COUNT(*) FROM inv_cabinet_items WHERE cabinet_id=? AND product_id=p.id) AS assigned
             FROM inv_products p WHERE p.name LIKE ? OR p.unique_code LIKE ? ORDER BY p.name LIMIT 15",
            [$cid,$q,$q]
        );
        echo json_encode(['ok'=>true,'products'=>$rows]); exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

$cabs = dbFetchAll(
    "SELECT c.*,
            (SELECT COUNT(*) FROM inv_cabinet_items ci WHERE ci.cabinet_id=c.id) AS linked_count,
            (SELECT COUNT(*) FROM inv_cabinet_custom_items cc WHERE cc.cabinet_id=c.id) AS custom_count
     FROM inv_cabinets c ORDER BY c.name"
);

$pageTitle = 'Cabinets';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div><h2 class="fw-bold mb-0">Cabinets</h2><div class="text-muted small"><?= count($cabs) ?> cabinet<?= count($cabs)!==1?'s':'' ?></div></div>
  <?php if ($canManage): ?><button class="btn btn-primary" onclick="openCab()"><i class="bi bi-plus-lg me-1"></i>Add Cabinet</button><?php endif; ?>
</div>

<div class="row g-3">
  <?php if (!$cabs): ?>
  <div class="col-12"><div class="card-section text-center text-muted py-5"><i class="bi bi-archive fs-2 d-block mb-2 opacity-25"></i>No cabinets yet.</div></div>
  <?php endif; ?>
  <?php foreach ($cabs as $c): ?>
  <div class="col-md-6 col-xl-4" id="cabCard-<?= $c['id'] ?>">
    <div class="card-section p-3 h-100">
      <div class="d-flex gap-2 mb-2">
        <div style="width:40px;height:40px;border-radius:10px;background:var(--primary-light);display:flex;align-items:center;justify-content:center;color:var(--primary);flex-shrink:0"><i class="bi bi-archive"></i></div>
        <div class="flex-grow-1 min-w-0">
          <div class="fw-bold text-truncate"><?= htmlspecialchars($c['name']) ?></div>
          <div class="small text-muted text-truncate"><?= $c['description'] ? htmlspecialchars(mb_substr($c['description'],0,50)) : 'No description' ?></div>
          <div class="mt-1 d-flex gap-1 flex-wrap">
            <span class="badge text-bg-light border"><?= $c['linked_count'] ?> asset<?= $c['linked_count']!=1?'s':'' ?></span>
            <?php if ($c['custom_count']>0): ?><span class="badge text-bg-light border"><?= $c['custom_count'] ?> custom</span><?php endif; ?>
          </div>
        </div>
        <?php if (!empty($c['qr_code']) && file_exists(INV_QR_DIR.$c['qr_code'])): ?>
        <img src="/uploads/qrcodes/<?= htmlspecialchars($c['qr_code']) ?>" style="width:44px;height:44px;border:1px solid var(--border);border-radius:6px;flex-shrink:0">
        <?php endif; ?>
      </div>
      <div class="d-flex gap-1 flex-wrap">
        <button class="btn btn-sm btn-dark" onclick='openContents(<?= (int)$c["id"] ?>, <?= htmlspecialchars(json_encode($c["name"]), ENT_QUOTES) ?>)'><i class="bi bi-box2 me-1"></i>Manage</button>
        <a href="/cabinet/<?= $c['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Public view"><i class="bi bi-eye"></i></a>
        <?php if ($canManage): ?>
        <button class="btn btn-sm btn-outline-secondary" onclick='openCab(<?= htmlspecialchars(json_encode(["id"=>$c["id"],"name"=>$c["name"],"description"=>$c["description"]??""]), ENT_QUOTES) ?>)'><i class="bi bi-pencil"></i></button>
        <button class="btn btn-sm btn-outline-danger" onclick="delCab(<?= $c['id'] ?>)"><i class="bi bi-trash3"></i></button>
        <?php endif; ?>
        <?php if (!empty($c['qr_code']) && file_exists(INV_QR_DIR.$c['qr_code'])): ?>
        <a href="/uploads/qrcodes/<?= htmlspecialchars($c['qr_code']) ?>" download class="btn btn-sm btn-outline-secondary" title="Download QR"><i class="bi bi-qr-code"></i></a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($canManage): ?>
<!-- Add/Edit Cabinet Modal -->
<div class="modal fade" id="cabModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="cabModalTitle">Add Cabinet</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="alert alert-danger py-2 d-none" id="cabErr"></div>
    <input type="hidden" id="cabId">
    <div class="mb-3"><label class="form-label fw-semibold">Name <span class="text-danger">*</span></label><input type="text" id="cabName" class="form-control" placeholder="e.g. Server Room Cabinet A"></div>
    <div class="mb-0"><label class="form-label fw-semibold">Description</label><textarea id="cabDesc" rows="2" class="form-control" placeholder="Location, notes…"></textarea></div>
  </div>
  <div class="modal-footer"><button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary btn-sm" onclick="saveCab()">Save</button></div>
</div></div></div>
<?php endif; ?>

<!-- Contents Modal -->
<div class="modal fade" id="contentsModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="contTitle">Cabinet Contents</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <ul class="nav nav-tabs mb-3">
      <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabCustom">Items</a></li>
      <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabLinked" onclick="refreshLinked()">Linked Assets</a></li>
    </ul>
    <div class="tab-content">
      <div class="tab-pane fade show active" id="tabCustom">
        <?php if ($canManage): ?>
        <div class="input-group mb-3">
          <input type="text" id="customInput" class="form-control" placeholder="e.g. Power strip, HDMI cable…" onkeydown="if(event.key==='Enter'){event.preventDefault();addCustom();}">
          <button class="btn btn-primary" onclick="addCustom()"><i class="bi bi-plus-lg"></i> Add</button>
        </div>
        <?php endif; ?>
        <div id="customList" class="d-flex flex-column gap-2"></div>
        <div id="customEmpty" class="text-muted small text-center py-3 d-none">No items yet.</div>
      </div>
      <div class="tab-pane fade" id="tabLinked">
        <?php if ($canManage): ?>
        <input type="text" id="prodSearch" class="form-control mb-2" placeholder="Search assets by name or code…" oninput="searchProds()">
        <div id="srList" class="d-flex flex-column gap-1 mb-3"></div>
        <hr>
        <?php endif; ?>
        <div class="small fw-semibold text-muted text-uppercase mb-2">Currently Linked</div>
        <div id="linkedList" class="d-flex flex-column gap-2"></div>
        <div id="linkedEmpty" class="text-muted small text-center py-3 d-none">No assets linked yet.</div>
      </div>
    </div>
  </div>
</div></div></div>

<script>
const canManage = <?= $canManage ? 'true' : 'false' ?>;
let curCab = null;
<?php if ($canManage): ?>
function cabModalEl(){ return bootstrap.Modal.getOrCreateInstance(document.getElementById('cabModal')); }
function openCab(c){
  document.getElementById('cabModalTitle').textContent = c?'Edit Cabinet':'Add Cabinet';
  document.getElementById('cabId').value=c?c.id:''; document.getElementById('cabName').value=c?c.name:'';
  document.getElementById('cabDesc').value=c?(c.description||''):''; document.getElementById('cabErr').classList.add('d-none');
  cabModalEl().show();
}
async function saveCab(){
  const id=document.getElementById('cabId').value,name=document.getElementById('cabName').value.trim(),
        desc=document.getElementById('cabDesc').value.trim(),err=document.getElementById('cabErr');
  if(!name){err.textContent='Name is required.';err.classList.remove('d-none');return;}
  const fd=new FormData();fd.append('ajax','1');fd.append('action',id?'edit':'add');if(id)fd.append('id',id);fd.append('name',name);fd.append('description',desc);
  const d=await(await fetch('',{method:'POST',body:fd})).json();
  if(!d.ok){err.textContent=d.msg||'Error';err.classList.remove('d-none');return;}
  location.reload();
}
async function delCab(id){
  if(!confirm('Delete this cabinet? Its contents links will be removed.'))return;
  const fd=new FormData();fd.append('ajax','1');fd.append('action','delete');fd.append('id',id);
  const d=await(await fetch('',{method:'POST',body:fd})).json();
  if(d.ok) document.getElementById('cabCard-'+id)?.remove();
}
<?php endif; ?>

function openContents(cid,name){
  curCab=cid;
  document.getElementById('contTitle').textContent=name;
  if(document.getElementById('customInput')) document.getElementById('customInput').value='';
  if(document.getElementById('prodSearch')) document.getElementById('prodSearch').value='';
  if(document.getElementById('srList')) document.getElementById('srList').innerHTML='';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('contentsModal')).show();
  refreshCustom();
}
async function getContents(){
  const fd=new FormData();fd.append('ajax','1');fd.append('action','get_contents');fd.append('cabinet_id',curCab);
  return (await(await fetch('',{method:'POST',body:fd})).json());
}
async function refreshCustom(){ const d=await getContents(); renderCustom(d.custom||[]); }
async function refreshLinked(){ const d=await getContents(); renderLinked(d.linked||[]); }
function renderCustom(items){
  const list=document.getElementById('customList'),empty=document.getElementById('customEmpty');
  if(!items.length){list.innerHTML='';empty.classList.remove('d-none');return;}
  empty.classList.add('d-none');
  list.innerHTML=items.map(i=>`<div class="d-flex align-items-center gap-2 p-2 rounded" style="background:#f8fafc" id="ci-${i.id}">
    <span class="flex-grow-1 small">${esc(i.label)}</span>
    ${canManage?`<button class="btn btn-sm btn-link text-danger p-0" onclick="removeCustom(${i.id})"><i class="bi bi-x-lg"></i></button>`:''}</div>`).join('');
}
function renderLinked(items){
  const list=document.getElementById('linkedList'),empty=document.getElementById('linkedEmpty');
  if(!items.length){list.innerHTML='';empty.classList.remove('d-none');return;}
  empty.classList.add('d-none');
  list.innerHTML=items.map(i=>`<div class="d-flex align-items-center gap-2 p-2 rounded" style="background:#f8fafc" id="la-${i.id}">
    <span class="flex-grow-1 small">${esc(i.name)}</span>
    ${i.unique_code?`<span class="small font-monospace text-muted">${esc(i.unique_code)}</span>`:''}
    ${canManage?`<button class="btn btn-sm btn-link text-danger p-0" onclick="unlink(${i.id})"><i class="bi bi-x-lg"></i></button>`:''}</div>`).join('');
}
async function addCustom(){
  const inp=document.getElementById('customInput'),label=inp.value.trim(); if(!label)return;
  const fd=new FormData();fd.append('ajax','1');fd.append('action','add_custom');fd.append('cabinet_id',curCab);fd.append('label',label);
  const d=await(await fetch('',{method:'POST',body:fd})).json();
  if(d.ok){inp.value='';refreshCustom();inp.focus();}
}
async function removeCustom(id){
  const fd=new FormData();fd.append('ajax','1');fd.append('action','remove_custom');fd.append('item_id',id);
  const d=await(await fetch('',{method:'POST',body:fd})).json(); if(d.ok)refreshCustom();
}
let srTimer;
function searchProds(){
  clearTimeout(srTimer);
  srTimer=setTimeout(async()=>{
    const q=document.getElementById('prodSearch').value.trim(),list=document.getElementById('srList');
    if(!q){list.innerHTML='';return;}
    const fd=new FormData();fd.append('ajax','1');fd.append('action','search_products');fd.append('q',q);fd.append('cabinet_id',curCab);
    const d=await(await fetch('',{method:'POST',body:fd})).json();
    if(!d.products.length){list.innerHTML='<div class="small text-muted py-1">No results.</div>';return;}
    list.innerHTML=d.products.map(p=>`<div class="d-flex align-items-center gap-2 p-2 rounded border">
      <span class="flex-grow-1 small">${esc(p.name)}</span>
      ${p.unique_code?`<span class="small font-monospace text-muted">${esc(p.unique_code)}</span>`:''}
      ${p.assigned>0?'<span class="badge text-bg-success">Linked</span>':`<button class="btn btn-sm btn-primary" onclick="linkAsset(${p.id})"><i class="bi bi-plus-lg"></i></button>`}</div>`).join('');
  },280);
}
async function linkAsset(pid){
  const fd=new FormData();fd.append('ajax','1');fd.append('action','assign');fd.append('cabinet_id',curCab);fd.append('product_id',pid);
  await fetch('',{method:'POST',body:fd}); searchProds(); refreshLinked();
}
async function unlink(pid){
  const fd=new FormData();fd.append('ajax','1');fd.append('action','unassign');fd.append('cabinet_id',curCab);fd.append('product_id',pid);
  await fetch('',{method:'POST',body:fd}); refreshLinked(); searchProds();
}
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
