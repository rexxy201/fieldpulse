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
    $allowed=['name','phone','address','email','plan','wifi_username','wifi_password','ticket_id','vendor_id','status','notes'];
    $sets=[]; $vals=[];
    foreach($allowed as $c){if(array_key_exists($c,$b)){$sets[]="$c=?";$vals[]=$b[$c];}}
    $sets[]="updated_at=NOW()"; $vals[]=$id;
    dbRun("UPDATE installation_profiles SET ".implode(',',$sets)." WHERE id=?",$vals);
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
    dbRun(
        "INSERT INTO installation_profiles (id,name,phone,address,email,plan,wifi_username,wifi_password,ticket_id,vendor_id,status,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
        [$newId,$b['name']??'',$b['phone']??'',$b['address']??'',$b['email']??'',$b['plan']??'',
         $b['wifiUsername']??'',$b['wifiPassword']??'',$b['ticketId']??null,
         $b['vendorId']??null,$b['status']??'pending',$b['notes']??'']
    );
    jsonResponse(dbFetch("SELECT * FROM installation_profiles WHERE id=?",[$newId]),201);
}
