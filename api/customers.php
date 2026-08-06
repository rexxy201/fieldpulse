<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (!hasPermission('customers.view')) jsonResponse(['error'=>'Forbidden'],403);

$id = $segments[2] ?? null;

if ($id && method() === 'GET') {
    jsonResponse(dbFetch("SELECT * FROM customers WHERE id = ?", [$id]) ?: ['error'=>'Not found']);
}

if ($id && method() === 'PATCH') {
    if (!hasPermission('customers.update')) jsonResponse(['error'=>'Forbidden'],403);
    $b = getBody();
    $allowed = ['name','email','phone','address','plan','status'];
    $sets=[]; $vals=[];
    foreach ($allowed as $c) { if (array_key_exists($c,$b)) { $sets[]="$c=?"; $vals[]=$b[$c]; } }
    if (!$sets) jsonResponse(['error'=>'Nothing to update'],400);
    $vals[]=$id;
    dbRun("UPDATE customers SET ".implode(',',$sets)." WHERE id=?",$vals);
    jsonResponse(dbFetch("SELECT * FROM customers WHERE id=?",[$id]));
}

if ($id && method() === 'DELETE') {
    if (!hasPermission('customers.delete')) jsonResponse(['error'=>'Forbidden'],403);
    dbRun("DELETE FROM customers WHERE id=?",[$id]);
    jsonResponse(['ok'=>true]);
}

if (method() === 'GET') {
    $search = $_GET['search'] ?? '';
    if ($search) {
        $like = "%$search%";
        jsonResponse(dbFetchAll("SELECT * FROM customers WHERE name LIKE ? OR account_number LIKE ? OR email LIKE ? ORDER BY name LIMIT 50",[$like,$like,$like]));
    }
    jsonResponse(dbFetchAll("SELECT * FROM customers ORDER BY name"));
}

if (method() === 'POST') {
    if (!hasPermission('customers.create')) jsonResponse(['error'=>'Forbidden'],403);
    $b = getBody();
    $newId = newUuid();
    dbRun("INSERT INTO customers (id,name,account_number,email,phone,address,plan,status,hub_id) VALUES (?,?,?,?,?,?,?,?,?)",
        [$newId,$b['name']??'',$b['accountNumber']??'',$b['email']??'',$b['phone']??'',$b['address']??'',$b['plan']??'',$b['status']??'active',$b['hubId']??null]);
    jsonResponse(dbFetch("SELECT * FROM customers WHERE id=?",[$newId]),201);
}
