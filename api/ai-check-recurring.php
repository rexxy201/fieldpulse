<?php
/**
 * AI recurring-issue detection — given a customer and a new ticket
 * description, checks the customer's recent ticket history for a likely
 * repeat or related issue. Purely advisory: surfaces a hint on Create
 * Ticket, doesn't block or auto-link anything.
 */
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('tickets.create');

if (method() !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
verifyCsrf();
if (!aiEnabled()) jsonResponse(['error' => 'AI Assistant is not enabled'], 503);

$b = getBody();
$customerId  = trim($b['customerId'] ?? '');
$description = trim($b['description'] ?? '');
if (!$customerId) jsonResponse(['error' => 'Select a customer first'], 400);
if (mb_strlen($description) < 8) jsonResponse(['error' => 'Description too short to check'], 400);

$_iv90 = dbNowMinusInterval(90, 'DAY');
$history = dbFetchAll(
    "SELECT ticket_number, description, status, created_at
     FROM tickets
     WHERE customer_id = ? AND created_at >= {$_iv90}
     ORDER BY created_at DESC
     LIMIT 10",
    [$customerId]
);

if (!$history) {
    jsonResponse(['isRecurring' => false, 'reasoning' => '']);
}

$historyText = implode("\n", array_map(
    fn($t) => "- {$t['ticket_number']} ({$t['status']}, " . date('d M Y', strtotime($t['created_at'])) . "): " . substr($t['description'] ?? '', 0, 200),
    $history
));

$system = "You review an internet service provider's fault ticket history for one customer to spot repeat or related "
        . "issues. Given a new ticket description and that customer's recent tickets, decide if the new one looks like "
        . "the same or a closely related problem recurring. Be conservative — only flag it if genuinely similar in "
        . "nature (e.g. same kind of fault, same equipment, same symptom), not just because both are internet issues. "
        . "Respond ONLY as JSON: {\"isRecurring\": true|false, \"relatedTicketNumber\": \"<ticket_number or empty>\", "
        . "\"reasoning\": \"<one short sentence, empty if not recurring>\"}.";

$user = "Customer's recent tickets:\n{$historyText}\n\nNew ticket description:\n{$description}";

$result = aiChatJson($system, $user, 0.2);
if (!$result) {
    jsonResponse(['isRecurring' => false, 'reasoning' => '']);
}

jsonResponse([
    'isRecurring'          => !empty($result['isRecurring']),
    'relatedTicketNumber'  => $result['relatedTicketNumber'] ?? '',
    'reasoning'            => $result['reasoning'] ?? '',
]);
