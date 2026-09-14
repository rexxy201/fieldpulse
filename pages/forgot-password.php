<?php
require_once __DIR__ . '/../config.php';
if (isLoggedIn()) { header('Location: /dashboard'); exit; }
$_cfg          = getAppConfig();
$_loginLogo    = $_cfg['companyLogo']  ?? '';
$_loginName    = $_cfg['companyName']  ?? 'FieldPulse';
$_loginFavicon = $_cfg['favicon'] ?? $_loginLogo;

$sent = false;
$error = '';
if (method() === 'POST') {
    verifyCsrf();
    $email = trim($_POST['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (!rateLimitCheck('password_reset_request', strtolower($email), 3, 60)) {
        // Previously unlimited — an attacker could mail-bomb any address by
        // repeatedly POSTing here. Same "always success" response either
        // way, so this still doesn't reveal whether the email has an account.
        $sent = true;
    } else {
        // Always the same outcome whether or not the email exists — issuePasswordReset()
        // silently no-ops for an unknown/inactive email, so this can't be used to
        // discover which addresses have accounts.
        issuePasswordReset($email);
        $sent = true;
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
      <h4 class="fw-bold mb-0" style="font-size:1.4rem;letter-spacing:-.02em">Reset your password</h4>
      <p class="text-muted mt-1 mb-0" style="font-size:.8375rem">Enter your account email and we'll send you a reset link.</p>
    </div>

    <?php if ($sent): ?>
    <div class="alert alert-success py-2 mb-3" style="font-size:.85rem">
      <i class="bi bi-check-circle me-1"></i>If that email has an account, a reset link is on its way — check your inbox. The link expires in <?= PASSWORD_RESET_MINUTES ?> minutes.
    </div>
    <div class="text-center mt-3">
      <a href="/login" class="text-muted" style="font-size:.8rem">← Back to sign in</a>
    </div>
    <?php else: ?>

    <?php if ($error): ?>
    <div class="alert alert-danger py-2 mb-3" style="font-size:.85rem">
      <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" autocomplete="on">
      <?= csrfField() ?>
      <div class="mb-4">
        <label class="form-label" for="email">Email</label>
        <div class="input-group">
          <span class="input-group-text" style="border-right:0;background:#f8fafc"><i class="bi bi-envelope text-muted"></i></span>
          <input type="email" id="email" name="email" class="form-control" style="border-left:0"
            placeholder="you@example.com" required autofocus autocomplete="email">
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-100 fw-semibold py-2">
        <i class="bi bi-send me-1"></i>Send Reset Link
      </button>
    </form>

    <div class="text-center mt-3">
      <a href="/login" class="text-muted" style="font-size:.8rem">← Back to sign in</a>
    </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
