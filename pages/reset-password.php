<?php
require_once __DIR__ . '/../config.php';
if (isLoggedIn()) { header('Location: /dashboard'); exit; }
$_cfg          = getAppConfig();
$_loginLogo    = $_cfg['companyLogo']  ?? '';
$_loginName    = $_cfg['companyName']  ?? 'FieldPulse';
$_loginFavicon = $_cfg['favicon'] ?? $_loginLogo;

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$resetUser = $token ? findUserByResetToken($token) : null;

$error = '';
$done  = false;
if (method() === 'POST' && $resetUser) {
    verifyCsrf();
    $pw  = $_POST['password'] ?? '';
    $pw2 = $_POST['password_confirm'] ?? '';
    if (strlen($pw) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pw !== $pw2) {
        $error = 'Passwords do not match.';
    } else {
        completePasswordReset($resetUser['id'], $pw);
        try { auditLog('password_reset', 'user', $resetUser['id']); } catch (Throwable) {}
        $done = true;
    }
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset Password – FieldPulse</title>
<?php if ($_loginFavicon): ?><link rel="icon" href="<?= htmlspecialchars($_loginFavicon) ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/assets/style.css" rel="stylesheet">
</head>
<body>
<div class="login-wrapper">
  <div class="login-card">

    <div class="text-center mb-4">
      <?php if ($_loginLogo): ?>
      <div class="brand-logo" style="width:auto;height:auto;background:none;border-radius:0;padding:0;margin-bottom:1rem">
        <img src="<?= htmlspecialchars($_loginLogo) ?>" alt="<?= htmlspecialchars($_loginName) ?>"
             style="max-height:72px;max-width:200px;width:auto;height:auto;object-fit:contain;display:block;margin:0 auto">
      </div>
      <?php else: ?>
      <div class="brand-logo"><i class="bi bi-broadcast-pin"></i></div>
      <?php endif; ?>
      <h4 class="fw-bold mb-0" style="font-size:1.4rem;letter-spacing:-.02em">Set a new password</h4>
    </div>

    <?php if ($done): ?>
    <div class="alert alert-success py-2 mb-3" style="font-size:.85rem">
      <i class="bi bi-check-circle me-1"></i>Your password has been reset. You can now sign in.
    </div>
    <a href="/login" class="btn btn-primary w-100 fw-semibold py-2">
      <i class="bi bi-box-arrow-in-right me-1"></i>Sign In
    </a>

    <?php elseif (!$resetUser): ?>
    <div class="alert alert-danger py-2 mb-3" style="font-size:.85rem">
      <i class="bi bi-exclamation-circle me-1"></i>This reset link is invalid or has expired.
    </div>
    <a href="/forgot-password" class="btn btn-primary w-100 fw-semibold py-2">
      Request a new link
    </a>

    <?php else: ?>
    <?php if ($error): ?>
    <div class="alert alert-danger py-2 mb-3" style="font-size:.85rem">
      <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <p class="text-muted mb-3" style="font-size:.8375rem">Resetting the password for <strong><?= htmlspecialchars($resetUser['username']) ?></strong>.</p>

    <form method="POST" autocomplete="off">
      <?= csrfField() ?>
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <div class="mb-3">
        <label class="form-label" for="password">New Password</label>
        <div class="input-group">
          <span class="input-group-text" style="border-right:0;background:#f8fafc"><i class="bi bi-lock text-muted"></i></span>
          <input type="password" id="password" name="password" class="form-control" style="border-left:0"
            placeholder="At least 8 characters" required minlength="8" autofocus autocomplete="new-password">
        </div>
      </div>
      <div class="mb-4">
        <label class="form-label" for="password_confirm">Confirm Password</label>
        <div class="input-group">
          <span class="input-group-text" style="border-right:0;background:#f8fafc"><i class="bi bi-lock text-muted"></i></span>
          <input type="password" id="password_confirm" name="password_confirm" class="form-control" style="border-left:0"
            placeholder="Retype the password" required minlength="8" autocomplete="new-password">
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-100 fw-semibold py-2">
        <i class="bi bi-check-circle me-1"></i>Reset Password
      </button>
    </form>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
