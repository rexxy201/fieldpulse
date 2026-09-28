<?php
/**
 * Customer Support — WhatsApp inbox (/support/whatsapp).
 * Agents see unassigned conversations and their own; support.view_all sees
 * every conversation. Replying to an unassigned conversation takes it.
 * Free-text replies are only allowed within 24h of the customer's last
 * message (a WhatsApp rule); see includes/support-whatsapp.php.
 */
require_once __DIR__ . '/../../config.php';
requireAuth();
requirePermission('support.view');

$me      = currentUser();
$viewAll = hasPermission('support.view_all');
$h       = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$s       = csWaSettings();
$waOn    = csWaEnabled($s);

$canSee = fn(?array $c) => $c && ($viewAll || empty($c['assigned_to']) || $c['assigned_to'] === $me['id']);

if (method() === 'POST') {
    verifyCsrf();
    $conv = dbFetch("SELECT * FROM cs_wa_conversations WHERE id = ?", [$_POST['c'] ?? '']);
    if (!$canSee($conv)) { header('Location: /support/whatsapp'); exit; }
    $action = $_POST['_action'] ?? '';
    $flash  = null;
    if ($action === 'send' || $action === 'template') {
        $r = csWaSend($conv, (string)($_POST['body'] ?? ''), $me, $action === 'template', $s);
        if (!$r['ok']) { $flash = ['danger', $r['error']]; $_SESSION['wa_draft'] = (string)($_POST['body'] ?? ''); }
    } elseif ($action === 'assign_me') {
        dbRun("UPDATE cs_wa_conversations SET assigned_to = ? WHERE id = ?", [$me['id'], $conv['id']]);
    } elseif ($action === 'assign' && $viewAll) {
        $to = (string)($_POST['user_id'] ?? '');
        dbRun("UPDATE cs_wa_conversations SET assigned_to = ? WHERE id = ?", [$to !== '' && dbFetch("SELECT id FROM users WHERE id = ?", [$to]) ? $to : null, $conv['id']]);
    } elseif ($action === 'close' || $action === 'reopen') {
        dbRun("UPDATE cs_wa_conversations SET status = ? WHERE id = ?", [$action === 'close' ? 'closed' : 'open', $conv['id']]);
        if ($action === 'close') $flash = ['success', 'Conversation closed. It reopens if the customer writes again.'];
    }
    if ($flash) $_SESSION['wa_flash'] = $flash;
    header('Location: /support/whatsapp?c=' . urlencode($conv['id']) . '&view=' . urlencode($_POST['view'] ?? '')); exit;
}

$flash = $_SESSION['wa_flash'] ?? null;
$draft = $_SESSION['wa_draft'] ?? '';
unset($_SESSION['wa_flash'], $_SESSION['wa_draft']);

$views = ['queue' => 'Unassigned', 'mine' => 'Mine', 'open' => 'All open', 'closed' => 'Closed'];
$view  = isset($views[$_GET['view'] ?? '']) ? $_GET['view'] : 'queue';
$conds = match ($view) {
    'queue'  => ["c.status = 'open' AND c.assigned_to IS NULL"],
    'mine'   => ["c.status = 'open' AND c.assigned_to = ?"],
    'open'   => ["c.status = 'open'"],
    'closed' => ["c.status = 'closed'"],
};
$params = $view === 'mine' ? [$me['id']] : [];
if (!$viewAll && in_array($view, ['open', 'closed'], true)) { $conds[] = '(c.assigned_to IS NULL OR c.assigned_to = ?)'; $params[] = $me['id']; }
$list = dbFetchAll("SELECT c.*, cu.name AS customer_name, u.name AS agent_name,
        (SELECT m.body FROM cs_wa_messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) AS last_body
    FROM cs_wa_conversations c LEFT JOIN customers cu ON cu.id = c.customer_id LEFT JOIN users u ON u.id = c.assigned_to
    WHERE " . implode(' AND ', $conds) . " ORDER BY c.last_message_at DESC LIMIT 100", $params);
$counts = dbFetch("SELECT SUM(CASE WHEN status='open' AND assigned_to IS NULL THEN 1 ELSE 0 END) AS queue,
                          SUM(CASE WHEN status='open' AND assigned_to = ? THEN 1 ELSE 0 END) AS mine FROM cs_wa_conversations", [$me['id']]);

$conv = !empty($_GET['c']) ? dbFetch("SELECT c.*, cu.name AS customer_name, cu.account_number, u.name AS agent_name
    FROM cs_wa_conversations c LEFT JOIN customers cu ON cu.id = c.customer_id LEFT JOIN users u ON u.id = c.assigned_to WHERE c.id = ?", [$_GET['c']]) : null;
if ($conv && !$canSee($conv)) $conv = null;
$messages = [];
if ($conv) {
    if ((int)$conv['unread'] > 0 && ($conv['assigned_to'] === null || $conv['assigned_to'] === $me['id'])) {
        dbRun("UPDATE cs_wa_conversations SET unread = 0 WHERE id = ?", [$conv['id']]);
    }
    $messages = array_reverse(dbFetchAll("SELECT * FROM cs_wa_messages WHERE conversation_id = ? ORDER BY created_at DESC LIMIT 200", [$conv['id']]));
}
$windowOpen = $conv && csWaWindowOpen($conv);
$agents = $viewAll ? dbFetchAll("SELECT DISTINCT u.id, u.name FROM users u JOIN role_permissions rp ON rp.role = u.role
                                 WHERE rp.permission = 'support.view' OR u.role = 'admin' ORDER BY u.name") : [];
$latest = (string)(dbFetch("SELECT MAX(last_message_at) AS m FROM cs_wa_conversations")['m'] ?? '');
$tick   = ['sent' => 'bi-check', 'delivered' => 'bi-check-all', 'read' => 'bi-check-all text-primary', 'failed' => 'bi-exclamation-circle text-danger', 'queued' => 'bi-clock'];

$pageTitle = 'WhatsApp';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-end mb-3 flex-wrap gap-2">
  <div>
    <h2 class="fw-bold mb-0">WhatsApp</h2>
    <div class="text-muted small">Customer conversations on the business WhatsApp number.</div>
  </div>
  <button type="button" id="waAlertsBtn" class="btn btn-sm btn-outline-secondary d-none"><i class="bi bi-bell"></i> Enable desktop alerts</button>
</div>

<?php if (!$waOn): ?>
<div class="alert alert-warning small">WhatsApp is not connected yet<?= hasPermission('support.manage') ? ' — set it up in <a href="/support/settings">Support Settings</a>' : '; ask a manager to set it up' ?>. Existing conversations are still shown.</div>
<?php endif; ?>
<?php if ($flash): ?><div class="alert alert-<?= $h($flash[0]) ?> small"><?= $h($flash[1]) ?></div><?php endif; ?>
<div id="waNew" class="alert alert-info small d-none">New messages. <a href="">Refresh</a></div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card-section">
      <div class="card-header p-2">
        <div class="btn-group btn-group-sm w-100 flex-wrap">
          <?php foreach ($views as $k => $l): $n = in_array($k, ['queue', 'mine'], true) ? (int)($counts[$k] ?? 0) : 0; ?>
          <a href="/support/whatsapp?view=<?= $k ?>" class="btn btn-<?= $view === $k ? 'primary' : 'outline-secondary' ?>"><?= $h($l) ?><?= $n ? " <span class=\"badge bg-danger\">$n</span>" : '' ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="list-group list-group-flush wa-list">
        <?php foreach ($list as $c): ?>
        <a href="/support/whatsapp?view=<?= $view ?>&amp;c=<?= $h($c['id']) ?>" class="list-group-item list-group-item-action <?= $conv && $conv['id'] === $c['id'] ? 'active' : '' ?>">
          <div class="d-flex justify-content-between">
            <span class="fw-semibold text-truncate"><?= $h($c['customer_name'] ?: ($c['contact_name'] ?: '+' . $c['wa_phone'])) ?></span>
            <small class="text-nowrap ms-2"><?= $h(date(date('Y-m-d') === substr($c['last_message_at'], 0, 10) ? 'H:i' : 'd M', strtotime($c['last_message_at']))) ?></small>
          </div>
          <div class="d-flex justify-content-between small">
            <span class="text-truncate opacity-75"><?= $h(mb_strimwidth((string)$c['last_body'], 0, 60, '…')) ?></span>
            <?php if ((int)$c['unread'] > 0): ?><span class="badge bg-success ms-2"><?= (int)$c['unread'] ?></span><?php endif; ?>
          </div>
          <?php if ($c['agent_name'] && $view !== 'mine'): ?><div class="small opacity-75"><i class="bi bi-person"></i> <?= $h($c['agent_name']) ?></div><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php if (!$list): ?><div class="p-3 text-muted small">No conversations here.</div><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <?php if (!$conv): ?>
    <div class="card-section p-4 text-center text-muted">Pick a conversation.</div>
    <?php else:
      $logQs = ($conv['customer_id'] ? 'customer=' . urlencode($conv['customer_id']) : 'new=1&caller=' . urlencode('+' . $conv['wa_phone'])) . '&wa=' . urlencode($conv['id']); ?>
    <div class="card-section">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <div class="fw-semibold"><?= $h($conv['customer_name'] ?: ($conv['contact_name'] ?: 'Unknown contact')) ?>
            <?php if ($conv['customer_id']): ?><a href="/customers/<?= $h($conv['customer_id']) ?>" class="small ms-1" target="_blank"><?= $h($conv['account_number']) ?></a><?php endif; ?></div>
          <div class="small text-muted">+<?= $h($conv['wa_phone']) ?><?= $conv['contact_name'] && $conv['customer_name'] ? ' · WhatsApp name: ' . $h($conv['contact_name']) : '' ?>
            · <?= $conv['agent_name'] ? 'with ' . $h($conv['agent_name']) : 'unassigned' ?> · <?= $h($conv['status']) ?></div>
        </div>
        <div class="d-flex gap-1 flex-wrap">
          <a href="/support?<?= $h($logQs) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-journal-plus"></i> Log interaction</a>
          <form method="POST" class="d-flex gap-1"><?= csrfField() ?><input type="hidden" name="c" value="<?= $h($conv['id']) ?>"><input type="hidden" name="view" value="<?= $h($view) ?>">
            <?php if ($conv['assigned_to'] !== $me['id']): ?><button name="_action" value="assign_me" class="btn btn-sm btn-outline-secondary">Take it</button><?php endif; ?>
            <?php if ($viewAll): ?>
            <select name="user_id" class="form-select form-select-sm" style="width:auto" aria-label="Assign to">
              <option value="">Unassigned</option>
              <?php foreach ($agents as $a): ?><option value="<?= $h($a['id']) ?>" <?= $conv['assigned_to'] === $a['id'] ? 'selected' : '' ?>><?= $h($a['name']) ?></option><?php endforeach; ?>
            </select>
            <button name="_action" value="assign" class="btn btn-sm btn-outline-secondary">Assign</button>
            <?php endif; ?>
            <button name="_action" value="<?= $conv['status'] === 'open' ? 'close' : 'reopen' ?>" class="btn btn-sm btn-outline-<?= $conv['status'] === 'open' ? 'success' : 'secondary' ?>"><?= $conv['status'] === 'open' ? 'Close' : 'Reopen' ?></button>
          </form>
        </div>
      </div>

      <div class="wa-thread p-3" id="waThread">
        <?php $lastDay = ''; foreach ($messages as $m): $day = substr($m['created_at'], 0, 10); ?>
          <?php if ($day !== $lastDay): $lastDay = $day; ?><div class="text-center small text-muted my-2"><?= $h(date('D j M Y', strtotime($day))) ?></div><?php endif; ?>
          <div class="wa-msg wa-<?= $m['direction'] === 'in' ? 'in' : 'out' ?>">
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
        <?php if (!$windowOpen): ?>
          <div class="alert alert-warning small py-2 mb-2">More than 24 hours since the customer last wrote. WhatsApp only allows an approved template message now<?= $s['provider'] === 'meta' && $s['meta_template'] !== '' ? '' : ' — call the customer instead' ?>.</div>
          <?php if ($waOn && $s['provider'] === 'meta' && $s['meta_template'] !== ''): ?>
          <form method="POST"><?= csrfField() ?><input type="hidden" name="c" value="<?= $h($conv['id']) ?>"><input type="hidden" name="view" value="<?= $h($view) ?>">
            <button name="_action" value="template" class="btn btn-sm btn-outline-primary">Send template “<?= $h($s['meta_template']) ?>”</button>
          </form>
          <?php endif; ?>
        <?php else: ?>
        <form method="POST" class="d-flex gap-2 align-items-end"><?= csrfField() ?><input type="hidden" name="c" value="<?= $h($conv['id']) ?>"><input type="hidden" name="view" value="<?= $h($view) ?>">
          <textarea name="body" id="waReply" rows="2" class="form-control form-control-sm" maxlength="4096" placeholder="Type a reply…" <?= $waOn ? '' : 'disabled' ?> required><?= $h($draft) ?></textarea>
          <button name="_action" value="send" class="btn btn-sm btn-success" <?= $waOn ? '' : 'disabled' ?>><i class="bi bi-send"></i> Send</button>
        </form>
        <div class="small text-muted mt-1">Replies can be sent until <?= $h(date('D H:i', strtotime($conv['last_inbound_at']) + CS_WA_WINDOW)) ?>. Ctrl+Enter sends.</div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div style="height:4rem"></div><!-- keeps the floating softphone off the Send button -->

<script>
(function () {
  var t = document.getElementById('waThread'); if (t) t.scrollTop = t.scrollHeight;
  var ab = document.getElementById('waAlertsBtn');
  if (ab && 'Notification' in window && Notification.permission === 'default') {
    ab.classList.remove('d-none');
    ab.addEventListener('click', function () { Notification.requestPermission().then(function () { ab.classList.add('d-none'); }); });
  }
  var r = document.getElementById('waReply');
  if (r) r.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); r.form.querySelector('[value=send]').click(); } });
  // Check for new messages every 15s; reload only when the agent isn't typing.
  var latest = <?= json_encode($latest) ?>;
  setInterval(function () {
    if (document.hidden) return;
    fetch('/api/support-whatsapp?action=latest', { credentials: 'same-origin' }).then(function (x) { return x.json(); }).then(function (d) {
      if (!d || d.latest === latest) return;
      if (r && r.value.trim() !== '') { document.getElementById('waNew').classList.remove('d-none'); return; }
      location.reload();
    }).catch(function () {});
  }, 15000);
})();
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
