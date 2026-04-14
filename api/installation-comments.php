<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$user   = currentUser();
$role   = $user['role'];
$profId = $segments[2] ?? null;

if (!$profId) jsonResponse(['error'=>'Profile ID required'],400);

if (method() === 'GET') {
    jsonResponse(dbFetchAll("SELECT * FROM installation_comments WHERE profile_id=? ORDER BY created_at",[$profId]));
}

if (method() === 'POST') {
    $editRoles = ['admin','project_admin','supervisor-fiber'];
    if (!in_array($role,$editRoles)) {
        if ($role === 'vendor') {
            $p = dbFetch("SELECT vendor_id FROM installation_profiles WHERE id=?",[$profId]);
            if (!$p || $p['vendor_id'] !== ($user['vendor_id']??'')) jsonResponse(['error'=>'Forbidden'],403);
        } else {
            jsonResponse(['error'=>'Forbidden'],403);
        }
    }
    $b = getBody();
    $newId = newUuid();
    dbRun(
        "INSERT INTO installation_comments (id,profile_id,user_id,user_name,user_role,content,type) VALUES (?,?,?,?,?,?,?)",
        [$newId,$profId,$user['id'],$user['name'],$role,$b['content']??'',$b['type']??'comment']
    );
    jsonResponse(dbFetch("SELECT * FROM installation_comments WHERE id=?",[$newId]),201);
}
