<?php
/**
 * WhatsApp inbox helpers for signed-in agents (support.view):
 *   GET action=latest   newest message time + my unread count (the inbox polls this)
 */
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('support.view');

$me = currentUser();
if (($_GET['action'] ?? '') === 'latest') {
    $r = dbFetch("SELECT MAX(last_message_at) AS latest,
                         SUM(CASE WHEN status = 'open' AND unread > 0 AND (assigned_to IS NULL OR assigned_to = ?) THEN 1 ELSE 0 END) AS unread
                  FROM cs_wa_conversations", [$me['id']]);
    jsonResponse(['latest' => (string)($r['latest'] ?? ''), 'unread' => (int)($r['unread'] ?? 0)]);
}
jsonResponse(['error' => 'Not found'], 404);
