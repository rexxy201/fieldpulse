<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (in_array(method(), ['POST','PATCH','DELETE'], true)) verifyCsrf();
$id = $segments[2] ?? null;

if ($id && method() === 'PATCH') {
    if (!hasPermission('team.manage')) jsonResponse(['error'=>'Forbidden'],403);
    $b=getBody(); $sets=[]; $vals=[];
    foreach(['name','type','supervisor_id'] as $c){
        if(array_key_exists($c,$b)){$sets[]="$c=?";$vals[]=$b[$c];}
    }
    if(!$sets) jsonResponse(['error'=>'Nothing to update'],400);
    $vals[]=$id;
    dbRun("UPDATE teams SET ".implode(',',$sets)." WHERE id=?",$vals);
    jsonResponse(dbFetch("SELECT * FROM teams WHERE id=?",[$id]));
}

if (method() === 'GET') {
    jsonResponse(dbFetchAll("SELECT * FROM teams ORDER BY name"));
}

if (method() === 'POST') {
    if (!hasPermission('team.manage')) jsonResponse(['error'=>'Forbidden'],403);
    $b=getBody();
    $newId=newUuid();
    dbRun("INSERT INTO teams (id,name,type,supervisor_id) VALUES (?,?,?,?)",
        [$newId,$b['name']??'',$b['type']??'fiber',$b['supervisorId']??null]);
    jsonResponse(dbFetch("SELECT * FROM teams WHERE id=?",[$newId]),201);
}

if ($id && method() === 'DELETE') {
    if (!hasPermission('team.manage')) jsonResponse(['error'=>'Forbidden'],403);
    dbRun("DELETE FROM teams WHERE id=?",[$id]);
    jsonResponse(['ok'=>true]);
}
