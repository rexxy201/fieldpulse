<?php
/**
 * WhatsApp provider webhook — public, no session.
 *
 * Set the exact URL shown on Support Settings (including ?k=…) as the webhook
 * in the Africa's Talking dashboard or the Meta app. The secret stops anyone
 * else from injecting messages. Meta additionally signs every POST with the
 * app secret (X-Hub-Signature-256) and verifies the URL with a GET handshake.
 * Every POST body is stored raw in cs_wa_events for troubleshooting.
 */
require_once __DIR__ . '/../config.php';

$s = csWaSettings();
if ($s['webhook_secret'] === '' || !is_string($_GET['k'] ?? null) || !hash_equals($s['webhook_secret'], $_GET['k'])) {
    http_response_code(403); exit('Forbidden');
}

// Meta's one-time verification handshake (PHP turns "hub.mode" into "hub_mode").
if (method() === 'GET') {
    $token = (string)($_GET['hub_verify_token'] ?? '');
    if (($_GET['hub_mode'] ?? '') === 'subscribe' && $s['meta_verify'] !== '' && hash_equals($s['meta_verify'], $token)) {
        header('Content-Type: text/plain'); echo (string)($_GET['hub_challenge'] ?? ''); exit;
    }
    http_response_code(403); exit('Forbidden');
}

$raw = (string)file_get_contents('php://input');
if ($s['provider'] === 'meta' && !csWaMetaSignatureValid($raw, (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''), $s['meta_app_secret'])) {
    http_response_code(401); exit('Bad signature');
}
$payload = json_decode($raw, true);
if (!is_array($payload)) $payload = $_POST;

try {
    dbRun("INSERT INTO cs_wa_events (id,provider,payload,created_at) VALUES (?,?,?,?)",
        [newUuid(), $s['provider'], mb_substr($raw !== '' ? $raw : json_encode($payload), 0, 60000), date('Y-m-d H:i:s')]);
} catch (\Throwable $e) { error_log('cs_wa_events insert failed: ' . $e->getMessage()); }

$result = csWaEnabled($s) ? csWaHandleWebhook($payload, $s) : ['messages' => 0, 'statuses' => 0];
jsonResponse(['ok' => true] + $result);
