<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$parts    = explode('/', trim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/'));
$ticketId = $parts[1] ?? null;
if (!$ticketId) { header('Location: /tickets'); exit; }

$ticket = dbFetch("SELECT * FROM tickets WHERE id = ?", [$ticketId]);
if (!$ticket) { header('Location: /tickets'); exit; }

// Access guard — view_all sees all, department supervisors see their dept, others own only
if (!canAccessTicket($ticket)) { header('Location: /tickets'); exit; }

// Fetch full customer record for contact details
$customer = !empty($ticket['customer_id'])
    ? dbFetch("SELECT name,phone,email,address,mailing_street,mailing_city,mailing_state,account_number FROM customers WHERE id = ?", [$ticket['customer_id']])
    : null;

$comments  = dbFetchAll("SELECT * FROM ticket_comments WHERE ticket_id = ? ORDER BY created_at", [$ticketId]);
$engineers = dbFetchAll("SELECT id,name,role FROM users WHERE role IN ('engineer','noc_engineer','supervisor-fiber','supervisor-noc','cx_supervisor') ORDER BY name");
$user      = currentUser();
$role      = $user['role'];
$canEdit   = hasPermission('tickets.update');
$canAssign = hasPermission('tickets.assign');
$canClose  = hasPermission('tickets.close');

if (method() === 'POST') {
    verifyCsrf();
    $b = array_merge($_POST, getBody() ?: []);
    $action = $b['_action'] ?? '';

    if ($action === 'comment' && !empty($b['content'])) {
        dbRun("INSERT INTO ticket_comments (id,ticket_id,user_id,user_name,content) VALUES (?,?,?,?,?)",
            [newUuid(),$ticketId,$user['id'],$user['name'],$b['content']]);
    }

    if ($action === 'escalate' && ($canEdit || $canAssign) && empty($ticket['escalated_at'])) {
        dbRun("UPDATE tickets SET escalated_at = NOW() WHERE id = ?", [$ticketId]);
        $note = trim($b['escalation_note'] ?? '');
        dbRun("INSERT INTO ticket_comments (id,ticket_id,user_id,user_name,content,type) VALUES (?,?,?,?,?,'escalation')",
            [newUuid(),$ticketId,$user['id'],$user['name'],'Ticket escalated' . ($note ? ": $note" : '.')]);
        auditLog('escalate','ticket',$ticketId);
    }

    if ($action === 'update' && ($canEdit || $canClose || $canAssign)) {
        $newStatus  = $b['status'] ?? $ticket['status'];
        $isResolving = in_array($newStatus, ['resolved','closed']) && !in_array($ticket['status'], ['resolved','closed']);

        // Enforce RCA when resolving
        if ($isResolving && empty(trim($b['roca_root_cause'] ?? ''))) {
            // Redirect back with error flag — RCA modal should have caught this client-side
            header("Location: /ticket/{$ticketId}?rca_required=1"); exit;
        }

        $sets=[]; $vals=[];
        foreach(['status','priority','description','olt'] as $c) {
            if (isset($b[$c])) { $sets[]="$c=?"; $vals[]=$b[$c]; }
        }
        if ($canAssign && isset($b['assigned_to'])) {
            $sets[]="assigned_to=?"; $vals[]=$b['assigned_to'];
        }
        // RCA fields
        foreach(['roca_root_cause','roca_observation','roca_corrective_action','roca_analysis'] as $c) {
            if (isset($b[$c])) { $sets[]="$c=?"; $vals[]=$b[$c]; }
        }
        if ($sets) {
            $sets[]="updated_at=NOW()"; $vals[]=$ticketId;
            if ($newStatus === 'resolved') { $sets[]="resolved_at=NOW()"; }
            if ($newStatus === 'closed')   { $sets[]="closed_at=NOW()"; }
            dbRun("UPDATE tickets SET ".implode(',',$sets)." WHERE id=?",$vals);
            auditLog('update','ticket',$ticketId,json_encode(array_keys($b)));

            $updatedTicket = dbFetch("SELECT * FROM tickets WHERE id = ?", [$ticketId]);

            // Email creator about the update (if not the one making the change)
            if (!empty($ticket['created_by']) && $ticket['created_by'] !== $user['id']) {
                $creator = dbFetch("SELECT name,email FROM users WHERE id = ?", [$ticket['created_by']]);
                if ($creator) emailTicketUpdated($updatedTicket, $creator, $user['name'], $newStatus);
            }

            // If assigning to a new person, email + notify them
            if ($canAssign && !empty($b['assigned_to']) && $b['assigned_to'] !== $ticket['assigned_to']) {
                $assignee = dbFetch("SELECT name,email FROM users WHERE id = ?", [$b['assigned_to']]);
                if ($assignee) {
                    emailTicketAssigned($updatedTicket, $assignee);
                    notifyUser(
                        $b['assigned_to'],
                        "Ticket Assigned to You — " . ($updatedTicket['ticket_number'] ?? ''),
                        substr($updatedTicket['description'] ?? '', 0, 90),
                        "/ticket/{$ticketId}"
                    );
                }
            }

            // First time the ticket reaches resolved/closed → email customer (once)
            if ($isResolving && $customer && !empty($customer['email'])) {
                emailCustomerTicketResolved($updatedTicket, $customer);
            }
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
        <?php if (empty($customer['email'])): ?>
        <div class="alert alert-warning py-1 px-2 mt-2 mb-0 small">
          <i class="bi bi-exclamation-triangle me-1"></i>No email on file — this customer will not receive ticket notifications.
        </div>
        <?php endif; ?>
        <?php elseif (($ticket['ticket_scope'] ?? 'customer') === 'city'): ?>
        <div class="d-flex align-items-center gap-2 mb-1">
          <span class="badge bg-warning text-dark"><i class="bi bi-map me-1"></i>City / Area Outage</span>
        </div>
        <div class="fw-semibold"><?= htmlspecialchars($ticket['customer_name'] ?? '—') ?></div>
        <div class="text-muted small mt-1">This ticket covers all customers in the named area.</div>
        <?php elseif (($ticket['ticket_scope'] ?? 'customer') === 'hub'): ?>
        <div class="d-flex align-items-center gap-2 mb-1">
          <span class="badge bg-primary"><i class="bi bi-hdd-network me-1"></i>Hub Outage</span>
        </div>
        <div class="fw-semibold"><?= htmlspecialchars($ticket['customer_name'] ?? '—') ?></div>
        <div class="text-muted small mt-1">This ticket covers all customers served by this hub.</div>
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
          <?php if ($ticket['escalated_at']): ?><dt class="col-5 text-muted">Escalated</dt><dd class="col-7 text-danger"><?= date('d M Y H:i', strtotime($ticket['escalated_at'])) ?></dd><?php endif; ?>
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

    <?php if ($canEdit || $canClose || $canAssign): ?>
    <div class="card-section">
      <div class="card-header">Update Ticket</div>
      <form method="POST" class="p-3 d-flex flex-column gap-2" id="updateForm">
        <input type="hidden" name="_action" value="update">
        <?= csrfField() ?>
        <!-- Hidden RCA fields populated by modal -->
        <input type="hidden" name="roca_root_cause" id="hRootCause" value="<?= htmlspecialchars($ticket['roca_root_cause'] ?? '') ?>">
        <input type="hidden" name="roca_observation" id="hObservation" value="<?= htmlspecialchars($ticket['roca_observation'] ?? '') ?>">
        <input type="hidden" name="roca_corrective_action" id="hCorrectiveAction" value="<?= htmlspecialchars($ticket['roca_corrective_action'] ?? '') ?>">

        <?php if ($canEdit || $canClose): ?>
        <div>
          <label class="form-label small fw-semibold mb-1">Status</label>
          <select name="status" class="form-select form-select-sm" id="statusSelect">
            <?php
            $statusOpts = ['open','in_progress','pending_confirmation'];
            if ($canClose) { $statusOpts[] = 'resolved'; $statusOpts[] = 'closed'; }
            foreach ($statusOpts as $s):
            ?>
            <option value="<?=$s?>" <?=$ticket['status']===$s?'selected':''?>><?=str_replace('_',' ',ucfirst($s))?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <?php if ($canEdit): ?>
        <div>
          <label class="form-label small fw-semibold mb-1">Priority</label>
          <select name="priority" class="form-select form-select-sm">
            <?php foreach(['p1','p2','p3','p4'] as $p): ?>
            <option value="<?=$p?>" <?=$ticket['priority']===$p?'selected':''?>><?=strtoupper($p)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <?php if ($canAssign): ?>
        <div>
          <label class="form-label small fw-semibold mb-1">Assign To</label>
          <select name="assigned_to" class="form-select form-select-sm">
            <option value="">Unassigned</option>
            <?php foreach ($engineers as $e): ?>
            <option value="<?=$e['id']?>" <?=$ticket['assigned_to']===$e['id']?'selected':''?>><?=htmlspecialchars($e['name'])?> (<?=$e['role']?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['rca_required'])): ?>
        <div class="alert alert-danger py-2 small mb-0">
          <i class="bi bi-exclamation-triangle me-1"></i>Root Cause Analysis is required before resolving.
        </div>
        <?php endif; ?>

        <button type="button" class="btn btn-sm btn-primary" onclick="handleSave()">Save Changes</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if (($canEdit || $canAssign) && empty($ticket['escalated_at']) && !in_array($ticket['status'], ['resolved','closed'])): ?>
    <div class="card-section mt-3">
      <div class="card-header text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Escalation</div>
      <form method="POST" class="p-3">
        <input type="hidden" name="_action" value="escalate">
        <?= csrfField() ?>
        <label class="form-label small fw-semibold mb-1">Escalation note (optional)</label>
        <textarea name="escalation_note" class="form-control form-control-sm mb-2" rows="2" placeholder="Why is this being escalated?"></textarea>
        <button type="submit" class="btn btn-sm btn-outline-danger w-100">
          <i class="bi bi-arrow-up-circle me-1"></i>Escalate Ticket
        </button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── RCA Modal (shown when resolving/closing) ───────────────────────────── -->
<div class="modal fade" id="rcaModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-clipboard2-check me-1 text-primary"></i>Root Cause Analysis
          <span class="badge bg-danger ms-1" style="font-size:.7rem">Required</span>
        </h5>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">Before marking this ticket as resolved, please complete the Root Cause Analysis. This is mandatory.</p>
        <div class="mb-3">
          <label class="form-label fw-semibold">Root Cause <span class="text-danger">*</span></label>
          <textarea id="rcaRootCause" class="form-control" rows="3"
            placeholder="What was the root cause of the issue?"><?= htmlspecialchars($ticket['roca_root_cause'] ?? '') ?></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Observation</label>
          <textarea id="rcaObservation" class="form-control" rows="2"
            placeholder="What was observed on-site or remotely?"><?= htmlspecialchars($ticket['roca_observation'] ?? '') ?></textarea>
        </div>
        <div class="mb-0">
          <label class="form-label fw-semibold">Corrective Action</label>
          <textarea id="rcaCorrective" class="form-control" rows="2"
            placeholder="What was done to fix the issue?"><?= htmlspecialchars($ticket['roca_corrective_action'] ?? '') ?></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="submitWithRca()">
          <i class="bi bi-check-circle me-1"></i>Save &amp; Resolve
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const closingStatuses = ['resolved', 'closed'];

function handleSave() {
  const status = document.getElementById('statusSelect')?.value;
  const alreadyClosed = <?= json_encode(in_array($ticket['status'], ['resolved','closed'])) ?>;

  // Show RCA modal only when transitioning to resolved/closed for the first time
  if (!alreadyClosed && closingStatuses.includes(status)) {
    const rootCause = document.getElementById('rcaRootCause').value.trim();
    // Pre-fill if RCA already saved
    bootstrap.Modal.getOrCreateInstance(document.getElementById('rcaModal')).show();
  } else {
    document.getElementById('updateForm').submit();
  }
}

function submitWithRca() {
  const rootCause = document.getElementById('rcaRootCause').value.trim();
  if (!rootCause) {
    document.getElementById('rcaRootCause').classList.add('is-invalid');
    document.getElementById('rcaRootCause').focus();
    return;
  }
  document.getElementById('rcaRootCause').classList.remove('is-invalid');

  // Push values into hidden fields on the main form
  document.getElementById('hRootCause').value       = rootCause;
  document.getElementById('hObservation').value     = document.getElementById('rcaObservation').value;
  document.getElementById('hCorrectiveAction').value= document.getElementById('rcaCorrective').value;

  bootstrap.Modal.getInstance(document.getElementById('rcaModal')).hide();
  document.getElementById('updateForm').submit();
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>