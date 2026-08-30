<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$id = $_GET['id'] ?? '';
$photo = $id ? dbFetch("SELECT * FROM ticket_photos WHERE id=?", [$id]) : null;
if (!$photo) { http_response_code(404); exit('Not found'); }

$ticket = dbFetch("SELECT * FROM tickets WHERE id=?", [$photo['ticket_id']]);
if (!$ticket) { http_response_code(404); exit('Not found'); }

// Same access rule the ticket detail page itself uses.
if (!canAccessTicket($ticket)) { http_response_code(403); exit('Forbidden'); }

$path = TICKET_PHOTO_DIR . $photo['stored_name'];
if (!is_file($path)) { http_response_code(404); exit('File missing'); }

$safeName = basename($photo['original_name'] ?: $photo['stored_name']);
$safeName = preg_replace('/[\x00-\x1F\x7F"]/', '', $safeName);

header('Content-Type: ' . ($photo['mime_type'] ?: 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=0, no-cache');
readfile($path);
exit;
