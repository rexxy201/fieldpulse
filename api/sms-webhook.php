<?php
/**
 * SMS provider callbacks (Africa's Talking) — public, no session.
 *
 * Set the exact URL shown on Support Settings (including ?k=…) as both the
 * incoming-messages and the delivery-reports callback for your SMS shortcode
 * or number. The secret stops anyone else from injecting messages. Every
 * callback is stored raw in cs_inbound_events for troubleshooting.
 */
require_once __DIR__ . '/../config.php';

$s = csInboxSettings();
if ($s['sms_secret'] === '' || !is_string($_GET['k'] ?? null) || !hash_equals($s['sms_secret'], $_GET['k'])) {
    http_response_code(403); exit('Forbidden');
}
$post = $_POST ?: (json_decode((string)file_get_contents('php://input'), true) ?: []);
try {
    dbRun("INSERT INTO cs_inbound_events (id,channel,payload,created_at) VALUES (?,'sms',?,?)", [newUuid(), mb_substr(json_encode($post), 0, 60000), date('Y-m-d H:i:s')]);
} catch (\Throwable $e) { error_log('cs_inbound_events insert failed: ' . $e->getMessage()); }

$r = $s['sms_enabled'] ? csSmsHandleWebhook($post, $s) : ['type' => 'disabled'];
jsonResponse(['ok' => true] + $r);
