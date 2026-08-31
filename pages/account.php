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
        <div class="form-text mt-2">To change your name, email, or password, contact an admin — or use <a href="/forgot-password">Forgot Password</a> to reset it yourself.</div>
      </div>
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

<?php require __DIR__ . '/../includes/footer.php'; ?>
