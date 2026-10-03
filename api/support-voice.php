<?php
/**
 * Browser phone support for signed-in agents (support.view):
 *   POST action=token      capability token for the Africa's Talking client
 *   GET  action=lookup     caller number → customer, open tickets, recent contacts, outage, menu choice (call popup)
 *   POST action=answered   this agent answered a call from `from`
 *   POST action=ended      this agent's call ended (Available again after the wrap-up window)
 *   GET  action=agents     agents free to take a transfer
 *   POST action=transfer   hand my live call (`call_id`) to `target` ('agent:<id>' or a number)
 *   GET  action=recent     my calls from the last 24h not yet logged as interactions
 */
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('support.view');
if (method() === 'POST') verifyCsrf();

$me     = currentUser();
$action = $_GET['action'] ?? '';

if ($action === 'token' && method() === 'POST') {
    $r = csVoiceToken($me);
    if (!$r['ok']) jsonResponse(['error' => $r['error']], 503);
    csSetAgentStatus($me['id'], csAgentStatus($me['id']) === 'offline' ? 'available' : csAgentStatus($me['id']));
    jsonResponse($r);
}

if ($action === 'lookup') {
    jsonResponse(csCallerContext((string)($_GET['number'] ?? '')));
}

if ($action === 'ended' && method() === 'POST') {
    csEndAgentCall($me['id'], csVoiceSettings());
    jsonResponse(['status' => csAgentStatus($me['id'])]);
}

if ($action === 'agents') {
    $free = csFreeAgentIds();
    $rows = $free ? dbFetchAll("SELECT id, name FROM users WHERE status = 'active' AND id IN (" . implode(',', array_fill(0, count($free), '?')) . ") ORDER BY name", $free) : [];
    jsonResponse(['agents' => array_values(array_filter($rows, fn($u) => $u['id'] !== $me['id']))]);
}

if ($action === 'transfer' && method() === 'POST') {
    $b    = getBody();
    $call = dbFetch("SELECT * FROM cs_calls WHERE id = ? AND agent_id = ?", [(string)($b['call_id'] ?? ''), $me['id']]);
    if (!$call) jsonResponse(['error' => 'That call is not yours.'], 404);
    $r = csTransferCall($call, (string)($b['target'] ?? ''), $me);
    jsonResponse($r, $r['ok'] ? 200 : 422);
}

if ($action === 'answered' && method() === 'POST') {
    $b = getBody();
    $id = csMarkCallAnswered((string)($b['from'] ?? ''), $me['id']);
    csSetAgentStatus($me['id'], 'on_call');
    jsonResponse(['call_id' => $id]);
}

if ($action === 'recent') {
    $rows = dbFetchAll(
        "SELECT c.id, c.direction, c.from_number, c.to_number, c.status, c.started_at, c.duration_sec, c.customer_id, cu.name AS customer_name
         FROM cs_calls c LEFT JOIN customers cu ON cu.id = c.customer_id
         WHERE c.agent_id = ? AND c.started_at >= ? AND c.status IN ('completed','in_progress')
           AND NOT EXISTS (SELECT 1 FROM cs_interactions i WHERE i.call_id = c.id)
         ORDER BY c.started_at DESC LIMIT 10", [$me['id'], date('Y-m-d H:i:s', time() - 86400)]);
    jsonResponse(['calls' => $rows]);
}

jsonResponse(['error' => 'Not found'], 404);
