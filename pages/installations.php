<?php
require_once __DIR__ . '/../config.php';
requireAuth();

requirePermission('installations.view');
$user     = currentUser();
$role     = $user['role'];
$canEdit  = hasPermission('installations.create') || hasPermission('installations.update');

$STATUS_LABELS = [
    'pending'             => 'Unassigned',
    'in_progress'         => 'Assigned',
    'on_hold_customer'    => 'On Hold (Customer)',
    'on_hold_deployment'  => 'On Hold (Deployment)',
    'cable_laying'        => 'Cable Laying',
    'termination_pending' => 'Termination Pending',
    'connected'           => 'Connected',
    'refunded'            => 'Refunded',
];
$STATUS_COLORS = [
    'pending'             => 'secondary',
    'in_progress'         => 'primary',
    'on_hold_customer'    => 'danger',
    'on_hold_deployment'  => 'danger',
    'cable_laying'        => 'info',
    'termination_pending' => 'info',
    'connected'           => 'success',
    'refunded'            => 'dark',
];
// Stages that require a mandatory reason before they can be saved.
$ON_HOLD_STATUSES = ['on_hold_customer', 'on_hold_deployment'];
// Mango plans (name => [price, speed]) — sourced from the signup site's live plan list.
$PLAN_OPTIONS = [
    'Mango Basic'     => ['price' => 14067, 'speed' => '25Mbps'],
    'Mango Plus'      => ['price' => 19929, 'speed' => '40Mbps'],
    'Mango Premium'   => ['price' => 25790, 'speed' => '60Mbps'],
    'Mango Premium+'  => ['price' => 35172, 'speed' => '80Mbps'],
    'Mango Gold'      => ['price' => 40447, 'speed' => '120Mbps'],
    'Mango Diamond'   => ['price' => 48362, 'speed' => '165Mbps'],
    'Mango Platinum'  => ['price' => 58245, 'speed' => '200Mbps'],
];

$msg = '';
if (method() === 'POST') {
    verifyCsrf();
    $b = $_POST;
    $action = $b['_action'] ?? '';

    if ($action === 'create' && $canEdit) {
        $newStatus = $b['status'] ?? 'pending';
        $onHoldReason = trim($b['on_hold_reason'] ?? '');
        $refundReason = trim($b['refund_reason'] ?? '');
        $createErr = null;
        $hubId = trim($b['hub_id'] ?? '') ?: getHubIdForCity($b['estate'] ?? '');
        if (trim($b['plan'] ?? '') === '') {
            $createErr = 'Plan is required.';
        } elseif (trim($b['estate'] ?? '') === '') {
            $createErr = 'Location is required.';
        } elseif (!$hubId) {
            $createErr = 'POP (Hub) is required.';
        } elseif (trim($b['installation_cost'] ?? '') === '') {
            $createErr = 'Cost of Installation is required.';
        } elseif (trim($b['installation_paid'] ?? '') === '') {
            $createErr = 'Installation Paid is required.';
        } elseif (in_array($newStatus, $ON_HOLD_STATUSES, true) && $onHoldReason === '') {
            $createErr = 'An On Hold reason is required.';
        } elseif ($newStatus === 'refunded' && $refundReason === '') {
            $createErr = 'A refund reason is required.';
        } elseif ($newStatus === 'connected' && trim($b['network_user_id'] ?? '') === '') {
            $createErr = 'User ID is required when Stage is Connected.';
        }
        if ($createErr) {
            header('Location: /installations?err=' . urlencode($createErr)); exit;
        } else {
        $newPid = newUuid();
        $paymentAt = trim($b['payment_confirmed_at'] ?? '');
        $slaDue = $paymentAt ? addWorkingDays($paymentAt, INSTALLATION_SLA_WORKING_DAYS) : null;
        $refundedAtInput = trim($b['refunded_at'] ?? '');
        $refundedAt = $newStatus === 'refunded' ? ($refundedAtInput ?: date('Y-m-d')) : null;
        dbRun("INSERT INTO installation_profiles
            (id,name,phone,address,email,plan,wifi_username,wifi_password,ticket_id,vendor_id,status,notes,payment_confirmed_at,sla_due_at,
             amount_paid,network_user_id,router_type,estate,connection_date,installation_cost,
             on_hold_reason,refund_reason,hub_id,installation_paid,refunded_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$newPid,$b['name']??'',$b['phone']??'',$b['address']??'',$b['email']??'',$b['plan']??'',$b['wifi_username']??'',$b['wifi_password']??'',$b['ticket_id']??null,$b['vendor_id']??null,$newStatus,$b['notes']??'',$paymentAt?:null,$slaDue,
             $b['amount_paid']!==''&&isset($b['amount_paid'])?$b['amount_paid']:null,$b['network_user_id']??null,$b['router_type']??null,$b['estate']??null,$b['connection_date']?:null,
             $b['installation_cost']!==''&&isset($b['installation_cost'])?$b['installation_cost']:null,
             $onHoldReason?:null,$refundReason?:null,$hubId?:null,$b['installation_paid']?:null,$refundedAt]);
        $msg = 'Profile created.';

        if (!empty($b['vendor_id'])) {
            $vendor = dbFetch("SELECT name,email FROM vendors WHERE id=?", [$b['vendor_id']]);
            if ($vendor) {
                $newProfile = dbFetch("SELECT * FROM installation_profiles WHERE id=?", [$newPid]);
                emailVendorInstallationAssigned($newProfile, $vendor, false);
            }
        }
        }
    }
    if ($action === 'update' && $canEdit) {
        $pid = $b['profile_id'] ?? '';
        if ($pid) {
            $existing = dbFetch("SELECT status, completed_at, refunded_at, payment_confirmed_at, vendor_id FROM installation_profiles WHERE id=?", [$pid]);
            if ($existing) {
                $newStatus = $b['status'] ?? 'pending';
                $paymentAt = trim($b['payment_confirmed_at'] ?? '');
                $onHoldReason = trim($b['on_hold_reason'] ?? '');
                $refundReason = trim($b['refund_reason'] ?? '');
                $amountPaidRaw = $b['amount_paid'] ?? '';
                $hubId = trim($b['hub_id'] ?? '') ?: getHubIdForCity($b['estate'] ?? '');
                $updateErr = null;
                if (trim($b['plan'] ?? '') === '') {
                    $updateErr = 'Plan is required.';
                } elseif (trim($b['estate'] ?? '') === '') {
                    $updateErr = 'Location is required.';
                } elseif (!$hubId) {
                    $updateErr = 'POP (Hub) is required.';
                } elseif (trim($b['installation_cost'] ?? '') === '') {
                    $updateErr = 'Cost of Installation is required.';
                } elseif (trim($b['installation_paid'] ?? '') === '') {
                    $updateErr = 'Installation Paid is required.';
                } elseif (in_array($newStatus, $ON_HOLD_STATUSES, true) && $onHoldReason === '') {
                    $updateErr = 'An On Hold reason is required.';
                } elseif ($newStatus === 'refunded' && $refundReason === '') {
                    $updateErr = 'A refund reason is required.';
                } elseif ($newStatus === 'connected' && trim($b['network_user_id'] ?? '') === '') {
                    $updateErr = 'User ID is required when Stage is Connected.';
                } elseif ($paymentAt !== '' && $paymentAt !== ($existing['payment_confirmed_at'] ? date('Y-m-d', strtotime($existing['payment_confirmed_at'])) : '') && $amountPaidRaw === '') {
                    $updateErr = 'Amount paid is required when setting the payment confirmation date.';
                }
                if ($updateErr) {
                    header('Location: /installations?err=' . urlencode($updateErr) . (!empty($pid) ? '#profile-'.$pid : '')); exit;
                }

                // Recompute the SLA due date if the payment date changed (or was just set)
                $slaDue = $paymentAt ? addWorkingDays($paymentAt, INSTALLATION_SLA_WORKING_DAYS) : null;

                $sets = [
                    "name=?","phone=?","address=?","email=?","plan=?","wifi_username=?","wifi_password=?",
                    "ticket_id=?","status=?","notes=?","payment_confirmed_at=?","sla_due_at=?",
                    "amount_paid=?","network_user_id=?","router_type=?","estate=?",
                    "connection_date=?","installation_cost=?","installation_paid=?",
                    "on_hold_reason=?","refund_reason=?","hub_id=?","updated_at=NOW()",
                ];
                $vals = [
                    $b['name']??'', $b['phone']??'', $b['address']??'', $b['email']??'', $b['plan']??'',
                    $b['wifi_username']??'', $b['wifi_password']??'', $b['ticket_id']?:null,
                    $newStatus, $b['notes']??'', $paymentAt?:null, $slaDue,
                    ($b['amount_paid']??'')!==''?$b['amount_paid']:null, $b['network_user_id']??null, $b['router_type']??null,
                    $b['estate']??null, $b['connection_date']?:null,
                    ($b['installation_cost']??'')!==''?$b['installation_cost']:null, $b['installation_paid']?:null,
                    $onHoldReason?:null, $refundReason?:null, $hubId?:null,
                ];
                // Capture the moment the work order is first marked connected (same rule as Update Stage)
                if ($newStatus === 'connected' && empty($existing['completed_at'])) {
                    $sets[] = "completed_at=NOW()";
                }
                // Refunded stops the SLA clock the same way, but the date is user-editable —
                // default to today if left blank, keep whatever was there if unchanged.
                if ($newStatus === 'refunded') {
                    $refundedAtInput = trim($b['refunded_at'] ?? '');
                    $refundedAt = $refundedAtInput ?: ($existing['refunded_at'] ? date('Y-m-d', strtotime($existing['refunded_at'])) : date('Y-m-d'));
                    $sets[] = "refunded_at=?"; $vals[] = $refundedAt;
                }
                $vals[] = $pid;
                dbRun("UPDATE installation_profiles SET " . implode(',', $sets) . " WHERE id=?", $vals);
                auditLog('update', 'installation_profile', $pid);

                // Vendor assignment is editable directly on this form now. Route any change
                // through the same history-log + email path as the dedicated Reassign Vendor action.
                $newVendorId = trim($b['vendor_id'] ?? '');
                $oldVendorId = $existing['vendor_id'] ?? '';
                if ($newVendorId !== ($oldVendorId ?? '')) {
                    dbRun("UPDATE installation_profiles SET vendor_id=?, sla_warned_at=NULL, sla_breached_notified_at=NULL WHERE id=?", [$newVendorId ?: null, $pid]);
                    dbRun("INSERT INTO installation_vendor_history (id,profile_id,old_vendor_id,new_vendor_id,reason,changed_by,changed_by_name) VALUES (?,?,?,?,?,?,?)",
                        [newUuid(),$pid,$oldVendorId ?: null,$newVendorId ?: null,'Changed via Edit form',$user['id'],$user['name']]);
                    $oldName = $oldVendorId ? (dbFetch("SELECT name FROM vendors WHERE id=?",[$oldVendorId])['name'] ?? 'Unknown') : 'Unassigned';
                    $newName = $newVendorId ? (dbFetch("SELECT name FROM vendors WHERE id=?",[$newVendorId])['name'] ?? 'Unknown') : 'Unassigned';
                    $cid = newUuid();
                    dbRun("INSERT INTO installation_comments (id,profile_id,user_id,user_name,user_role,content,type) VALUES (?,?,?,?,?,?,?)",
                        [$cid,$pid,$user['id'],$user['name'],$role,"Vendor changed from {$oldName} to {$newName} (via Edit).",'vendor_reassigned']);
                    if ($newVendorId) {
                        $newVendorRow = dbFetch("SELECT name,email FROM vendors WHERE id=?", [$newVendorId]);
                        if ($newVendorRow) {
                            $updatedProfile = dbFetch("SELECT * FROM installation_profiles WHERE id=?", [$pid]);
                            emailVendorInstallationAssigned($updatedProfile, $newVendorRow, true);
                        }
                    }
                }

                $msg = 'Profile updated.';
            }
        }
    }
    if ($action === 'update_stage') {
        // canEdit or vendor assigned to this profile
        $pid = $b['profile_id'] ?? '';
        $allowed = $canEdit || ($role === 'vendor' && ($user['vendor_id'] ?? '') !== '');
        if ($allowed) {
            $newStatus = $b['status'] ?? 'pending';
            $reason = trim($b['reason'] ?? '');
            if (in_array($newStatus, $ON_HOLD_STATUSES, true) && $reason === '') {
                header('Location: /installations?err=' . urlencode('An On Hold reason is required.') . (!empty($pid) ? '#profile-'.$pid : '')); exit;
            }
            if ($newStatus === 'refunded' && $reason === '') {
                header('Location: /installations?err=' . urlencode('A refund reason is required.') . (!empty($pid) ? '#profile-'.$pid : '')); exit;
            }
            if ($newStatus === 'connected' && empty(dbFetch("SELECT network_user_id FROM installation_profiles WHERE id=?", [$pid])['network_user_id'])) {
                header('Location: /installations?err=' . urlencode('User ID is required when Stage is Connected — set it via Edit first.') . (!empty($pid) ? '#profile-'.$pid : '')); exit;
            }
            $existing = dbFetch("SELECT status, completed_at, refunded_at FROM installation_profiles WHERE id=?", [$pid]);
            $sets = ["status=?", "updated_at=NOW()"]; $vals = [$newStatus];
            if (in_array($newStatus, $ON_HOLD_STATUSES, true)) { $sets[] = "on_hold_reason=?"; $vals[] = $reason; }
            if ($newStatus === 'refunded') { $sets[] = "refund_reason=?"; $vals[] = $reason; }
            // Capture the moment the work order is first marked connected
            if ($newStatus === 'connected' && $existing && empty($existing['completed_at'])) {
                $sets[] = "completed_at=NOW()";
            }
            // Refunded stops the SLA clock the same way — captured once, like completed_at.
            if ($newStatus === 'refunded' && $existing && empty($existing['refunded_at'])) {
                $sets[] = "refunded_at=NOW()";
            }
            $vals[] = $pid;
            dbRun("UPDATE installation_profiles SET " . implode(',', $sets) . " WHERE id=?", $vals);
            $cid = newUuid();
            $stageMsg = 'Stage updated to: '.($STATUS_LABELS[$b['status']]??$b['status']).($reason?" — {$reason}":'');
            dbRun("INSERT INTO installation_comments (id,profile_id,user_id,user_name,user_role,content,type) VALUES (?,?,?,?,?,?,?)",
                [$cid,$pid,$user['id'],$user['name'],$role,$stageMsg,'stage_change']);
            $msg = 'Stage updated.';
        }
    }
    if ($action === 'confirm_payment' && $canEdit) {
        $pid = $b['profile_id'] ?? '';
        $paymentAt = trim($b['payment_confirmed_at'] ?? '');
        if ($pid && $paymentAt) {
            $slaDue = addWorkingDays($paymentAt, INSTALLATION_SLA_WORKING_DAYS);
            dbRun("UPDATE installation_profiles SET payment_confirmed_at=?, sla_due_at=?, updated_at=NOW() WHERE id=?", [$paymentAt, $slaDue, $pid]);
            $cid = newUuid();
            dbRun("INSERT INTO installation_comments (id,profile_id,user_id,user_name,user_role,content,type) VALUES (?,?,?,?,?,?,?)",
                [$cid,$pid,$user['id'],$user['name'],$role,'Payment confirmed on '.date('d M Y', strtotime($paymentAt)).'. SLA due '.date('d M Y', strtotime($slaDue)).'.','payment_confirmed']);
            $msg = 'Payment confirmation recorded.';
        }
    }
    if ($action === 'sync_signups' && $canEdit) {
        $result = syncInstallationsFromSignup();
        if ($result['errors']) {
            $msg = "Synced {$result['imported']} new installation(s), skipped {$result['skipped']} already imported. " . count($result['errors']) . ' error(s) — check the PHP error log.';
            foreach ($result['errors'] as $_e) { error_log('Signup sync: ' . $_e); }
        } else {
            $msg = "Synced {$result['imported']} new installation(s) from signups. {$result['skipped']} were already imported.";
        }
    }
    if ($action === 'reassign_vendor' && $canEdit) {
        $pid = $b['profile_id'] ?? '';
        $newVendorId = $b['new_vendor_id'] ?? '';
        $reason = trim($b['reason'] ?? '');
        if ($pid) {
            $current = dbFetch("SELECT vendor_id FROM installation_profiles WHERE id=?", [$pid]);
            $oldVendorId = $current['vendor_id'] ?? null;
            // Reset SLA notification flags on reassignment — the due date itself doesn't
            // move, but a new vendor who hasn't been warned/notified yet still should be.
            dbRun("UPDATE installation_profiles SET vendor_id=?, sla_warned_at=NULL, sla_breached_notified_at=NULL, updated_at=NOW() WHERE id=?", [$newVendorId ?: null, $pid]);
            dbRun("INSERT INTO installation_vendor_history (id,profile_id,old_vendor_id,new_vendor_id,reason,changed_by,changed_by_name) VALUES (?,?,?,?,?,?,?)",
                [newUuid(),$pid,$oldVendorId,$newVendorId ?: null,$reason,$user['id'],$user['name']]);
            $oldName = $oldVendorId ? (dbFetch("SELECT name FROM vendors WHERE id=?",[$oldVendorId])['name'] ?? 'Unknown') : 'Unassigned';
            $newName = $newVendorId ? (dbFetch("SELECT name FROM vendors WHERE id=?",[$newVendorId])['name'] ?? 'Unknown') : 'Unassigned';
            $cid = newUuid();
            dbRun("INSERT INTO installation_comments (id,profile_id,user_id,user_name,user_role,content,type) VALUES (?,?,?,?,?,?,?)",
                [$cid,$pid,$user['id'],$user['name'],$role,"Vendor reassigned from {$oldName} to {$newName}".($reason?": {$reason}":'.'),'vendor_reassigned']);

            if ($newVendorId) {
                $newVendorRow = dbFetch("SELECT name,email FROM vendors WHERE id=?", [$newVendorId]);
                if ($newVendorRow) {
                    $updatedProfile = dbFetch("SELECT * FROM installation_profiles WHERE id=?", [$pid]);
                    emailVendorInstallationAssigned($updatedProfile, $newVendorRow, true);
                }
            }
            $msg = 'Vendor reassigned.';
        }
    }
    if ($action === 'add_comment') {
        $pid = $b['profile_id'] ?? '';
        $allowed = $canEdit || ($role === 'vendor');
        if ($allowed) {
            $cid = newUuid();
            dbRun("INSERT INTO installation_comments (id,profile_id,user_id,user_name,user_role,content,type) VALUES (?,?,?,?,?,?,?)",
                [$cid,$pid,$user['id'],$user['name'],$role,$b['content']??'','comment']);
            $msg = 'Comment added.';
        }
    }
    if ($action === 'delete' && $canEdit) {
        dbRun("DELETE FROM installation_profiles WHERE id=?",[$b['profile_id']??'']);
        $msg = 'Profile deleted.';
    }
    header('Location: /installations' . (!empty($b['profile_id']) ? '#profile-'.$b['profile_id'] : '')); exit;
}

$search  = $_GET['search'] ?? '';
$stFilter = $_GET['status'] ?? '';
$paidFilter = $_GET['paid'] ?? '';
$vendorFilter = $_GET['vendor'] ?? '';

$where=[]; $params=[];
// Vendor users only ever see their own company's installation jobs — this
// overrides any ?vendor= query param, it isn't just a default.
if ($role === 'vendor') {
    $where[]="p.vendor_id=?"; $params[]=$user['vendor_id'] ?? '__none__';
} elseif ($vendorFilter) {
    $where[]="p.vendor_id=?"; $params[]=$vendorFilter;
}
if ($search) { $where[]="(p.name LIKE ? OR p.email LIKE ? OR p.phone LIKE ?)"; $like="%$search%"; array_push($params,$like,$like,$like); }
if ($stFilter) { $where[]="p.status=?"; $params[]=$stFilter; }
if ($paidFilter === 'unset') { $where[]="(p.installation_paid IS NULL OR p.installation_paid='')"; }
elseif ($paidFilter) { $where[]="p.installation_paid=?"; $params[]=$paidFilter; }

$profiles = dbFetchAll("SELECT p.*,v.name AS vendor_name,h.name AS hub_name FROM installation_profiles p LEFT JOIN vendors v ON v.id=p.vendor_id LEFT JOIN hubs h ON h.id=p.hub_id".($where?" WHERE ".implode(' AND ',$where):'')." ORDER BY p.created_at DESC",$params);
$vendors  = $canEdit ? dbFetchAll("SELECT id,name FROM vendors WHERE type='installation' AND status='active' ORDER BY name") : [];
$allVendors = dbFetchAll("SELECT id,name FROM vendors ORDER BY name");
// All tickets, not just type='installation' — staff need to link whichever ticket actually
// applies (e.g. a cable-cut ticket found while investigating a customer's original LOS report).
$tickets  = dbFetchAll("SELECT id,ticket_number,customer_name,description FROM tickets ORDER BY created_at DESC LIMIT 500");
$hubs     = dbFetchAll("SELECT id,name FROM hubs ORDER BY name");
$errMsg   = $_GET['err'] ?? '';

// Stats — scoped to the vendor's own jobs when logged in as a vendor
$_statsVendorSql = ''; $_statsVendorParams = [];
if ($role === 'vendor') { $_statsVendorSql = ' AND vendor_id=?'; $_statsVendorParams = [$user['vendor_id'] ?? '__none__']; }
$stats = [];
foreach ($STATUS_LABELS as $s => $l) {
    $stats[$s] = (int)(dbFetch("SELECT COUNT(*) AS c FROM installation_profiles WHERE status=?{$_statsVendorSql}",array_merge([$s],$_statsVendorParams))['c'] ?? 0);
}
$paidStats = [
    'Yes'   => (int)(dbFetch("SELECT COUNT(*) AS c FROM installation_profiles WHERE installation_paid='Yes'{$_statsVendorSql}",$_statsVendorParams)['c'] ?? 0),
    'No'    => (int)(dbFetch("SELECT COUNT(*) AS c FROM installation_profiles WHERE installation_paid='No'{$_statsVendorSql}",$_statsVendorParams)['c'] ?? 0),
    'unset' => (int)(dbFetch("SELECT COUNT(*) AS c FROM installation_profiles WHERE (installation_paid IS NULL OR installation_paid=''){$_statsVendorSql}",$_statsVendorParams)['c'] ?? 0),
];

// For detail view — vendors may only open their own jobs
$detailId = $_GET['detail'] ?? null;
$detailProfile = $detailId ? dbFetch("SELECT p.*,v.name AS vendor_name,h.name AS hub_name FROM installation_profiles p LEFT JOIN vendors v ON v.id=p.vendor_id LEFT JOIN hubs h ON h.id=p.hub_id WHERE p.id=?",[$detailId]) : null;
if ($detailProfile && $role === 'vendor' && ($detailProfile['vendor_id'] ?? null) !== ($user['vendor_id'] ?? null)) {
    $detailProfile = null;
}
$detailComments = $detailId ? dbFetchAll("SELECT * FROM installation_comments WHERE profile_id=? ORDER BY created_at",[$detailId]) : [];

$pageTitle = 'Installations';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?><div class="alert alert-success alert-dismissible py-2"><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($errMsg): ?><div class="alert alert-danger alert-dismissible py-2"><?= htmlspecialchars($errMsg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<!-- Stats row -->
<div class="row g-2 mb-3">
  <?php foreach ($STATUS_LABELS as $s => $l): ?>
  <div class="col-6 col-sm-4 col-xl-2">
    <a href="/installations?status=<?=$s?>" class="stat-card py-2 text-decoration-none d-block text-center <?= $stFilter===$s?'border-primary':'' ?>">
      <div class="fw-bold fs-5"><?= $stats[$s] ?></div>
      <div style="font-size:.7rem;color:#64748b"><?= $l ?></div>
    </a>
  </div>
  <?php endforeach; ?>
  <div class="col-6 col-sm-4 col-xl-2">
    <a href="/installations?paid=Yes" class="stat-card py-2 text-decoration-none d-block text-center <?= $paidFilter==='Yes'?'border-primary':'' ?>">
      <div class="fw-bold fs-5"><?= $paidStats['Yes'] ?></div>
      <div style="font-size:.7rem;color:#64748b">Installation Paid</div>
    </a>
  </div>
  <div class="col-6 col-sm-4 col-xl-2">
    <a href="/installations?paid=No" class="stat-card py-2 text-decoration-none d-block text-center <?= $paidFilter==='No'?'border-primary':'' ?>">
      <div class="fw-bold fs-5"><?= $paidStats['No'] ?></div>
      <div style="font-size:.7rem;color:#64748b">Installation Not Paid</div>
    </a>
  </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <form class="d-flex gap-2 flex-grow-1 flex-wrap" method="GET">
    <?php if ($paidFilter): ?><input type="hidden" name="paid" value="<?= htmlspecialchars($paidFilter) ?>"><?php endif; ?>
    <div class="input-group" style="max-width:260px">
      <span class="input-group-text"><i class="bi bi-search"></i></span>
      <input type="text" name="search" class="form-control" placeholder="Search…" value="<?= htmlspecialchars($search) ?>">
    </div>
    <select name="status" class="form-select" style="width:auto" onchange="this.form.submit()">
      <option value="">All Stages</option>
      <?php foreach($STATUS_LABELS as $s=>$l): ?><option value="<?=$s?>" <?=$stFilter===$s?'selected':''?>><?=$l?></option><?php endforeach; ?>
    </select>
    <select name="vendor" class="form-select" style="width:auto" onchange="this.form.submit()">
      <option value="">All Vendors</option>
      <?php foreach($allVendors as $v): ?><option value="<?=$v['id']?>" <?=$vendorFilter===$v['id']?'selected':''?>><?= htmlspecialchars($v['name']) ?></option><?php endforeach; ?>
    </select>
    <?php if ($search||$stFilter||$paidFilter||$vendorFilter): ?><a href="/installations" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
  </form>
  <a href="/api/installations-export.php?<?= http_build_query(['search'=>$search,'status'=>$stFilter,'paid'=>$paidFilter,'vendor'=>$vendorFilter]) ?>" class="btn btn-outline-success btn-sm">
    <i class="bi bi-file-earmark-excel me-1"></i>Export
  </a>
  <?php if ($canEdit): ?>
  <a href="/installations/analytics" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-graph-up-arrow me-1"></i>SLA Analytics
  </a>
  <form method="POST" class="d-inline">
    <input type="hidden" name="_action" value="sync_signups">
    <?= csrfField() ?>
    <button type="submit" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-repeat me-1"></i>Sync Signups
    </button>
  </form>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addProfileModal">
    <i class="bi bi-plus-lg me-1"></i>New Profile
  </button>
  <?php endif; ?>
</div>

<div class="card-section">
  <div class="card-header"><?= count($profiles) ?> profile<?= count($profiles)!==1?'s':'' ?></div>
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0">
      <thead class="table-light">
        <tr><th>Customer</th><th>Plan</th><th>Stage</th><th>Vendor</th><th>POP</th><th>Location</th><th>Amount</th><th>SLA</th><th>Days Pending</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$profiles): ?><tr><td colspan="10" class="text-center text-muted py-4">No profiles found</td></tr><?php endif; ?>
        <?php foreach ($profiles as $p): $sla = installationSlaBadge($p); $daysPending = installationDaysPending($p); ?>
        <tr id="profile-<?= $p['id'] ?>">
          <td class="fw-semibold"><?= htmlspecialchars($p['name']) ?><?php if (!empty($p['signup_submission_id'])): ?> <i class="bi bi-arrow-repeat text-muted" title="Auto-imported from the signup site"></i><?php endif; ?><div class="text-muted small fw-normal"><?= htmlspecialchars($p['phone']??'') ?></div></td>
          <td class="small"><?= htmlspecialchars($p['plan']??'') ?></td>
          <td><span class="badge bg-<?= $STATUS_COLORS[$p['status']]??'secondary' ?>"><?= $STATUS_LABELS[$p['status']]??$p['status'] ?></span></td>
          <td class="small"><?= htmlspecialchars($p['vendor_name']??'—') ?></td>
          <td class="small"><?= htmlspecialchars($p['hub_name']??'—') ?></td>
          <td class="small"><?= htmlspecialchars($p['estate']??'—') ?></td>
          <td class="small"><?= $p['amount_paid'] !== null ? number_format((float)$p['amount_paid']) : '—' ?></td>
          <td><span class="badge bg-<?= $sla['class'] ?>"><?= $sla['label'] ?></span></td>
          <td class="small"><?= $daysPending !== null ? $daysPending . 'd' : '—' ?></td>
          <td>
            <div class="d-flex gap-1">
              <a href="/installations?detail=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary py-0" title="View details"><i class="bi bi-chat-left-text"></i></a>
              <?php if ($canEdit): ?>
              <button class="btn btn-sm btn-outline-primary py-0" onclick="openEditFull(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
              <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="profile_id" value="<?= $p['id'] ?>">
                <?= csrfField() ?>
                <button type="submit" class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Detail / Comments view -->
<?php if ($detailProfile): ?>
<div class="mt-4 card-section">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-chat-left-text me-1 text-primary"></i><?= htmlspecialchars($detailProfile['name']) ?>
      <span class="badge bg-<?= $STATUS_COLORS[$detailProfile['status']]??'secondary' ?> ms-1"><?= $STATUS_LABELS[$detailProfile['status']]??$detailProfile['status'] ?></span>
      <?php if (!empty($detailProfile['signup_submission_id'])): ?>
      <span class="badge bg-light text-dark border ms-1" title="Auto-imported from the signup site"><i class="bi bi-arrow-repeat me-1"></i>From Signup</span>
      <?php endif; ?>
    </span>
    <a href="/installations" class="btn btn-sm btn-outline-secondary">Close</a>
  </div>
  <div class="row g-0">
    <div class="col-lg-5 p-3 border-end">
      <dl class="row small mb-3">
        <?php if ($detailProfile['phone']): ?><dt class="col-4 text-muted">Phone</dt><dd class="col-8"><?= htmlspecialchars($detailProfile['phone']) ?></dd><?php endif; ?>
        <?php if ($detailProfile['email']): ?><dt class="col-4 text-muted">Email</dt><dd class="col-8"><?= htmlspecialchars($detailProfile['email']) ?></dd><?php endif; ?>
        <?php if ($detailProfile['address']): ?><dt class="col-4 text-muted">Address</dt><dd class="col-8"><?= htmlspecialchars($detailProfile['address']) ?></dd><?php endif; ?>
        <?php if ($detailProfile['estate']): ?><dt class="col-4 text-muted">Estate / City</dt><dd class="col-8"><?= htmlspecialchars($detailProfile['estate']) ?></dd><?php endif; ?>
        <?php if ($detailProfile['plan']): ?><dt class="col-4 text-muted">Plan</dt><dd class="col-8"><?= htmlspecialchars($detailProfile['plan']) ?></dd><?php endif; ?>
        <?php if ($detailProfile['router_type']): ?><dt class="col-4 text-muted">Router Type</dt><dd class="col-8"><?= htmlspecialchars($detailProfile['router_type']) ?></dd><?php endif; ?>
        <?php if ($detailProfile['network_user_id']): ?><dt class="col-4 text-muted">User ID</dt><dd class="col-8"><?= htmlspecialchars($detailProfile['network_user_id']) ?></dd><?php endif; ?>
        <dt class="col-4 text-muted">POP (Hub)</dt><dd class="col-8"><?= htmlspecialchars($detailProfile['hub_name']??'—') ?></dd>
        <dt class="col-4 text-muted">Vendor</dt><dd class="col-8"><?= htmlspecialchars($detailProfile['vendor_name']??'Unassigned') ?></dd>
        <?php if (!empty($detailProfile['installation_paid'])): ?><dt class="col-4 text-muted">Installation Paid</dt><dd class="col-8"><?= htmlspecialchars($detailProfile['installation_paid']) ?></dd><?php endif; ?>
        <?php if (!empty($detailProfile['on_hold_reason'])): ?><dt class="col-4 text-muted">On Hold Reason</dt><dd class="col-8"><?= htmlspecialchars($detailProfile['on_hold_reason']) ?></dd><?php endif; ?>
        <?php if (!empty($detailProfile['refund_reason'])): ?><dt class="col-4 text-muted">Refund Reason</dt><dd class="col-8"><?= htmlspecialchars($detailProfile['refund_reason']) ?></dd><?php endif; ?>
        <?php if (!empty($detailProfile['refunded_at'])): ?><dt class="col-4 text-muted">Date Refunded</dt><dd class="col-8"><?= date('d M Y', strtotime($detailProfile['refunded_at'])) ?></dd><?php endif; ?>
        <?php if ($detailProfile['installer']): ?><dt class="col-4 text-muted">Installer</dt><dd class="col-8"><?= htmlspecialchars($detailProfile['installer']) ?></dd><?php endif; ?>
        <?php if ($detailProfile['connection_date']): ?><dt class="col-4 text-muted">Connection Date</dt><dd class="col-8"><?= date('d M Y', strtotime($detailProfile['connection_date'])) ?></dd><?php endif; ?>
        <?php if ($detailProfile['amount_paid'] !== null): ?><dt class="col-4 text-muted">Amount Paid</dt><dd class="col-8"><?= number_format((float)$detailProfile['amount_paid'], 2) ?></dd><?php endif; ?>
        <?php if ($detailProfile['installation_cost'] !== null): ?><dt class="col-4 text-muted">Install Cost</dt><dd class="col-8"><?= number_format((float)$detailProfile['installation_cost'], 2) ?></dd><?php endif; ?>
        <dt class="col-4 text-muted">SLA</dt><dd class="col-8"><?php $sla = installationSlaBadge($detailProfile); ?><span class="badge bg-<?= $sla['class'] ?>"><?= $sla['label'] ?></span></dd>
        <?php $daysPending = installationDaysPending($detailProfile); if ($daysPending !== null): ?><dt class="col-4 text-muted">Days Pending</dt><dd class="col-8"><?= $daysPending ?> day<?= $daysPending!==1?'s':'' ?></dd><?php endif; ?>
        <?php if ($detailProfile['payment_confirmed_at']): ?><dt class="col-4 text-muted">Payment Confirmed</dt><dd class="col-8"><?= date('d M Y', strtotime($detailProfile['payment_confirmed_at'])) ?></dd><?php endif; ?>
        <?php if ($detailProfile['sla_due_at']): ?><dt class="col-4 text-muted">SLA Due</dt><dd class="col-8"><?= date('d M Y', strtotime($detailProfile['sla_due_at'])) ?> <span class="text-muted">(<?= INSTALLATION_SLA_WORKING_DAYS ?> working days)</span></dd><?php endif; ?>
        <?php if ($detailProfile['completed_at']): ?><dt class="col-4 text-muted">Completed</dt><dd class="col-8"><?= date('d M Y H:i', strtotime($detailProfile['completed_at'])) ?></dd><?php endif; ?>
      </dl>
      <?php
      $canInteract = $canEdit || ($role === 'vendor' && !empty($user['vendor_id']) && $user['vendor_id'] === $detailProfile['vendor_id']);
      ?>
      <?php if ($canInteract): ?>
      <form method="POST" class="border rounded p-2 mb-3 bg-light">
        <input type="hidden" name="_action" value="update_stage">
        <input type="hidden" name="profile_id" value="<?= $detailProfile['id'] ?>">
        <?= csrfField() ?>
        <label class="form-label small fw-semibold mb-1">Update Stage</label>
        <div class="d-flex gap-2 mb-2">
          <select name="status" id="stageQuickSelect" class="form-select form-select-sm flex-grow-1" onchange="toggleStageReason(this.value)">
            <?php foreach($STATUS_LABELS as $s=>$l): ?>
            <option value="<?=$s?>" <?=$detailProfile['status']===$s?'selected':''?>><?=$l?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm btn-primary">Apply</button>
        </div>
        <textarea name="reason" id="stageQuickReason" class="form-control form-control-sm d-none" rows="2" placeholder="Reason (required for On Hold / Refunded)…"><?= htmlspecialchars($detailProfile['on_hold_reason'] ?: $detailProfile['refund_reason'] ?: '') ?></textarea>
      </form>
      <script>
        function toggleStageReason(v) {
          document.getElementById('stageQuickReason').classList.toggle('d-none', !['on_hold_customer','on_hold_deployment','refunded'].includes(v));
        }
        toggleStageReason(document.getElementById('stageQuickSelect').value);
      </script>
      <?php endif; ?>

      <?php if ($canEdit): ?>
      <form method="POST" class="border rounded p-2 mb-3 bg-light">
        <input type="hidden" name="_action" value="confirm_payment">
        <input type="hidden" name="profile_id" value="<?= $detailProfile['id'] ?>">
        <?= csrfField() ?>
        <label class="form-label small fw-semibold mb-1">
          <?= $detailProfile['payment_confirmed_at'] ? 'Correct Payment Date' : 'Confirm Payment' ?>
          <span class="text-muted fw-normal">(starts the <?= INSTALLATION_SLA_WORKING_DAYS ?>-working-day SLA clock)</span>
        </label>
        <div class="d-flex gap-2">
          <input type="date" name="payment_confirmed_at" class="form-control form-control-sm flex-grow-1"
                 value="<?= $detailProfile['payment_confirmed_at'] ? date('Y-m-d', strtotime($detailProfile['payment_confirmed_at'])) : date('Y-m-d') ?>"
                 max="<?= date('Y-m-d') ?>" required>
          <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
        </div>
      </form>

      <form method="POST" class="border rounded p-2 mb-3 bg-light">
        <input type="hidden" name="_action" value="reassign_vendor">
        <input type="hidden" name="profile_id" value="<?= $detailProfile['id'] ?>">
        <?= csrfField() ?>
        <label class="form-label small fw-semibold mb-1">Reassign Vendor</label>
        <select name="new_vendor_id" class="form-select form-select-sm mb-2">
          <option value="">— Unassigned —</option>
          <?php foreach ($allVendors as $v): ?>
          <option value="<?= $v['id'] ?>" <?= $detailProfile['vendor_id']===$v['id']?'selected':'' ?>><?= htmlspecialchars($v['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <textarea name="reason" class="form-control form-control-sm mb-2" rows="2" placeholder="Reason (e.g. vendor failed to deliver)…"></textarea>
        <button type="submit" class="btn btn-sm btn-outline-danger w-100">Reassign</button>
      </form>
      <?php endif; ?>
    </div>
    <div class="col-lg-7 p-3">
      <p class="small fw-semibold text-muted text-uppercase mb-2"><i class="bi bi-chat-left-text me-1"></i>Activity Log</p>
      <?php if (!$detailComments): ?>
      <p class="text-muted small">No comments yet.</p>
      <?php endif; ?>
      <div style="max-height:280px;overflow-y:auto" class="mb-3">
        <?php foreach ($detailComments as $c): ?>
        <?php
        $_dotColor = match($c['type']) {
          'stage_change'      => '#f59e0b',
          'payment_confirmed' => '#22c55e',
          'vendor_reassigned' => '#dc2626',
          default             => '#0ea5e9',
        };
        $_typeBadge = match($c['type']) {
          'stage_change'      => '<span class="badge bg-warning text-dark" style="font-size:.65rem">Stage Change</span>',
          'payment_confirmed' => '<span class="badge bg-success" style="font-size:.65rem">Payment</span>',
          'vendor_reassigned' => '<span class="badge bg-danger" style="font-size:.65rem">Vendor Reassigned</span>',
          default             => '',
        };
        ?>
        <div class="d-flex gap-2 mb-3">
          <div style="width:28px;height:28px;border-radius:50%;background:<?=$_dotColor?>;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.7rem;font-weight:700;flex-shrink:0">
            <?= strtoupper(substr($c['user_name']??'?',0,1)) ?>
          </div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-1 flex-wrap">
              <strong class="small"><?= htmlspecialchars($c['user_name']??'') ?></strong>
              <span class="badge bg-light text-dark border" style="font-size:.65rem"><?= htmlspecialchars($c['user_role']??'') ?></span>
              <?= $_typeBadge ?>
              <small class="text-muted ms-auto"><?= date('d M Y H:i', strtotime($c['created_at'])) ?></small>
            </div>
            <div class="mt-1 small <?=$c['type']!=='comment'?'text-muted fst-italic':''?>"><?= nl2br(htmlspecialchars($c['content'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if ($canInteract): ?>
      <form method="POST" class="border-top pt-2">
        <input type="hidden" name="_action" value="add_comment">
        <input type="hidden" name="profile_id" value="<?= $detailProfile['id'] ?>">
        <?= csrfField() ?>
        <div class="d-flex gap-2">
          <textarea name="content" class="form-control form-control-sm" rows="2" placeholder="Add a comment…" required></textarea>
          <button type="submit" class="btn btn-sm btn-primary align-self-end"><i class="bi bi-send"></i></button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Add Profile Modal -->
<?php if ($canEdit): ?>
<div class="modal fade" id="addProfileModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="POST"><input type="hidden" name="_action" value="create">
      <?= csrfField() ?>
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-wifi me-1 text-primary"></i>New Installation Profile</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-12"><label class="form-label small fw-semibold">Customer Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control form-control-sm" required></div>
          <div class="col-6"><label class="form-label small fw-semibold">Phone</label><input type="text" name="phone" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small fw-semibold">Email</label><input type="email" name="email" class="form-control form-control-sm"></div>
          <div class="col-8"><label class="form-label small fw-semibold">Address</label><input type="text" name="address" class="form-control form-control-sm"></div>
          <div class="col-4"><label class="form-label small fw-semibold">Estate / City (Location) <span class="text-danger">*</span></label>
            <input type="text" name="estate" class="form-control form-control-sm" list="knownCitiesList" onchange="autoSelectHub(this.value,'hub_id')" required>
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">Plan <span class="text-danger">*</span></label>
            <select name="plan" class="form-select form-select-sm" required>
              <option value="">— Select —</option>
              <?php foreach($PLAN_OPTIONS as $pl=>$info): ?>
              <option value="<?=htmlspecialchars($pl)?>"><?=htmlspecialchars($pl)?> — <?=$info['speed']?> — ₦<?=number_format($info['price'])?>/mo</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">Stage</label>
            <select name="status" class="form-select form-select-sm" onchange="toggleAddReasons(this.value)">
              <?php foreach($STATUS_LABELS as $s=>$l): ?><option value="<?=$s?>"><?=$l?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 d-none" id="addOnHoldWrap"><label class="form-label small fw-semibold">On Hold Reason <span class="text-danger">*</span></label><input type="text" name="on_hold_reason" class="form-control form-control-sm"></div>
          <div class="col-6 d-none" id="addRefundWrap"><label class="form-label small fw-semibold">Refund Reason <span class="text-danger">*</span></label><input type="text" name="refund_reason" class="form-control form-control-sm"></div>
          <div class="col-6 d-none" id="addRefundedAtWrap"><label class="form-label small fw-semibold">Date Refunded</label><input type="date" name="refunded_at" class="form-control form-control-sm" max="<?= date('Y-m-d') ?>"></div>
          <div class="col-6"><label class="form-label small fw-semibold">Payment Confirmed Date</label>
            <input type="date" name="payment_confirmed_at" class="form-control form-control-sm" max="<?= date('Y-m-d') ?>">
            <div class="form-text">Starts the <?= INSTALLATION_SLA_WORKING_DAYS ?>-working-day SLA clock. Leave blank if payment isn't confirmed yet.</div>
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">Amount Paid</label><input type="number" step="0.01" min="0" name="amount_paid" class="form-control form-control-sm"></div>
          <div class="col-6 d-none" id="addUserIdWrap"><label class="form-label small fw-semibold">User ID <span class="text-danger">*</span></label><input type="text" name="network_user_id" class="form-control form-control-sm" placeholder="Network / BSS username"></div>
          <div class="col-6"><label class="form-label small fw-semibold">Router Type</label><input type="text" name="router_type" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small fw-semibold">POP (Hub) <span class="text-danger">*</span></label>
            <select name="hub_id" id="hub_id" class="form-select form-select-sm" required>
              <option value="">— Auto-detect or select —</option>
              <?php foreach($hubs as $h): ?><option value="<?=$h['id']?>"><?= htmlspecialchars($h['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">Connection Date</label><input type="date" name="connection_date" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small fw-semibold">Cost of Installation <span class="text-danger">*</span></label><input type="number" step="0.01" min="0" name="installation_cost" class="form-control form-control-sm" required></div>
          <div class="col-6"><label class="form-label small fw-semibold">Installation Paid <span class="text-danger">*</span></label>
            <select name="installation_paid" class="form-select form-select-sm" required>
              <option value="">— Select —</option>
              <option value="Yes">Yes</option>
              <option value="No">No</option>
            </select>
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">WiFi Username</label><input type="text" name="wifi_username" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small fw-semibold">WiFi Password</label><input type="text" name="wifi_password" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small fw-semibold">Vendor</label>
            <select name="vendor_id" class="form-select form-select-sm"><option value="">— None —</option>
              <?php foreach($allVendors as $v): ?><option value="<?=$v['id']?>"><?= htmlspecialchars($v['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">Linked Ticket</label>
            <select name="ticket_id" id="addTicketId" class="form-select form-select-sm"><option value="">— None —</option>
              <?php foreach($tickets as $t): ?><option value="<?=$t['id']?>"><?= htmlspecialchars($t['ticket_number'].' — '.$t['customer_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12"><label class="form-label small fw-semibold">Notes</label><textarea name="notes" class="form-control form-control-sm" rows="2"></textarea></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary btn-sm">Create Profile</button></div>
    </form>
  </div></div>
</div>
<datalist id="knownCitiesList">
  <?php foreach (dbFetchAll("SELECT name FROM locations ORDER BY name") as $c): ?>
  <option value="<?= htmlspecialchars($c['name']) ?>">
  <?php endforeach; ?>
</datalist>
<script>
function toggleAddReasons(v) {
  document.getElementById('addOnHoldWrap').classList.toggle('d-none', v !== 'on_hold_customer' && v !== 'on_hold_deployment');
  document.getElementById('addRefundWrap').classList.toggle('d-none', v !== 'refunded');
  document.getElementById('addRefundedAtWrap').classList.toggle('d-none', v !== 'refunded');
  document.getElementById('addUserIdWrap').classList.toggle('d-none', v !== 'connected');
}
var CITY_HUB_MAP = <?= json_encode(array_column(dbFetchAll("SELECT LOWER(TRIM(city_name)) AS city_name, hub_id FROM hub_city_mappings"), 'hub_id', 'city_name')) ?>;
function autoSelectHub(cityValue, selectId) {
  var hubId = CITY_HUB_MAP[(cityValue || '').trim().toLowerCase()];
  if (hubId) document.getElementById(selectId).value = hubId;
}
</script>

<!-- Edit Profile Modal — every field editable, same pattern as the Customers module -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="POST"><input type="hidden" name="_action" value="update">
      <input type="hidden" name="profile_id" id="editProfileId">
      <?= csrfField() ?>
      <div class="modal-header"><h5 class="modal-title" id="editModalTitle"><i class="bi bi-pencil me-1 text-primary"></i>Edit Profile</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-12"><label class="form-label small fw-semibold">Customer Name <span class="text-danger">*</span></label><input type="text" name="name" id="editName" class="form-control form-control-sm" required></div>
          <div class="col-6"><label class="form-label small fw-semibold">Phone</label><input type="text" name="phone" id="editPhone" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small fw-semibold">Email</label><input type="email" name="email" id="editEmail" class="form-control form-control-sm"></div>
          <div class="col-8"><label class="form-label small fw-semibold">Address</label><input type="text" name="address" id="editAddress" class="form-control form-control-sm"></div>
          <div class="col-4"><label class="form-label small fw-semibold">Estate / City (Location) <span class="text-danger">*</span></label>
            <input type="text" name="estate" id="editEstate" class="form-control form-control-sm" list="knownCitiesList" onchange="autoSelectHub(this.value,'editHubId')" required>
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">Plan <span class="text-danger">*</span></label>
            <select name="plan" id="editPlan" class="form-select form-select-sm" required>
              <option value="">— Select —</option>
              <?php foreach($PLAN_OPTIONS as $pl=>$info): ?>
              <option value="<?=htmlspecialchars($pl)?>"><?=htmlspecialchars($pl)?> — <?=$info['speed']?> — ₦<?=number_format($info['price'])?>/mo</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">Stage</label>
            <select name="status" id="editStatus" class="form-select form-select-sm" onchange="toggleEditReasons(this.value)">
              <?php foreach($STATUS_LABELS as $s=>$l): ?><option value="<?=$s?>"><?=$l?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 d-none" id="editOnHoldWrap"><label class="form-label small fw-semibold">On Hold Reason <span class="text-danger">*</span></label><input type="text" name="on_hold_reason" id="editOnHoldReason" class="form-control form-control-sm"></div>
          <div class="col-6 d-none" id="editRefundWrap"><label class="form-label small fw-semibold">Refund Reason <span class="text-danger">*</span></label><input type="text" name="refund_reason" id="editRefundReason" class="form-control form-control-sm"></div>
          <div class="col-6 d-none" id="editRefundedAtWrap"><label class="form-label small fw-semibold">Date Refunded</label><input type="date" name="refunded_at" id="editRefundedAtInput" class="form-control form-control-sm" max="<?= date('Y-m-d') ?>"></div>
          <div class="col-6"><label class="form-label small fw-semibold">Payment Confirmed Date</label>
            <input type="date" name="payment_confirmed_at" id="editPaymentDate" class="form-control form-control-sm" max="<?= date('Y-m-d') ?>">
            <div class="form-text">Recalculates the SLA due date if changed. Setting/changing this requires Amount Paid below.</div>
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">Amount Paid</label><input type="number" step="0.01" min="0" name="amount_paid" id="editAmountPaid" class="form-control form-control-sm"></div>
          <div class="col-6 d-none" id="editUserIdWrap"><label class="form-label small fw-semibold">User ID <span class="text-danger">*</span></label><input type="text" name="network_user_id" id="editNetworkUserId" class="form-control form-control-sm" placeholder="Network / BSS username"></div>
          <div class="col-6"><label class="form-label small fw-semibold">Router Type</label><input type="text" name="router_type" id="editRouterType" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small fw-semibold">POP (Hub) <span class="text-danger">*</span></label>
            <select name="hub_id" id="editHubId" class="form-select form-select-sm" required>
              <option value="">— Auto-detect or select —</option>
              <?php foreach($hubs as $h): ?><option value="<?=$h['id']?>"><?= htmlspecialchars($h['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">Connection Date</label><input type="date" name="connection_date" id="editConnectionDate" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small fw-semibold">Vendor</label>
            <select name="vendor_id" id="editVendorId" class="form-select form-select-sm"><option value="">— Unassigned —</option>
              <?php foreach($allVendors as $v): ?><option value="<?=$v['id']?>"><?= htmlspecialchars($v['name']) ?></option><?php endforeach; ?>
            </select>
            <div class="form-text">Changing this is logged in vendor history and emails the new vendor.</div>
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">Cost of Installation <span class="text-danger">*</span></label><input type="number" step="0.01" min="0" name="installation_cost" id="editInstallationCost" class="form-control form-control-sm" required></div>
          <div class="col-6"><label class="form-label small fw-semibold">Installation Paid <span class="text-danger">*</span></label>
            <select name="installation_paid" id="editInstallationPaid" class="form-select form-select-sm" required>
              <option value="">— Select —</option>
              <option value="Yes">Yes</option>
              <option value="No">No</option>
            </select>
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">WiFi Username</label><input type="text" name="wifi_username" id="editWifiUsername" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small fw-semibold">WiFi Password</label><input type="text" name="wifi_password" id="editWifiPassword" class="form-control form-control-sm"></div>
          <div class="col-12"><label class="form-label small fw-semibold">Linked Ticket</label>
            <select name="ticket_id" id="editTicketId" class="form-select form-select-sm"><option value="">— None —</option>
              <?php foreach($tickets as $t): ?><option value="<?=$t['id']?>"><?= htmlspecialchars($t['ticket_number'].' — '.$t['customer_name'].($t['description']?' — '.mb_substr($t['description'],0,40):'')) ?></option><?php endforeach; ?>
            </select>
            <div class="form-text">Shows all tickets, not just installation tickets — pick whichever one actually applies (e.g. a cable-cut ticket found during investigation).</div>
          </div>
          <div class="col-12"><label class="form-label small fw-semibold">Notes</label><textarea name="notes" id="editNotes" class="form-control form-control-sm" rows="2"></textarea></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-floppy me-1"></i>Save Changes</button></div>
    </form>
  </div></div>
</div>

<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
function toggleEditReasons(v) {
  document.getElementById('editOnHoldWrap').classList.toggle('d-none', v !== 'on_hold_customer' && v !== 'on_hold_deployment');
  document.getElementById('editRefundWrap').classList.toggle('d-none', v !== 'refunded');
  document.getElementById('editRefundedAtWrap').classList.toggle('d-none', v !== 'refunded');
  document.getElementById('editUserIdWrap').classList.toggle('d-none', v !== 'connected');
}

let editTicketTS = null, addTicketTS = null;
document.addEventListener('DOMContentLoaded', function () {
  editTicketTS = new TomSelect('#editTicketId', { create:false, sortField:{field:'text',direction:'asc'} });
  addTicketTS  = new TomSelect('#addTicketId',  { create:false, sortField:{field:'text',direction:'asc'} });
});

function openEditFull(p) {
  document.getElementById('editProfileId').value      = p.id || '';
  document.getElementById('editModalTitle').innerHTML  = '<i class="bi bi-pencil me-1 text-primary"></i>Edit: ' + (p.name || '');
  document.getElementById('editName').value            = p.name || '';
  document.getElementById('editPhone').value           = p.phone || '';
  document.getElementById('editEmail').value           = p.email || '';
  document.getElementById('editAddress').value         = p.address || '';
  document.getElementById('editEstate').value          = p.estate || '';
  document.getElementById('editPlan').value            = p.plan || '';
  document.getElementById('editStatus').value          = p.status || 'pending';
  toggleEditReasons(p.status || 'pending');
  document.getElementById('editOnHoldReason').value    = p.on_hold_reason || '';
  document.getElementById('editRefundReason').value    = p.refund_reason || '';
  document.getElementById('editRefundedAtInput').value = p.refunded_at ? p.refunded_at.substring(0,10) : '';
  document.getElementById('editInstallationPaid').value = p.installation_paid || '';
  document.getElementById('editPaymentDate').value     = p.payment_confirmed_at ? p.payment_confirmed_at.substring(0,10) : '';
  document.getElementById('editAmountPaid').value      = p.amount_paid ?? '';
  document.getElementById('editNetworkUserId').value   = p.network_user_id || '';
  document.getElementById('editRouterType').value      = p.router_type || '';
  document.getElementById('editHubId').value           = p.hub_id || '';
  document.getElementById('editConnectionDate').value  = p.connection_date ? p.connection_date.substring(0,10) : '';
  document.getElementById('editVendorId').value        = p.vendor_id || '';
  document.getElementById('editInstallationCost').value= p.installation_cost ?? '';
  document.getElementById('editWifiUsername').value    = p.wifi_username || '';
  document.getElementById('editWifiPassword').value    = p.wifi_password || '';
  if (editTicketTS) editTicketTS.setValue(p.ticket_id || ''); else document.getElementById('editTicketId').value = p.ticket_id || '';
  document.getElementById('editNotes').value            = p.notes || '';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
