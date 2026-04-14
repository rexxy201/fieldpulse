<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit; }

$template = isset($_GET['template']);

if ($template) {
    // Return blank template with headers only
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="customers_template.csv"');
    header('Cache-Control: no-cache');
    echo "name,account_number,email,phone,address,plan,status\n";
    echo "John Smith,ACC-000001,john@example.com,+60123456789,123 Main St Kuala Lumpur,100 Mbps Fast,active\n";
    exit;
}

$customers = dbFetchAll("SELECT account_number,first_name,last_name,name,email,phone,address,mailing_city,mailing_state,plan,status,expiration,created_at FROM customers ORDER BY name");

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="customers_' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-cache');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
fputcsv($out, ['account_number','first_name','last_name','name','email','phone','address','mailing_city','mailing_state','plan','status','expiration','created_at']);
foreach ($customers as $c) {
    fputcsv($out, [
        $c['account_number'] ?? '',
        $c['first_name'] ?? '',
        $c['last_name'] ?? '',
        $c['name'] ?? '',
        $c['email'] ?? '',
        $c['phone'] ?? '',
        $c['address'] ?? '',
        $c['mailing_city'] ?? '',
        $c['mailing_state'] ?? '',
        $c['plan'] ?? '',
        $c['status'] ?? 'active',
        $c['expiration'] ?? '',
        $c['created_at'] ?? '',
    ]);
}
fclose($out);
