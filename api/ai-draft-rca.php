<?php
/**
 * AI RCA drafting — given an engineer's rough notes on what happened, drafts
 * formal Root Cause / Observation / Corrective Action text. Purely advisory:
 * the engineer reviews and edits the draft in the RCA modal before it's saved.
 */
require_once __DIR__ . '/../config.php';
requireAuth();
if (!hasPermission('tickets.resolve') && !hasPermission('tickets.close') && !hasPermission('tickets.update')) {
    jsonResponse(['error' => 'Forbidden'], 403);
}

if (method() !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
if (!aiEnabled()) jsonResponse(['error' => 'AI Assistant is not enabled'], 503);

$b = getBody();
$ticketId = trim($b['ticketId'] ?? '');
$notes    = trim($b['notes'] ?? '');
if (mb_strlen($notes) < 8) jsonResponse(['error' => 'Add a few more details about what happened first'], 400);

$ticket = $ticketId ? dbFetch("SELECT description, priority, type FROM tickets WHERE id = ?", [$ticketId]) : null;
if (!$ticket) jsonResponse(['error' => 'Ticket not found'], 404);

$system = "You write formal Root Cause Analysis entries for an internet service provider's fault ticket system. "
        . "Given the original ticket description and an engineer's rough, informal notes on what they found and did, "
        . "write three short, professional sections: the root cause (what actually caused the fault), the observation "
        . "(what was seen/found on-site or remotely), and the corrective action (what was done to fix it). "
        . "Each should be 1-3 concise sentences, based only on what's stated or reasonably implied — never invent "
        . "specifics (equipment names, dates, numbers) that weren't mentioned. "
        . "Respond ONLY as JSON: {\"rootCause\": \"...\", \"observation\": \"...\", \"correctiveAction\": \"...\"}.";

$user = "Ticket description:\n" . ($ticket['description'] ?? '(none)') . "\n\nEngineer's rough notes:\n{$notes}";

$result = aiChatJson($system, $user, 0.3);
if (!$result || empty($result['rootCause'])) {
    jsonResponse(['error' => 'AI could not produce a draft'], 502);
}

jsonResponse([
    'rootCause'        => $result['rootCause'] ?? '',
    'observation'      => $result['observation'] ?? '',
    'correctiveAction' => $result['correctiveAction'] ?? '',
]);
