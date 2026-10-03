<?php
/**
 * Customer Support — SMS and email inbox (/support/inbox).
 * Same rules as the WhatsApp inbox: agents see unassigned threads and their
 * own, support.view_all sees everything, replying to an unassigned thread
 * takes it. "New message" starts a conversation (an SMS or an email) with
 * any number or address. See includes/support-inbox.php.
 */
require_once __DIR__ . '/../../config.php';
requireAuth();
requirePermission('support.view');

$me      = currentUser();
$viewAll = hasPermission('support.view_all');
$h       = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$s       = csInboxSettings();
$smsOn   = csSmsEnabled($s);
$mailOn  = $s['email_enabled'] && $s['email_address'] !== '';

$canSee = fn(?array $t) => $t && ($viewAll || empty($t['assigned_to']) || $t['assigned_to'] === $me['id']);
$chans  = ['all' => 'All'] + CS_THREAD_CHANNELS;
$ch     = isset($chans[$_GET['ch'] ?? '']) ? $_GET['ch'] : 'all';

if (method() === 'POST') {
    verifyCsrf();
    $action = $_POST['_action'] ?? '';
    $flash  = null;
    if ($action === 'compose') {
        $channel = ($_POST['channel'] ?? '') === 'email' ? 'email' : 'sms';
        $address = csThreadAddress($channel, (string)($_POST['to'] ?? ''));
        if ($address === '' || ($channel === 'sms' && !preg_match('/^\d{7,15}$/', $address))) {
            $_SESSION['inbox_flash'] = ['danger', $channel === 'sms' ? 'Enter a valid phone number.' : 'Enter a valid email address.'];
            header('Location: /support/inbox?compose=1'); exit;
        }
        $t = csThreadFor($channel, $address, '', trim((string)($_POST['subject'] ?? '')), 'open');
        $r = csThreadSend($t, (string)($_POST['body'] ?? ''), $me, $s, trim((string)($_POST['subject'] ?? '')));
        if (!$r['ok']) $_SESSION['inbox_flash'] = ['danger', $r['error']];
        header('Location: /support/inbox?view=mine&t=' . urlencode($t['id'])); exit;
    }
    $t = dbFetch("SELECT * FROM cs_threads WHERE id = ?", [$_POST['t'] ?? '']);
    if (!$canSee($t)) { header('Location: /support/inbox'); exit; }
    if ($action === 'send') {
        $r = csThreadSend($t, (string)($_POST['body'] ?? ''), $me, $s);
        if (!$r['ok']) { $flash = ['danger', $r['error']]; $_SESSION['inbox_draft'] = (string)($_POST['body'] ?? ''); }
    } elseif ($action === 'assign_me') {
        dbRun("UPDATE cs_threads SET assigned_to = ? WHERE id = ?", [$me['id'], $t['id']]);
    } elseif ($action === 'assign' && $viewAll) {
        $to = (string)($_POST['user_id'] ?? '');
        dbRun("UPDATE cs_threads SET assigned_to = ? WHERE id = ?", [$to !== '' && dbFetch("SELECT id FROM users WHERE id = ?", [$to]) ? $to : null, $t['id']]);
    } elseif ($action === 'close' || $action === 'reopen') {
        dbRun("UPDATE cs_threads SET status = ? WHERE id = ?", [$action === 'close' ? 'closed' : 'open', $t['id']]);
        if ($action === 'close') $flash = ['success', 'Conversation closed. It reopens if the customer writes again.'];
    }
    if ($flash) $_SESSION['inbox_flash'] = $flash;
    header('Location: /support/inbox?t=' . urlencode($t['id']) . '&view=' . urlencode($_POST['view'] ?? '') . '&ch=' . urlencode($_POST['ch'] ?? '')); exit;
}

$flash = $_SESSION['inbox_flash'] ?? null;
$draft = $_SESSION['inbox_draft'] ?? '';
unset($_SESSION['inbox_flash'], $_SESSION['inbox_draft']);

$views = ['queue' => 'Unassigned', 'mine' => 'Mine', 'open' => 'All open', 'closed' => 'Closed'];
$view  = isset($views[$_GET['view'] ?? '']) ? $_GET['view'] : 'queue';
$conds = match ($view) {
    'queue'  => ["t.status = 'open' AND t.assigned_to IS NULL"],
    'mine'   => ["t.status = 'open' AND t.assigned_to = ?"],
    'open'   => ["t.status = 'open'"],
    'closed' => ["t.status = 'closed'"],
};
$params = $view === 'mine' ? [$me['id']] : [];
if (!$viewAll && in_array($view, ['open', 'closed'], true)) { $conds[] = '(t.assigned_to IS NULL OR t.assigned_to = ?)'; $params[] = $me['id']; }
if ($ch !== 'all') { $conds[] = 't.channel = ?'; $params[] = $ch; }
$list = dbFetchAll("SELECT t.*, cu.name AS customer_name, u.name AS agent_name,
        (SELECT m.body FROM cs_thread_messages m WHERE m.thread_id = t.id ORDER BY m.created_at DESC LIMIT 1) AS last_body
    FROM cs_threads t LEFT JOIN customers cu ON cu.id = t.customer_id LEFT JOIN users u ON u.id = t.assigned_to
    WHERE " . implode(' AND ', $conds) . " ORDER BY t.last_message_at DESC LIMIT 100", $params);
$counts = dbFetch("SELECT SUM(CASE WHEN status='open' AND assigned_to IS NULL THEN 1 ELSE 0 END) AS queue,
                          SUM(CASE WHEN status='open' AND assigned_to = ? THEN 1 ELSE 0 END) AS mine FROM cs_threads", [$me['id']]);

$thread = !empty($_GET['t']) ? dbFetch("SELECT t.*, cu.name AS customer_name, cu.account_number, u.name AS agent_name
    FROM cs_threads t LEFT JOIN customers cu ON cu.id = t.customer_id LEFT JOIN users u ON u.id = t.assigned_to WHERE t.id = ?", [$_GET['t']]) : null;
if ($thread && !$canSee($thread)) $thread = null;
$messages = [];
if ($thread) {
    if ((int)$thread['unread'] > 0 && ($thread['assigned_to'] === null || $thread['assigned_to'] === $me['id'])) {
        dbRun("UPDATE cs_threads SET unread = 0 WHERE id = ?", [$thread['id']]);
    }
    $messages = array_reverse(dbFetchAll("SELECT * FROM cs_thread_messages WHERE thread_id = ? ORDER BY created_at DESC LIMIT 200", [$thread['id']]));
}
$agents = $viewAll ? dbFetchAll("SELECT DISTINCT u.id, u.name FROM users u JOIN role_permissions rp ON rp.role = u.role
                                 WHERE rp.permission = 'support.view' OR u.role = 'admin' ORDER BY u.name") : [];
$latest = (string)(dbFetch("SELECT MAX(last_message_at) AS m FROM cs_threads")['m'] ?? '');
$tick   = ['sent' => 'bi-check', 'delivered' => 'bi-check-all', 'failed' => 'bi-exclamation-circle text-danger'];
$who    = fn($t) => $t['customer_name'] ?: ($t['contact_name'] ?: ($t['channel'] === 'sms' ? '+' . $t['address'] : $t['address']));
$canReply = $thread && ($thread['channel'] === 'sms' ? $smsOn : $mailOn);
$compose  = !empty($_GET['compose']);

$pageTitle = 'Inbox';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-end mb-3 flex-wrap gap-2">
  <div>
    <h2 class="fw-bold mb-0">Inbox</h2>
    <div class="text-muted small">Customer SMS and email<?= $mailOn ? ' to ' . $h($s['email_address']) : '' ?>. WhatsApp has its <a href="/support/whatsapp">own inbox</a>.</div>
  </div>
  <a href="/support/inbox?compose=1" class="btn btn-sm btn-primary"><i class="bi bi-pencil-square"></i> New message</a>
</div>

<?php if (!$smsOn || !$mailOn): ?>
<div class="alert alert-warning small"><?= !$smsOn && !$mailOn ? 'SMS and email are' : (!$smsOn ? 'SMS is' : 'Email is') ?> not connected yet<?= hasPermission('support.manage') ? ' — set it up in <a href="/support/settings">Support Settings</a>' : '; ask a manager to set it up' ?>.</div>
<?php endif; ?>
<?php if ($flash): ?><div class="alert alert-<?= $h($flash[0]) ?> small"><?= $h($flash[1]) ?></div><?php endif; ?>
<div id="inNew" class="alert alert-info small d-none">New messages. <a href="">Refresh</a></div>

<?php if ($compose): ?>
<div class="card-section mb-3">
  <div class="card-header"><i class="bi bi-pencil-square me-1 text-primary"></i>New message</div>
  <form method="POST" class="p-3 row g-2">
    <?= csrfField() ?><input type="hidden" name="_action" value="compose">
    <div class="col-sm-2">
      <label class="form-label small fw-semibold mb-1">Send by</label>
      <select name="channel" id="inChan" class="form-select form-select-sm">
        <option value="sms" <?= $smsOn ? '' : 'disabled' ?>>SMS</option>
        <option value="email" <?= $mailOn ? '' : 'disabled' ?> <?= !$smsOn && $mailOn ? 'selected' : '' ?>>Email</option>
      </select>
    </div>
    <div class="col-sm-4">
      <label class="form-label small fw-semibold mb-1">To</label>
      <input type="text" name="to" class="form-control form-control-sm" required placeholder="0803… or name@example.com" value="<?= $h($_GET['to'] ?? '') ?>">
    </div>
    <div class="col-sm-6 in-email">
      <label class="form-label small fw-semibold mb-1">Subject</label>
      <input type="text" name="subject" class="form-control form-control-sm" maxlength="200">
    </div>
    <div class="col-12">
      <textarea name="body" rows="3" class="form-control form-control-sm" required placeholder="Message…"></textarea>
    </div>
    <div class="col-12 d-flex justify-content-between">
      <a href="/support/inbox" class="btn btn-sm btn-link">Cancel</a>
      <button class="btn btn-sm btn-primary" <?= $smsOn || $mailOn ? '' : 'disabled' ?>><i class="bi bi-send"></i> Send</button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card-section">
      <div class="card-header p-2">
        <div class="btn-group btn-group-sm w-100 flex-wrap mb-1">
          <?php foreach ($views as $k => $l): $n = in_array($k, ['queue', 'mine'], true) ? (int)($counts[$k] ?? 0) : 0; ?>
          <a href="/support/inbox?view=<?= $k ?>&amp;ch=<?= $ch ?>" class="btn btn-<?= $view === $k ? 'primary' : 'outline-secondary' ?>"><?= $h($l) ?><?= $n ? " <span class=\"badge bg-danger\">$n</span>" : '' ?></a>
          <?php endforeach; ?>
        </div>
        <div class="btn-group btn-group-sm w-100">
          <?php foreach ($chans as $k => $l): ?><a href="/support/inbox?view=<?= $view ?>&amp;ch=<?= $k ?>" class="btn btn-<?= $ch === $k ? 'secondary' : 'outline-secondary' ?>"><?= $h($l) ?></a><?php endforeach; ?>
        </div>
      </div>
      <div class="list-group list-group-flush wa-list">
        <?php foreach ($list as $t): ?>
        <a href="/support/inbox?view=<?= $view ?>&amp;ch=<?= $ch ?>&amp;t=<?= $h($t['id']) ?>" class="list-group-item list-group-item-action <?= $thread && $thread['id'] === $t['id'] ? 'active' : '' ?>">
          <div class="d-flex justify-content-between">
            <span class="fw-semibold text-truncate"><i class="bi <?= $t['channel'] === 'sms' ? 'bi-chat-dots' : 'bi-envelope' ?> me-1"></i><?= $h($who($t)) ?></span>
            <small class="text-nowrap ms-2"><?= $h(date(date('Y-m-d') === substr($t['last_message_at'], 0, 10) ? 'H:i' : 'd M', strtotime($t['last_message_at']))) ?></small>
          </div>
          <?php if ($t['channel'] === 'email' && $t['subject'] !== ''): ?><div class="small fw-semibold text-truncate"><?= $h($t['subject']) ?></div><?php endif; ?>
          <div class="d-flex justify-content-between small">
            <span class="text-truncate opacity-75"><?= $h(mb_strimwidth((string)$t['last_body'], 0, 60, '…')) ?></span>
            <?php if ((int)$t['unread'] > 0): ?><span class="badge bg-success ms-2"><?= (int)$t['unread'] ?></span><?php endif; ?>
          </div>
          <?php if ($t['agent_name'] && $view !== 'mine'): ?><div class="small opacity-75"><i class="bi bi-person"></i> <?= $h($t['agent_name']) ?></div><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php if (!$list): ?><div class="p-3 text-muted small">No conversations here.</div><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <?php if (!$thread): ?>
    <div class="card-section p-4 text-center text-muted">Pick a conversation.</div>
    <?php else:
      $contact = $thread['channel'] === 'sms' ? '+' . $thread['address'] : $thread['address'];
      $logQs = ($thread['customer_id'] ? 'customer=' . urlencode($thread['customer_id']) : 'new=1&caller=' . urlencode($contact)); ?>
    <div class="card-section">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <div class="fw-semibold"><i class="bi <?= $thread['channel'] === 'sms' ? 'bi-chat-dots' : 'bi-envelope' ?> me-1"></i><?= $h($who($thread)) ?>
            <?php if ($thread['customer_id']): ?><a href="/support?customer=<?= $h($thread['customer_id']) ?>" class="small ms-1"><?= $h($thread['account_number']) ?></a><?php endif; ?></div>
          <div class="small text-muted"><?= $h($contact) ?> · <?= $thread['agent_name'] ? 'with ' . $h($thread['agent_name']) : 'unassigned' ?> · <?= $h($thread['status']) ?></div>
        </div>
        <div class="d-flex gap-1 flex-wrap">
          <a href="/support?<?= $h($logQs) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-journal-plus"></i> Log interaction</a>
          <form method="POST" class="d-flex gap-1"><?= csrfField() ?><input type="hidden" name="t" value="<?= $h($thread['id']) ?>"><input type="hidden" name="view" value="<?= $h($view) ?>"><input type="hidden" name="ch" value="<?= $h($ch) ?>">
            <?php if ($thread['assigned_to'] !== $me['id']): ?><button name="_action" value="assign_me" class="btn btn-sm btn-outline-secondary">Take it</button><?php endif; ?>
            <?php if ($viewAll): ?>
            <select name="user_id" class="form-select form-select-sm" style="width:auto" aria-label="Assign to">
              <option value="">Unassigned</option>
              <?php foreach ($agents as $a): ?><option value="<?= $h($a['id']) ?>" <?= $thread['assigned_to'] === $a['id'] ? 'selected' : '' ?>><?= $h($a['name']) ?></option><?php endforeach; ?>
            </select>
            <button name="_action" value="assign" class="btn btn-sm btn-outline-secondary">Assign</button>
            <?php endif; ?>
            <button name="_action" value="<?= $thread['status'] === 'open' ? 'close' : 'reopen' ?>" class="btn btn-sm btn-outline-<?= $thread['status'] === 'open' ? 'success' : 'secondary' ?>"><?= $thread['status'] === 'open' ? 'Close' : 'Reopen' ?></button>
          </form>
        </div>
      </div>

      <div class="wa-thread p-3" id="inThread">
        <?php $lastDay = ''; foreach ($messages as $m): $day = substr($m['created_at'], 0, 10); ?>
          <?php if ($day !== $lastDay): $lastDay = $day; ?><div class="text-center small text-muted my-2"><?= $h(date('D j M Y', strtotime($day))) ?></div><?php endif; ?>
          <div class="wa-msg wa-<?= $m['direction'] === 'in' ? 'in' : 'out' ?>">
            <?php if ($thread['channel'] === 'email' && $m['subject'] !== ''): ?><div class="small fw-semibold"><?= $h($m['subject']) ?></div><?php endif; ?>
            <div class="wa-body"><?= nl2br($h($m['body'])) ?></div>
            <div class="wa-meta">
              <?= $m['direction'] === 'out' ? $h($m['agent_name'] ?: '') . ' · ' : '' ?><?= $h(date('H:i', strtotime($m['created_at']))) ?>
              <?php if ($m['direction'] === 'out'): ?><i class="bi <?= $tick[$m['status']] ?? 'bi-check' ?>" title="<?= $h($m['status'] . ($m['error'] ? ': ' . $m['error'] : '')) ?>"></i><?php endif; ?>
            </div>
            <?php if ($m['status'] === 'failed' && $m['error']): ?><div class="small text-danger"><?= $h($m['error']) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="p-3 border-top">
        <form method="POST" class="d-flex gap-2 align-items-end"><?= csrfField() ?><input type="hidden" name="t" value="<?= $h($thread['id']) ?>"><input type="hidden" name="view" value="<?= $h($view) ?>"><input type="hidden" name="ch" value="<?= $h($ch) ?>">
          <textarea name="body" id="inReply" rows="<?= $thread['channel'] === 'email' ? 4 : 2 ?>" class="form-control form-control-sm" <?= $thread['channel'] === 'sms' ? 'maxlength="918"' : '' ?>
            placeholder="<?= $thread['channel'] === 'sms' ? 'Type an SMS reply…' : 'Type an email reply (sent from ' . $h($s['email_address']) . ')…' ?>" <?= $canReply ? '' : 'disabled' ?> required><?= $h($draft) ?></textarea>
          <button name="_action" value="send" class="btn btn-sm btn-success" <?= $canReply ? '' : 'disabled' ?>><i class="bi bi-send"></i> Send</button>
        </form>
        <div class="small text-muted mt-1"><?php if ($thread['channel'] === 'sms'): ?><span id="inCount">0</span> characters · each 160 is one SMS. <?php endif; ?>Ctrl+Enter sends.</div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div style="height:4rem"></div><!-- keeps the floating softphone off the Send button -->

<script>
(function () {
  var t = document.getElementById('inThread'); if (t) t.scrollTop = t.scrollHeight;
  var r = document.getElementById('inReply'), cnt = document.getElementById('inCount');
  if (r) r.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); r.form.querySelector('[value=send]').click(); } });
  if (r && cnt) { var upd = function () { cnt.textContent = r.value.length; }; r.addEventListener('input', upd); upd(); }
  var chan = document.getElementById('inChan');
  if (chan) { var sync = function () { document.querySelectorAll('.in-email').forEach(function (e) { e.style.display = chan.value === 'email' ? '' : 'none'; }); }; chan.addEventListener('change', sync); sync(); }
  var latest = <?= json_encode($latest) ?>;
  setInterval(function () {
    if (document.hidden) return;
    fetch('/api/support-inbox?action=latest', { credentials: 'same-origin' }).then(function (x) { return x.json(); }).then(function (d) {
      if (!d || d.latest === latest) return;
      if (r && r.value.trim() !== '') { document.getElementById('inNew').classList.remove('d-none'); return; }
      if (document.querySelector('form [name=to]')) return;   // composing
      location.reload();
    }).catch(function () {});
  }, 15000);
})();
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
