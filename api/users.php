<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (in_array(method(), ['POST','PATCH','DELETE'], true)) verifyCsrf();

$id  = $segments[2] ?? null;
$sub = $segments[3] ?? null;

if ($id && $sub === 'password' && method() === 'PATCH') {
    $b = getBody();
    $u = dbFetch("SELECT password FROM users WHERE id=?",[$id]);
    if (!$u || !verifyPassword($b['currentPassword']??'',$u['password'])) jsonResponse(['error'=>'Current password incorrect'],400);
    dbRun("UPDATE users SET password=?, must_change_password=0 WHERE id=?", [hashPassword($b['newPassword']??''),$id]);
    jsonResponse(['ok'=>true]);
}

if ($id && method() === 'PATCH') {
    if (!isAdmin()) jsonResponse(['error'=>'Forbidden'],403);
    $b = getBody();
    $allowed=['name','email','phone','role','hub_id','team_id','vendor_id','status'];
    $sets=[]; $vals=[];
    foreach ($allowed as $c) { if (array_key_exists($c,$b)) { $sets[]="$c=?"; $vals[]=$b[$c]; } }
    if (!$sets) jsonResponse(['error'=>'Nothing to update'],400);
    $vals[]=$id;
    dbRun("UPDATE users SET ".implode(',',$sets)." WHERE id=?",$vals);
    jsonResponse(dbFetch("SELECT id,username,name,email,phone,role,hub_id,team_id,vendor_id,status FROM users WHERE id=?",[$id]));
}

if (method() === 'GET') {
    jsonResponse(dbFetchAll("SELECT id,username,name,email,phone,role,hub_id,team_id,vendor_id,status FROM users ORDER BY name"));
}

if (method() === 'POST') {
    if (!isAdmin()) jsonResponse(['error'=>'Forbidden'],403);
    $b = getBody();
    $newId = newUuid();
    dbRun(
        "INSERT INTO users (id,username,name,email,phone,role,password,hub_id,team_id,vendor_id,status) VALUES (?,?,?,?,?,?,?,?,?,?,'active')",
        [$newId,$b['username']??'',$b['name']??'',$b['email']??'',$b['phone']??'',$b['role']??'engineer',
         hashPassword($b['password']??'admin123'),$b['hubId']??null,$b['teamId']??null,$b['vendorId']??null]
    );
    jsonResponse(dbFetch("SELECT id,username,name,email,role,status FROM users WHERE id=?",[$newId]),201);
}

if ($id && method() === 'DELETE') {
    if (!isAdmin()) jsonResponse(['error'=>'Forbidden'],403);
    dbRun("DELETE FROM users WHERE id=?",[$id]);
    jsonResponse(['ok'=>true]);
}
