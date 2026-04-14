<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$parts    = explode('/', trim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/'));
$ticketId = $parts[1] ?? null;
if (!$ticketId) { header('Location: /tickets'); exit; }

$ticket = dbFetch("SELECT * FROM tickets WHERE id = ?", [$ticketId]);
if (!$ticket) { header('Location: /tickets'); exit; }

// Fetch full customer record for contact details
$customer = !empty($ticket['customer_id'])
    ? dbFetch("SELECT name,phone,email,address,mailing_street,mailing_city,mailing_state,account_number FROM customers WHERE id = ?", [$ticket['customer_id']])
    : null;

$comments  = dbFetchAll("SELECT * FROM ticket_comments WHERE ticket_id = ? ORDER BY created_at", [$ticketId]);
$engineers = dbFetchAll("SELECT id,name,role FROM users WHERE role IN ('engineer','supervisor-fiber','supervisor-noc') ORDER BY name");
$user      = currentUser();
$role      = $user['role'];
$canEdit   = in_array($role, ['admin','project_admin','supervisor-fiber','supervisor-noc']);

if (method() === 'POST') {
    verifyCsrf();
    $b = array_merge($_POST, getBody() ?: []);
    $action = $b['_action'] ?? '';
    if ($action === 'comment' && !empty($b['content'])) {
        dbRun("INSERT INTO ticket_comments (id,ticket_id,user_id,user_name,content) VALUES (?,?,?,?,?)",
            [newUuid(),$ticketId,$user['id'],$user['name'],$b['content']]);
    }
    if ($action === 'update' && $canEdit) {
        $sets=[]; $vals=[];
        foreach(['status','priority','assigned_to','description','olt'] as $c) {
            if (isset($b[$c])) { $sets[]="$c=?"; $vals[]=$b[$c]; }
        }
        // RCA fields
        foreach(['roca_root_cause','roca_observation','roca_corrective_action','roca_analysis'] as $c) {
            if (isset($b[$c])) { $sets[]="$c=?"; $vals[]=$b[$c]; }
        }
        if ($sets) {
            $sets[]="updated_at=NOW()"; $vals[]=$ticketId;
            if (isset($b['status'])) {
                if ($b['status']==='resolved') { $sets[]="resolved_at=NOW()"; }
                if ($b['status']==='closed')   { $sets[]="closed_at=NOW()"; }
            }
            dbRun("UPDATE tickets SET ".implode(',',$sets)." WHERE id=?",$vals);
            auditLog('update','ticket',$ticketId,json_encode(array_keys($b)));
        }
    }
    header("Location: /ticket/$ticketId"); exit;
}

$slaBreach  = $ticket['sla_breach_at'] ? new DateTime($ticket['sla_breach_at']) : null;
$isBreached = $slaBreach && $slaBreach < new DateTime();
$statusBg   = ['open'=>'primary','in_progress'=>'info','resolved'=>'success','closed'=>'secondary','pending_confirmation'=>'warning'];
$prioBg     = ['p1'=>'danger','p2'=>'warning','p3'=>'secondary','p4'=>'success'];

$displayTitle = $ticket['ticket_number'] . ' — ' . substr($ticket['description']??'',0,60);

$pageTitle = $ticket['ticket_number'] ?? 'Ticket';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="/tickets" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
  <span class="fw-bold"><?= htmlspecialchars($ticket['ticket_number']??'') ?></span>
  <span class="badge bg-<?= $statusBg[$ticket['status']]??'secondary' ?>"><?= str_replace('_',' ',ucfirst($ticket['status'])) ?></span>
  <span class="badge bg-<?= $prioBg[$ticket['priority']]??'secondary' ?>"><?= strtoupper($ticket['priority']??'') ?></span>
  <?php if ($isBreached): ?><span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>SLA Breached</span><?php endif; ?>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <!-- Description -->
    <div class="card-section mb-3">
      <div class="card-header">Description</div>
      <div class="p-3"><?= nl2br(htmlspecialchars($ticket['description']??'No description.')) ?></div>
    </div>

    <!-- RCA -->
    <?php if ($canEdit): ?>
    <div class="card-section mb-3">
      <div class="card-header">Root Cause Analysis</div>
      <form method="POST" class="p-3">
        <input type="hidden" name="_action" value="update">
        <div class="row g-2 mb-2">
          <div class="col-12"><label class="form-label small fw-semibold">Root Cause</label><textarea name="roca_root_cause" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($ticket['roca_root_cause']??'') ?></textarea></div>
          <div class="col-12"><label class="form-label small fw-semibold">Observation</label><textarea name="roca_observation" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($ticket['roca_observation']??'') ?></textarea></div>
          <div class="col-12"><label class="form-label small fw-semibold">Corrective Action</label><textarea name="roca_corrective_action" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($ticket['roca_corrective_action']??'') ?></textarea></div>
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Save RCA</button>
      </form>
    </div>
    <?php elseif ($ticket['roca_root_cause']): ?>
    <div class="card-section mb-3">
      <div class="card-header">Root Cause Analysis</div>
      <div class="p-3">
        <?php if ($ticket['roca_root_cause']): ?><p class="small"><strong>Root Cause:</strong> <?= nl2br(htmlspecialchars($ticket['roca_root_cause'])) ?></p><?php endif; ?>
        <?php if ($ticket['roca_observation']): ?><p class="small"><strong>Observation:</strong> <?= nl2br(htmlspecialchars($ticket['roca_observation'])) ?></p><?php endif; ?>
        <?php if ($ticket['roca_corrective_action']): ?><p class="small"><strong>Corrective Action:</strong> <?= nl2br(htmlspecialchars($ticket['roca_corrective_action'])) ?></p><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Comments -->
    <div class="card-section">
      <div class="card-header"><i class="bi bi-chat-left-text me-1"></i>Comments (<?= count($comments) ?>)</div>
      <div class="p-3">
        <?php if (!$comments): ?><p class="text-muted small mb-3">No comments yet.</p><?php endif; ?>
        <?php foreach ($comments as $c): ?>
        <div class="d-flex gap-3 mb-3">
          <div style="width:32px;height:32px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.75rem;font-weight:700;flex-shrink:0">
            <?= strtoupper(substr($c['user_name']??'?',0,1)) ?>
          </div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-2">
              <strong class="small"><?= htmlspecialchars($c['user_name']??'') ?></strong>
              <small class="text-muted"><?= date('d M Y H:i', strtotime($c['created_at'])) ?></small>
            </div>
            <div class="mt-1 small"><?= nl2br(htmlspecialchars($c['content'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
        <form method="POST" class="mt-3 border-top pt-3">
          <input type="hidden" name="_action" value="comment">
          <textarea name="content" class="form-control mb-2" rows="2" placeholder="Add a comment…" required></textarea>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-send me-1"></i>Post Comment</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Details sidebar -->
  <div class="col-lg-4">

    <!-- Customer contact card -->
    <div class="card-section mb-3">
      <div class="card-header"><i class="bi bi-person-lines-fill me-1 text-primary"></i>Customer</div>
      <div class="p-3">
        <?php if ($customer): ?>
        <div class="fw-semibold mb-1"><?= htmlspecialchars($customer['name']) ?></div>
        <?php if (!empty($customer['account_number'])): ?>
        <div class="text-muted small mb-2"><?= htmlspecialchars($customer['account_number']) ?></div>
        <?php endif; ?>
        <dl class="row small mb-0">
          <?php
          // Build address from available fields
          $addrParts = array_filter([
            $customer['address'] ?: $customer['mailing_street'],
            $customer['mailing_city'],
            $customer['mailing_state'],
          ]);
          ?>
          <?php if ($addrParts): ?>
          <dt class="col-4 text-muted">Address</dt>
          <dd class="col-8"><?= htmlspecialchars(implode(', ', $addrParts)) ?></dd>
          <?php endif; ?>
          <?php if (!empty($customer['phone'])): ?>
          <dt class="col-4 text-muted">Phone</dt>
          <dd class="col-8">
            <a href="tel:<?= htmlspecialchars($customer['phone']) ?>" class="text-decoration-none">
              <?= htmlspecialchars($customer['phone']) ?>
            </a>
          </dd>
          <?php endif; ?>
          <?php if (!empty($customer['email'])): ?>
          <dt class="col-4 text-muted">Email</dt>
          <dd class="col-8" style="word-break:break-all">
            <a href="mailto:<?= htmlspecialchars($customer['email']) ?>" class="text-decoration-none">
              <?= htmlspecialchars($customer['email']) ?>
            </a>
          </dd>
          <?php endif; ?>
        </dl>
        <?php else: ?>
        <span class="text-muted small"><?= htmlspecialchars($ticket['customer_name']??'—') ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div class="card-section mb-3">
      <div class="card-header">Details</div>
      <div class="p-3">
        <dl class="row small mb-0">
          <dt class="col-5 text-muted">Ticket #</dt><dd class="col-7"><?= htmlspecialchars($ticket['ticket_number']??'') ?></dd>
          <dt class="col-5 text-muted">Type</dt><dd class="col-7"><?= htmlspecialchars($ticket['type']??'') ?></dd>
          <dt class="col-5 text-muted">Created</dt><dd class="col-7"><?= date('d M Y H:i', strtotime($ticket['created_at'])) ?></dd>
          <?php if ($ticket['resolved_at']): ?><dt class="col-5 text-muted">Resolved</dt><dd class="col-7"><?= date('d M Y H:i', strtotime($ticket['resolved_at'])) ?></dd><?php endif; ?>
          <?php if ($slaBreach): ?>
          <dt class="col-5 text-muted">SLA Due</dt>
          <dd class="col-7 <?= $isBreached?'text-danger fw-semibold':'' ?>"><?= $slaBreach->format('d M Y H:i') ?></dd>
          <?php endif; ?>
          <?php if ($ticket['olt']): ?><dt class="col-5 text-muted">OLT</dt><dd class="col-7"><?= htmlspecialchars($ticket['olt']) ?></dd><?php endif; ?>
          <?php if ($ticket['assigned_team']): ?><dt class="col-5 text-muted">Team</dt><dd class="col-7"><?= htmlspecialchars($ticket['assigned_team']) ?></dd><?php endif; ?>
        </dl>
      </div>
    </div>

    <?php if ($canEdit): ?>
    <div class="card-section">
      <div class="card-header">Update Ticket</div>
      <form method="POST" class="p-3 d-flex flex-column gap-2">
        <input type="hidden" name="_action" value="update">
        <div>
          <label class="form-label small fw-semibold mb-1">Status</label>
          <select name="status" class="form-select form-select-sm">
            <?php foreach(['open','in_progress','pending_confirmation','resolved','closed'] as $s): ?>
            <option value="<?=$s?>" <?=$ticket['status']===$s?'selected':''?>><?=str_replace('_',' ',ucfirst($s))?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label small fw-semibold mb-1">Priority</label>
          <select name="priority" class="form-select form-select-sm">
            <?php foreach(['p1','p2','p3','p4'] as $p): ?>
            <option value="<?=$p?>" <?=$ticket['priority']===$p?'selected':''?>><?=strtoupper($p)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label small fw-semibold mb-1">Assign To</label>
          <select name="assigned_to" class="form-select form-select-sm">
            <option value="">Unassigned</option>
            <?php foreach($engineers as $e): ?>
            <option value="<?=$e['id']?>" <?=$ticket['assigned_to']===$e['id']?'selected':''?>><?=htmlspecialchars($e['name'])?> (<?=$e['role']?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Save Changes</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
