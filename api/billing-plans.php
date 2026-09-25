<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (method() === 'POST') verifyCsrf();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (method() === 'GET' && $action === 'list') {
    requirePermission('billing.invoices.view');
    jsonResponse(['plans' => dbFetchAll("SELECT * FROM billing_plans ORDER BY is_active DESC, name")]);
}

if ($action === 'save_plan') {
    requirePermission('billing.invoices.manage');
    $id     = trim($_POST['id'] ?? '');
    $name   = trim($_POST['name'] ?? '');
    $desc   = trim($_POST['description'] ?? '') ?: null;
    $amount = max(0, (float)($_POST['amount'] ?? 0));
    $tax    = max(0, min(100, (float)($_POST['tax_rate'] ?? 0)));
    $cycle  = in_array($_POST['billing_cycle'] ?? '', ['monthly','quarterly','annually'], true)
              ? $_POST['billing_cycle'] : 'monthly';
    $day    = max(1, min(28, (int)($_POST['billing_day'] ?? 1)));
    $active = ($_POST['is_active'] ?? '0') === '1' ? 1 : 0;

    if (!$name) jsonResponse(['error' => 'Name is required'], 400);

    if ($id) {
        dbRun("UPDATE billing_plans SET name=?,description=?,amount=?,tax_rate=?,billing_cycle=?,billing_day=?,is_active=? WHERE id=?",
              [$name, $desc, $amount, $tax, $cycle, $day, $active, $id]);
        auditLog('update', 'billing_plan', $id);
    } else {
        $id = newUuid();
        dbRun("INSERT INTO billing_plans (id,name,description,amount,tax_rate,billing_cycle,billing_day,is_active) VALUES (?,?,?,?,?,?,?,?)",
              [$id, $name, $desc, $amount, $tax, $cycle, $day, $active]);
        auditLog('create', 'billing_plan', $id);
    }
    jsonResponse(['ok' => true, 'id' => $id]);
}

if ($action === 'assign_customer') {
    requirePermission('billing.invoices.manage');
    $custId  = trim($_POST['customer_id'] ?? '');
    $planId  = trim($_POST['plan_id'] ?? '') ?: null;
    $active  = ($_POST['billing_active'] ?? '0') === '1' ? 1 : 0;
    $nextDate = trim($_POST['next_invoice_date'] ?? '') ?: null;
    if (!$custId) jsonResponse(['error' => 'customer_id required'], 400);
    dbRun("UPDATE customers SET billing_plan_id=?,billing_active=?,next_invoice_date=? WHERE id=?",
          [$planId, $active, $nextDate, $custId]);
    jsonResponse(['ok' => true]);
}

jsonResponse(['error' => 'Unknown action'], 400);
