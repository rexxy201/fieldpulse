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
$photos    = dbFetchAll("SELECT * FROM ticket_photos WHERE ticket_id = ? ORDER BY created_at DESC", [$ticketId]);
// Per-record activity timeline — the audit_logs data already exists (every
// write on this page calls auditLog('...','ticket',$ticketId)), it just
// wasn't surfaced anywhere but the admin-only global log. Cheap to expose.
$activity  = dbFetchAll("SELECT * FROM audit_logs WHERE entity='ticket' AND entity_id=? ORDER BY created_at DESC LIMIT 30", [$ticketId]);
$engineers = dbFetchAll("SELECT id,name,role FROM users WHERE role IN ('engineer','noc_engineer','supervisor-fiber','supervisor-noc','cx_supervisor') ORDER BY name");
$user      = currentUser();
$role      = $user['role'];
// Vendor-type roles use the exact same permission-gated "Update Ticket" form
// and 'update' action as staff — whatever the admin has granted them in
// Roles & Permissions (tickets.update/.assign/.resolve/.close) is what they
// can do here, same as any other role. The only thing that's different for
// them is ticket *visibility* (their own company's tickets — see
// ticketScopeSql()/canAccessTicket()), not what they can do once they can
// see one.
$canEdit    = hasPermission('tickets.update');
$canAssign  = hasPermission('tickets.assign');
$canResolve = hasPermission('tickets.resolve');
$canClose   = hasPermission('tickets.close');
$allVendors = ($canEdit || $canAssign) ? dbFetchAll("SELECT id,name FROM vendors ORDER BY name") : [];
$maintVendors = ($canEdit || $canAssign) ? dbFetchAll("SELECT id,name FROM vendors WHERE type='maintenance' ORDER BY name") : [];
$assignedVendor = !empty($ticket['vendor_id']) ? dbFetch("SELECT name FROM vendors WHERE id=?", [$ticket['vendor_id']]) : null;
$assignedMaintVendor = !empty($ticket['maintenance_vendor_id']) ? dbFetch("SELECT name FROM vendors WHERE id=?", [$ticket['maintenance_vendor_id']]) : null;

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

    // Hand this ticket to a vendor for field work — mirrors the same pattern as
    // installation vendor (re)assignment.
    if ($action === 'assign_vendor' && ($canEdit || $canAssign)) {
        $newVendorId = trim($b['vendor_id'] ?? '');
        $oldVendorId = $ticket['vendor_id'] ?? '';
        dbRun("UPDATE tickets SET vendor_id=?, updated_at=NOW() WHERE id=?", [$newVendorId ?: null, $ticketId]);
        $oldName = $oldVendorId ? (dbFetch("SELECT name FROM vendors WHERE id=?",[$oldVendorId])['name'] ?? 'Unknown') : 'Unassigned';
        $newName = $newVendorId ? (dbFetch("SELECT name FROM vendors WHERE id=?",[$newVendorId])['name'] ?? 'Unknown') : 'Unassigned';
        dbRun("INSERT INTO ticket_comments (id,ticket_id,user_id,user_name,content,type) VALUES (?,?,?,?,?,'vendor_assigned')",
            [newUuid(),$ticketId,$user['id'],$user['name'],"Vendor changed from {$oldName} to {$newName}."]);
        auditLog('assign_vendor','ticket',$ticketId);
    }

    // Hand this ticket to a maintenance vendor's whole team manually — same
    // as the automatic hub-based routing (see getMaintenanceVendorForHub()),
    // just staff-triggered for a one-off reassignment or a hub with no
    // default vendor configured. Every active member of the new team gets
    // notified (hub-aware, same rule as auto-routing).
    if ($action === 'assign_maintenance_vendor' && ($canEdit || $canAssign)) {
        $newVendorId = trim($b['maintenance_vendor_id'] ?? '');
        $oldVendorId = $ticket['maintenance_vendor_id'] ?? '';
        dbRun("UPDATE tickets SET maintenance_vendor_id=?, updated_at=NOW() WHERE id=?", [$newVendorId ?: null, $ticketId]);
        $oldName = $oldVendorId ? (dbFetch("SELECT name FROM vendors WHERE id=?",[$oldVendorId])['name'] ?? 'Unknown') : 'Unassigned';
        $newName = $newVendorId ? (dbFetch("SELECT name FROM vendors WHERE id=?",[$newVendorId])['name'] ?? 'Unknown') : 'Unassigned';
        dbRun("INSERT INTO ticket_comments (id,ticket_id,user_id,user_name,content,type) VALUES (?,?,?,?,?,'vendor_assigned')",
            [newUuid(),$ticketId,$user['id'],$user['name'],"Maintenance vendor changed from {$oldName} to {$newName}."]);
        auditLog('assign_maintenance_vendor','ticket',$ticketId);
        if ($newVendorId && $newVendorId !== $oldVendorId) {
            $freshTicket = dbFetch("SELECT * FROM tickets WHERE id = ?", [$ticketId]);
            notifyVendorTeamTicketAssigned($freshTicket, $newVendorId);
        }
    }

    if ($action === 'update' && ($canEdit || $canResolve || $canClose || $canAssign)) {
        // Server-side enforcement of the resolve/close split — the status dropdown
        // only offers options the user is permitted to set, but a direct POST must
        // not be able to bypass that, so re-check here regardless of what was hidden.
        if (($b['status'] ?? null) === 'resolved' && !$canResolve) unset($b['status']);
        if (($b['status'] ?? null) === 'closed'   && !$canClose)   unset($b['status']);

        $newStatus  = $b['status'] ?? $ticket['status'];
        $isResolving = in_array($newStatus, ['resolved','closed']) && !in_array($ticket['status'], ['resolved','closed']);

        // Enforce RCA when resolving
        if ($isResolving && empty(trim($b['roca_root_cause'] ?? ''))) {
            // Redirect back with error flag — RCA modal should have caught this client-side
            header("Location: /ticket/{$ticketId}?rca_required=1"); exit;
        }

        // Proof-of-service photos — optional, validated before anything is
        // persisted so a bad upload can't leave the ticket half-updated.
        $photoCheck = validateTicketPhotos($_FILES['photos'] ?? []);
        if (!$photoCheck['ok']) {
            header("Location: /ticket/{$ticketId}?photo_error=" . urlencode($photoCheck['error'])); exit;
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
        if (!empty($_FILES['photos'])) {
            saveTicketPhotos($_FILES['photos'], $ticketId, $user['id'], $user['name']);
        }
        if ($sets) {
            $sets[]="updated_at=NOW()"; $sets[]="lock_version=lock_version+1";
            if ($newStatus === 'resolved') { $sets[]="resolved_at=NOW()"; }
            if ($newStatus === 'closed')   { $sets[]="closed_at=NOW()"; }
            // Optimistic lock: guard the UPDATE on the lock_version the form was
            // rendered with, so two people editing the same ticket concurrently
            // can't silently clobber each other — if someone else's update landed
            // first, rowCount() is 0 and we bounce back with a conflict notice
            // instead of overwriting their change.
            $expectedLockVersion = (int)($b['lock_version'] ?? -1);
            $vals[] = $ticketId; $vals[] = $expectedLockVersion;
            $st = dbRun("UPDATE tickets SET ".implode(',',$sets)." WHERE id=? AND lock_version=?", $vals);
            if ($st->rowCount() === 0) {
                header("Location: /ticket/{$ticketId}?conflict=1"); exit;
            }
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

  <?php if (hasPermission('payment_requests.create')): ?>
  <?php
    // Short context for the payment-request Description field — the requester
    // still fills in the actual amount/breakdown, this just saves them from
    // retyping what the ticket already says.
    $_prPrefillDesc = trim(
        ($ticket['ticket_number'] ? $ticket['ticket_number'] . ' — ' : '')
        . ($ticket['customer_name'] ? $ticket['customer_name'] . ': ' : '')
        . substr($ticket['description'] ?? '', 0, 150)
    );
  ?>
  <a href="/payment-requests?prefillTicket=<?= urlencode($ticketId) ?>&prefillDesc=<?= urlencode($_prPrefillDesc) ?>"
     class="btn btn-sm btn-outline-primary ms-auto">
    <i class="bi bi-cash-coin me-1"></i>Create Payment Request
  </a>
  <?php endif; ?>
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

    <!-- Photos (proof of service) -->
    <?php if ($photos): ?>
    <div class="card-section mb-3">
      <div class="card-header"><i class="bi bi-camera me-1"></i>Photos (<?= count($photos) ?>)</div>
      <div class="p-3 d-flex flex-wrap gap-2">
        <?php foreach ($photos as $p): ?>
        <a href="/api/ticket-photo?id=<?= $p['id'] ?>" target="_blank" rel="noopener" class="d-block" title="<?= htmlspecialchars($p['original_name'] ?? '') ?>">
          <img src="/api/ticket-photo?id=<?= $p['id'] ?>" alt="Ticket photo" style="width:96px;height:96px;object-fit:cover;border-radius:.4rem;border:1px solid #e2e8f0">
        </a>
        <?php endforeach; ?>
      </div>
      <div class="px-3 pb-3 small text-muted">
        <?php $latest = $photos[0]; ?>
        Last added by <?= htmlspecialchars($latest['uploaded_by_name'] ?? '—') ?> on <?= date('d M Y H:i', strtotime($latest['created_at'])) ?>
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
          <?php if ($ticket['csat_score']): ?>
          <dt class="col-5 text-muted">Customer Rating</dt>
          <dd class="col-7">
            <?php $csatColor = $ticket['csat_score'] >= 4 ? 'success' : ($ticket['csat_score'] >= 3 ? 'warning' : 'danger'); ?>
            <span class="badge bg-<?= $csatColor ?>"><?= (int)$ticket['csat_score'] ?>/5</span>
            <?php if ($ticket['csat_comment']): ?><div class="small text-muted fst-italic mt-1">“<?= htmlspecialchars($ticket['csat_comment']) ?>”</div><?php endif; ?>
          </dd>
          <?php endif; ?>
          <?php if ($slaBreach): ?>
          <dt class="col-5 text-muted">SLA Due</dt>
          <dd class="col-7 <?= $isBreached?'text-danger fw-semibold':'' ?>"><?= $slaBreach->format('d M Y H:i') ?></dd>
          <?php endif; ?>
          <?php if ($ticket['olt']): ?><dt class="col-5 text-muted">OLT</dt><dd class="col-7"><?= htmlspecialchars($ticket['olt']) ?></dd><?php endif; ?>
          <?php if ($ticket['assigned_team']): ?><dt class="col-5 text-muted">Team</dt><dd class="col-7"><?= htmlspecialchars($ticket['assigned_team']) ?></dd><?php endif; ?>
          <?php if ($assignedMaintVendor): ?><dt class="col-5 text-muted">Maintenance Vendor</dt><dd class="col-7"><span class="badge bg-light text-dark border"><i class="bi bi-people-fill me-1"></i><?= htmlspecialchars($assignedMaintVendor['name']) ?> (team)</span></dd><?php endif; ?>
          <?php if ($assignedVendor && $ticket['type'] === 'installation'): ?><dt class="col-5 text-muted">Installation Vendor</dt><dd class="col-7"><?= htmlspecialchars($assignedVendor['name']) ?></dd><?php endif; ?>
        </dl>
      </div>
    </div>

    <?php if ($canEdit || $canAssign): ?>
    <div class="card-section mb-3">
      <div class="card-header">Maintenance Vendor</div>
      <form method="POST" class="p-3">
        <input type="hidden" name="_action" value="assign_maintenance_vendor">
        <?= csrfField() ?>
        <label class="form-label small fw-semibold mb-1">Assign to Vendor Team</label>
        <div class="d-flex gap-2">
          <select name="maintenance_vendor_id" class="form-select form-select-sm">
            <option value="">— Unassigned —</option>
            <?php foreach ($maintVendors as $v): ?>
            <option value="<?= $v['id'] ?>" <?= ($ticket['maintenance_vendor_id']??'')===$v['id']?'selected':'' ?>><?= htmlspecialchars($v['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
        </div>
        <div class="form-text">Hands this ticket to the vendor's whole team — visible to every hub-scoped member covering this ticket's hub, and to their supervisor regardless of hub. Overrides (or sets, if the hub has no default) automatic hub-based routing for this ticket.</div>
      </form>
    </div>
    <?php endif; ?>

    <?php // Installation vendor assignment only makes sense on installation-type
    // tickets — a trouble/fault ticket is resolved by the maintenance vendor
    // (routed automatically by hub), not an installation vendor. ?>
    <?php if (($canEdit || $canAssign) && $ticket['type'] === 'installation'): ?>
    <div class="card-section mb-3">
      <div class="card-header">Installation Vendor</div>
      <form method="POST" class="p-3">
        <input type="hidden" name="_action" value="assign_vendor">
        <?= csrfField() ?>
        <label class="form-label small fw-semibold mb-1">Assign to Vendor</label>
        <div class="d-flex gap-2">
          <select name="vendor_id" class="form-select form-select-sm">
            <option value="">— Unassigned —</option>
            <?php foreach ($allVendors as $v): ?>
            <option value="<?= $v['id'] ?>" <?= ($ticket['vendor_id']??'')===$v['id']?'selected':'' ?>><?= htmlspecialchars($v['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
        </div>
        <div class="form-text">Hand this ticket to a vendor for field work — they'll see it under their own logins.</div>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($canEdit || $canResolve || $canClose || $canAssign): ?>
    <div class="card-section">
      <div class="card-header">Update Ticket</div>
      <form method="POST" enctype="multipart/form-data" class="p-3 d-flex flex-column gap-2" id="updateForm">
        <input type="hidden" name="_action" value="update">
        <input type="hidden" name="lock_version" value="<?= (int)($ticket['lock_version'] ?? 0) ?>">
        <?= csrfField() ?>
        <!-- Hidden RCA fields populated by modal -->
        <input type="hidden" name="roca_root_cause" id="hRootCause" value="<?= htmlspecialchars($ticket['roca_root_cause'] ?? '') ?>">
        <input type="hidden" name="roca_observation" id="hObservation" value="<?= htmlspecialchars($ticket['roca_observation'] ?? '') ?>">
        <input type="hidden" name="roca_corrective_action" id="hCorrectiveAction" value="<?= htmlspecialchars($ticket['roca_corrective_action'] ?? '') ?>">

        <?php if ($canEdit || $canResolve || $canClose): ?>
        <div>
          <label class="form-label small fw-semibold mb-1">Status</label>
          <select name="status" class="form-select form-select-sm" id="statusSelect">
            <?php
            $statusOpts = ['open','in_progress','pending_confirmation'];
            if ($canResolve) { $statusOpts[] = 'resolved'; }
            if ($canClose)   { $statusOpts[] = 'closed'; }
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

        <div>
          <label class="form-label small fw-semibold mb-1">Photos <span class="text-muted fw-normal">(proof of service — optional, up to <?= TICKET_PHOTO_MAX_FILES ?>)</span></label>
          <input type="file" name="photos[]" class="form-control form-control-sm" multiple accept="image/jpeg,image/png,image/webp" capture="environment">
          <div class="form-text">JPG, PNG, or WebP, up to 8MB each. Especially worth attaching when resolving on-site.</div>
        </div>

        <?php if (!empty($_GET['photo_error'])): ?>
        <div class="alert alert-danger py-2 small mb-0">
          <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($_GET['photo_error']) ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['rca_required'])): ?>
        <div class="alert alert-danger py-2 small mb-0">
          <i class="bi bi-exclamation-triangle me-1"></i>Root Cause Analysis is required before resolving.
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['conflict'])): ?>
        <div class="alert alert-warning py-2 small mb-0">
          <i class="bi bi-exclamation-triangle me-1"></i>Someone else updated this ticket while you were editing it. Your changes were <strong>not</strong> saved — the page below now shows the latest version; please re-apply your changes.
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

    <!-- Activity timeline -->
    <div class="card-section mt-3">
      <div class="card-header"><i class="bi bi-clock-history me-1"></i>Activity (<?= count($activity) ?>)</div>
      <div class="p-3" style="max-height:320px;overflow-y:auto">
        <?php if (!$activity): ?>
        <div class="text-muted small">No activity recorded yet.</div>
        <?php endif; ?>
        <?php foreach ($activity as $a): ?>
        <div class="d-flex align-items-start gap-2 mb-2 small">
          <span class="badge bg-light text-dark border" style="font-size:.65rem"><?= htmlspecialchars(str_replace('_',' ',$a['action'] ?? '')) ?></span>
          <div class="flex-grow-1">
            <div><?= htmlspecialchars($a['user_name'] ?: 'System') ?></div>
            <div class="text-muted" style="font-size:.72rem"><?= date('d M Y H:i', strtotime($a['created_at'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
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
        <?php if (aiEnabled()): ?>
        <div class="mb-3 p-2 border rounded bg-light">
          <label class="form-label small fw-semibold mb-1"><i class="bi bi-stars text-primary me-1"></i>Draft with AI</label>
          <textarea id="rcaRoughNotes" class="form-control form-control-sm mb-2" rows="2"
            placeholder="Jot down what happened in your own words — AI will draft the three fields below from this."></textarea>
          <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-outline-primary" id="aiDraftRcaBtn" onclick="aiDraftRca()">
              <i class="bi bi-stars me-1"></i>Draft
            </button>
            <span class="small text-muted" id="aiDraftRcaStatus"></span>
          </div>
        </div>
        <?php endif; ?>
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
const CURRENT_TICKET_ID = <?= json_encode($ticketId) ?>;

function aiDraftRca() {
  const notes = document.getElementById('rcaRoughNotes').value.trim();
  const btn = document.getElementById('aiDraftRcaBtn');
  const status = document.getElementById('aiDraftRcaStatus');
  if (notes.length < 8) {
    status.textContent = 'Write a bit more detail first.';
    status.className = 'small text-warning';
    return;
  }
  btn.disabled = true;
  status.textContent = 'Drafting…';
  status.className = 'small text-muted';

  fetch('/api/ai-draft-rca', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ticketId: CURRENT_TICKET_ID, notes })
  })
    .then(r => r.json().then(data => ({ ok: r.ok, data })))
    .then(({ ok, data }) => {
      if (!ok || data.error) {
        status.textContent = data.error || 'Could not draft an RCA.';
        status.className = 'small text-danger';
        return;
      }
      document.getElementById('rcaRootCause').value = data.rootCause || '';
      document.getElementById('rcaObservation').value = data.observation || '';
      document.getElementById('rcaCorrective').value = data.correctiveAction || '';
      document.getElementById('rcaRootCause').classList.remove('is-invalid');
      status.innerHTML = '<i class="bi bi-check-circle text-success me-1"></i>Draft applied — review and edit before saving.';
      status.className = 'small text-success';
    })
    .catch(() => {
      status.textContent = 'Request failed — check your connection and try again.';
      status.className = 'small text-danger';
    })
    .finally(() => { btn.disabled = false; });
}

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