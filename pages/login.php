<?php
require_once __DIR__ . '/../config.php';
if (isLoggedIn()) { header('Location: /dashboard'); exit; }
$_cfg         = getAppConfig();
$_loginNotice = $_cfg['loginNotice'] ?? '';
$_loginLogo   = $_cfg['companyLogo']  ?? '';
$_loginName   = $_cfg['companyName']  ?? 'FieldPulse';
$_loginFavicon = $_cfg['favicon'] ?? $_loginLogo;

$error = '';
if (method() === 'POST') {
    $body = getBody();
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';
    $result = attemptLogin($username, $password);
    if ($result['ok']) {
        $user = $result['user'];
        if (!empty($user['twofa_enabled']) && !empty($user['email'])) {
            // Password is correct, but the session stays unauthenticated
            // (no user_id set) until the emailed code is also verified.
            session_regenerate_id(true);
            $_SESSION['twofa_pending_user_id'] = $user['id'];
            issueTwoFactorCode($user['id'], $user['email'], $user['name']);
            header('Location: /verify-2fa'); exit;
        }
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user']    = sanitizeUser($user);
        try { auditLog('login', 'user', $user['id']); } catch (Throwable) {}
        header('Location: /dashboard'); exit;
    }
    $error = $result['error'];
}
if (empty($error) && isset($_GET['twofa_locked'])) {
    $error = 'Too many incorrect verification codes. Please sign in again to get a new code.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign In – FieldPulse</title>
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
      <div class="brand-logo">
        <i class="bi bi-broadcast-pin"></i>
      </div>
      <?php endif; ?>
      <h4 class="fw-bold mb-0" style="font-size:1.4rem;letter-spacing:-.02em"><?= htmlspecialchars($_loginName) ?></h4>
      <p class="text-muted mt-1 mb-0" style="font-size:.8375rem">Field Service &amp; Operations Management</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger py-2 mb-3" style="font-size:.85rem">
      <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" autocomplete="on">
      <div class="mb-3">
        <label class="form-label" for="username">Username</label>
        <div class="input-group">
          <span class="input-group-text" style="border-right:0;background:#f8fafc"><i class="bi bi-person text-muted"></i></span>
          <input type="text" id="username" name="username" class="form-control" style="border-left:0"
            placeholder="admin" required autofocus autocomplete="username">
        </div>
      </div>
      <div class="mb-2">
        <label class="form-label" for="password">Password</label>
        <div class="input-group">
          <span class="input-group-text" style="border-right:0;background:#f8fafc"><i class="bi bi-lock text-muted"></i></span>
          <input type="password" id="password" name="password" class="form-control" style="border-left:0"
            placeholder="••••••••" required autocomplete="current-password">
        </div>
      </div>
      <div class="text-end mb-4">
        <a href="/forgot-password" class="text-muted" style="font-size:.8rem">Forgot password?</a>
      </div>
      <button type="submit" class="btn btn-primary w-100 fw-semibold py-2">
        <i class="bi bi-box-arrow-in-right me-1"></i>Sign In
      </button>
    </form>

    <?php if ($_loginNotice !== ''): ?>
    <div class="migration-alert mt-3 mb-0" style="white-space:pre-line">
      <i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($_loginNotice) ?>
    </div>
    <?php endif; ?>

    <div class="text-center mt-3">
      <a href="/portal" class="text-muted" style="font-size:.8rem">Customer self-service portal →</a>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
