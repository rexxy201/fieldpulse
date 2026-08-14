<?php
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('installations.view');

$user = currentUser();
$role = $user['role'];

$search  = trim($_GET['search'] ?? '');
$status  = $_GET['status'] ?? '';
$paid    = $_GET['paid'] ?? '';
$vendorId = $_GET['vendor'] ?? '';

$where = []; $params = [];
// Vendor users only ever export their own company's installation jobs — this
// overrides any ?vendor= query param, it isn't just a default.
if ($role === 'vendor') {
    $where[] = "p.vendor_id = ?"; $params[] = $user['vendor_id'] ?? '__none__';
} elseif ($vendorId) {
    $where[] = "p.vendor_id = ?"; $params[] = $vendorId;
}
if ($search) { $where[] = "(p.name LIKE ? OR p.email LIKE ? OR p.phone LIKE ?)"; $like = "%$search%"; array_push($params, $like, $like, $like); }
if ($status) { $where[] = "p.status = ?"; $params[] = $status; }
if ($paid === 'unset') { $where[] = "(p.installation_paid IS NULL OR p.installation_paid = '')"; }
elseif ($paid) { $where[] = "p.installation_paid = ?"; $params[] = $paid; }
$whereSQL = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$profiles = dbFetchAll(
    "SELECT p.*, v.name AS vendor_name, h.name AS hub_name
     FROM installation_profiles p
     LEFT JOIN vendors v ON v.id = p.vendor_id
     LEFT JOIN hubs h ON h.id = p.hub_id
     $whereSQL ORDER BY p.created_at DESC",
    $params
);

$STATUS_LABELS = [
    'pending'             => 'Unassigned',
    'in_progress'         => 'Assigned',
    'on_hold_customer'    => 'On Hold (Customer)',
    'on_hold_deployment'  => 'On Hold (Deployment)',
    'cable_laying'        => 'Cable Laying',
    'termination_pending' => 'Termination Pending',
    'connected'           => 'Connected',
    'refunded'            => 'Refunded',
];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="installations_' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-cache');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
fputcsv($out, [
    'name','phone','email','address','estate','plan','stage','vendor','pop_hub','amount_paid','installation_paid',
    'network_user_id','router_type','on_hold_reason','refund_reason','payment_confirmed_at','sla_due_at',
    'completed_at','refunded_at','installation_cost','connection_date','notes','created_at',
]);
foreach ($profiles as $p) {
    fputcsv($out, [
        $p['name'] ?? '',
        $p['phone'] ?? '',
        $p['email'] ?? '',
        $p['address'] ?? '',
        $p['estate'] ?? '',
        $p['plan'] ?? '',
        $STATUS_LABELS[$p['status']] ?? $p['status'] ?? '',
        $p['vendor_name'] ?? '',
        $p['hub_name'] ?? '',
        $p['amount_paid'] ?? '',
        $p['installation_paid'] ?? '',
        $p['network_user_id'] ?? '',
        $p['router_type'] ?? '',
        $p['on_hold_reason'] ?? '',
        $p['refund_reason'] ?? '',
        $p['payment_confirmed_at'] ?? '',
        $p['sla_due_at'] ?? '',
        $p['completed_at'] ?? '',
        $p['refunded_at'] ?? '',
        $p['installation_cost'] ?? '',
        $p['connection_date'] ?? '',
        $p['notes'] ?? '',
        $p['created_at'] ?? '',
    ]);
}
fclose($out);
