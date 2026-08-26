<?php
/**
 * Integration API — Customers. API-key auth (see includes/api-auth.php), not
 * session auth — this is for external platforms, not the staff app.
 *
 * GET  /api/v1/customers            list (paginated, optional ?search=)
 * GET  /api/v1/customers?id=<id>    single record
 * POST /api/v1/customers            create
 * PATCH /api/v1/customers?id=<id>   update
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/api-auth.php';

function customerOut(array $c): array {
    return [
        'id' => $c['id'], 'name' => $c['name'], 'first_name' => $c['first_name'], 'last_name' => $c['last_name'],
        'account_number' => $c['account_number'], 'email' => $c['email'], 'phone' => $c['phone'],
        'address' => $c['address'], 'mailing_city' => $c['mailing_city'], 'mailing_state' => $c['mailing_state'],
        'plan' => $c['plan'], 'status' => $c['status'], 'expiration' => $c['expiration'],
        'created_at' => $c['created_at'] ?? null,
    ];
}

$id = trim($_GET['id'] ?? '');

if (method() === 'GET') {
    $key = requireApiScope('customers.read');
    if ($id) {
        $c = dbFetch("SELECT * FROM customers WHERE id = ?", [$id]);
        if (!$c) jsonResponse(['error' => 'Not found'], 404);
        jsonResponse(['data' => customerOut($c)]);
    }
    $search = trim($_GET['search'] ?? '');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(200, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $where = []; $params = [];
    if ($search) {
        $like = "%$search%";
        $where[] = "(name LIKE ? OR account_number LIKE ? OR phone LIKE ? OR email LIKE ?)";
        array_push($params, $like, $like, $like, $like);
    }
    $whereSQL = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $total = (int)(dbFetch("SELECT COUNT(*) c FROM customers" . $whereSQL, $params)['c'] ?? 0);
    $rows  = dbFetchAll("SELECT * FROM customers" . $whereSQL . " ORDER BY name LIMIT $limit OFFSET $offset", $params);
    jsonResponse(['data' => array_map('customerOut', $rows), 'page' => $page, 'limit' => $limit, 'total' => $total]);
}

if (method() === 'POST') {
    $key = requireApiScope('customers.write');
    $b = getBody();
    $name = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
    if (!$name) $name = trim($b['name'] ?? '');
    if ($name === '') jsonResponse(['error' => 'name (or first_name/last_name) is required'], 400);

    $newId = newUuid();
    dbRun("INSERT INTO customers
            (id,name,first_name,last_name,account_number,email,phone,address,mailing_city,mailing_state,plan,status,expiration)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [$newId, $name, $b['first_name'] ?? '', $b['last_name'] ?? '', $b['account_number'] ?? '', $b['email'] ?? '',
         $b['phone'] ?? '', $b['address'] ?? '', $b['mailing_city'] ?? '', $b['mailing_state'] ?? '',
         $b['plan'] ?? '', $b['status'] ?? 'active', $b['expiration'] ?? null]);
    auditLog('api_create', 'customer', $newId);

    $c = dbFetch("SELECT * FROM customers WHERE id = ?", [$newId]);
    fireWebhooks('customer.created', customerOut($c));
    jsonResponse(['data' => customerOut($c)], 201);
}

if (method() === 'PATCH') {
    $key = requireApiScope('customers.write');
    if (!$id) jsonResponse(['error' => '?id= is required'], 400);
    $existing = dbFetch("SELECT * FROM customers WHERE id = ?", [$id]);
    if (!$existing) jsonResponse(['error' => 'Not found'], 404);

    $b = getBody();
    $allowed = ['first_name','last_name','account_number','email','phone','address','mailing_city','mailing_state','plan','status','expiration'];
    $sets = []; $vals = [];
    foreach ($allowed as $col) {
        if (array_key_exists($col, $b)) { $sets[] = "$col=?"; $vals[] = $b[$col]; }
    }
    if (array_key_exists('first_name', $b) || array_key_exists('last_name', $b)) {
        $name = trim(($b['first_name'] ?? $existing['first_name']) . ' ' . ($b['last_name'] ?? $existing['last_name']));
        if ($name !== '') { $sets[] = "name=?"; $vals[] = $name; }
    } elseif (array_key_exists('name', $b)) {
        $sets[] = "name=?"; $vals[] = $b['name'];
    }
    if (!$sets) jsonResponse(['error' => 'Nothing to update'], 400);
    $vals[] = $id;
    dbRun("UPDATE customers SET " . implode(',', $sets) . " WHERE id=?", $vals);
    auditLog('api_update', 'customer', $id);

    $c = dbFetch("SELECT * FROM customers WHERE id = ?", [$id]);
    fireWebhooks('customer.updated', customerOut($c));
    jsonResponse(['data' => customerOut($c)]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
