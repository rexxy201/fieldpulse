<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (in_array(method(), ['POST','PATCH','DELETE'], true)) verifyCsrf();
$id = $segments[2] ?? null;

if ($id && method() === 'PATCH') {
    if (!isAdmin()) jsonResponse(['error'=>'Forbidden'],403);
    $b=getBody(); $sets=[]; $vals=[];
    foreach(['name','location','lat','lng'] as $c){
        if(array_key_exists($c,$b)){$sets[]="$c=?";$vals[]=$b[$c];}
    }
    if(!$sets) jsonResponse(['error'=>'Nothing to update'],400);
    $vals[]=$id;
    dbRun("UPDATE hubs SET ".implode(',',$sets)." WHERE id=?",$vals);
    jsonResponse(dbFetch("SELECT * FROM hubs WHERE id=?",[$id]));
}

if (method() === 'GET') {
    jsonResponse(dbFetchAll("SELECT * FROM hubs ORDER BY name"));
}

if (method() === 'POST') {
    if (!isAdmin()) jsonResponse(['error'=>'Forbidden'],403);
    $b=getBody();
    $newId=newUuid();
    dbRun("INSERT INTO hubs (id,name,location,lat,lng) VALUES (?,?,?,?,?)",
        [$newId,$b['name']??'',$b['location']??'',$b['lat']??null,$b['lng']??null]);
    jsonResponse(dbFetch("SELECT * FROM hubs WHERE id=?",[$newId]),201);
}

if ($id && method() === 'DELETE') {
    if (!isAdmin()) jsonResponse(['error'=>'Forbidden'],403);
    dbRun("DELETE FROM hubs WHERE id=?",[$id]);
    jsonResponse(['ok'=>true]);
}
