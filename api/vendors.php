<?php
require_once __DIR__ . '/../config.php';
requireAuth();
$id = $segments[2] ?? null;

if ($id && method() === 'PATCH') {
    $b=getBody();
    $allowed=['name','type','status','email','phone','supervisor_name'];
    $sets=[]; $vals=[];
    foreach($allowed as $c){if(array_key_exists($c,$b)){$sets[]="$c=?";$vals[]=$b[$c];}}
    if(!$sets) jsonResponse(['error'=>'Nothing to update'],400);
    $vals[]=$id;
    dbRun("UPDATE vendors SET ".implode(',',$sets)." WHERE id=?",$vals);
    jsonResponse(dbFetch("SELECT * FROM vendors WHERE id=?",[$id]));
}

if (method() === 'GET') {
    jsonResponse(dbFetchAll("SELECT * FROM vendors ORDER BY name"));
}

if (method() === 'POST') {
    $b=getBody();
    $newId=newUuid();
    dbRun("INSERT INTO vendors (id,name,type,status,email,phone,supervisor_name) VALUES (?,?,?,?,?,?,?)",
        [$newId,$b['name']??'',$b['type']??'general',$b['status']??'active',
         $b['email']??'',$b['phone']??'',$b['supervisorName']??'']);
    jsonResponse(dbFetch("SELECT * FROM vendors WHERE id=?",[$newId]),201);
}

if ($id && method() === 'DELETE') {
    dbRun("DELETE FROM vendors WHERE id=?",[$id]);
    jsonResponse(['ok'=>true]);
}
