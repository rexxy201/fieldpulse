<?php
require_once __DIR__ . '/../config.php';

$action = $segments[2] ?? '';

if ($action === 'login' && method() === 'POST') {
    $body = getBody();
    $user = dbFetch("SELECT * FROM users WHERE username = ?", [trim($body['username'] ?? '')]);
    if ($user && verifyPassword($body['password'] ?? '', $user['password'])) {
        if (!str_starts_with($user['password'], '$2y$')) {
            dbRun("UPDATE users SET password = ? WHERE id = ?", [hashPassword($body['password']), $user['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user']    = sanitizeUser($user);
        jsonResponse(sanitizeUser($user));
    }
    jsonResponse(['error' => 'Invalid credentials'], 401);
}

if ($action === 'logout') {
    session_destroy();
    jsonResponse(['ok' => true]);
}

if ($action === 'me') {
    if (!isLoggedIn()) jsonResponse(['error' => 'Not authenticated'], 401);
    jsonResponse(sanitizeUser(currentUser()));
}

jsonResponse(['error' => 'Not found'], 404);
