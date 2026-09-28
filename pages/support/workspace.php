<?php
/**
 * Customer Support — agent workspace (/support).
 *
 * Look a customer up by name, account number, phone or email, see everything
 * about them on one screen (Customer 360), and log the contact with a wrap-up
 * code and outcome. Callers who aren't customers yet can be logged by name
 * and phone. Phase 1 has no telephony: calls happen on the agent's phone.
 */
require_once __DIR__ . '/../../config.php';
requireAuth();
requirePermission('support.view');

$me      = currentUser();
$viewAll = hasPermission('support.view_all');
$h       = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);

// ── Actions (POST → redirect) ────────────────────────────────────────────────
if (method() === 'POST') {
    verifyCsrf();
    $action = $_POST['_action'] ?? '';
    $back   = '/support' . (!empty($_POST['customer_id']) ? '?customer=' . urlencode($_POST['customer_id']) : '');
    $flash  = null;

    if ($action === 'log') {
        $res = csLogInteraction($_POST, $me);
        if ($res['ok']) {
            $flash = ['success', 'Interaction logged.'];
        } else {
            // Keep what the agent typed so a validation error doesn't lose it.
            $_SESSION['support_form'] = $_POST;
            $flash = ['danger', $res['error']];
            if (empty($_POST['customer_id'])) $back = '/support?new=1';
        }
    } elseif ($action === 'status') {
        csSetAgentStatus($me['id'], (string)($_POST['status'] ?? ''));
        $back = $_POST['back'] ?? '/support';
        if (!str_starts_with($back, '/support')) $back = '/support';
    } elseif ($action === 'followup_done' || $action === 'followup_cancel') {
        $f = dbFetch("SELECT * FROM cs_followups WHERE id = ?", [$_POST['followup_id'] ?? '']);
        if ($f && ($f['assigned_to'] === $me['id'] || $viewAll) && $f['status'] === 'open') {
            dbRun("UPDATE cs_followups SET status = ?, done_at = ? WHERE id = ?",
                [$action === 'followup_done' ? 'done' : 'cancelled', date('Y-m-d H:i:s'), $f['id']]);
            $flash = ['success', $action === 'followup_done' ? 'Follow-up marked done.' : 'Follow-up cancelled.'];
        }
    }
    if ($flash) $_SESSION['support_flash'] = $flash;
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['support_flash'] ?? null;
$form  = $_SESSION['support_form'] ?? [];
unset($_SESSION['support_flash'], $_SESSION['support_form']);

// ── Search ───────────────────────────────────────────────────────────────────
$q       = trim($_GET['q'] ?? '');
$results = [];
if (mb_strlen($q) >= 2) {
    $like = '%' . $q . '%';
    $results = dbFetchAll(
        "SELECT id, name, account_number, phone, email, status, plan FROM customers
         WHERE name LIKE ? OR account_number LIKE ? OR phone LIKE ? OR email LIKE ?
         ORDER BY name LIMIT 25", [$like, $like, $like, $like]);
    // Phone numbers are stored in many formats (0803…, +234803…); also match on
    // the last digits so a caller's number finds them either way.
    $key = csPhoneKey($q);
    if (strlen($key) >= 7) {
        $seen = array_column($results, 'id');
        foreach (dbFetchAll("SELECT id, name, account_number, phone, email, status, plan FROM customers WHERE phone LIKE ? LIMIT 25", ['%' . substr($key, -7) . '%']) as $r) {
            if (!in_array($r['id'], $seen, true) && csPhoneKey($r['phone']) === $key) $results[] = $r;
        }
    }
}

// ── Customer 360 ─────────────────────────────────────────────────────────────
$customer = null;
if (!empty($_GET['customer'])) {
    $customer = dbFetch("SELECT c.*, hb.name AS hub_name FROM customers c LEFT JOIN hubs hb ON hb.id = c.hub_id WHERE c.id = ?", [$_GET['customer']]);
}
$newCaller = !$customer && !empty($_GET['new']);

$tickets = $installations = $history = $custFollowups = [];
if ($customer) {
    $tickets = dbFetchAll(
        "SELECT id, ticket_number, description, status, priority, created_at FROM tickets WHERE customer_id = ?
         ORDER BY CASE WHEN status IN ('resolved','closed') THEN 1 ELSE 0 END, created_at DESC LIMIT 15", [$customer['id']]);
    $key = csPhoneKey($customer['phone'] ?? '');
    if (strlen($key) >= 7) {
        foreach (dbFetchAll("SELECT id, name, phone, status, plan, created_at, completed_at FROM installation_profiles WHERE phone LIKE ? ORDER BY created_at DESC LIMIT 10", ['%' . substr($key, -7) . '%']) as $r) {
            if (csPhoneKey($r['phone']) === $key) $installations[] = $r;
        }
    }
    $history = dbFetchAll(
        "SELECT i.*, w.category AS wrap_category, w.name AS wrap_name, t.ticket_number
         FROM cs_interactions i LEFT JOIN cs_wrap_codes w ON w.id = i.wrap_code_id LEFT JOIN tickets t ON t.id = i.ticket_id
         WHERE i.customer_id = ? ORDER BY i.created_at DESC LIMIT 20", [$customer['id']]);
    $custFollowups = dbFetchAll(
        "SELECT f.*, u.name AS assigned_name FROM cs_followups f LEFT JOIN users u ON u.id = f.assigned_to
         WHERE f.customer_id = ? AND f.status = 'open' ORDER BY f.due_at", [$customer['id']]);
}

$myFollowups = dbFetchAll(
    "SELECT * FROM cs_followups WHERE assigned_to = ? AND status = 'open' AND due_at <= ? ORDER BY due_at LIMIT 20",
    [$me['id'], date('Y-m-d 23:59:59')]);
$myStatus  = csAgentStatus($me['id']);
$wrapCodes = csWrapCodesGrouped();
$openTicketCount = count(array_filter($tickets, fn($t) => !in_array($t['status'], ['resolved', 'closed'], true)));
$startedTs = time();

$statusColors = ['available' => 'success', 'on_call' => 'danger', 'wrap_up' => 'warning', 'break' => 'secondary', 'offline' => 'dark'];
$pageTitle = 'Agent Workspace';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
  <div>
    <h2 class="fw-bold mb-0">Agent Workspace</h2>
    <div class="text-muted small">Find the customer, see their history, log the contact.</div>
  </div>
  <form method="POST" class="d-flex align-items-center gap-2">
    <?= csrfField() ?>
    <input type="hidden" name="_action" value="status">
    <input type="hidden" name="back" value="<?= $h($_SERVER['REQUEST_URI'] ?? '/support') ?>">
    <span class="badge bg-<?= $statusColors[$myStatus] ?? 'dark' ?>">&nbsp;</span>
    <select name="status" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()" aria-label="My status">
      <?php foreach (CS_AGENT_STATUSES as $k => $label): ?>
      <option value="<?= $k ?>" <?= $myStatus === $k ? 'selected' : '' ?>><?= $h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash[0] === 'danger' ? 'danger' : 'success' ?> py-2"><?= $h($flash[1]) ?></div>
<?php endif; ?>

<div class="row g-3">
  <!-- Left: search + my follow-ups -->
  <div class="col-lg-4">
    <div class="card-section mb-3">
      <div class="card-header"><i class="bi bi-search me-1 text-primary"></i>Find customer</div>
      <div class="p-3">
        <form method="GET" action="/support" class="d-flex gap-2 mb-2">
          <input type="text" name="q" value="<?= $h($q) ?>" class="form-control form-control-sm" placeholder="Name, account no., phone or email" autofocus>
          <button class="btn btn-sm btn-primary">Search</button>
        </form>
        <?php if ($q !== '' && mb_strlen($q) < 2): ?>
          <div class="small text-muted">Type at least 2 characters.</div>
        <?php elseif ($q !== ''): ?>
          <?php if (!$results): ?><div class="small text-muted mb-2">No customer matches “<?= $h($q) ?>”.</div><?php endif; ?>
          <div class="list-group list-group-flush small">
            <?php foreach ($results as $r): ?>
            <a class="list-group-item list-group-item-action px-2" href="/support?customer=<?= $h($r['id']) ?>">
              <div class="fw-semibold"><?= $h($r['name']) ?> <span class="text-muted fw-normal">· <?= $h($r['account_number']) ?></span></div>
              <div class="text-muted"><?= $h($r['phone']) ?> <?= $r['plan'] ? '· ' . $h($r['plan']) : '' ?> · <?= $h($r['status']) ?></div>
            </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <a href="/support?new=1<?= $q !== '' ? '&amp;caller=' . urlencode($q) : '' ?>" class="btn btn-sm btn-outline-secondary w-100 mt-2">
          <i class="bi bi-person-plus me-1"></i>Log a contact for a non-customer / unknown caller
        </a>
      </div>
    </div>

    <div class="card-section">
      <div class="card-header d-flex justify-content-between">
        <span><i class="bi bi-alarm me-1 text-primary"></i>My follow-ups due</span>
        <a href="/support/followups" class="small">All</a>
      </div>
      <div class="p-2">
        <?php if (!$myFollowups): ?><div class="small text-muted p-2">Nothing due today.</div><?php endif; ?>
        <?php foreach ($myFollowups as $f): $overdue = strtotime($f['due_at']) < time(); ?>
        <div class="border-bottom small p-2">
          <div class="d-flex justify-content-between">
            <?php if ($f['customer_id']): ?>
              <a href="/support?customer=<?= $h($f['customer_id']) ?>" class="fw-semibold"><?= $h($f['contact_name'] ?: $f['contact_phone']) ?></a>
            <?php else: ?>
              <span class="fw-semibold"><?= $h($f['contact_name'] ?: $f['contact_phone']) ?></span>
            <?php endif; ?>
            <span class="<?= $overdue ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= $h(date('H:i', strtotime($f['due_at']))) ?><?= $overdue ? ' overdue' : '' ?></span>
          </div>
          <div class="text-muted"><?= $h($f['contact_phone']) ?> — <?= $h(mb_strimwidth((string)$f['note'], 0, 90, '…')) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Right: customer 360 + log form -->
  <div class="col-lg-8">
    <?php if (!$customer && !$newCaller): ?>
      <div class="card-section p-4 text-center text-muted">
        <i class="bi bi-headset" style="font-size:2rem"></i>
        <div class="mt-2">Search for the customer on the left to see their details and log the contact.</div>
      </div>
    <?php endif; ?>

    <?php if ($customer): ?>
    <div class="card-section mb-3">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-person-vcard me-1 text-primary"></i><?= $h($customer['name']) ?>
          <span class="badge bg-<?= $customer['status'] === 'active' ? 'success' : 'secondary' ?> ms-1"><?= $h($customer['status']) ?></span>
          <?php if ($openTicketCount): ?><span class="badge bg-warning text-dark ms-1"><?= $openTicketCount ?> open ticket<?= $openTicketCount > 1 ? 's' : '' ?></span><?php endif; ?>
        </span>
        <?php if (hasPermission('tickets.create')): ?>
        <a href="/create-ticket?customer_id=<?= $h($customer['id']) ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg me-1"></i>New ticket</a>
        <?php endif; ?>
      </div>
      <div class="p-3 row g-2 small">
        <div class="col-sm-4"><div class="text-muted">Account no.</div><div class="fw-semibold"><?= $h($customer['account_number']) ?></div></div>
        <div class="col-sm-4"><div class="text-muted">Phone</div><div class="fw-semibold"><?= $h($customer['phone'] ?: '—') ?></div></div>
        <div class="col-sm-4"><div class="text-muted">Email</div><div class="fw-semibold text-break"><?= $h($customer['email'] ?: '—') ?></div></div>
        <div class="col-sm-4"><div class="text-muted">Plan</div><div class="fw-semibold"><?= $h($customer['plan'] ?: '—') ?></div></div>
        <div class="col-sm-4"><div class="text-muted">Expiry</div><div class="fw-semibold"><?= $h($customer['expiration'] ?: '—') ?></div></div>
        <div class="col-sm-4"><div class="text-muted">Hub</div><div class="fw-semibold"><?= $h($customer['hub_name'] ?: '—') ?></div></div>
        <div class="col-12"><div class="text-muted">Address</div><div><?= $h(trim(($customer['address'] ?: '') . ' ' . implode(', ', array_filter([$customer['mailing_city'] ?? '', $customer['mailing_state'] ?? ''])))) ?: '—' ?></div></div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($customer || $newCaller): ?>
    <div class="card-section mb-3">
      <div class="card-header"><i class="bi bi-journal-plus me-1 text-primary"></i>Log this contact</div>
      <form method="POST" class="p-3">
        <?= csrfField() ?>
        <input type="hidden" name="_action" value="log">
        <input type="hidden" name="started_ts" value="<?= $h($form['started_ts'] ?? $startedTs) ?>">
        <input type="hidden" name="customer_id" value="<?= $h($customer['id'] ?? '') ?>">
        <div class="row g-2">
          <?php if ($newCaller): ?>
          <?php $callerGuess = trim($_GET['caller'] ?? ''); $guessIsPhone = strlen(csPhoneKey($callerGuess)) >= 7; ?>
          <div class="col-sm-6">
            <label class="form-label small fw-semibold mb-1">Caller name</label>
            <input type="text" name="contact_name" class="form-control form-control-sm" value="<?= $h($form['contact_name'] ?? ($guessIsPhone ? '' : $callerGuess)) ?>">
          </div>
          <div class="col-sm-6">
            <label class="form-label small fw-semibold mb-1">Caller phone</label>
            <input type="text" name="contact_phone" class="form-control form-control-sm" value="<?= $h($form['contact_phone'] ?? ($guessIsPhone ? $callerGuess : '')) ?>">
          </div>
          <?php endif; ?>
          <div class="col-sm-4">
            <label class="form-label small fw-semibold mb-1">Channel</label>
            <select name="channel" class="form-select form-select-sm">
              <?php foreach (CS_CHANNELS as $k => $label): ?>
              <option value="<?= $k ?>" <?= ($form['channel'] ?? 'call') === $k ? 'selected' : '' ?>><?= $h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label small fw-semibold mb-1">Direction</label>
            <select name="direction" class="form-select form-select-sm">
              <option value="inbound">Inbound</option>
              <option value="outbound" <?= ($form['direction'] ?? '') === 'outbound' ? 'selected' : '' ?>>Outbound</option>
            </select>
          </div>
          <div class="col-sm-5">
            <label class="form-label small fw-semibold mb-1">Reason (wrap-up code)</label>
            <select name="wrap_code_id" class="form-select form-select-sm" required>
              <option value="">— Choose —</option>
              <?php foreach ($wrapCodes as $cat => $codes): ?>
              <optgroup label="<?= $h($cat) ?>">
                <?php foreach ($codes as $c): ?>
                <option value="<?= $h($c['id']) ?>" <?= ($form['wrap_code_id'] ?? '') === $c['id'] ? 'selected' : '' ?>><?= $h($c['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small fw-semibold mb-1">Summary</label>
            <textarea name="summary" rows="3" class="form-control form-control-sm" required placeholder="What did the customer need, and what did you do?"><?= $h($form['summary'] ?? '') ?></textarea>
          </div>
          <div class="col-sm-5">
            <label class="form-label small fw-semibold mb-1">Outcome</label>
            <select name="outcome" id="csOutcome" class="form-select form-select-sm" required>
              <?php foreach (CS_OUTCOMES as $k => $label): ?>
              <option value="<?= $k ?>" <?= ($form['outcome'] ?? 'resolved') === $k ? 'selected' : '' ?>><?= $h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($customer): ?>
          <div class="col-sm-7">
            <label class="form-label small fw-semibold mb-1">Related ticket <span class="text-muted fw-normal">(required for “Ticket created”)</span></label>
            <select name="ticket_id" class="form-select form-select-sm">
              <option value="">— None —</option>
              <?php foreach ($tickets as $t): ?>
              <option value="<?= $h($t['id']) ?>" <?= ($form['ticket_id'] ?? '') === $t['id'] ? 'selected' : '' ?>>
                <?= $h($t['ticket_number']) ?> · <?= $h($t['status']) ?> · <?= $h(mb_strimwidth((string)$t['description'], 0, 40, '…')) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Created a ticket in another tab? Reload this page to see it here.</div>
          </div>
          <?php endif; ?>
          <div class="col-sm-5">
            <label class="form-label small fw-semibold mb-1">Follow-up due <span class="text-muted fw-normal">(optional)</span></label>
            <input type="datetime-local" name="followup_due" class="form-control form-control-sm" value="<?= $h($form['followup_due'] ?? '') ?>">
          </div>
          <div class="col-sm-7">
            <label class="form-label small fw-semibold mb-1">Follow-up note</label>
            <input type="text" name="followup_note" class="form-control form-control-sm" value="<?= $h($form['followup_note'] ?? '') ?>" placeholder="e.g. Call back to confirm service is restored">
          </div>
        </div>
        <div class="mt-3 d-flex justify-content-end gap-2">
          <a href="/support" class="btn btn-sm btn-outline-secondary">Cancel</a>
          <button class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i>Save interaction</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($customer): ?>
    <div class="row g-3">
      <div class="col-md-6">
        <div class="card-section h-100">
          <div class="card-header"><i class="bi bi-ticket-perforated me-1 text-primary"></i>Tickets</div>
          <div class="p-2 small">
            <?php if (!$tickets): ?><div class="text-muted p-2">No tickets.</div><?php endif; ?>
            <?php foreach ($tickets as $t): ?>
            <a class="d-block border-bottom p-2 text-decoration-none" href="/ticket/<?= $h($t['id']) ?>" target="_blank">
              <span class="fw-semibold"><?= $h($t['ticket_number']) ?></span>
              <span class="badge bg-light text-dark border"><?= $h($t['status']) ?></span>
              <span class="badge bg-light text-dark border"><?= $h(strtoupper((string)$t['priority'])) ?></span>
              <div class="text-muted"><?= $h(mb_strimwidth((string)$t['description'], 0, 80, '…')) ?> · <?= $h(date('d M Y', strtotime($t['created_at']))) ?></div>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card-section mb-3">
          <div class="card-header"><i class="bi bi-alarm me-1 text-primary"></i>Open follow-ups</div>
          <div class="p-2 small">
            <?php if (!$custFollowups): ?><div class="text-muted p-2">None.</div><?php endif; ?>
            <?php foreach ($custFollowups as $f): ?>
            <div class="border-bottom p-2 d-flex justify-content-between gap-2">
              <div>
                <div class="fw-semibold"><?= $h(date('d M H:i', strtotime($f['due_at']))) ?> · <?= $h($f['assigned_name'] ?? '') ?></div>
                <div class="text-muted"><?= $h($f['note']) ?></div>
              </div>
              <?php if ($f['assigned_to'] === $me['id'] || $viewAll): ?>
              <form method="POST" class="flex-shrink-0">
                <?= csrfField() ?>
                <input type="hidden" name="followup_id" value="<?= $h($f['id']) ?>">
                <input type="hidden" name="customer_id" value="<?= $h($customer['id']) ?>">
                <button name="_action" value="followup_done" class="btn btn-sm btn-outline-success py-0">Done</button>
              </form>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="card-section">
          <div class="card-header"><i class="bi bi-wifi me-1 text-primary"></i>Installations <span class="text-muted small fw-normal">(matched by phone)</span></div>
          <div class="p-2 small">
            <?php if (!$installations): ?><div class="text-muted p-2">None found.</div><?php endif; ?>
            <?php foreach ($installations as $i): ?>
            <div class="border-bottom p-2">
              <span class="fw-semibold"><?= $h($i['plan'] ?: $i['name']) ?></span>
              <span class="badge bg-light text-dark border"><?= $h($i['status']) ?></span>
              <div class="text-muted">Created <?= $h(date('d M Y', strtotime($i['created_at']))) ?><?= $i['completed_at'] ? ' · completed ' . $h(date('d M Y', strtotime($i['completed_at']))) : '' ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="col-12">
        <div class="card-section">
          <div class="card-header"><i class="bi bi-clock-history me-1 text-primary"></i>Contact history</div>
          <div class="p-2 small">
            <?php if (!$history): ?><div class="text-muted p-2">No logged contacts yet.</div><?php endif; ?>
            <?php foreach ($history as $i): ?>
            <div class="border-bottom p-2">
              <div class="d-flex flex-wrap justify-content-between gap-2">
                <span>
                  <span class="fw-semibold"><?= $h(CS_CHANNELS[$i['channel']] ?? $i['channel']) ?></span>
                  <span class="text-muted">(<?= $h($i['direction']) ?>)</span> ·
                  <?= $h(($i['wrap_category'] ?? '') . ' › ' . ($i['wrap_name'] ?? '')) ?>
                  <span class="badge bg-light text-dark border"><?= $h(CS_OUTCOMES[$i['outcome']] ?? $i['outcome']) ?></span>
                  <?php if ($i['ticket_number']): ?><a href="/ticket/<?= $h($i['ticket_id']) ?>" target="_blank"><?= $h($i['ticket_number']) ?></a><?php endif; ?>
                </span>
                <span class="text-muted"><?= $h(date('d M Y H:i', strtotime($i['created_at']))) ?> · <?= $h($i['agent_name']) ?></span>
              </div>
              <div><?= nl2br($h($i['summary'])) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
