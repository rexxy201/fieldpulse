<?php
/**
 * Streams a call recording: GET /api/support-recording?call=<id>.
 * Agents hear their own calls; support.view_all hears every call. Recordings
 * live outside the web root, so this check is the only way to reach them.
 */
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('support.view');

$me   = currentUser();
$call = dbFetch("SELECT id, agent_id, recording_path, recording_url, recording_deleted_at FROM cs_calls WHERE id = ?", [$_GET['call'] ?? '']);
if (!$call || (!hasPermission('support.view_all') && $call['agent_id'] !== $me['id'])) { http_response_code(404); exit('Not found'); }
if ($call['recording_deleted_at']) { http_response_code(410); exit('This recording was deleted after the retention period.'); }

if (!$call['recording_path'] && $call['recording_url']) {
    csFetchPendingRecordings(50);   // not downloaded yet: try now
    $call = dbFetch("SELECT id, recording_path FROM cs_calls WHERE id = ?", [$call['id']]);
}
$abs = $call['recording_path'] ? csRecordingsDir() . '/' . $call['recording_path'] : '';
$real = $abs !== '' ? realpath($abs) : false;
if (!$real || !str_starts_with($real, realpath(csRecordingsDir()) . DIRECTORY_SEPARATOR)) { http_response_code(404); exit('Recording not available yet.'); }

try { auditLog('view', 'cs_call_recording', $call['id']); } catch (\Throwable $e) {}
header('Content-Type: audio/mpeg');
header('Content-Length: ' . filesize($real));
header('Cache-Control: private, no-store');
readfile($real);
