<?php
/**
 * Global cross-module search — powers the topbar search box in
 * includes/header.php. Session-auth (internal app), not the Integration API.
 *
 * GET /api/search?q=<text>
 *
 * Searches whatever the caller already has permission to see, using the
 * exact same scoping helpers as each module's own list page
 * (ticketScopeSql()/installationScopeSql()) — this endpoint doesn't grant
 * any visibility a user doesn't already have, it just lets them jump to a
 * match from anywhere instead of hunting through each module's own filter.
 */
require_once __DIR__ . '/../config.php';
requireAuth();

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) jsonResponse(['results' => []]);
$like = "%$q%";

$results = [];

{
    // No permission gate needed here — ticketScopeSql() itself returns
    // '1=0' for a role with no ticket visibility at all, and narrows to
    // "own tickets only" etc. for everyone else, same as the Tickets list.
    [$scopeSql, $scopeParams] = ticketScopeSql('');
    $where = ["(ticket_number LIKE ? OR description LIKE ? OR customer_name LIKE ?)"];
    $params = [$like, $like, $like];
    if ($scopeSql !== '') { $where[] = $scopeSql; $params = array_merge($params, $scopeParams); }
    $rows = dbFetchAll(
        "SELECT id, ticket_number, customer_name, status, priority FROM tickets WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT 6",
        $params
    );
    if ($rows) {
        $results['tickets'] = array_map(fn($t) => [
            'id' => $t['id'], 'title' => $t['ticket_number'] ?? '(no number)',
            'subtitle' => ($t['customer_name'] ?? '') . ' — ' . strtoupper($t['status'] ?? ''),
            'url' => '/ticket/' . $t['id'],
        ], $rows);
    }
}

if (hasPermission('customers.view')) {
    $rows = dbFetchAll(
        "SELECT id, name, account_number, mailing_city FROM customers
         WHERE (name LIKE ? OR account_number LIKE ? OR phone LIKE ? OR email LIKE ?)
         ORDER BY name LIMIT 6",
        [$like, $like, $like, $like]
    );
    if ($rows) {
        // customers.php has no single-record detail page — it's a list with an
        // edit modal populated per-row. Deep-link via its own ?search= filter
        // (account number is the most precise match it supports) so the
        // matched row is on the page, rather than inventing a new "open" param.
        $results['customers'] = array_map(fn($c) => [
            'id' => $c['id'], 'title' => $c['name'] ?? '(no name)',
            'subtitle' => trim(($c['account_number'] ?? '') . ' ' . ($c['mailing_city'] ? '— ' . $c['mailing_city'] : '')),
            'url' => '/customers?search=' . urlencode($c['account_number'] ?: $c['name']),
        ], $rows);
    }
}

if (hasPermission('installations.view')) {
    [$scopeSql, $scopeParams] = installationScopeSql('');
    $where = ["(name LIKE ? OR phone LIKE ? OR network_user_id LIKE ? OR address LIKE ?)"];
    $params = [$like, $like, $like, $like];
    if ($scopeSql !== '') { $where[] = $scopeSql; $params = array_merge($params, $scopeParams); }
    $rows = dbFetchAll(
        "SELECT id, name, status, network_user_id FROM installation_profiles WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT 6",
        $params
    );
    if ($rows) {
        // Same reasoning as customers above — installations.php's own ?search=
        // only matches name/email/phone, so deep-link by name.
        $results['installations'] = array_map(fn($p) => [
            'id' => $p['id'], 'title' => $p['name'] ?? '(no name)',
            'subtitle' => str_replace('_', ' ', ucfirst($p['status'] ?? '')) . ($p['network_user_id'] ? ' — ' . $p['network_user_id'] : ''),
            'url' => '/installations?search=' . urlencode($p['name'] ?: ''),
        ], $rows);
    }
}

jsonResponse(['results' => $results]);
