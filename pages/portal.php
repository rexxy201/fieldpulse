<?php
require_once __DIR__ . '/../config.php';
// Public page — no auth required
$_appCfg      = getAppConfig();
$_companyName = $_appCfg['companyName'] ?? 'FieldPulse';
$_favicon     = $_appCfg['favicon'] ?? ($_appCfg['companyLogo'] ?? '');
$_primaryColor = $_appCfg['primaryColor'] ?? '#0ea5e9';
$_hex = ltrim($_primaryColor, '#');
if (strlen($_hex) === 3) $_hex = $_hex[0].$_hex[0].$_hex[1].$_hex[1].$_hex[2].$_hex[2];
$_pr = hexdec(substr($_hex,0,2));
$_pg = hexdec(substr($_hex,2,2));
$_pb = hexdec(substr($_hex,4,2));

$results    = [];
$searched   = false;
$account    = '';
$cust       = null;
$onus       = [];
$raiseSuccess = null;
$raiseError   = '';

$faultTypes = dbFetchAll("SELECT id,name,category FROM fault_types WHERE enabled=1 ORDER BY category,name");

// ── Handle ticket submission ─────────────────────────────────────────────────
// Shares the lookup rate limit below: raising a ticket also looks the account
// up and shows its tickets and ONUs, so without it this path was an unlimited
// way around the lookup throttle for enumerating account numbers.
$raiseRateLimited = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'raise_ticket') {
    verifyCsrf();
    $raiseRateLimited = !rateLimitCheck('portal_lookup', clientIp(), 20, 15);
    $account = trim($_POST['account'] ?? '');
    $cust    = ($account && !$raiseRateLimited) ? dbFetch("SELECT * FROM customers WHERE account_number = ?", [$account]) : null;

    if ($raiseRateLimited) {
        $raiseError = 'Too many requests. Please wait a few minutes and try again.';
    } elseif (!$cust) {
        $raiseError = 'Account not found. Please look up your account first.';
    } elseif (empty(trim($_POST['description'] ?? ''))) {
        $raiseError = 'Please describe your issue.';
    } else {
        $priority = in_array($_POST['priority'] ?? '', TICKET_PRIORITIES, true) ? $_POST['priority'] : 'p3';
        $sla      = dbFetch("SELECT resolution_time_hours FROM sla_configs WHERE priority = ?", [$priority]);
        $hours    = $sla ? (int)$sla['resolution_time_hours'] : 24;
        $ftId     = !empty($_POST['fault_type_id']) ? trim($_POST['fault_type_id']) : null;

        $ticketNum = generateTicketNumber('INC');
        $newId = newUuid();
        $_slaExpr = dbNowPlusInterval($hours, 'HOUR');

        $supervisor = $ftId ? getAutoAssignSupervisor($ftId) : null;
        $assignedTo = $supervisor['id'] ?? null;

        dbRun(
            "INSERT INTO tickets (id,ticket_number,description,priority,type,status,customer_id,customer_name,fault_type_id,assigned_to,sla_breach_at)
             VALUES (?,?,?,?,?,?,?,?,?,?, {$_slaExpr})",
            [$newId, $ticketNum, trim($_POST['description']), $priority, 'fault', 'open',
             $cust['id'], $cust['name'], $ftId, $assignedTo]
        );
        $row = dbFetch("SELECT * FROM tickets WHERE id = ?", [$newId]);
        auditLog('create', 'ticket', $row['id']);
        $raiseSuccess = $row['ticket_number'];

        emailCustomerTicketCreated($row, $cust);

        if ($supervisor) {
            if (!empty($supervisor['email'])) emailTicketAssigned($row, $supervisor);
            notifyUser(
                $supervisor['id'],
                "Customer Ticket Assigned — {$row['ticket_number']}",
                "{$cust['name']}: " . substr(trim($_POST['description']), 0, 90),
                "/ticket/{$row['id']}"
            );
        }

        $priorityLabelsN = ['p1'=>'Critical','p2'=>'High','p3'=>'Medium','p4'=>'Low'];
        $pLabel = $priorityLabelsN[$priority] ?? $priority;
        notifyRoles(
            ['admin','project_admin','cx_supervisor','supervisor-fiber','supervisor-noc'],
            "Customer Ticket Raised — {$row['ticket_number']}",
            "{$cust['name']} raised a new {$pLabel} ticket: " . substr(trim($_POST['description']), 0, 100),
            "/ticket/{$row['id']}"
        );
    }

    $searched = true;
    if ($cust) {
        $results = dbFetchAll(
            "SELECT id,ticket_number,description,status,priority,created_at,resolved_at
             FROM tickets WHERE customer_id = ? ORDER BY created_at DESC",
            [$cust['id']]
        );
        $onus = dbFetchAll(
            "SELECT serial_number,description,status,rx_power_dbm,last_online_at
             FROM onu_units WHERE customer_id = ? ORDER BY status,serial_number",
            [$cust['id']]
        );
    }
}

// ── Handle account lookup ────────────────────────────────────────────────────
// Rate-limited to 20 lookups per 15 minutes per IP to prevent enumeration.
$lookupRateLimited = false;
$isLookupAttempt = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'lookup')
    || (isset($_GET['account']) && ($_POST['_action'] ?? '') !== 'raise_ticket');
if ($isLookupAttempt && !rateLimitCheck('portal_lookup', clientIp(), 20, 15)) {
    $lookupRateLimited = true;
}

if ($lookupRateLimited) {
    $searched = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'lookup') {
    verifyCsrf();
    $account = trim($_POST['account'] ?? '');
    if ($account) {
        $searched = true;
        $cust = dbFetch("SELECT * FROM customers WHERE account_number = ?", [$account]);
        if ($cust) {
            $results = dbFetchAll(
                "SELECT id,ticket_number,description,status,priority,created_at,resolved_at
                 FROM tickets WHERE customer_id = ? ORDER BY created_at DESC",
                [$cust['id']]
            );
            $onus = dbFetchAll(
                "SELECT serial_number,description,status,rx_power_dbm,last_online_at
                 FROM onu_units WHERE customer_id = ? ORDER BY status,serial_number",
                [$cust['id']]
            );
        }
    }
} elseif (isset($_GET['account']) && ($_POST['_action'] ?? '') !== 'raise_ticket') {
    $account = trim($_GET['account'] ?? '');
    if ($account) {
        $searched = true;
        $cust = dbFetch("SELECT * FROM customers WHERE account_number = ?", [$account]);
        if ($cust) {
            $results = dbFetchAll(
                "SELECT id,ticket_number,description,status,priority,created_at,resolved_at
                 FROM tickets WHERE customer_id = ? ORDER BY created_at DESC",
                [$cust['id']]
            );
            $onus = dbFetchAll(
                "SELECT serial_number,description,status,rx_power_dbm,last_online_at
                 FROM onu_units WHERE customer_id = ? ORDER BY status,serial_number",
                [$cust['id']]
            );
        }
    }
}

$ticketStatusColors = [
    'open'                 => 'primary',
    'in_progress'          => 'info',
    'resolved'             => 'success',
    'closed'               => 'secondary',
    'pending_confirmation' => 'warning',
];
$priorityLabels = ['p1'=>'Critical','p2'=>'High','p3'=>'Medium','p4'=>'Low'];

// ONU status display config
$onuStatusCfg = [
    'working'     => ['color'=>'success',   'icon'=>'bi-wifi',              'label'=>'Connected'],
    'offline'     => ['color'=>'danger',    'icon'=>'bi-wifi-off',          'label'=>'Offline'],
    'los'         => ['color'=>'danger',    'icon'=>'bi-exclamation-circle','label'=>'Loss of Signal'],
    'dying_gasp'  => ['color'=>'warning',   'icon'=>'bi-battery',           'label'=>'Dying Gasp'],
    'lof'         => ['color'=>'warning',   'icon'=>'bi-reception-0',       'label'=>'Loss of Frame'],
    'unknown'     => ['color'=>'secondary', 'icon'=>'bi-question-circle',   'label'=>'Unknown'],
];

// Derive overall connection status from ONUs
$overallOnuStatus = 'none'; // no ONU linked
if ($onus) {
    // If any ONU is working → connected; else worst status wins
    $hasWorking = false;
    $hasDanger  = false;
    $hasWarning = false;
    foreach ($onus as $o) {
        if ($o['status'] === 'working') $hasWorking = true;
        if (in_array($o['status'], ['offline','los'])) $hasDanger = true;
        if (in_array($o['status'], ['dying_gasp','lof'])) $hasWarning = true;
    }
    if ($hasWorking)       $overallOnuStatus = 'working';
    elseif ($hasDanger)    $overallOnuStatus = 'down';
    elseif ($hasWarning)   $overallOnuStatus = 'degraded';
    else                   $overallOnuStatus = 'unknown';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Customer Portal – <?= htmlspecialchars($_companyName) ?></title>
<?php if ($_favicon): ?><link rel="icon" href="<?= htmlspecialchars($_favicon) ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/assets/style.css" rel="stylesheet">
<style>
:root {
  --primary: <?= htmlspecialchars($_primaryColor) ?>;
  --primary-dark: <?= htmlspecialchars(darkenColor($_primaryColor)) ?>;
  --primary-rgb: <?= $_pr ?>, <?= $_pg ?>, <?= $_pb ?>;
}
.portal-status-card {
  border-radius: .75rem;
  border: 1.5px solid transparent;
  transition: border-color .2s;
}
.portal-status-card.status-working  { border-color: #22c55e; background: #f0fdf4; }
.portal-status-card.status-down     { border-color: #ef4444; background: #fff1f2; }
.portal-status-card.status-degraded { border-color: #f59e0b; background: #fffbeb; }
.portal-status-card.status-unknown  { border-color: #94a3b8; background: #f8fafc; }
.portal-status-card.status-none     { border-color: #e2e8f0; background: #f8fafc; }
.onu-row { border-bottom: 1px solid #f1f5f9; }
.onu-row:last-child { border-bottom: none; }
</style>
</head>
<body style="background:#f1f5f9">

<nav class="navbar py-3" style="background:#0f172a;border-bottom:1px solid rgba(255,255,255,.07)">
  <div class="container">
    <span class="navbar-brand text-white fw-bold d-flex align-items-center gap-2">
      <i class="bi bi-broadcast-pin" style="color:var(--primary);font-size:1.2rem"></i>
      <?= htmlspecialchars($_companyName) ?>
    </span>
    <a href="/login" class="btn btn-outline-light btn-sm">Staff Login →</a>
  </div>
</nav>

<div class="container py-5">
  <div class="text-center mb-5">
    <h1 class="fw-bold fs-3">Customer Self-Service Portal</h1>
    <p class="text-muted mb-0">Look up your account to check your connection status and manage service tickets.</p>
  </div>

  <div class="row justify-content-center">
    <div class="col-md-8 col-lg-7">

      <!-- Account lookup -->
      <div class="card-section mb-4">
        <div class="p-4">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="lookup">
            <label class="form-label fw-semibold">Account Number</label>
            <div class="input-group">
              <span class="input-group-text bg-light border-end-0"><i class="bi bi-person-badge text-muted"></i></span>
              <input type="text" name="account" class="form-control border-start-0"
                placeholder="e.g. ACC-001234"
                value="<?= htmlspecialchars($account) ?>" required autofocus>
              <button type="submit" class="btn btn-primary px-4">Look Up</button>
            </div>
          </form>
        </div>
      </div>

      <?php if ($lookupRateLimited): ?>
      <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Too many lookups from this connection. Please wait a few minutes and try again.
      </div>
      <?php elseif ($searched && !$cust): ?>
      <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        No account found for <strong><?= htmlspecialchars($account) ?></strong>. Please check your account number.
      </div>
      <?php endif; ?>

      <?php if ($raiseSuccess): ?>
      <div class="alert alert-success d-flex align-items-start gap-2">
        <i class="bi bi-check-circle-fill fs-5 mt-1 flex-shrink-0"></i>
        <div>
          <strong>Ticket raised successfully!</strong><br>
          Your reference number is <strong class="font-monospace"><?= htmlspecialchars($raiseSuccess) ?></strong>.
          Our team will be in touch shortly.
        </div>
      </div>
      <?php endif; ?>

      <?php if ($raiseError): ?>
      <div class="alert alert-danger"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($raiseError) ?></div>
      <?php endif; ?>

      <?php if ($searched && $cust): ?>

      <!-- ── Subscription Card ─────────────────────────────────────────────── -->
      <div class="card-section mb-4">
        <div class="card-header"><i class="bi bi-person-circle me-1 text-primary"></i>Account Details</div>
        <div class="p-4">
          <div class="row g-3">
            <div class="col-12">
              <h5 class="fw-bold mb-1"><?= htmlspecialchars($cust['name']) ?></h5>
              <div class="text-muted small">Account: <strong class="font-monospace"><?= htmlspecialchars($cust['account_number']) ?></strong>
                <?php if (!empty($cust['email'])): ?> &bull; <?= htmlspecialchars($cust['email']) ?><?php endif; ?>
              </div>
            </div>

            <div class="col-sm-4">
              <div class="small text-muted fw-semibold text-uppercase mb-1" style="font-size:.7rem;letter-spacing:.05em">Account Status</div>
              <?php
              $custStatus = strtolower($cust['status'] ?? 'active');
              $statusBadge = match($custStatus) {
                  'active'    => 'success',
                  'suspended' => 'warning',
                  'inactive'  => 'secondary',
                  default     => 'secondary',
              };
              ?>
              <span class="badge bg-<?= $statusBadge ?> fs-6"><?= ucfirst(htmlspecialchars($custStatus)) ?></span>
            </div>

            <?php if (!empty($cust['plan'])): ?>
            <div class="col-sm-4">
              <div class="small text-muted fw-semibold text-uppercase mb-1" style="font-size:.7rem;letter-spacing:.05em">Plan</div>
              <div class="fw-semibold"><?= htmlspecialchars($cust['plan']) ?></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($cust['expiration'])): ?>
            <div class="col-sm-4">
              <?php
              $expDate  = strtotime($cust['expiration']);
              $expPast  = $expDate && $expDate < time();
              $expSoon  = $expDate && !$expPast && $expDate < strtotime('+7 days');
              $expColor = $expPast ? 'danger' : ($expSoon ? 'warning' : 'muted');
              ?>
              <div class="small text-muted fw-semibold text-uppercase mb-1" style="font-size:.7rem;letter-spacing:.05em">
                <?= $expPast ? 'Expired' : 'Expires' ?>
              </div>
              <div class="fw-semibold text-<?= $expColor ?>">
                <?php if ($expPast): ?><i class="bi bi-exclamation-circle me-1"></i><?php elseif ($expSoon): ?><i class="bi bi-clock me-1"></i><?php endif; ?>
                <?= date('d M Y', $expDate) ?>
              </div>
            </div>
            <?php endif; ?>
          </div>

          <div class="mt-3">
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#raiseModal">
              <i class="bi bi-plus-lg me-1"></i>Raise a Ticket
            </button>
          </div>
        </div>
      </div>

      <!-- ── Connection Status Card ────────────────────────────────────────── -->
      <div class="portal-status-card p-4 mb-4 status-<?= htmlspecialchars($overallOnuStatus) ?>">
        <div class="d-flex align-items-center gap-2 mb-3">
          <?php
          $overallIcon  = match($overallOnuStatus) {
              'working'  => 'bi-wifi text-success',
              'down'     => 'bi-wifi-off text-danger',
              'degraded' => 'bi-exclamation-circle text-warning',
              default    => 'bi-question-circle text-secondary',
          };
          $overallLabel = match($overallOnuStatus) {
              'working'  => 'Connection is Active',
              'down'     => 'Connection is Down',
              'degraded' => 'Connection Degraded',
              'none'     => 'No Equipment Linked',
              default    => 'Status Unknown',
          };
          $overallBadge = match($overallOnuStatus) {
              'working'  => 'success',
              'down'     => 'danger',
              'degraded' => 'warning',
              default    => 'secondary',
          };
          ?>
          <i class="bi <?= $overallIcon ?> fs-4"></i>
          <div>
            <div class="fw-semibold"><?= $overallLabel ?></div>
            <div class="small text-muted">Network equipment status</div>
          </div>
          <span class="badge bg-<?= $overallBadge ?> ms-auto"><?= ucfirst($overallOnuStatus === 'none' ? 'N/A' : $overallOnuStatus) ?></span>
        </div>

        <?php if ($onus): ?>
        <div class="rounded overflow-hidden" style="border:1px solid rgba(0,0,0,.08)">
          <?php foreach ($onus as $onu):
            $sc = $onuStatusCfg[$onu['status']] ?? $onuStatusCfg['unknown'];
          ?>
          <div class="onu-row px-3 py-2 d-flex align-items-center gap-3 bg-white">
            <i class="bi <?= $sc['icon'] ?> text-<?= $sc['color'] ?> fs-5 flex-shrink-0"></i>
            <div class="flex-grow-1 min-w-0">
              <div class="fw-semibold small font-monospace text-truncate"><?= htmlspecialchars($onu['serial_number']) ?></div>
              <?php if (!empty($onu['description'])): ?>
              <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($onu['description']) ?></div>
              <?php endif; ?>
            </div>
            <div class="text-end flex-shrink-0">
              <span class="badge bg-<?= $sc['color'] ?> bg-opacity-10 text-<?= $sc['color'] ?> border border-<?= $sc['color'] ?> border-opacity-25 small">
                <?= $sc['label'] ?>
              </span>
              <?php if ($onu['rx_power_dbm'] !== null): ?>
              <div class="text-muted" style="font-size:.7rem"><?= $onu['rx_power_dbm'] ?> dBm</div>
              <?php endif; ?>
              <?php if ($onu['last_online_at'] && $onu['status'] !== 'working'): ?>
              <div class="text-muted" style="font-size:.7rem">Last seen <?= date('d M, H:i', strtotime($onu['last_online_at'])) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php elseif ($overallOnuStatus === 'none'): ?>
        <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>No network equipment is currently linked to your account. Contact support if this seems incorrect.</p>
        <?php endif; ?>

        <?php if ($overallOnuStatus === 'down' || $overallOnuStatus === 'degraded'): ?>
        <div class="mt-3 p-3 rounded" style="background:rgba(0,0,0,.04);border:1px solid rgba(0,0,0,.08)">
          <div class="small fw-semibold mb-1">Experiencing issues?</div>
          <p class="small text-muted mb-2">Our NOC may already be aware. You can also raise a ticket and a technician will follow up.</p>
          <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#raiseModal">
            <i class="bi bi-plus-lg me-1"></i>Raise a Ticket
          </button>
        </div>
        <?php endif; ?>
      </div>

      <!-- ── Tickets ───────────────────────────────────────────────────────── -->
      <div class="card-section mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span><i class="bi bi-ticket-perforated me-1 text-primary"></i>Your Tickets (<?= count($results) ?>)</span>
          <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#raiseModal">
            <i class="bi bi-plus-lg me-1"></i>New Ticket
          </button>
        </div>
        <?php if (!$results): ?>
        <div class="p-4 text-center text-muted">
          <i class="bi bi-check-circle fs-2 d-block mb-2 text-success"></i>
          No tickets on this account yet.
          <div class="mt-3">
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#raiseModal">
              <i class="bi bi-plus-lg me-1"></i>Raise Your First Ticket
            </button>
          </div>
        </div>
        <?php endif; ?>
        <?php foreach ($results as $t): ?>
        <div class="p-3 border-bottom">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="flex-grow-1 min-w-0">
              <div class="fw-semibold mb-1" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= htmlspecialchars(substr($t['description'] ?? '(no description)', 0, 100)) ?>
              </div>
              <div class="d-flex flex-wrap gap-2 align-items-center">
                <span class="badge bg-light text-dark border font-monospace"><?= htmlspecialchars($t['ticket_number'] ?? '') ?></span>
                <small class="text-muted"><?= date('d M Y, H:i', strtotime($t['created_at'])) ?></small>
                <small class="text-muted">· <?= $priorityLabels[$t['priority']] ?? ucfirst($t['priority']) ?></small>
              </div>
            </div>
            <span class="badge bg-<?= $ticketStatusColors[$t['status']] ?? 'secondary' ?> text-nowrap flex-shrink-0">
              <?= str_replace('_', ' ', ucfirst($t['status'])) ?>
            </span>
          </div>
          <?php if ($t['resolved_at']): ?>
          <div class="mt-1"><small class="text-success"><i class="bi bi-check-circle me-1"></i>Resolved <?= date('d M Y', strtotime($t['resolved_at'])) ?></small></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if (aiEnabled()): ?>
      <!-- Chat assistant -->
      <div class="card-section mt-4">
        <div class="card-header"><i class="bi bi-chat-dots me-1 text-primary"></i>Ask a Question</div>
        <div class="p-3">
          <div id="chatMessages" style="max-height:280px;overflow-y:auto" class="mb-3 small"></div>
          <div class="d-flex gap-2">
            <input type="text" id="chatInput" class="form-control form-control-sm" placeholder="Ask about your account or tickets…" maxlength="1000">
            <button type="button" class="btn btn-primary btn-sm text-nowrap" id="chatSendBtn" onclick="sendChatMessage()">
              <i class="bi bi-send"></i>
            </button>
          </div>
          <div id="chatDraftBox" class="alert alert-light border mt-3 mb-0 d-none small">
            <div class="fw-semibold mb-1"><i class="bi bi-ticket-perforated me-1"></i>Want to raise this as a ticket?</div>
            <div id="chatDraftText" class="text-muted mb-2"></div>
            <button type="button" class="btn btn-sm btn-primary" onclick="useChatDraft()">Use This — Review &amp; Submit</button>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php endif; ?>

    </div>
  </div>
</div>

<footer class="text-center text-muted py-4" style="font-size:.8rem">
  © <?= date('Y') ?> <?= htmlspecialchars($_companyName) ?> &bull; Powered by MangoNet
</footer>

<!-- ── Raise Ticket Modal ─────────────────────────────────────────────────── -->
<?php if ($searched && $cust): ?>
<div class="modal fade" id="raiseModal" tabindex="-1" aria-labelledby="raiseModalLabel">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="_action" value="raise_ticket">
        <input type="hidden" name="account" value="<?= htmlspecialchars($account) ?>">

        <div class="modal-header">
          <h5 class="modal-title" id="raiseModalLabel">
            <i class="bi bi-ticket-perforated me-1 text-primary"></i>Raise a New Ticket
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="mb-3 p-3 rounded" style="background:#f8fafc;border:1px solid var(--border,#e2e8f0)">
            <div class="fw-semibold small"><?= htmlspecialchars($cust['name']) ?></div>
            <div class="text-muted" style="font-size:.8rem">Account: <?= htmlspecialchars($cust['account_number']) ?></div>
          </div>

          <?php if ($faultTypes): ?>
          <div class="mb-3">
            <label class="form-label fw-semibold">Issue Type <span class="text-muted fw-normal">(optional)</span></label>
            <select name="fault_type_id" class="form-select">
              <option value="">— Select issue type —</option>
              <?php
              $lastCat = '';
              foreach ($faultTypes as $ft):
                if ($ft['category'] !== $lastCat):
                  if ($lastCat !== '') echo '</optgroup>';
                  echo '<optgroup label="'.htmlspecialchars($ft['category']).'">';
                  $lastCat = $ft['category'];
                endif;
              ?>
              <option value="<?= $ft['id'] ?>"><?= htmlspecialchars($ft['name']) ?></option>
              <?php endforeach; if ($lastCat !== '') echo '</optgroup>'; ?>
            </select>
          </div>
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label fw-semibold">Priority</label>
            <select name="priority" class="form-select">
              <option value="p4">Low — General enquiry</option>
              <option value="p3" selected>Medium — Service degraded</option>
              <option value="p2">High — Service intermittent</option>
              <option value="p1">Critical — Complete outage</option>
            </select>
          </div>

          <div class="mb-1">
            <label class="form-label fw-semibold">Describe your issue <span class="text-danger">*</span></label>
            <textarea name="description" class="form-control" rows="4" required
              placeholder="Please describe the problem in as much detail as possible — what's happening, when it started, and any error messages you see."></textarea>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-send me-1"></i>Submit Ticket
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CHAT_ACCOUNT = <?= json_encode($account) ?>;
let chatHistory = [];
let pendingDraft = null;

function chatAppend(role, text) {
  const wrap = document.getElementById('chatMessages');
  if (!wrap) return;
  const bubble = document.createElement('div');
  bubble.className = role === 'user' ? 'text-end mb-2' : 'mb-2';
  const inner = document.createElement('span');
  inner.className = role === 'user'
    ? 'd-inline-block px-2 py-1 rounded text-white'
    : 'd-inline-block px-2 py-1 rounded';
  inner.style.cssText = role === 'user' ? 'background:var(--bs-primary,#0ea5e9);max-width:85%' : 'background:#f1f5f9;max-width:85%';
  inner.textContent = text;
  bubble.appendChild(inner);
  wrap.appendChild(bubble);
  wrap.scrollTop = wrap.scrollHeight;
}

function sendChatMessage() {
  const input = document.getElementById('chatInput');
  const btn = document.getElementById('chatSendBtn');
  const message = input.value.trim();
  if (!message || !CHAT_ACCOUNT) return;

  chatAppend('user', message);
  input.value = '';
  btn.disabled = true;
  document.getElementById('chatDraftBox').classList.add('d-none');

  fetch('/api/ai-portal-chat', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ account: CHAT_ACCOUNT, message, history: chatHistory })
  })
    .then(r => r.json().then(data => ({ ok: r.ok, data })))
    .then(({ ok, data }) => {
      if (!ok || data.error) {
        chatAppend('assistant', data.error || 'Sorry, something went wrong. Please try again.');
        return;
      }
      chatAppend('assistant', data.reply);
      chatHistory.push({ role: 'user', text: message }, { role: 'assistant', text: data.reply });
      if (data.draftTicket && data.draftTicket.description) {
        pendingDraft = data.draftTicket;
        document.getElementById('chatDraftText').textContent = data.draftTicket.description;
        document.getElementById('chatDraftBox').classList.remove('d-none');
      }
    })
    .catch(() => chatAppend('assistant', 'Sorry, the request failed. Please try again.'))
    .finally(() => { btn.disabled = false; });
}

document.getElementById('chatInput')?.addEventListener('keydown', function (e) {
  if (e.key === 'Enter') { e.preventDefault(); sendChatMessage(); }
});

function useChatDraft() {
  if (!pendingDraft) return;
  const modalEl = document.getElementById('raiseModal');
  if (!modalEl) return;
  const descField = modalEl.querySelector('[name="description"]');
  const ftField = modalEl.querySelector('[name="fault_type_id"]');
  if (descField) descField.value = pendingDraft.description;
  if (ftField && pendingDraft.faultTypeId) ftField.value = pendingDraft.faultTypeId;
  bootstrap.Modal.getOrCreateInstance(modalEl).show();
}
</script>
</body>
</html>
