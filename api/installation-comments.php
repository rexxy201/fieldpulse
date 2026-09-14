<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (in_array(method(), ['POST','PATCH','DELETE'], true)) verifyCsrf();

$user   = currentUser();
$role   = $user['role'];
$profId = $segments[2] ?? null;

if (!$profId) jsonResponse(['error'=>'Profile ID required'],400);

if (method() === 'GET') {
    // Previously ungated — requireAuth() only, meaning any authenticated
    // user (including a vendor with no relationship to this job) could read
    // any installation's internal comments by guessing/iterating profId.
    // Mirrors the same three-part check api/installations.php's own GET uses:
    // vendor roles need ownership (canAccessInstallation()), everyone else
    // needs installations.view — canAccessInstallation() alone returns true
    // for any non-vendor role, by design, so it's not sufficient on its own.
    $profileForAccess = dbFetch("SELECT * FROM installation_profiles WHERE id=?", [$profId]);
    if (!$profileForAccess) jsonResponse(['error'=>'Not found'],404);
    $isVendorRole = in_array($role, ['vendor','vendor-mtce'], true);
    if ($isVendorRole && !canAccessInstallation($profileForAccess)) jsonResponse(['error'=>'Forbidden'],403);
    if (!$isVendorRole && !in_array($role, ['admin','project_admin','supervisor-fiber'], true) && !hasPermission('installations.view')) {
        jsonResponse(['error'=>'Forbidden'],403);
    }
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
