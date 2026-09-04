<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (in_array(method(), ['POST','PATCH','DELETE'], true)) verifyCsrf();

$user = currentUser();
$uid  = $user['id'];  // UUID string — do NOT cast to int

if (method() === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'unread_count') {
        $row = dbFetch("SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND is_read = false", [$uid]);
        jsonResponse(['count' => (int)($row['n'] ?? 0)]);
    }

    // list — most recent 20
    $rows = dbFetchAll(
        "SELECT id, title, message, link, is_read, created_at
         FROM notifications WHERE user_id = ?
         ORDER BY created_at DESC LIMIT 20",
        [$uid]
    );
    $unread = dbFetch("SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND is_read = false", [$uid]);
    jsonResponse(['notifications' => $rows, 'unread' => (int)($unread['n'] ?? 0)]);
}

if (method() === 'POST') {
    $b = getBody();
    $action = $b['action'] ?? '';

    if ($action === 'mark_all_read') {
        dbRun("UPDATE notifications SET is_read = true WHERE user_id = ?", [$uid]);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'mark_read' && !empty($b['id'])) {
        dbRun("UPDATE notifications SET is_read = true WHERE id = ? AND user_id = ?", [(string)$b['id'], $uid]);
        jsonResponse(['ok' => true]);
    }

    jsonResponse(['error' => 'Unknown action'], 400);
}

jsonResponse(['error' => 'Method not allowed'], 405);
