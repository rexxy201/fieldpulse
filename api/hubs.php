<?php
require_once __DIR__ . '/../config.php';
requireAuth();
$id = $segments[2] ?? null;

if ($id && method() === 'PATCH') {
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
    $b=getBody();
    $newId=newUuid();
    dbRun("INSERT INTO hubs (id,name,location,lat,lng) VALUES (?,?,?,?,?)",
        [$newId,$b['name']??'',$b['location']??'',$b['lat']??null,$b['lng']??null]);
    jsonResponse(dbFetch("SELECT * FROM hubs WHERE id=?",[$newId]),201);
}

if ($id && method() === 'DELETE') {
    dbRun("DELETE FROM hubs WHERE id=?",[$id]);
    jsonResponse(['ok'=>true]);
}
