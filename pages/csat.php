<?php
require_once __DIR__ . '/../config.php';
// Public page — no auth. Access is gated by the per-ticket csat_token instead,
// same trust model as a password-reset link: possession of the (unguessable,
// emailed-only) token is the credential.

$_cfg = getAppConfig();
$_companyName = $_cfg['companyName'] ?? 'FieldPulse';
$_favicon = $_cfg['favicon'] ?? ($_cfg['companyLogo'] ?? '');

$ticketId = trim($_GET['ticket'] ?? $_POST['ticket'] ?? '');
$token    = trim($_GET['token'] ?? $_POST['token'] ?? '');
$score    = $_GET['score'] ?? null;

$ticket = ($ticketId && $token) ? dbFetch("SELECT * FROM tickets WHERE id = ? AND csat_token = ?", [$ticketId, $token]) : null;

$error = '';
$saved = false;
if ($ticket && $score !== null && method() === 'GET') {
    $score = (int)$score;
    if ($score < 1 || $score > 5) {
        $error = 'Invalid rating.';
    } elseif (empty($ticket['csat_submitted_at'])) {
        dbRun("UPDATE tickets SET csat_score=?, csat_submitted_at=NOW() WHERE id=?", [$score, $ticketId]);
        $ticket['csat_score'] = $score;
        $saved = true;
    } else {
        // Already rated — clicking an old link again just re-shows the thank-you,
        // doesn't overwrite (a comment submitted afterward still can, below).
        $saved = true;
    }
}

if ($ticket && method() === 'POST') {
    verifyCsrf();
    $comment = trim($_POST['comment'] ?? '');
    if ($comment !== '') {
        dbRun("UPDATE tickets SET csat_comment=? WHERE id=?", [$comment, $ticketId]);
    }
    $saved = true;
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Feedback – <?= htmlspecialchars($_companyName) ?></title>
<?php if ($_favicon): ?><link rel="icon" href="<?= htmlspecialchars($_favicon) ?>"><?php endif; ?>
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
      <div class="brand-logo"><i class="bi bi-chat-heart"></i></div>
      <h4 class="fw-bold mb-0" style="font-size:1.4rem;letter-spacing:-.02em"><?= htmlspecialchars($_companyName) ?></h4>
    </div>

    <?php if (!$ticket): ?>
    <div class="alert alert-danger py-2 mb-0" style="font-size:.85rem">
      <i class="bi bi-exclamation-circle me-1"></i>This feedback link is invalid or has expired.
    </div>

    <?php elseif ($error): ?>
    <div class="alert alert-danger py-2 mb-0" style="font-size:.85rem">
      <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?>
    </div>

    <?php elseif ($saved && empty($_POST)): ?>
    <div class="alert alert-success py-2 mb-3" style="font-size:.85rem">
      <i class="bi bi-check-circle me-1"></i>Thanks for rating ticket <strong><?= htmlspecialchars($ticket['ticket_number']) ?></strong> — <?= (int)$ticket['csat_score'] ?>/5.
    </div>
    <p class="text-muted mb-3" style="font-size:.8375rem">Want to tell us more? Totally optional.</p>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="ticket" value="<?= htmlspecialchars($ticketId) ?>">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <div class="mb-3">
        <textarea name="comment" class="form-control" rows="3" placeholder="What went well, or what could be better?"><?= htmlspecialchars($ticket['csat_comment'] ?? '') ?></textarea>
      </div>
      <button type="submit" class="btn btn-primary w-100 fw-semibold py-2">Send Feedback</button>
    </form>

    <?php elseif ($saved): ?>
    <div class="alert alert-success py-2 mb-0" style="font-size:.85rem">
      <i class="bi bi-check-circle me-1"></i>Thank you — your feedback has been recorded.
    </div>

    <?php else: ?>
    <p class="text-muted text-center mb-0" style="font-size:.8375rem">Something went wrong. Please use the link from your email again.</p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
