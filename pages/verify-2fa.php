<?php
require_once __DIR__ . '/../config.php';
if (isLoggedIn()) { header('Location: /dashboard'); exit; }

$pendingUserId = $_SESSION['twofa_pending_user_id'] ?? null;
if (!$pendingUserId) { header('Location: /login'); exit; }
$user = dbFetch("SELECT * FROM users WHERE id = ? AND twofa_enabled = 1", [$pendingUserId]);
if (!$user) { unset($_SESSION['twofa_pending_user_id']); header('Location: /login'); exit; }

$_cfg          = getAppConfig();
$_loginLogo    = $_cfg['companyLogo']  ?? '';
$_loginName    = $_cfg['companyName']  ?? 'FieldPulse';
$_loginFavicon = $_cfg['favicon'] ?? $_loginLogo;

$error = '';
if (method() === 'POST') {
    verifyCsrf();
    if (isset($_POST['resend'])) {
        // Light cooldown so a mis-click can't hammer the mail server.
        $lastSent = $_SESSION['twofa_last_resend'] ?? 0;
        if (time() - $lastSent >= 20) {
            issueTwoFactorCode($user['id'], $user['email'], $user['name']);
            $_SESSION['twofa_last_resend'] = time();
        }
        $error = '';
    } else {
        // Cap guesses against the current code — previously unlimited, so
        // anyone who already had a correct username/password (e.g. from a
        // breach/reuse) could brute-force the 6-digit code within its
        // 10-minute validity window. 5 attempts per 10 minutes forces
        // re-login (a fresh code) rather than letting the same code be
        // guessed indefinitely.
        if (!rateLimitCheck('twofa_verify', $user['id'], 5, 10)) {
            unset($_SESSION['twofa_pending_user_id'], $_SESSION['twofa_last_resend']);
            header('Location: /login?twofa_locked=1'); exit;
        }
        $code = trim($_POST['code'] ?? '');
        if (verifyTwoFactorCode($user['id'], $code)) {
            unset($_SESSION['twofa_pending_user_id'], $_SESSION['twofa_last_resend']);
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user']    = sanitizeUser($user);
            try { auditLog('login', 'user', $user['id'], '2fa'); } catch (Throwable) {}
            header('Location: /dashboard'); exit;
        }
        $error = 'That code is incorrect or has expired.';
    }
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Verify – <?= htmlspecialchars($_loginName) ?></title>
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
      <div class="brand-logo"><i class="bi bi-shield-lock"></i></div>
      <?php endif; ?>
      <h4 class="fw-bold mb-0" style="font-size:1.4rem;letter-spacing:-.02em">Check your email</h4>
      <p class="text-muted mt-1 mb-0" style="font-size:.8375rem">We sent a 6-digit code to <?= htmlspecialchars(preg_replace('/(?<=.).(?=.*@)/', '*', $user['email'])) ?></p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger py-2 mb-3" style="font-size:.85rem">
      <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <?= csrfField() ?>
      <div class="mb-4">
        <label class="form-label" for="code">Verification Code</label>
        <input type="text" id="code" name="code" class="form-control text-center" style="font-size:1.5rem;letter-spacing:.3em"
          placeholder="000000" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autofocus autocomplete="one-time-code">
      </div>
      <button type="submit" class="btn btn-primary w-100 fw-semibold py-2">
        <i class="bi bi-check-circle me-1"></i>Verify &amp; Sign In
      </button>
    </form>

    <form method="POST" class="mt-3">
      <?= csrfField() ?>
      <input type="hidden" name="resend" value="1">
      <button type="submit" class="btn btn-link w-100 text-muted" style="font-size:.8rem">Didn't get it? Resend code</button>
    </form>

    <div class="text-center mt-2">
      <a href="/login" class="text-muted" style="font-size:.8rem">← Back to sign in</a>
    </div>
  </div>
</div>
</body>
</html>
