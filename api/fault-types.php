<?php
require_once __DIR__ . '/../config.php';
requireAuth();
$id = $segments[2] ?? null;

if ($id && method() === 'PATCH') {
    $b=getBody(); $sets=[]; $vals=[];
    foreach(['name','category','route_to','enabled'] as $c){
        if(array_key_exists($c,$b)){$sets[]="$c=?";$vals[]=$b[$c];}
    }
    if(!$sets) jsonResponse(['error'=>'Nothing to update'],400);
    $vals[]=$id;
    dbRun("UPDATE fault_types SET ".implode(',',$sets)." WHERE id=?",$vals);
    jsonResponse(dbFetch("SELECT * FROM fault_types WHERE id=?",[$id]));
}

if (method() === 'GET') {
    jsonResponse(dbFetchAll("SELECT * FROM fault_types ORDER BY category,name"));
}

if (method() === 'POST') {
    $b=getBody();
    $newId=newUuid();
    dbRun("INSERT INTO fault_types (id,name,category,route_to,enabled) VALUES (?,?,?,?,1)",
        [$newId,$b['name']??'',$b['category']??'',$b['route_to']??'']);
    jsonResponse(dbFetch("SELECT * FROM fault_types WHERE id=?",[$newId]),201);
}

if ($id && method() === 'DELETE') {
    dbRun("DELETE FROM fault_types WHERE id=?",[$id]);
    jsonResponse(['ok'=>true]);
}
