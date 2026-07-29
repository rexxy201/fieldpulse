<?php
require_once __DIR__ . '/../config.php';
requireAuth();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="tickets_' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-cache');

$search     = trim($_GET['search'] ?? '');
$status     = $_GET['status'] ?? '';
$prio       = $_GET['priority'] ?? '';
$engineerId = $_GET['engineer'] ?? '';
$department = $_GET['department'] ?? '';
$vendorId   = $_GET['vendor'] ?? '';
$faultType  = $_GET['faultType'] ?? '';
$olt        = $_GET['olt'] ?? '';
$customerId = $_GET['customerId'] ?? '';
$user   = currentUser();
$role   = $user['role'];

$where = []; $params = [];
if (in_array($role, ['engineer','noc_engineer'])) { $where[] = "t.assigned_to = ?"; $params[] = $user['id']; }
if ($status)     { $where[] = "t.status = ?"; $params[] = $status; }
if ($prio)       { $where[] = "t.priority = ?"; $params[] = $prio; }
if ($engineerId) { $where[] = "t.assigned_to = ?"; $params[] = $engineerId; }
if ($vendorId)   { $where[] = "t.vendor_id = ?"; $params[] = $vendorId; }
if ($faultType)  { $where[] = "t.fault_type_id = ?"; $params[] = $faultType; }
if ($olt)        { $where[] = "t.olt = ?"; $params[] = $olt; }
if ($customerId) { $where[] = "t.customer_id = ?"; $params[] = $customerId; }
if ($department) { $where[] = "t.fault_type_id IN (SELECT id FROM fault_types WHERE route_to = ?)"; $params[] = $department; }
if ($search) {
    $like = "%$search%";
    $where[] = "(t.description LIKE ? OR t.ticket_number LIKE ? OR t.customer_name LIKE ? OR t.address LIKE ?)";
    array_push($params, $like, $like, $like, $like);
}
$whereSQL = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$tickets = dbFetchAll(
    "SELECT t.ticket_number, t.customer_name, t.address, t.status, t.priority, t.type,
            u.name AS assigned_name, cb.name AS created_by_name,
            t.created_at, t.resolved_at, t.description, t.sla_breach_at
     FROM tickets t
     LEFT JOIN users u  ON u.id = t.assigned_to
     LEFT JOIN users cb ON cb.id = t.created_by
     $whereSQL ORDER BY t.created_at DESC",
    $params
);

$out = fopen('php://output', 'w');
fputcsv($out, ['ticket_number','customer','address','status','priority','type','assigned_to','created_by','created_at','resolved_at','description','sla_breach_at']);
foreach ($tickets as $t) {
    fputcsv($out, [
        $t['ticket_number'] ?? '',
        $t['customer_name'] ?? '',
        $t['address'] ?? '',
        $t['status'] ?? '',
        strtoupper($t['priority'] ?? ''),
        $t['type'] ?? '',
        $t['assigned_name'] ?? 'Unassigned',
        $t['created_by_name'] ?? '',
        $t['created_at'] ?? '',
        $t['resolved_at'] ?? '',
        $t['description'] ?? '',
        $t['sla_breach_at'] ?? '',
    ]);
}
fclose($out);