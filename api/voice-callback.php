<?php
/**
 * Voice provider callback (Africa's Talking) — public, no session.
 *
 * Set this as the phone number's callback URL in the Africa's Talking
 * dashboard (the exact URL, including ?k=…, is shown on Support Settings).
 * The secret stops anyone else from driving the menu or faking call records.
 * Every callback is stored raw in cs_call_events for troubleshooting.
 */
require_once __DIR__ . '/../config.php';

$s = csVoiceSettings();
if ($s['webhook_secret'] === '' || !is_string($_GET['k'] ?? null) || !hash_equals($s['webhook_secret'], $_GET['k'])) {
    http_response_code(403); exit('Forbidden');
}
$step = in_array($_GET['step'] ?? 'answer', ['answer', 'menu', 'voicemail'], true) ? ($_GET['step'] ?? 'answer') : 'answer';

$post = $_POST ?: (json_decode((string)file_get_contents('php://input'), true) ?: []);
try {
    dbRun("INSERT INTO cs_call_events (id,session_id,step,payload,created_at) VALUES (?,?,?,?,?)",
        [newUuid(), mb_substr((string)($post['sessionId'] ?? ''), 0, 100), $step, json_encode($post), date('Y-m-d H:i:s')]);
} catch (\Throwable $e) { error_log('cs_call_events insert failed: ' . $e->getMessage()); }

$xml = csVoiceEnabled($s) ? csHandleVoiceCallback($post, $step, $s) : csVoiceXml([['say' => 'Sorry, this line is not available right now.']]);
header('Content-Type: application/xml; charset=utf-8');
echo $xml;
