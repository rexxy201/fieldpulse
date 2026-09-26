<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$user = currentUser();
$fresh = dbFetch("SELECT * FROM users WHERE id = ?", [$user['id']]);

$msg = ''; $msgType = 'success';
$showCodeEntry = false;

if (method() === 'POST') {
    verifyCsrf();
    $action = $_POST['_action'] ?? '';

    if ($action === 'start_enable_2fa') {
        if (empty($fresh['email'])) {
            $msg = 'Add an email address to your account before enabling two-factor sign-in — contact an admin to set one.';
            $msgType = 'danger';
        } else {
            issueTwoFactorCode($fresh['id'], $fresh['email'], $fresh['name']);
            $_SESSION['2fa_setup_pending'] = true;
            $msg = 'A verification code was sent to ' . htmlspecialchars($fresh['email']) . '. Enter it below to finish turning on two-factor sign-in.';
            $showCodeEntry = true;
        }
    } elseif ($action === 'confirm_enable_2fa') {
        $code = trim($_POST['code'] ?? '');
        if (verifyTwoFactorCode($fresh['id'], $code)) {
            dbRun("UPDATE users SET twofa_enabled = 1 WHERE id = ?", [$fresh['id']]);
            unset($_SESSION['2fa_setup_pending']);
            auditLog('enable_2fa', 'user', $fresh['id']);
            $msg = 'Two-factor sign-in is now on. You\'ll be asked for a code from your email every time you log in.';
            $fresh['twofa_enabled'] = 1;
        } else {
            $msg = 'That code is incorrect or has expired.'; $msgType = 'danger';
            $showCodeEntry = true;
        }
    } elseif ($action === 'disable_2fa') {
        $pw = $_POST['password'] ?? '';
        if (!verifyPassword($pw, $fresh['password'] ?? '')) {
            $msg = 'Incorrect password.'; $msgType = 'danger';
        } else {
            dbRun("UPDATE users SET twofa_enabled = 0, twofa_code_hash = NULL, twofa_code_expires_at = NULL WHERE id = ?", [$fresh['id']]);
            auditLog('disable_2fa', 'user', $fresh['id']);
            $msg = 'Two-factor sign-in is now off.';
            $fresh['twofa_enabled'] = 0;
        }
    } elseif ($action === 'change_password') {
        $err = changeOwnPassword($fresh['id'], $_POST['current_password'] ?? '', $_POST['new_password'] ?? '', $_POST['confirm_password'] ?? '');
        if ($err !== null) {
            $msg = $err; $msgType = 'danger';
        } else {
            session_regenerate_id(true);
            $_SESSION['user']['must_change_password'] = 0;
            $fresh['must_change_password'] = 0;
            auditLog('change_password', 'user', $fresh['id']);
            $msg = 'Your password has been changed.';
        }
    } elseif ($action === 'save_signature') {
        $sig = trim($_POST['signature_data'] ?? '');
        if ($sig && str_starts_with($sig, 'data:image/')) {
            dbRun("UPDATE users SET signature_data=? WHERE id=?", [$sig, $user['id']]);
            $fresh['signature_data'] = $sig;
            $msg = 'Signature saved.';
        } elseif ($sig === '__clear__') {
            dbRun("UPDATE users SET signature_data=NULL WHERE id=?", [$user['id']]);
            $fresh['signature_data'] = null;
            $msg = 'Signature cleared.';
        } else {
            $msg = 'No signature data received.'; $msgType = 'danger';
        }
    }
}
if (!empty($_SESSION['2fa_setup_pending'])) $showCodeEntry = true;

$pageTitle = 'My Account';
require __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
  <h2 class="fw-bold mb-0">My Account</h2>
  <div class="text-muted small">Your sign-in details and security settings.</div>
</div>

<?php if (!empty($fresh['must_change_password'])): ?>
<div class="alert alert-warning py-2"><i class="bi bi-key me-1"></i>You signed in with a temporary password. Choose a new password below to continue.</div>
<?php endif; ?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'danger' ? 'danger' : 'success' ?> py-2"><i class="bi bi-<?= $msgType==='danger'?'exclamation-circle':'check-circle' ?> me-1"></i><?= $msg ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card-section">
      <div class="card-header"><i class="bi bi-person me-1 text-primary"></i>Profile</div>
      <div class="p-3">
        <dl class="row small mb-0">
          <dt class="col-4 text-muted">Name</dt><dd class="col-8"><?= htmlspecialchars($fresh['name'] ?? '') ?></dd>
          <dt class="col-4 text-muted">Username</dt><dd class="col-8"><?= htmlspecialchars($fresh['username'] ?? '') ?></dd>
          <dt class="col-4 text-muted">Email</dt><dd class="col-8"><?= htmlspecialchars($fresh['email'] ?: '—') ?></dd>
          <dt class="col-4 text-muted">Role</dt><dd class="col-8"><?= htmlspecialchars($fresh['role'] ?? '') ?></dd>
        </dl>
        <div class="form-text mt-2">To change your name or email, contact an admin.</div>
      </div>
    </div>

    <div class="card-section mt-3" id="change-password">
      <div class="card-header"><i class="bi bi-key me-1 text-primary"></i>Change Password</div>
      <form method="post" class="p-3">
        <?= csrfField() ?>
        <input type="hidden" name="_action" value="change_password">
        <div class="mb-2">
          <label class="form-label small fw-semibold mb-1" for="current_password">Current password</label>
          <input type="password" id="current_password" name="current_password" class="form-control" required autocomplete="current-password">
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold mb-1" for="new_password">New password</label>
          <input type="password" id="new_password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password" placeholder="At least 8 characters">
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold mb-1" for="confirm_password">Confirm new password</label>
          <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Change password</button>
      </form>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card-section">
      <div class="card-header"><i class="bi bi-shield-lock me-1 text-primary"></i>Two-Factor Sign-In</div>
      <div class="p-3">
        <?php if (!empty($fresh['twofa_enabled'])): ?>
        <p class="small mb-3"><span class="badge bg-success me-1">On</span>You'll be asked for a code sent to your email every time you sign in.</p>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="_action" value="disable_2fa">
          <label class="form-label small fw-semibold mb-1">Confirm your password to turn this off</label>
          <div class="input-group input-group-sm mb-2">
            <input type="password" name="password" class="form-control" required autocomplete="current-password">
            <button type="submit" class="btn btn-outline-danger">Turn Off</button>
          </div>
        </form>

        <?php elseif ($showCodeEntry): ?>
        <p class="small text-muted mb-3">Enter the 6-digit code we just emailed you.</p>
        <form method="POST" class="d-flex gap-2 flex-wrap">
          <?= csrfField() ?>
          <input type="hidden" name="_action" value="confirm_enable_2fa">
          <input type="text" name="code" class="form-control form-control-sm" style="max-width:140px;letter-spacing:.2em" placeholder="000000" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" required autofocus>
          <button type="submit" class="btn btn-sm btn-primary">Confirm</button>
        </form>
        <form method="POST" class="mt-2">
          <?= csrfField() ?>
          <input type="hidden" name="_action" value="start_enable_2fa">
          <button type="submit" class="btn btn-sm btn-link text-muted px-0">Resend code</button>
        </form>

        <?php else: ?>
        <p class="small mb-3"><span class="badge bg-secondary me-1">Off</span>Add a second step at login — a code emailed to you, on top of your password.</p>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="_action" value="start_enable_2fa">
          <button type="submit" class="btn btn-sm btn-primary" <?= empty($fresh['email']) ? 'disabled' : '' ?>>
            <i class="bi bi-shield-check me-1"></i>Enable Two-Factor Sign-In
          </button>
          <?php if (empty($fresh['email'])): ?><div class="form-text text-danger">No email on file — ask an admin to add one first.</div><?php endif; ?>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>


<div class="row g-3 mt-0">
  <div class="col-12">
    <div class="card-section">
      <div class="card-header"><i class="bi bi-pen me-1 text-primary"></i>My Signature</div>
      <div class="p-3">
        <p class="small text-muted mb-3">Your signature is auto-stamped on payment vouchers that you Authorize, Approve, or Finance-Review. Draw it below or upload an image.</p>

        <?php if (!empty($fresh['signature_data'])): ?>
        <div class="mb-3">
          <div class="text-muted" style="font-size:.75rem">CURRENT SIGNATURE ON FILE</div>
          <img src="<?= htmlspecialchars($fresh['signature_data']) ?>" class="border rounded mt-1" style="max-height:80px;background:#fff;padding:4px">
        </div>
        <?php endif; ?>

        <!-- Draw pad -->
        <div class="mb-2">
          <label class="form-label small fw-semibold mb-1">Draw your signature</label>
          <canvas id="sigCanvas" width="480" height="120" style="border:1px solid #ced4da;border-radius:.375rem;cursor:crosshair;background:#fff;display:block;max-width:100%"></canvas>
          <div class="d-flex gap-2 mt-1">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearCanvas()"><i class="bi bi-eraser me-1"></i>Clear</button>
            <button type="button" class="btn btn-sm btn-primary" onclick="saveSignature('canvas')"><i class="bi bi-floppy me-1"></i>Save Drawn Signature</button>
          </div>
        </div>

        <!-- Upload -->
        <div class="mb-3">
          <label class="form-label small fw-semibold mb-1">Or upload an image</label>
          <input type="file" id="sigFile" accept="image/*" class="form-control form-control-sm" style="max-width:300px" onchange="previewUpload(this)">
          <img id="sigFilePreview" class="border rounded mt-1" style="max-height:80px;background:#fff;padding:4px;display:none">
          <div class="mt-1"><button type="button" class="btn btn-sm btn-primary" id="sigUploadBtn" onclick="saveSignature('upload')" style="display:none"><i class="bi bi-floppy me-1"></i>Save Uploaded Signature</button></div>
        </div>

        <?php if (!empty($fresh['signature_data'])): ?>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="_action" value="save_signature">
          <input type="hidden" name="signature_data" value="__clear__">
          <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Remove Signature</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
// ── Signature canvas ──────────────────────────────────────────────────────
const canvas = document.getElementById('sigCanvas');
const ctx = canvas.getContext('2d');
let drawing = false;
function getPos(e) {
  const r = canvas.getBoundingClientRect();
  const scaleX = canvas.width / r.width, scaleY = canvas.height / r.height;
  if (e.touches) return { x: (e.touches[0].clientX - r.left) * scaleX, y: (e.touches[0].clientY - r.top) * scaleY };
  return { x: (e.clientX - r.left) * scaleX, y: (e.clientY - r.top) * scaleY };
}
canvas.addEventListener('mousedown', e => { drawing=true; const p=getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); });
canvas.addEventListener('mousemove', e => { if(!drawing) return; const p=getPos(e); ctx.lineTo(p.x,p.y); ctx.strokeStyle='#111'; ctx.lineWidth=1.8; ctx.lineCap='round'; ctx.stroke(); });
canvas.addEventListener('mouseup', ()=>drawing=false);
canvas.addEventListener('mouseleave', ()=>drawing=false);
canvas.addEventListener('touchstart', e=>{e.preventDefault();drawing=true;const p=getPos(e);ctx.beginPath();ctx.moveTo(p.x,p.y);},{passive:false});
canvas.addEventListener('touchmove', e=>{e.preventDefault();if(!drawing)return;const p=getPos(e);ctx.lineTo(p.x,p.y);ctx.strokeStyle='#111';ctx.lineWidth=1.8;ctx.lineCap='round';ctx.stroke();},{passive:false});
canvas.addEventListener('touchend', ()=>drawing=false);
function clearCanvas(){ ctx.clearRect(0,0,canvas.width,canvas.height); }
function previewUpload(input) {
  const file = input.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    const preview = document.getElementById('sigFilePreview');
    preview.src = e.target.result; preview.style.display='block';
    document.getElementById('sigUploadBtn').style.display='inline-block';
  };
  reader.readAsDataURL(file);
}
function saveSignature(source) {
  let dataUrl;
  if (source === 'canvas') {
    // Check if canvas has any drawing
    const blank = document.createElement('canvas'); blank.width=canvas.width; blank.height=canvas.height;
    if (canvas.toDataURL() === blank.toDataURL()) { alert('Please draw your signature first.'); return; }
    dataUrl = canvas.toDataURL('image/png');
  } else {
    dataUrl = document.getElementById('sigFilePreview').src;
    if (!dataUrl || !dataUrl.startsWith('data:')) { alert('Please select an image file first.'); return; }
  }
  const fd = new FormData();
  fd.append('_csrf', document.querySelector('[name="_csrf"]')?.value || '');
  fd.append('_action', 'save_signature');
  fd.append('signature_data', dataUrl);
  // Use a hidden form to stay consistent with CSRF pattern
  const form = document.createElement('form'); form.method='POST'; form.style.display='none';
  const inpAction = document.createElement('input'); inpAction.name='_action'; inpAction.value='save_signature'; form.appendChild(inpAction);
  const inpSig = document.createElement('input'); inpSig.name='signature_data'; inpSig.value=dataUrl; form.appendChild(inpSig);
  // inject csrf token
  const csrfEl = document.querySelector('input[name="_csrf"]');
  if (csrfEl) { const c=csrfEl.cloneNode(); form.appendChild(c); }
  document.body.appendChild(form); form.submit();
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
