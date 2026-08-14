<?php
require_once __DIR__ . '/../config.php';
requireAuth();

requirePermission('team.view');

$msg = ''; $msgType = 'success';

if (method() === 'POST') {
    verifyCsrf();
    $b = $_POST;
    $action = $b['_action'] ?? '';

    if ($action === 'add_user') {
        $hubIds = !empty($b['hub_ids']) ? '{' . implode(',', array_map('trim', (array)$b['hub_ids'])) . '}' : null;
        dbRun("INSERT INTO users (id,username,name,email,phone,role,password,hub_id,hub_ids,team_id,vendor_id,status)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,'active')",
            [newUuid(),$b['username']??'',$b['name']??'',$b['email']??'',$b['phone']??'',$b['role']??'engineer',
             hashPassword('admin123'),$b['hub_id']??null,$hubIds,$b['team_id']??null,$b['vendor_id']??null]);
        $msg = 'Member added. Default password: admin123';
    }
    if ($action === 'edit_user' && !empty($b['id'])) {
        $hubIds = !empty($b['hub_ids']) ? '{' . implode(',', array_map('trim', (array)$b['hub_ids'])) . '}' : null;
        $sets = "name=?,email=?,phone=?,role=?,status=?,hub_id=?,hub_ids=?,team_id=?,vendor_id=?";
        dbRun("UPDATE users SET $sets WHERE id=?",
            [$b['name']??'',$b['email']??'',$b['phone']??'',$b['role']??'engineer',
             $b['status']??'active',$b['hub_id']??null,$hubIds,$b['team_id']??null,$b['vendor_id']??null,$b['id']]);
        if (!empty($b['new_password'])) {
            dbRun("UPDATE users SET password=? WHERE id=?", [hashPassword($b['new_password']), $b['id']]);
        }
        $msg = 'Member updated.';
    }
    if ($action === 'del_user' && !empty($b['id'])) {
        dbRun("DELETE FROM users WHERE id=?", [$b['id']]);
        $msg = 'Member removed.';
    }
    if ($action === 'add_vendor') {
        dbRun("INSERT INTO vendors (id,name,type,status,email,phone,supervisor_name) VALUES (?,?,?,?,?,?,?)",
            [newUuid(),$b['name']??'',$b['type']??'general','active',$b['email']??'',$b['phone']??'',$b['supervisor_name']??'']);
        $msg = 'Vendor added.';
    }
    if ($action === 'edit_vendor' && !empty($b['id'])) {
        dbRun("UPDATE vendors SET name=?,type=?,status=?,email=?,phone=?,supervisor_name=? WHERE id=?",
            [$b['name']??'',$b['type']??'general',$b['status']??'active',$b['email']??'',$b['phone']??'',$b['supervisor_name']??'',$b['id']]);
        $msg = 'Vendor updated.';
    }
    if ($action === 'del_vendor' && !empty($b['id'])) {
        dbRun("DELETE FROM vendors WHERE id=?", [$b['id']]);
        $msg = 'Vendor removed.';
    }
    if ($action === 'add_team') {
        dbRun("INSERT INTO teams (id,name,type) VALUES (?,?,?)",[newUuid(),$b['name']??'',$b['type']??'fiber']);
        $msg = 'Team created.';
    }

    header('Location: /team'); exit;
}

$users   = dbFetchAll("SELECT * FROM users ORDER BY name");
$vendors = dbFetchAll("SELECT * FROM vendors ORDER BY name");
$teams   = dbFetchAll("SELECT * FROM teams ORDER BY name");
$hubs    = dbFetchAll("SELECT id,name FROM hubs ORDER BY name");
$hubMap  = array_column($hubs, 'name', 'id');

// Role display config — labels come from the DB-managed roles registry
$roleLabel = [];
foreach (getRoles() as $rn => $rrow) { $roleLabel[$rn] = $rrow['label']; }
$roleBadge = [
    'admin'=>'danger','project_admin'=>'dark','supervisor-fiber'=>'primary',
    'supervisor-noc'=>'info','cx_supervisor'=>'purple','cx'=>'teal',
    'engineer'=>'success','vendor'=>'warning'
];

// Helper: resolve hub_ids array → hub names
function resolveHubIds(?string $rawIds, array $hubMap): array {
    if (!$rawIds) return [];
    // PostgreSQL array literal: {uuid1,uuid2,...}
    $clean = trim($rawIds, '{}');
    if (!$clean) return [];
    $ids = explode(',', $clean);
    $names = [];
    foreach ($ids as $id) {
        $id = trim($id, ' "\'');
        if (isset($hubMap[$id])) $names[] = $hubMap[$id];
    }
    return $names;
}

function initials(string $name): string {
    $words = preg_split('/\s+/', trim($name));
    if (count($words) >= 2) return strtoupper(substr($words[0],0,1).substr($words[1],0,1));
    return strtoupper(substr($name,0,2));
}

$pageTitle = 'Team & Vendors';
require __DIR__ . '/../includes/header.php';
?>

<style>
.member-card {
    background:#fff;border:1px solid var(--border);border-radius:var(--radius);
    padding:1.25rem;position:relative;transition:box-shadow .15s;
}
.member-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
.member-avatar {
    width:48px;height:48px;border-radius:50%;background:var(--primary);
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-weight:700;font-size:.95rem;flex-shrink:0;
}
.member-actions { position:absolute;top:.75rem;right:.75rem;display:flex;gap:.3rem; }
.member-actions button,.member-actions .btn { padding:.2rem .45rem;line-height:1; }
.hub-pill {
    display:inline-block;font-size:.68rem;font-weight:500;
    background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;
    border-radius:99px;padding:.1rem .55rem;white-space:nowrap;
}
.badge-purple  { background:#ede9fe !important;color:#6d28d9 !important; }
.badge-teal    { background:#ccfbf1 !important;color:#0f766e !important; }
</style>

<?php if ($msg): ?>
<div class="alert alert-success alert-dismissible py-2 mb-3">
  <i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($msg) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex align-items-start justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h4 class="fw-bold mb-1">Team &amp; Vendors</h4>
    <p class="text-muted mb-0 small">Manage internal staff and external vendor partners.</p>
  </div>
  <?php if (hasPermission('team.manage')): ?>
  <div class="d-flex gap-2 flex-wrap">
    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#addTeamModal">
      <i class="bi bi-people me-1"></i>Create Team
    </button>
    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#addVendorModal">
      <i class="bi bi-building me-1"></i>Add Vendor
    </button>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
      <i class="bi bi-person-plus me-1"></i>Add Member
    </button>
  </div>
  <?php endif; ?>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" id="teamTabs">
  <li class="nav-item">
    <a class="nav-link active" data-bs-toggle="tab" href="#staff">
      <i class="bi bi-people me-1"></i>Staff (<?= count($users) ?>)
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link" data-bs-toggle="tab" href="#vendors">
      <i class="bi bi-building me-1"></i>Vendors (<?= count($vendors) ?>)
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link" data-bs-toggle="tab" href="#teams">
      <i class="bi bi-diagram-3 me-1"></i>Teams (<?= count($teams) ?>)
    </a>
  </li>
</ul>

<div class="tab-content">

  <!-- ── Staff ─────────────────────────────────────────────────────────────── -->
  <div class="tab-pane fade show active" id="staff">
    <div class="row g-3">
      <?php foreach ($users as $u):
        $hubNames   = resolveHubIds($u['hub_ids'] ?? null, $hubMap);
        $singleHub  = !empty($u['hub_id']) && isset($hubMap[$u['hub_id']]) ? $hubMap[$u['hub_id']] : null;
        $status     = $u['status'] ?? 'active';
        $badge      = $roleBadge[$u['role']] ?? 'secondary';
        $label      = $roleLabel[$u['role']] ?? $u['role'];
        $isMultiHub = !empty($hubNames);
      ?>
      <div class="col-md-6 col-xl-4">
        <div class="member-card">
          <!-- Actions -->
          <?php if (hasPermission('team.manage')): ?>
          <div class="member-actions">
            <button class="btn btn-sm btn-outline-secondary"
              onclick='openEditUser(<?= htmlspecialchars(json_encode([
                'id'=>$u['id'],'name'=>$u['name'],'email'=>$u['email']??'',
                'phone'=>$u['phone']??'','role'=>$u['role'],'status'=>$status,
                'hub_id'=>$u['hub_id']??'','team_id'=>$u['team_id']??'',
                'vendor_id'=>$u['vendor_id']??'','hub_ids'=>$hubNames
              ])) ?>)' title="Edit"><i class="bi bi-pencil"></i></button>
            <form method="POST" class="d-inline" onsubmit="return confirm('Remove this member?')">
              <input type="hidden" name="_action" value="del_user">
              <input type="hidden" name="id" value="<?= $u['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="Remove"><i class="bi bi-trash3"></i></button>
            </form>
          </div>
          <?php endif; ?>

          <!-- Header -->
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="member-avatar"><?= initials($u['name']) ?></div>
            <div>
              <div class="fw-bold" style="font-size:.9375rem;line-height:1.2"><?= htmlspecialchars($u['name']) ?></div>
              <div class="text-muted small"><?= htmlspecialchars($label) ?></div>
            </div>
          </div>

          <!-- Fields -->
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="text-muted small">Status</span>
            <span class="badge <?= $status==='active'?'bg-success':'bg-secondary' ?>"><?= ucfirst($status) ?></span>
          </div>
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="text-muted small">Role</span>
            <span class="badge badge-<?= $badge ?>"><?= htmlspecialchars($label) ?></span>
          </div>

          <?php if ($isMultiHub): ?>
          <div class="d-flex justify-content-between align-items-start mb-2 gap-2">
            <span class="text-muted small flex-shrink-0">Hubs</span>
            <div class="d-flex flex-wrap gap-1 justify-content-end">
              <?php foreach ($hubNames as $hn): ?>
              <span class="hub-pill"><?= htmlspecialchars($hn) ?> POP</span>
              <?php endforeach; ?>
            </div>
          </div>
          <?php elseif ($singleHub): ?>
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="text-muted small">Hub</span>
            <span class="hub-pill"><?= htmlspecialchars($singleHub) ?> POP</span>
          </div>
          <?php endif; ?>

          <?php if (!empty($u['phone'])): ?>
          <div class="d-flex align-items-center gap-2 mt-2 small">
            <i class="bi bi-telephone text-muted"></i>
            <a href="tel:<?= htmlspecialchars($u['phone']) ?>" class="text-dark text-decoration-none"><?= htmlspecialchars($u['phone']) ?></a>
          </div>
          <?php endif; ?>
          <?php if (!empty($u['email'])): ?>
          <div class="d-flex align-items-center gap-2 mt-1 small" style="word-break:break-all">
            <i class="bi bi-envelope text-muted"></i>
            <a href="mailto:<?= htmlspecialchars($u['email']) ?>" class="text-dark text-decoration-none"><?= htmlspecialchars($u['email']) ?></a>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (!$users): ?>
      <div class="col-12 text-center text-muted py-5">No staff members yet.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Vendors ────────────────────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="vendors">
    <div class="row g-3">
      <?php foreach ($vendors as $v): ?>
      <div class="col-md-6 col-xl-4">
        <div class="member-card">
          <?php if (hasPermission('team.manage')): ?>
          <div class="member-actions">
            <button class="btn btn-sm btn-outline-secondary"
              onclick='openEditVendor(<?= htmlspecialchars(json_encode([
                'id'=>$v['id'],'name'=>$v['name'],'type'=>$v['type']??'',
                'status'=>$v['status'],'email'=>$v['email']??'','phone'=>$v['phone']??'',
                'supervisor_name'=>$v['supervisor_name']??''
              ])) ?>)' title="Edit"><i class="bi bi-pencil"></i></button>
            <form method="POST" class="d-inline" onsubmit="return confirm('Remove this vendor?')">
              <input type="hidden" name="_action" value="del_vendor">
              <input type="hidden" name="id" value="<?= $v['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="Remove"><i class="bi bi-trash3"></i></button>
            </form>
          </div>
          <?php endif; ?>

          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="member-avatar" style="background:#64748b;font-size:.8rem"><?= initials($v['name']) ?></div>
            <div>
              <div class="fw-bold" style="font-size:.9375rem;line-height:1.2"><?= htmlspecialchars($v['name']) ?></div>
              <div class="text-muted small"><?= ucfirst(htmlspecialchars($v['type']??'Vendor')) ?></div>
            </div>
          </div>

          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="text-muted small">Status</span>
            <span class="badge <?= ($v['status']??'active')==='active'?'bg-success':'bg-secondary' ?>"><?= ucfirst($v['status']??'active') ?></span>
          </div>
          <?php if (!empty($v['supervisor_name'])): ?>
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="text-muted small">Supervisor</span>
            <span class="small fw-semibold"><?= htmlspecialchars($v['supervisor_name']) ?></span>
          </div>
          <?php endif; ?>
          <?php if (!empty($v['phone'])): ?>
          <div class="d-flex align-items-center gap-2 mt-2 small">
            <i class="bi bi-telephone text-muted"></i>
            <a href="tel:<?= htmlspecialchars($v['phone']) ?>" class="text-dark text-decoration-none"><?= htmlspecialchars($v['phone']) ?></a>
          </div>
          <?php endif; ?>
          <?php if (!empty($v['email'])): ?>
          <div class="d-flex align-items-center gap-2 mt-1 small" style="word-break:break-all">
            <i class="bi bi-envelope text-muted"></i>
            <a href="mailto:<?= htmlspecialchars($v['email']) ?>" class="text-dark text-decoration-none"><?= htmlspecialchars($v['email']) ?></a>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (!$vendors): ?>
      <div class="col-12 text-center text-muted py-5">No vendors yet.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Teams ──────────────────────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="teams">
    <?php if (!$teams): ?>
    <div class="text-center text-muted py-5">No teams created yet.</div>
    <?php else: ?>
    <div class="card-section">
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>Team Name</th><th>Type</th></tr></thead>
          <tbody>
            <?php foreach ($teams as $t): ?>
            <tr>
              <td class="fw-semibold"><?= htmlspecialchars($t['name']) ?></td>
              <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($t['type']??'—') ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /tab-content -->

<!-- ── Add Member Modal ───────────────────────────────────────────────────── -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="POST"><input type="hidden" name="_action" value="add_user">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-plus me-1 text-primary"></i>Add Team Member</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-sm-6"><label class="form-label fw-semibold small">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control form-control-sm" required></div>
          <div class="col-sm-6"><label class="form-label fw-semibold small">Username <span class="text-danger">*</span></label>
            <input type="text" name="username" class="form-control form-control-sm" required></div>
          <div class="col-sm-6"><label class="form-label fw-semibold small">Email</label>
            <input type="email" name="email" class="form-control form-control-sm"></div>
          <div class="col-sm-6"><label class="form-label fw-semibold small">Phone</label>
            <input type="text" name="phone" class="form-control form-control-sm" placeholder="e.g. 08012345678"></div>
          <div class="col-sm-6"><label class="form-label fw-semibold small">Role</label>
            <select name="role" class="form-select form-select-sm">
              <?php foreach(roleKeys() as $r): ?><option value="<?=$r?>"><?= $roleLabel[$r] ?? $r ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6"><label class="form-label fw-semibold small">Primary Hub</label>
            <select name="hub_id" class="form-select form-select-sm">
              <option value="">— None —</option>
              <?php foreach($hubs as $h): ?><option value="<?=$h['id']?>"><?= htmlspecialchars($h['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6"><label class="form-label fw-semibold small">Team</label>
            <select name="team_id" class="form-select form-select-sm">
              <option value="">— None —</option>
              <?php foreach($teams as $t): ?><option value="<?=$t['id']?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6" id="au_vendorWrap">
            <label class="form-label fw-semibold small">Vendor Company <span class="text-muted fw-normal">(role = Vendor only)</span></label>
            <select name="vendor_id" class="form-select form-select-sm">
              <option value="">— None —</option>
              <?php foreach($vendors as $v): ?><option value="<?=$v['id']?>"><?= htmlspecialchars($v['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold small">Additional Hubs (for supervisors)</label>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach($hubs as $h): ?>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="hub_ids[]" id="ah_<?=$h['id']?>" value="<?=$h['id']?>">
                <label class="form-check-label small" for="ah_<?=$h['id']?>"><?= htmlspecialchars($h['name']) ?></label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="alert alert-info py-2 mt-3 small mb-0">
          <i class="bi bi-info-circle me-1"></i>Default password will be set to <strong>admin123</strong>. Ask the member to change it after first login.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i>Add Member</button>
      </div>
    </form>
  </div></div>
</div>

<!-- ── Edit Member Modal ──────────────────────────────────────────────────── -->
<div class="modal fade" id="editUserModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="POST"><input type="hidden" name="_action" value="edit_user">
      <input type="hidden" name="id" id="eu_id">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-1 text-primary"></i>Edit Member</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-sm-6"><label class="form-label fw-semibold small">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="eu_name" class="form-control form-control-sm" required></div>
          <div class="col-sm-6"><label class="form-label fw-semibold small">Email</label>
            <input type="email" name="email" id="eu_email" class="form-control form-control-sm"></div>
          <div class="col-sm-6"><label class="form-label fw-semibold small">Phone</label>
            <input type="text" name="phone" id="eu_phone" class="form-control form-control-sm"></div>
          <div class="col-sm-6"><label class="form-label fw-semibold small">Role</label>
            <select name="role" id="eu_role" class="form-select form-select-sm">
              <?php foreach(roleKeys() as $r): ?><option value="<?=$r?>"><?= $roleLabel[$r] ?? $r ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6"><label class="form-label fw-semibold small">Status</label>
            <select name="status" id="eu_status" class="form-select form-select-sm">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
          <div class="col-sm-6"><label class="form-label fw-semibold small">Primary Hub</label>
            <select name="hub_id" id="eu_hub_id" class="form-select form-select-sm">
              <option value="">— None —</option>
              <?php foreach($hubs as $h): ?><option value="<?=$h['id']?>"><?= htmlspecialchars($h['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6"><label class="form-label fw-semibold small">Team</label>
            <select name="team_id" id="eu_team_id" class="form-select form-select-sm">
              <option value="">— None —</option>
              <?php foreach($teams as $t): ?><option value="<?=$t['id']?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold small">Vendor Company <span class="text-muted fw-normal">(role = Vendor only)</span></label>
            <select name="vendor_id" id="eu_vendor_id" class="form-select form-select-sm">
              <option value="">— None —</option>
              <?php foreach($vendors as $v): ?><option value="<?=$v['id']?>"><?= htmlspecialchars($v['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold small">Additional Hubs (for supervisors)</label>
            <div class="d-flex flex-wrap gap-2" id="eu_hub_ids_wrap">
              <?php foreach($hubs as $h): ?>
              <div class="form-check form-check-inline">
                <input class="form-check-input eu-hub-check" type="checkbox" name="hub_ids[]"
                  id="eh_<?=$h['id']?>" value="<?=$h['name']?>">
                <label class="form-check-label small" for="eh_<?=$h['id']?>"><?= htmlspecialchars($h['name']) ?></label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold small">New Password <span class="text-muted fw-normal">(leave blank to keep current)</span></label>
            <input type="password" name="new_password" class="form-control form-control-sm" placeholder="Enter new password to change">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-floppy me-1"></i>Save Changes</button>
      </div>
    </form>
  </div></div>
</div>

<!-- ── Add Vendor Modal ───────────────────────────────────────────────────── -->
<div class="modal fade" id="addVendorModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST"><input type="hidden" name="_action" value="add_vendor">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-building me-1 text-primary"></i>Add Vendor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label fw-semibold small">Company Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control form-control-sm" required></div>
        <div class="mb-3"><label class="form-label fw-semibold small">Type</label>
          <select name="type" class="form-select form-select-sm">
            <option value="general">General</option><option value="installation">Installation</option>
            <option value="maintenance">Maintenance</option><option value="noc">NOC</option>
          </select>
        </div>
        <div class="mb-3"><label class="form-label fw-semibold small">Supervisor / Contact Person</label>
          <input type="text" name="supervisor_name" class="form-control form-control-sm"></div>
        <div class="mb-3"><label class="form-label fw-semibold small">Email</label>
          <input type="email" name="email" class="form-control form-control-sm"></div>
        <div class="mb-0"><label class="form-label fw-semibold small">Phone</label>
          <input type="text" name="phone" class="form-control form-control-sm"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-building me-1"></i>Add Vendor</button>
      </div>
    </form>
  </div></div>
</div>

<!-- ── Edit Vendor Modal ──────────────────────────────────────────────────── -->
<div class="modal fade" id="editVendorModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST"><input type="hidden" name="_action" value="edit_vendor">
      <input type="hidden" name="id" id="ev_id">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-1 text-primary"></i>Edit Vendor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label fw-semibold small">Company Name <span class="text-danger">*</span></label>
          <input type="text" name="name" id="ev_name" class="form-control form-control-sm" required></div>
        <div class="mb-3"><label class="form-label fw-semibold small">Type</label>
          <select name="type" id="ev_type" class="form-select form-select-sm">
            <option value="general">General</option><option value="installation">Installation</option>
            <option value="maintenance">Maintenance</option><option value="noc">NOC</option>
          </select>
        </div>
        <div class="mb-3"><label class="form-label fw-semibold small">Status</label>
          <select name="status" id="ev_status" class="form-select form-select-sm">
            <option value="active">Active</option><option value="inactive">Inactive</option>
          </select>
        </div>
        <div class="mb-3"><label class="form-label fw-semibold small">Supervisor / Contact Person</label>
          <input type="text" name="supervisor_name" id="ev_supervisor" class="form-control form-control-sm"></div>
        <div class="mb-3"><label class="form-label fw-semibold small">Email</label>
          <input type="email" name="email" id="ev_email" class="form-control form-control-sm"></div>
        <div class="mb-0"><label class="form-label fw-semibold small">Phone</label>
          <input type="text" name="phone" id="ev_phone" class="form-control form-control-sm"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-floppy me-1"></i>Save Changes</button>
      </div>
    </form>
  </div></div>
</div>

<!-- ── Add Team Modal ─────────────────────────────────────────────────────── -->
<div class="modal fade" id="addTeamModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST"><input type="hidden" name="_action" value="add_team">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-diagram-3 me-1 text-primary"></i>Create Team</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label fw-semibold small">Team Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control form-control-sm" required></div>
        <div class="mb-0"><label class="form-label fw-semibold small">Type</label>
          <select name="type" class="form-select form-select-sm">
            <option value="fiber">Fiber</option><option value="noc">NOC</option>
            <option value="cx">CX</option><option value="installation">Installation</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Create Team</button>
      </div>
    </form>
  </div></div>
</div>

<script>
function openEditUser(u) {
  document.getElementById('eu_id').value     = u.id;
  document.getElementById('eu_name').value   = u.name || '';
  document.getElementById('eu_email').value  = u.email || '';
  document.getElementById('eu_phone').value  = u.phone || '';
  document.getElementById('eu_role').value   = u.role || 'engineer';
  document.getElementById('eu_status').value = u.status || 'active';
  document.getElementById('eu_hub_id').value = u.hub_id || '';
  document.getElementById('eu_team_id').value = u.team_id || '';
  document.getElementById('eu_vendor_id').value = u.vendor_id || '';
  // Tick hub checkboxes
  document.querySelectorAll('.eu-hub-check').forEach(cb => {
    cb.checked = u.hub_ids && u.hub_ids.includes(cb.value);
  });
  bootstrap.Modal.getOrCreateInstance(document.getElementById('editUserModal')).show();
}

function openEditVendor(v) {
  document.getElementById('ev_id').value         = v.id;
  document.getElementById('ev_name').value       = v.name || '';
  document.getElementById('ev_type').value       = v.type || 'general';
  document.getElementById('ev_status').value     = v.status || 'active';
  document.getElementById('ev_supervisor').value = v.supervisor_name || '';
  document.getElementById('ev_email').value      = v.email || '';
  document.getElementById('ev_phone').value      = v.phone || '';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('editVendorModal')).show();
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
