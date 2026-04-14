<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$user     = currentUser();
$role     = $user['role'];
$canEdit  = in_array($role, ['admin','project_admin','supervisor-fiber']);

$STATUS_LABELS = [
    'pending'             => 'Pending',
    'in_progress'         => 'In Progress',
    'on_hold_customer'    => 'On Hold (Customer)',
    'on_hold_deployment'  => 'On Hold (Deployment)',
    'completed'           => 'Completed',
    'configured'          => 'Configured',
];
$STATUS_COLORS = [
    'pending'            => 'secondary',
    'in_progress'        => 'primary',
    'on_hold_customer'   => 'warning',
    'on_hold_deployment' => 'warning',
    'completed'          => 'success',
    'configured'         => 'info',
];

$msg = '';
if (method() === 'POST') {
    verifyCsrf();
    $b = $_POST;
    $action = $b['_action'] ?? '';

    if ($action === 'create' && $canEdit) {
        $newPid = newUuid();
        dbRun("INSERT INTO installation_profiles (id,name,phone,address,email,plan,wifi_username,wifi_password,ticket_id,vendor_id,status,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            [$newPid,$b['name']??'',$b['phone']??'',$b['address']??'',$b['email']??'',$b['plan']??'',$b['wifi_username']??'',$b['wifi_password']??'',$b['ticket_id']??null,$b['vendor_id']??null,$b['status']??'pending',$b['notes']??'']);
        $msg = 'Profile created.';
    }
    if ($action === 'update_stage') {
        // canEdit or vendor assigned to this profile
        $pid = $b['profile_id'] ?? '';
        $allowed = $canEdit || ($role === 'vendor' && ($user['vendor_id'] ?? '') !== '');
        if ($allowed) {
            dbRun("UPDATE installation_profiles SET status=?,updated_at=NOW() WHERE id=?", [$b['status']??'pending',$pid]);
            $cid = newUuid();
            dbRun("INSERT INTO installation_comments (id,profile_id,user_id,user_name,user_role,content,type) VALUES (?,?,?,?,?,?,?)",
                [$cid,$pid,$user['id'],$user['name'],$role,'Stage updated to: '.($STATUS_LABELS[$b['status']]??$b['status']),'stage_change']);
            $msg = 'Stage updated.';
        }
    }
    if ($action === 'add_comment') {
        $pid = $b['profile_id'] ?? '';
        $allowed = $canEdit || ($role === 'vendor');
        if ($allowed) {
            $cid = newUuid();
            dbRun("INSERT INTO installation_comments (id,profile_id,user_id,user_name,user_role,content,type) VALUES (?,?,?,?,?,?,?)",
                [$cid,$pid,$user['id'],$user['name'],$role,$b['content']??'','comment']);
            $msg = 'Comment added.';
        }
    }
    if ($action === 'delete' && $canEdit) {
        dbRun("DELETE FROM installation_profiles WHERE id=?",[$b['profile_id']??'']);
        $msg = 'Profile deleted.';
    }
    header('Location: /installations' . ($b['profile_id'] ? '#profile-'.$b['profile_id'] : '')); exit;
}

$search  = $_GET['search'] ?? '';
$stFilter = $_GET['status'] ?? '';

$where=[]; $params=[];
if ($search) { $where[]="(name LIKE ? OR email LIKE ? OR phone LIKE ?)"; $like="%$search%"; array_push($params,$like,$like,$like); }
if ($stFilter) { $where[]="status=?"; $params[]=$stFilter; }

$profiles = dbFetchAll("SELECT p.*,v.name AS vendor_name FROM installation_profiles p LEFT JOIN vendors v ON v.id=p.vendor_id".($where?" WHERE ".implode(' AND ',$where):'')." ORDER BY p.created_at DESC",$params);
$vendors  = $canEdit ? dbFetchAll("SELECT id,name FROM vendors WHERE type='installation' AND status='active' ORDER BY name") : [];
$allVendors = dbFetchAll("SELECT id,name FROM vendors ORDER BY name");
$tickets  = dbFetchAll("SELECT id,ticket_number,customer_name FROM tickets WHERE type='installation' ORDER BY created_at DESC");

// Stats
$stats = [];
foreach ($STATUS_LABELS as $s => $l) {
    $stats[$s] = (int)(dbFetch("SELECT COUNT(*) AS c FROM installation_profiles WHERE status=?",[$s])['c'] ?? 0);
}

// For detail view
$detailId = $_GET['detail'] ?? null;
$detailProfile = $detailId ? dbFetch("SELECT p.*,v.name AS vendor_name FROM installation_profiles p LEFT JOIN vendors v ON v.id=p.vendor_id WHERE p.id=?",[$detailId]) : null;
$detailComments = $detailId ? dbFetchAll("SELECT * FROM installation_comments WHERE profile_id=? ORDER BY created_at",[$detailId]) : [];

$pageTitle = 'Installations';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?><div class="alert alert-success alert-dismissible py-2"><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<!-- Stats row -->
<div class="row g-2 mb-3">
  <?php foreach ($STATUS_LABELS as $s => $l): ?>
  <div class="col-6 col-sm-4 col-xl-2">
    <a href="/installations?status=<?=$s?>" class="stat-card py-2 text-decoration-none d-block text-center <?= $stFilter===$s?'border-primary':'' ?>">
      <div class="fw-bold fs-5"><?= $stats[$s] ?></div>
      <div style="font-size:.7rem;color:#64748b"><?= $l ?></div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <form class="d-flex gap-2 flex-grow-1 flex-wrap" method="GET">
    <div class="input-group" style="max-width:260px">
      <span class="input-group-text"><i class="bi bi-search"></i></span>
      <input type="text" name="search" class="form-control" placeholder="Search…" value="<?= htmlspecialchars($search) ?>">
    </div>
    <select name="status" class="form-select" style="width:auto" onchange="this.form.submit()">
      <option value="">All Stages</option>
      <?php foreach($STATUS_LABELS as $s=>$l): ?><option value="<?=$s?>" <?=$stFilter===$s?'selected':''?>><?=$l?></option><?php endforeach; ?>
    </select>
    <?php if ($search||$stFilter): ?><a href="/installations" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
  </form>
  <?php if ($canEdit): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addProfileModal">
    <i class="bi bi-plus-lg me-1"></i>New Profile
  </button>
  <?php endif; ?>
</div>

<div class="card-section">
  <div class="card-header"><?= count($profiles) ?> profile<?= count($profiles)!==1?'s':'' ?></div>
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0">
      <thead class="table-light">
        <tr><th>Name</th><th>Phone</th><th>Plan</th><th>Status</th><th>Vendor</th><th>Created</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$profiles): ?><tr><td colspan="7" class="text-center text-muted py-4">No profiles found</td></tr><?php endif; ?>
        <?php foreach ($profiles as $p): ?>
        <tr id="profile-<?= $p['id'] ?>">
          <td class="fw-semibold"><?= htmlspecialchars($p['name']) ?></td>
          <td class="small"><?= htmlspecialchars($p['phone']??'') ?></td>
          <td class="small"><?= htmlspecialchars($p['plan']??'') ?></td>
          <td><span class="badge bg-<?= $STATUS_COLORS[$p['status']]??'secondary' ?>"><?= $STATUS_LABELS[$p['status']]??$p['status'] ?></span></td>
          <td class="small"><?= htmlspecialchars($p['vendor_name']??'—') ?></td>
          <td class="small text-muted"><?= date('d M Y', strtotime($p['created_at'])) ?></td>
          <td>
            <div class="d-flex gap-1">
              <a href="/installations?detail=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary py-0" title="View details"><i class="bi bi-chat-left-text"></i></a>
              <?php if ($canEdit): ?>
              <button class="btn btn-sm btn-outline-primary py-0" onclick="openEdit('<?= $p['id'] ?>','<?= htmlspecialchars(addslashes($p['name'])) ?>','<?= htmlspecialchars($p['status']) ?>')" title="Edit"><i class="bi bi-pencil"></i></button>
              <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="profile_id" value="<?= $p['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
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

<!-- Detail / Comments view -->
<?php if ($detailProfile): ?>
<div class="mt-4 card-section">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-chat-left-text me-1 text-primary"></i><?= htmlspecialchars($detailProfile['name']) ?>
      <span class="badge bg-<?= $STATUS_COLORS[$detailProfile['status']]??'secondary' ?> ms-1"><?= $STATUS_LABELS[$detailProfile['status']]??$detailProfile['status'] ?></span>
    </span>
    <a href="/installations" class="btn btn-sm btn-outline-secondary">Close</a>
  </div>
  <div class="row g-0">
    <div class="col-lg-5 p-3 border-end">
      <dl class="row small mb-3">
        <?php if ($detailProfile['phone']): ?><dt class="col-4 text-muted">Phone</dt><dd class="col-8"><?= htmlspecialchars($detailProfile['phone']) ?></dd><?php endif; ?>
        <?php if ($detailProfile['email']): ?><dt class="col-4 text-muted">Email</dt><dd class="col-8"><?= htmlspecialchars($detailProfile['email']) ?></dd><?php endif; ?>
        <?php if ($detailProfile['address']): ?><dt class="col-4 text-muted">Address</dt><dd class="col-8"><?= htmlspecialchars($detailProfile['address']) ?></dd><?php endif; ?>
        <?php if ($detailProfile['plan']): ?><dt class="col-4 text-muted">Plan</dt><dd class="col-8"><?= htmlspecialchars($detailProfile['plan']) ?></dd><?php endif; ?>
        <?php if ($detailProfile['vendor_name']): ?><dt class="col-4 text-muted">Vendor</dt><dd class="col-8"><?= htmlspecialchars($detailProfile['vendor_name']) ?></dd><?php endif; ?>
      </dl>
      <?php
      $canInteract = $canEdit || ($role === 'vendor' && !empty($user['vendor_id']) && $user['vendor_id'] === $detailProfile['vendor_id']);
      ?>
      <?php if ($canInteract): ?>
      <form method="POST" class="border rounded p-2 mb-3 bg-light">
        <input type="hidden" name="_action" value="update_stage">
        <input type="hidden" name="profile_id" value="<?= $detailProfile['id'] ?>">
        <label class="form-label small fw-semibold mb-1">Update Stage</label>
        <div class="d-flex gap-2">
          <select name="status" class="form-select form-select-sm flex-grow-1">
            <?php foreach($STATUS_LABELS as $s=>$l): ?>
            <option value="<?=$s?>" <?=$detailProfile['status']===$s?'selected':''?>><?=$l?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm btn-primary">Apply</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
    <div class="col-lg-7 p-3">
      <p class="small fw-semibold text-muted text-uppercase mb-2"><i class="bi bi-chat-left-text me-1"></i>Activity Log</p>
      <?php if (!$detailComments): ?>
      <p class="text-muted small">No comments yet.</p>
      <?php endif; ?>
      <div style="max-height:280px;overflow-y:auto" class="mb-3">
        <?php foreach ($detailComments as $c): ?>
        <div class="d-flex gap-2 mb-3">
          <div style="width:28px;height:28px;border-radius:50%;background:<?=$c['type']==='stage_change'?'#f59e0b':'#0ea5e9'?>;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.7rem;font-weight:700;flex-shrink:0">
            <?= strtoupper(substr($c['user_name']??'?',0,1)) ?>
          </div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-1 flex-wrap">
              <strong class="small"><?= htmlspecialchars($c['user_name']??'') ?></strong>
              <span class="badge bg-light text-dark border" style="font-size:.65rem"><?= htmlspecialchars($c['user_role']??'') ?></span>
              <?php if ($c['type']==='stage_change'): ?>
              <span class="badge bg-warning text-dark" style="font-size:.65rem">Stage Change</span>
              <?php endif; ?>
              <small class="text-muted ms-auto"><?= date('d M Y H:i', strtotime($c['created_at'])) ?></small>
            </div>
            <div class="mt-1 small <?=$c['type']==='stage_change'?'text-muted fst-italic':''?>"><?= nl2br(htmlspecialchars($c['content'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if ($canInteract): ?>
      <form method="POST" class="border-top pt-2">
        <input type="hidden" name="_action" value="add_comment">
        <input type="hidden" name="profile_id" value="<?= $detailProfile['id'] ?>">
        <div class="d-flex gap-2">
          <textarea name="content" class="form-control form-control-sm" rows="2" placeholder="Add a comment…" required></textarea>
          <button type="submit" class="btn btn-sm btn-primary align-self-end"><i class="bi bi-send"></i></button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Add Profile Modal -->
<?php if ($canEdit): ?>
<div class="modal fade" id="addProfileModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="POST"><input type="hidden" name="_action" value="create">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-wifi me-1 text-primary"></i>New Installation Profile</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-12"><label class="form-label small fw-semibold">Customer Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control form-control-sm" required></div>
          <div class="col-6"><label class="form-label small fw-semibold">Phone</label><input type="text" name="phone" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small fw-semibold">Email</label><input type="email" name="email" class="form-control form-control-sm"></div>
          <div class="col-12"><label class="form-label small fw-semibold">Address</label><input type="text" name="address" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small fw-semibold">Plan</label>
            <select name="plan" class="form-select form-select-sm">
              <option value="">— Select —</option>
              <?php foreach(['10 Mbps Basic','20 Mbps Standard','50 Mbps Plus','100 Mbps Fast','200 Mbps Ultra','500 Mbps Business','1 Gbps Enterprise','Custom'] as $pl): ?>
              <option value="<?=$pl?>"><?=$pl?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">Stage</label>
            <select name="status" class="form-select form-select-sm">
              <?php foreach($STATUS_LABELS as $s=>$l): ?><option value="<?=$s?>"><?=$l?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">WiFi Username</label><input type="text" name="wifi_username" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small fw-semibold">WiFi Password</label><input type="text" name="wifi_password" class="form-control form-control-sm"></div>
          <?php if ($vendors): ?>
          <div class="col-6"><label class="form-label small fw-semibold">Assign Vendor</label>
            <select name="vendor_id" class="form-select form-select-sm"><option value="">— None —</option>
              <?php foreach($vendors as $v): ?><option value="<?=$v['id']?>"><?= htmlspecialchars($v['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="col-6"><label class="form-label small fw-semibold">Linked Ticket</label>
            <select name="ticket_id" class="form-select form-select-sm"><option value="">— None —</option>
              <?php foreach($tickets as $t): ?><option value="<?=$t['id']?>"><?= htmlspecialchars($t['ticket_number'].' — '.$t['customer_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12"><label class="form-label small fw-semibold">Notes</label><textarea name="notes" class="form-control form-control-sm" rows="2"></textarea></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary btn-sm">Create Profile</button></div>
    </form>
  </div></div>
</div>

<!-- Edit stage modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-sm"><div class="modal-content">
    <form method="POST"><input type="hidden" name="_action" value="update_stage">
      <input type="hidden" name="profile_id" id="editProfileId">
      <div class="modal-header"><h5 class="modal-title" id="editModalTitle">Edit Stage</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <select name="status" id="editStatus" class="form-select">
          <?php foreach($STATUS_LABELS as $s=>$l): ?><option value="<?=$s?>"><?=$l?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary btn-sm">Update</button></div>
    </form>
  </div></div>
</div>

<script>
function openEdit(id, name, status) {
  document.getElementById('editProfileId').value = id;
  document.getElementById('editModalTitle').textContent = 'Edit: ' + name;
  document.getElementById('editStatus').value = status;
  new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
