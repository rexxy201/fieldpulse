<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$user = currentUser();
$role = $user['role'];
$id   = $segments[2] ?? null;

$editRoles = ['admin','project_admin','supervisor-fiber'];

if ($id && method() === 'GET') {
    jsonResponse(dbFetch("SELECT * FROM installation_profiles WHERE id=?",[$id]) ?: ['error'=>'Not found']);
}

if ($id && method() === 'PATCH') {
    $b = getBody();
    if (!in_array($role, $editRoles)) {
        if ($role === 'vendor') {
            $p = dbFetch("SELECT vendor_id FROM installation_profiles WHERE id=?",[$id]);
            if (!$p || $p['vendor_id'] !== ($user['vendor_id']??'')) jsonResponse(['error'=>'Forbidden'],403);
            dbRun("UPDATE installation_profiles SET status=?, updated_at=NOW() WHERE id=?",[$b['status']??'',$id]);
            jsonResponse(dbFetch("SELECT * FROM installation_profiles WHERE id=?",[$id]));
        }
        jsonResponse(['error'=>'Forbidden'],403);
    }
    $existing = dbFetch("SELECT vendor_id, status, completed_at FROM installation_profiles WHERE id=?", [$id]);

    $allowed=['name','phone','address','email','plan','wifi_username','wifi_password','ticket_id','vendor_id','status','notes',
        'amount_paid','network_user_id','router_type','estate','pop','connection_status','connection_date','installer','installation_cost','field_marketer',
        'on_hold_reason','refund_reason','payment_status','hub_id'];
    $sets=[]; $vals=[];
    foreach($allowed as $c){if(array_key_exists($c,$b)){$sets[]="$c=?";$vals[]=$b[$c];}}

    // Payment confirmation → (re)computes the SLA due date
    if (array_key_exists('paymentConfirmedAt', $b) && !empty($b['paymentConfirmedAt'])) {
        $sets[]="payment_confirmed_at=?"; $vals[]=$b['paymentConfirmedAt'];
        $sets[]="sla_due_at=?"; $vals[]=addWorkingDays($b['paymentConfirmedAt'], INSTALLATION_SLA_WORKING_DAYS);
    }
    // First time the work order is marked connected
    if (($b['status'] ?? null) === 'connected' && $existing && empty($existing['completed_at'])) {
        $sets[]="completed_at=NOW()";
    }

    if (!$sets) jsonResponse(['error'=>'Nothing to update'],400);
    $sets[]="updated_at=NOW()"; $vals[]=$id;
    dbRun("UPDATE installation_profiles SET ".implode(',',$sets)." WHERE id=?",$vals);

    // Log vendor reassignment
    if (array_key_exists('vendor_id', $b) && $existing && $b['vendor_id'] !== $existing['vendor_id']) {
        // Due date doesn't move on reassignment, but a new vendor who hasn't been
        // warned/notified yet still should be.
        dbRun("UPDATE installation_profiles SET sla_warned_at=NULL, sla_breached_notified_at=NULL WHERE id=?", [$id]);
        dbRun("INSERT INTO installation_vendor_history (id,profile_id,old_vendor_id,new_vendor_id,reason,changed_by,changed_by_name) VALUES (?,?,?,?,?,?,?)",
            [newUuid(),$id,$existing['vendor_id'],$b['vendor_id']?:null,$b['reassignReason']??'',$user['id'],$user['name']]);
        if (!empty($b['vendor_id'])) {
            $newVendorRow = dbFetch("SELECT name,email FROM vendors WHERE id=?", [$b['vendor_id']]);
            if ($newVendorRow) {
                $updatedProfile = dbFetch("SELECT * FROM installation_profiles WHERE id=?", [$id]);
                emailVendorInstallationAssigned($updatedProfile, $newVendorRow, !empty($existing['vendor_id']));
            }
        }
    }

    jsonResponse(dbFetch("SELECT * FROM installation_profiles WHERE id=?",[$id]));
}

if ($id && method() === 'DELETE') {
    if (!in_array($role,$editRoles)) jsonResponse(['error'=>'Forbidden'],403);
    dbRun("DELETE FROM installation_profiles WHERE id=?",[$id]);
    jsonResponse(['ok'=>true]);
}

if (method() === 'GET') {
    jsonResponse(dbFetchAll("SELECT * FROM installation_profiles ORDER BY created_at DESC"));
}

if (method() === 'POST') {
    if (!in_array($role,$editRoles)) jsonResponse(['error'=>'Forbidden'],403);
    $b = getBody();
    $newId = newUuid();
    $paymentAt = $b['paymentConfirmedAt'] ?? null;
    $slaDue = $paymentAt ? addWorkingDays($paymentAt, INSTALLATION_SLA_WORKING_DAYS) : null;
    dbRun(
        "INSERT INTO installation_profiles
            (id,name,phone,address,email,plan,wifi_username,wifi_password,ticket_id,vendor_id,status,notes,payment_confirmed_at,sla_due_at,
             amount_paid,network_user_id,router_type,estate,pop,connection_status,connection_date,installer,installation_cost,field_marketer)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [$newId,$b['name']??'',$b['phone']??'',$b['address']??'',$b['email']??'',$b['plan']??'',
         $b['wifiUsername']??'',$b['wifiPassword']??'',$b['ticketId']??null,
         $b['vendorId']??null,$b['status']??'pending',$b['notes']??'',$paymentAt,$slaDue,
         $b['amountPaid']??null,$b['networkUserId']??null,$b['routerType']??null,$b['estate']??null,$b['pop']??null,
         $b['connectionStatus']??null,$b['connectionDate']??null,$b['installer']??null,$b['installationCost']??null,$b['fieldMarketer']??null]
    );
    if (!empty($b['vendorId'])) {
        $vendorRow = dbFetch("SELECT name,email FROM vendors WHERE id=?", [$b['vendorId']]);
        if ($vendorRow) {
            $newProfile = dbFetch("SELECT * FROM installation_profiles WHERE id=?", [$newId]);
            emailVendorInstallationAssigned($newProfile, $vendorRow, false);
        }
    }
    jsonResponse(dbFetch("SELECT * FROM installation_profiles WHERE id=?",[$newId]),201);
}
