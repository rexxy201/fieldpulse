<?php
require_once __DIR__ . '/../config.php';

$action = $segments[2] ?? '';

if ($action === 'login' && method() === 'POST') {
    $body = getBody();
    $result = attemptLogin(trim($body['username'] ?? ''), $body['password'] ?? '');
    if ($result['ok']) {
        $user = $result['user'];
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user']    = sanitizeUser($user);
        try { auditLog('login', 'user', $user['id']); } catch (Throwable) {}
        jsonResponse(sanitizeUser($user));
    }
    jsonResponse(['error' => $result['error']], 401);
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
