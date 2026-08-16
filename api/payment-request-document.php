<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$user = currentUser();
$role = $user['role'];
$id   = $_GET['id'] ?? '';

$doc = $id ? dbFetch("SELECT * FROM payment_request_documents WHERE id=?", [$id]) : null;
if (!$doc) { http_response_code(404); exit('Not found'); }

$pr = dbFetch("SELECT * FROM payment_requests WHERE id=?", [$doc['payment_request_id']]);
if (!$pr) { http_response_code(404); exit('Not found'); }

// Same authorization the module itself uses: approvers/viewers see everything,
// vendors see their own company's requests, everyone else sees only their own.
$allowed = hasPermission('payment_requests.view') || hasPermission('payment_requests.approve')
    || $pr['requester_id'] === $user['id']
    || ($role === 'vendor' && !empty($user['vendor_id']) && $pr['vendor_id'] === $user['vendor_id']);
if (!$allowed) { http_response_code(403); exit('Forbidden'); }

$path = PR_DOC_DIR . $doc['stored_name'];
if (!is_file($path)) { http_response_code(404); exit('File missing'); }

// Sanitize the user-supplied original filename before it goes into a header —
// strip control chars (CRLF injection) and escape quotes.
$safeName = basename($doc['original_name'] ?: $doc['stored_name']);
$safeName = preg_replace('/[\x00-\x1F\x7F"]/', '', $safeName);

header('Content-Type: ' . ($doc['mime_type'] ?: 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=0, no-cache');
readfile($path);
exit;
