<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (in_array(method(), ['POST','PATCH','DELETE'], true)) verifyCsrf();

$id  = $segments[2] ?? null;
$sub = $segments[3] ?? null;

if ($id && $sub === 'password' && method() === 'PATCH') {
    // Your own password only (admins reset others from the Team page), and a
    // limit on guesses: otherwise any signed-in user could brute-force another
    // user's current password here, past the login lockout.
    if ($id !== (currentUser()['id'] ?? null)) jsonResponse(['error'=>'You can only change your own password.'],403);
    if (!rateLimitCheck('password_change', $id, 5, 15)) jsonResponse(['error'=>'Too many attempts. Try again in 15 minutes.'],429);
    $b = getBody();
    $u = dbFetch("SELECT password FROM users WHERE id=?",[$id]);
    if (!$u || !verifyPassword($b['currentPassword']??'',$u['password'])) jsonResponse(['error'=>'Current password incorrect'],400);
    if (strlen((string)($b['newPassword'] ?? '')) < 8) jsonResponse(['error'=>'New password must be at least 8 characters'],400);
    if (isWeakDefaultPassword((string)($b['newPassword'] ?? ''))) jsonResponse(['error'=>'That password is a well-known default. Choose a different one.'],400);
    dbRun("UPDATE users SET password=?, must_change_password=0, temp_password_expires_at=NULL WHERE id=?", [hashPassword($b['newPassword']??''),$id]);
    keepSessionAfterPasswordChange($id);
    auditUserChange('password_change', $id, 'changed own password (API)');
    jsonResponse(['ok'=>true]);
}

if ($id && method() === 'PATCH') {
    if (!isAdmin()) jsonResponse(['error'=>'Forbidden'],403);
    $b = getBody();
    $target = dbFetch("SELECT role FROM users WHERE id=?",[$id]);
    if (!$target) jsonResponse(['error'=>'Not found'],404);
    if ($err = userRoleChangeError($target['role'], (string)($b['role'] ?? $target['role']))) jsonResponse(['error'=>$err],403);
    $allowed=['name','email','phone','role','hub_id','team_id','vendor_id','status'];
    $sets=[]; $vals=[];
    foreach ($allowed as $c) { if (array_key_exists($c,$b)) { $sets[]="$c=?"; $vals[]=$b[$c]; } }
    if (!$sets) jsonResponse(['error'=>'Nothing to update'],400);
    $vals[]=$id;
    dbRun("UPDATE users SET ".implode(',',$sets)." WHERE id=?",$vals);
    $_what = array_key_exists('role', $b) && $b['role'] !== $target['role'] ? "role {$target['role']} → {$b['role']}" : 'fields: ' . implode(', ', array_intersect($allowed, array_keys($b)));
    auditUserChange('user_update', $id, $_what . ' (API)');
    jsonResponse(dbFetch("SELECT id,username,name,email,phone,role,hub_id,team_id,vendor_id,status FROM users WHERE id=?",[$id]));
}

if (method() === 'GET') {
    // Staff contact details (email, phone) and account fields are limited to
    // the people who can already see them on the Team page. Everyone else
    // signed in gets the directory basics (who exists and their role), which
    // is what picking an assignee needs.
    jsonResponse(dbFetchAll(usersListColumns() . " FROM users ORDER BY name"));
}

if (method() === 'POST') {
    if (!isAdmin()) jsonResponse(['error'=>'Forbidden'],403);
    $b = getBody();
    if ($err = userRoleChangeError(null, (string)($b['role'] ?? 'engineer'))) jsonResponse(['error'=>$err],403);
    $newId = newUuid();
    // Same rule as the Team page: the password is temporary (must be changed
    // at first sign-in, expires after TEMP_PASSWORD_HOURS). If none is given a
    // random one is generated and returned once — never the old fixed "admin123".
    $tempPassword = ($b['password'] ?? '') !== '' ? (string)$b['password'] : generateTempPassword();
    dbRun(
        "INSERT INTO users (id,username,name,email,phone,role,password,hub_id,team_id,vendor_id,status) VALUES (?,?,?,?,?,?,?,?,?,?,'active')",
        [$newId,$b['username']??'',$b['name']??'',$b['email']??'',$b['phone']??'',$b['role']??'engineer',
         hashPassword($tempPassword),$b['hubId']??null,$b['teamId']??null,$b['vendorId']??null]
    );
    setTemporaryPassword($newId, $tempPassword);
    auditUserChange('user_create', $newId, 'added via API');
    $out = dbFetch("SELECT id,username,name,email,role,status FROM users WHERE id=?",[$newId]);
    if (($b['password'] ?? '') === '') $out['temporaryPassword'] = $tempPassword;
    jsonResponse($out, 201);
}

if ($id && method() === 'DELETE') {
    if (!isAdmin()) jsonResponse(['error'=>'Forbidden'],403);
    if (!canManageUserAccount(dbFetch("SELECT role FROM users WHERE id=?",[$id]))) jsonResponse(['error'=>'Only an admin can remove admin accounts.'],403);
    auditUserChange('user_delete', $id, 'removed via API');
    dbRun("DELETE FROM users WHERE id=?",[$id]);
    jsonResponse(['ok'=>true]);
}
