<?php
/**
 * AI ticket triage — given a free-text description, suggests the best-matching
 * fault type and a priority. Purely advisory: the caller (create-ticket.php)
 * pre-fills the form with the suggestion, staff review/override before submit.
 */
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('tickets.create');

if (method() !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
if (!aiEnabled()) jsonResponse(['error' => 'AI Assistant is not enabled'], 503);

$b = getBody();
$description = trim($b['description'] ?? '');
if (mb_strlen($description) < 8) jsonResponse(['error' => 'Description too short to triage'], 400);

$faultTypes = dbFetchAll("SELECT id, name, category FROM fault_types WHERE enabled = 1 ORDER BY category, name");
if (!$faultTypes) jsonResponse(['error' => 'No fault types configured'], 500);

$catalog = implode("\n", array_map(
    fn($f) => "- id={$f['id']} | {$f['category']}: {$f['name']}",
    $faultTypes
));

$system = "You triage internet service provider support tickets for FieldPulse (MangoNet). "
        . "Given a customer/engineer-written issue description, pick the single best-matching fault type "
        . "from the provided list (by its exact id) and a priority. "
        . "Priority guide: p1=critical/total outage/safety/many customers affected, p2=high/major degradation for one customer, "
        . "p3=medium/standard fault (default for most issues), p4=low/cosmetic or non-urgent request. "
        . "Respond ONLY as JSON: {\"faultTypeId\": \"<id from the list>\", \"priority\": \"p1|p2|p3|p4\", \"reasoning\": \"<one short sentence>\"}. "
        . "If nothing matches well, pick the closest reasonable option — never invent an id that isn't in the list.";

$user = "Fault types:\n{$catalog}\n\nIssue description:\n{$description}";

$result = aiChatJson($system, $user);
if (!$result || empty($result['faultTypeId'])) {
    jsonResponse(['error' => 'AI could not produce a suggestion'], 502);
}

// Validate the suggested id is actually one of the real options — never trust
// the model to only echo back valid ids.
$validIds = array_column($faultTypes, 'id');
if (!in_array($result['faultTypeId'], $validIds, true)) {
    jsonResponse(['error' => 'AI suggested an invalid fault type'], 502);
}
if (!in_array($result['priority'] ?? '', ['p1', 'p2', 'p3', 'p4'], true)) {
    $result['priority'] = 'p3';
}

jsonResponse([
    'faultTypeId' => $result['faultTypeId'],
    'priority'    => $result['priority'],
    'reasoning'   => $result['reasoning'] ?? '',
]);
