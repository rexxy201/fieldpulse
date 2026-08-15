<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit; }

$template = isset($_GET['template']);

if ($template) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="customers_template.csv"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['account_number','name','email','phone','address','mailing_city','mailing_state','plan','status','expiration']);
    fputcsv($out, ['02_0100','John Smith','john@example.com','08033065348','12 Adeola Str','Iponri','Lagos','10 Mbps','active','2026-12-31']);
    fputcsv($out, ['02_0101','Amaka Obi','amaka@example.com','07011223344','5 Marina Close','Victoria Island','Lagos','5 Mbps','active','2026-11-30']);
    fclose($out);
    exit;
}

$customers = dbFetchAll("SELECT account_number,first_name,last_name,name,email,phone,address,mailing_city,mailing_state,plan,status,expiration FROM customers ORDER BY name");

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="customers_' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-cache');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
fputcsv($out, ['account_number','first_name','last_name','name','email','phone','address','mailing_city','mailing_state','plan','status','expiration']);
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
    ]);
}
fclose($out);