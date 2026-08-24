<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$user       = currentUser();
$role       = $user['role'];
$canCreate  = hasPermission('payment_requests.create');
$canView    = hasPermission('payment_requests.view');
// Three distinct sign-off stages: Authorize (Line Manager / Supervisor),
// Approve (COO / senior management), Finance Check (Accountant / Accounts
// Payable — disburses or returns to the requester for edits).
$canAuthorize    = hasPermission('payment_requests.authorize');
$canApproveStage = hasPermission('payment_requests.approve');
$canFinanceCheck = hasPermission('payment_requests.finance_check');
$canReview       = $canAuthorize || $canApproveStage || $canFinanceCheck;
if (!$canCreate && !$canView) { header('Location: /dashboard'); exit; }

$isVendor = $role === 'vendor';

const PR_CATEGORIES = ['Operational','Deployment/Expansion','Fiber Cut Restoration','Equipment','Inventory/Materials','Other'];
// Request Type controls whether this voucher is tied to one or more real
// customer records. 'operational' — Customer-type: at least one customer
// must be linked (feeds the Customers module). 'expansion' / 'deployment' —
// not tied to a specific customer; the customer picker is shown but optional.
// 'admin' — Admin Requests: no customer involved, the picker isn't offered.
const PR_REQUEST_TYPES = ['operational' => 'Operational', 'expansion' => 'Expansion', 'deployment' => 'Deployment', 'admin' => 'Admin Requests'];
const PR_CUSTOMER_REQUIRED_TYPES = ['operational'];
const PR_CUSTOMER_HIDDEN_TYPES = ['admin'];

// ─── AJAX authorize / approve / reject / return / disburse ──────────────────
if (method() === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    verifyCsrf();
    if (!$canReview) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }
    $action = $_POST['action'] ?? '';
    $reqId  = $_POST['req_id'] ?? '';
    $notes  = trim($_POST['review_notes'] ?? '');

    if ($action === 'authorize') {
        if (!$canAuthorize) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }
        // The Authorizer may revise the requested amount (e.g. trimming an
        // inflated figure) before it moves on to Approve. The requester's
        // original figure is preserved in original_amount for audit/print.
        $overrideRaw = $_POST['amount'] ?? '';
        if ($overrideRaw !== '' && is_numeric($overrideRaw)) {
            $newAmt = round((float)$overrideRaw, 2);
            if ($newAmt <= 0) { echo json_encode(['ok'=>false,'msg'=>'Amount must be greater than zero.']); exit; }
            $cur = dbFetch("SELECT amount, original_amount FROM payment_requests WHERE id=? AND status='pending'", [$reqId]);
            if ($cur && $newAmt != (float)$cur['amount']) {
                $origToStore = $cur['original_amount'] !== null ? $cur['original_amount'] : $cur['amount'];
                dbRun("UPDATE payment_requests SET amount=?, original_amount=? WHERE id=? AND status='pending'", [$newAmt, $origToStore, $reqId]);
            }
        }
        dbRun("UPDATE payment_requests SET status='authorized', authorized_by=?, authorized_by_name=?, authorized_at=NOW() WHERE id=? AND status='pending'",
            [$user['id'], $user['name'], $reqId]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'approve') {
        if (!$canApproveStage) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }
        dbRun("UPDATE payment_requests SET status='approved', approved_by=?, approved_by_name=?, approved_at=NOW() WHERE id=? AND status='authorized'",
            [$user['id'], $user['name'], $reqId]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'reject') {
        // Terminal rejection — only at the Authorize/Approve stages. Finance
        // uses "return" instead, which sends the request back to the requester.
        if (!$canAuthorize && !$canApproveStage) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }
        if ($notes === '') { echo json_encode(['ok'=>false,'msg'=>'A reason is required to reject.']); exit; }
        dbRun("UPDATE payment_requests SET status='rejected', reviewed_by=?, reviewed_by_name=?, reviewed_at=NOW(), review_notes=? WHERE id=? AND status IN ('pending','authorized')",
            [$user['id'], $user['name'], $notes, $reqId]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'return') {
        if (!$canFinanceCheck) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }
        if ($notes === '') { echo json_encode(['ok'=>false,'msg'=>'A reason is required to return a request to the requester.']); exit; }
        // Only returnable before any money has moved — once a partial payment
        // has been recorded, the bill must be paid off, not sent back.
        dbRun("UPDATE payment_requests SET status='returned', returned_by=?, returned_by_name=?, returned_at=NOW(), return_notes=? WHERE id=? AND status='approved'",
            [$user['id'], $user['name'], $notes, $reqId]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'disburse') {
        if (!$canFinanceCheck) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }
        // Bill-style payment recording — Finance can pay an approved request
        // off in one or more installments. Each call records a payment row
        // and advances amount_paid; the request moves to 'partially_disbursed'
        // until the running total reaches the full amount, then 'disbursed'.
        $payAmt = $_POST['pay_amount'] ?? '';
        $ref    = trim($_POST['payment_reference'] ?? '');
        $note   = trim($_POST['payment_note'] ?? '');
        if ($payAmt === '' || !is_numeric($payAmt) || (float)$payAmt <= 0) {
            echo json_encode(['ok'=>false,'msg'=>'Enter a valid payment amount.']); exit;
        }
        $payAmt = round((float)$payAmt, 2);
        $pr = dbFetch("SELECT amount, amount_paid FROM payment_requests WHERE id=? AND status IN ('approved','partially_disbursed')", [$reqId]);
        if (!$pr) { echo json_encode(['ok'=>false,'msg'=>'Request not found or not awaiting payment.']); exit; }
        $balance = round((float)$pr['amount'] - (float)$pr['amount_paid'], 2);
        if ($payAmt > $balance + 0.01) {
            echo json_encode(['ok'=>false,'msg'=>'Payment of ₦'.number_format($payAmt,2).' exceeds the outstanding balance of ₦'.number_format($balance,2).'.']); exit;
        }
        // TODO: Zoho Books integration — once the API is wired up, post this
        // payment against the corresponding bill in Zoho Books here. Not
        // built yet per the user's request; this is a placeholder note only.
        dbRun("INSERT INTO payment_request_payments (id,payment_request_id,amount,payment_reference,note,paid_by,paid_by_name,paid_at) VALUES (?,?,?,?,?,?,?,NOW())",
            [newUuid(), $reqId, $payAmt, $ref ?: null, $note ?: null, $user['id'], $user['name']]);
        $newPaid = round((float)$pr['amount_paid'] + $payAmt, 2);
        $isFull  = $newPaid >= round((float)$pr['amount'] - 0.01, 2);
        if ($isFull) {
            dbRun("UPDATE payment_requests SET amount_paid=?, status='disbursed', paid_at=NOW(), payment_reference=?, disbursed_by=?, disbursed_by_name=? WHERE id=?",
                [$newPaid, $ref ?: null, $user['id'], $user['name'], $reqId]);
        } else {
            dbRun("UPDATE payment_requests SET amount_paid=?, status='partially_disbursed', payment_reference=?, disbursed_by=?, disbursed_by_name=? WHERE id=?",
                [$newPaid, $ref ?: null, $user['id'], $user['name'], $reqId]);
        }
        echo json_encode(['ok'=>true,'full'=>$isFull]); exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

// ─── Create / Resubmit ────────────────────────────────────────────────────────
// Resubmit reuses the same form + validation as Create, but UPDATEs the
// existing 'returned' record instead of inserting a new one, and resets its
// status back to 'pending' so it re-enters the Authorize → Approve → Finance
// Check chain from the start.
$err = '';
$resubmitId = '';
if (method() === 'POST' && !isset($_POST['ajax'])) {
    verifyCsrf();
    $b = $_POST;
    $formAction = $b['_action'] ?? '';
    if ($formAction === 'resubmit') {
        $resubmitId = trim($b['req_id'] ?? '');
        $owned = $resubmitId ? dbFetch("SELECT id FROM payment_requests WHERE id=? AND requester_id=? AND status='returned'", [$resubmitId, $user['id']]) : null;
        if (!$owned) { $formAction = ''; $err = 'That request can no longer be edited.'; }
    }
    if ($formAction === 'create' || $formAction === 'resubmit') {
        $desc   = trim($b['description'] ?? '');
        $linkedType = in_array($b['linked_type'] ?? '', ['installation','ticket'], true) ? $b['linked_type'] : null;
        $linkedId   = $linkedType ? trim($b['linked_id'] ?? '') : null;
        $category   = in_array($b['category'] ?? '', PR_CATEGORIES, true) ? $b['category'] : '';
        $categoryOther = trim($b['category_other'] ?? '');
        $capexOpex  = in_array($b['capex_opex'] ?? '', ['Capex','Opex'], true) ? $b['capex_opex'] : '';
        $priority   = in_array($b['priority'] ?? '', ['High','Medium','Low'], true) ? $b['priority'] : '';
        $dateOfReq  = trim($b['date_of_request'] ?? '') ?: date('Y-m-d');
        $requestType = array_key_exists($b['request_type'] ?? '', PR_REQUEST_TYPES) ? $b['request_type'] : '';
        // One or more real customers, selected from the Customers module —
        // required for Customer-type (Operational) requests.
        $customerIds = array_values(array_unique(array_filter(array_map('trim', $b['customer_ids'] ?? []))));
        if ($customerIds) {
            $ph = implode(',', array_fill(0, count($customerIds), '?'));
            $validCustomers = dbFetchAll("SELECT id,name,account_number FROM customers WHERE id IN ($ph)", $customerIds);
            $customerIds = array_column($validCustomers, 'id'); // drop any bogus/deleted ids
        } else {
            $validCustomers = [];
        }
        $existingDocCount = $formAction === 'resubmit'
            ? (int)(dbFetch("SELECT COUNT(*) c FROM payment_request_documents WHERE payment_request_id=?", [$resubmitId])['c'] ?? 0)
            : 0;
        $docCheck   = validatePaymentRequestDocuments($_FILES['documents'] ?? [], true, $existingDocCount);

        // Build + validate line items — empty rows (no description and no price) are dropped.
        $itemDescs  = $b['item_description'] ?? [];
        $itemQtys   = $b['item_qty'] ?? [];
        $itemPrices = $b['item_unit_price'] ?? [];
        $items = [];
        $grandTotal = 0.0;
        foreach ($itemDescs as $i => $d) {
            $d = trim($d);
            $qty   = is_numeric($itemQtys[$i] ?? '') ? (float)$itemQtys[$i] : 0;
            $price = is_numeric($itemPrices[$i] ?? '') ? (float)$itemPrices[$i] : 0;
            if ($d === '' && $qty == 0 && $price == 0) continue;
            $qty = $qty > 0 ? $qty : 1;
            $lineTotal = round($qty * $price, 2);
            $items[] = ['description' => $d, 'qty' => $qty, 'unit_price' => $price, 'line_total' => $lineTotal];
            $grandTotal += $lineTotal;
        }
        $grandTotal = round($grandTotal, 2);

        if ($desc === '') {
            $err = 'Reason / Description of Request is required.';
        } elseif (!$items) {
            $err = 'Add at least one line item with a description and amount.';
        } elseif ($grandTotal <= 0) {
            $err = 'Grand Total must be greater than zero.';
        } elseif ($category === '') {
            $err = 'Category is required.';
        } elseif ($category === 'Other' && $categoryOther === '') {
            $err = 'Please specify the category under "Other".';
        } elseif ($requestType === '') {
            $err = 'Request Type is required.';
        } elseif (in_array($requestType, PR_CUSTOMER_REQUIRED_TYPES, true) && !$customerIds) {
            $err = 'Select at least one customer for an Operational (Customer-type) request.';
        } elseif ($capexOpex === '') {
            $err = 'Capex / Opex is required.';
        } elseif ($priority === '') {
            $err = 'Priority is required.';
        } elseif ($linkedType && !$linkedId) {
            $err = 'Select the linked record, or set Link Type back to None.';
        } elseif (!$docCheck['ok']) {
            $err = $docCheck['error'];
        } else {
            // Vendors always attach their own company; staff have no manual
            // vendor picker on this form (removed — vendor is inferred from
            // the linked Installation/Ticket, if any).
            $vendorId = $isVendor ? ($user['vendor_id'] ?? null) : null;
            // Ownership check on the linked record — vendors may only link their own jobs/tickets.
            if ($isVendor && $linkedType === 'installation') {
                $p = dbFetch("SELECT vendor_id FROM installation_profiles WHERE id=?", [$linkedId]);
                if (!$p || $p['vendor_id'] !== $vendorId) { $linkedId = null; $linkedType = null; }
            }
            if ($isVendor && $linkedType === 'ticket') {
                $t = dbFetch("SELECT vendor_id FROM tickets WHERE id=?", [$linkedId]);
                if (!$t || $t['vendor_id'] !== $vendorId) { $linkedId = null; $linkedType = null; }
            }
            $hubId = trim($b['hub_id'] ?? '') ?: getHubIdForCity($b['location'] ?? '');
            // Admin Requests never carry a customer, regardless of what was
            // posted — the picker is hidden client-side, enforce it server-side
            // too. customer_name/customer_user_id are kept in sync from the
            // linked customer(s) for back-compat display (list, print voucher).
            $applyCustomers = !in_array($requestType, PR_CUSTOMER_HIDDEN_TYPES, true) ? $validCustomers : [];
            $storedCustName   = $applyCustomers ? implode(', ', array_column($applyCustomers, 'name')) : null;
            $storedCustUserId = $applyCustomers ? implode(', ', array_filter(array_column($applyCustomers, 'account_number'))) ?: null : null;
            $applyCustomerIds = array_column($applyCustomers, 'id');

            if ($formAction === 'resubmit') {
                $prId = $resubmitId;
                dbRun("UPDATE payment_requests SET
                        vendor_id=?, linked_type=?, linked_id=?, amount=?, description=?, status='pending',
                        date_of_request=?, department=?, request_type=?, customer_name=?, customer_user_id=?, location=?, hub_id=?,
                        category=?, category_other=?, capex_opex=?, receiver=?, priority=?,
                        original_amount=NULL,
                        authorized_by=NULL, authorized_by_name=NULL, authorized_at=NULL,
                        approved_by=NULL, approved_by_name=NULL, approved_at=NULL,
                        reviewed_by=NULL, reviewed_by_name=NULL, reviewed_at=NULL, review_notes=NULL,
                        returned_by=NULL, returned_by_name=NULL, returned_at=NULL, return_notes=NULL
                       WHERE id=?",
                    [$vendorId, $linkedType, $linkedId, $grandTotal, $desc,
                     $dateOfReq, trim($b['department']??'')?:null, $requestType, $storedCustName, $storedCustUserId,
                     trim($b['location']??'')?:null, $hubId?:null, $category, $category==='Other'?$categoryOther:null,
                     $capexOpex, trim($b['receiver']??'')?:null, $priority, $prId]);
                dbRun("DELETE FROM payment_request_items WHERE payment_request_id=?", [$prId]);
                auditLog('resubmit','payment_request', $prId);
            } else {
                $prId = newUuid();
                dbRun("INSERT INTO payment_requests
                        (id,requester_id,requester_name,vendor_id,linked_type,linked_id,amount,description,status,
                         date_of_request,department,request_type,customer_name,customer_user_id,location,hub_id,category,category_other,
                         capex_opex,receiver,priority)
                       VALUES (?,?,?,?,?,?,?,?,'pending',?,?,?,?,?,?,?,?,?,?,?,?)",
                    [$prId, $user['id'], $user['name'], $vendorId, $linkedType, $linkedId, $grandTotal, $desc,
                     $dateOfReq, trim($b['department']??'')?:null, $requestType, $storedCustName, $storedCustUserId,
                     trim($b['location']??'')?:null, $hubId?:null, $category, $category==='Other'?$categoryOther:null,
                     $capexOpex, trim($b['receiver']??'')?:null, $priority]);
                auditLog('create','payment_request', $prId);
            }
            if ($formAction === 'resubmit') {
                dbRun("DELETE FROM payment_request_customers WHERE payment_request_id=?", [$prId]);
            }
            foreach ($applyCustomerIds as $cid) {
                dbInsertIgnore("INSERT INTO payment_request_customers (id,payment_request_id,customer_id) VALUES (?,?,?)", [newUuid(), $prId, $cid]);
            }
            foreach ($items as $idx => $it) {
                dbRun("INSERT INTO payment_request_items (id,payment_request_id,description,qty,unit_price,line_total,sort_order) VALUES (?,?,?,?,?,?,?)",
                    [newUuid(), $prId, $it['description'], $it['qty'], $it['unit_price'], $it['line_total'], $idx]);
            }
            if (!empty($_FILES['documents'])) savePaymentRequestDocuments($_FILES['documents'], $prId, $user['id']);
            header('Location: /payment-requests'); exit;
        }
    }
}

$status = $_GET['status'] ?? 'all';
if (!in_array($status, ['all','pending','authorized','approved','partially_disbursed','disbursed','rejected','returned'], true)) $status = 'all';

// Scope: approvers/viewers see everyone's; vendors see their own vendor_id;
// everyone else sees only what they personally submitted.
$where = []; $params = [];
if (!$canView) {
    if ($isVendor && !empty($user['vendor_id'])) {
        $where[] = "pr.vendor_id = ?"; $params[] = $user['vendor_id'];
    } else {
        $where[] = "pr.requester_id = ?"; $params[] = $user['id'];
    }
}
if ($status !== 'all') { $where[] = "pr.status = ?"; $params[] = $status; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$requests = dbFetchAll(
    "SELECT pr.*, v.name AS vendor_name, h.name AS hub_name
     FROM payment_requests pr
     LEFT JOIN vendors v ON v.id = pr.vendor_id
     LEFT JOIN hubs h ON h.id = pr.hub_id
     $whereSql ORDER BY pr.created_at DESC LIMIT 500",
    $params
);

// Resolve linked-record display labels
$installIds = array_values(array_unique(array_filter(array_map(fn($r) => $r['linked_type']==='installation' ? $r['linked_id'] : null, $requests))));
$ticketIds  = array_values(array_unique(array_filter(array_map(fn($r) => $r['linked_type']==='ticket' ? $r['linked_id'] : null, $requests))));
$installLabels = [];
if ($installIds) {
    $ph = implode(',', array_fill(0, count($installIds), '?'));
    foreach (dbFetchAll("SELECT id,name FROM installation_profiles WHERE id IN ($ph)", $installIds) as $ip) $installLabels[$ip['id']] = $ip['name'];
}
$ticketLabels = [];
if ($ticketIds) {
    $ph = implode(',', array_fill(0, count($ticketIds), '?'));
    foreach (dbFetchAll("SELECT id,ticket_number,customer_name FROM tickets WHERE id IN ($ph)", $ticketIds) as $tk) $ticketLabels[$tk['id']] = $tk['ticket_number'].' — '.$tk['customer_name'];
}

// Attached documents for the listed requests
$docsByRequest = [];
if ($requests) {
    $reqIds = array_column($requests, 'id');
    $ph = implode(',', array_fill(0, count($reqIds), '?'));
    foreach (dbFetchAll("SELECT id,payment_request_id,original_name,mime_type FROM payment_request_documents WHERE payment_request_id IN ($ph) ORDER BY created_at", $reqIds) as $d) {
        $docsByRequest[$d['payment_request_id']][] = $d;
    }
}

// Prefill data for the requester's own 'returned' requests — powers the
// "Edit & Resubmit" modal on the client without a second round trip.
$resubmitData = [];
$ownReturnedIds = array_values(array_map(fn($r) => $r['id'], array_filter($requests, fn($r) => $r['status']==='returned' && $r['requester_id']===$user['id'])));
if ($ownReturnedIds) {
    $ph = implode(',', array_fill(0, count($ownReturnedIds), '?'));
    $itemsByReq = [];
    foreach (dbFetchAll("SELECT payment_request_id,description,qty,unit_price FROM payment_request_items WHERE payment_request_id IN ($ph) ORDER BY sort_order", $ownReturnedIds) as $it) {
        $itemsByReq[$it['payment_request_id']][] = $it;
    }
    $customerIdsByReq = [];
    foreach (dbFetchAll("SELECT payment_request_id,customer_id FROM payment_request_customers WHERE payment_request_id IN ($ph)", $ownReturnedIds) as $pc) {
        $customerIdsByReq[$pc['payment_request_id']][] = $pc['customer_id'];
    }
    $docCountByReq = [];
    foreach (dbFetchAll("SELECT payment_request_id, COUNT(*) c FROM payment_request_documents WHERE payment_request_id IN ($ph) GROUP BY payment_request_id", $ownReturnedIds) as $dc) {
        $docCountByReq[$dc['payment_request_id']] = (int)$dc['c'];
    }
    foreach ($requests as $r) {
        if (!in_array($r['id'], $ownReturnedIds, true)) continue;
        $resubmitData[$r['id']] = [
            'date_of_request'  => $r['date_of_request'],
            'department'       => $r['department'],
            'request_type'     => $r['request_type'],
            'customer_ids'     => $customerIdsByReq[$r['id']] ?? [],
            'doc_count'        => $docCountByReq[$r['id']] ?? 0,
            'location'         => $r['location'],
            'hub_id'           => $r['hub_id'],
            'category'         => $r['category'],
            'category_other'   => $r['category_other'],
            'capex_opex'       => $r['capex_opex'],
            'priority'         => $r['priority'],
            'description'      => $r['description'],
            'receiver'         => $r['receiver'],
            'vendor_id'        => $r['vendor_id'],
            'linked_type'      => $r['linked_type'],
            'linked_id'        => $r['linked_id'],
            'items'            => $itemsByReq[$r['id']] ?? [],
            'return_notes'     => $r['return_notes'],
            'returned_by_name' => $r['returned_by_name'],
        ];
    }
}

// Full bill-view + payment history for requests awaiting Finance action —
// powers the "View & Record Payment" modal (Zoho-Books-style bill view)
// without a second round trip.
$disburseData = [];
if ($canFinanceCheck) {
    $payableIds = array_values(array_map(fn($r) => $r['id'], array_filter($requests, fn($r) => in_array($r['status'], ['approved','partially_disbursed'], true))));
    if ($payableIds) {
        $ph = implode(',', array_fill(0, count($payableIds), '?'));
        $itemsByPr = [];
        foreach (dbFetchAll("SELECT payment_request_id,description,qty,unit_price,line_total FROM payment_request_items WHERE payment_request_id IN ($ph) ORDER BY sort_order", $payableIds) as $it) {
            $itemsByPr[$it['payment_request_id']][] = $it;
        }
        $paymentsByPr = [];
        foreach (dbFetchAll("SELECT payment_request_id,amount,payment_reference,note,paid_by_name,paid_at FROM payment_request_payments WHERE payment_request_id IN ($ph) ORDER BY paid_at", $payableIds) as $p) {
            $paymentsByPr[$p['payment_request_id']][] = $p;
        }
        foreach ($requests as $r) {
            if (!in_array($r['id'], $payableIds, true)) continue;
            $linkLbl = $r['linked_type']==='installation' ? ($installLabels[$r['linked_id']] ?? null)
                     : ($r['linked_type']==='ticket' ? ($ticketLabels[$r['linked_id']] ?? null) : null);
            $disburseData[$r['id']] = [
                'requester_name'   => $r['requester_name'],
                'date_of_request'  => $r['date_of_request'],
                'department'       => $r['department'],
                'customer_name'    => $r['customer_name'],
                'customer_user_id' => $r['customer_user_id'],
                'location'         => $r['location'],
                'hub_name'         => $r['hub_name'],
                'category'         => $r['category'],
                'category_other'   => $r['category_other'],
                'capex_opex'       => $r['capex_opex'],
                'priority'         => $r['priority'],
                'description'      => $r['description'],
                'receiver'         => $r['receiver'],
                'vendor_name'      => $r['vendor_name'],
                'linked_label'     => $linkLbl,
                'amount'           => (float)$r['amount'],
                'original_amount'  => $r['original_amount'] !== null ? (float)$r['original_amount'] : null,
                'amount_paid'      => (float)$r['amount_paid'],
                'balance'          => round((float)$r['amount'] - (float)$r['amount_paid'], 2),
                'items'            => $itemsByPr[$r['id']] ?? [],
                'payments'         => $paymentsByPr[$r['id']] ?? [],
                'docs'             => array_map(fn($d) => ['id'=>$d['id'],'name'=>$d['original_name']], $docsByRequest[$r['id']] ?? []),
            ];
        }
    }
}

// Stats (same scope, ignoring status filter)
$sw = []; $sp = [];
if (!$canView) {
    if ($isVendor && !empty($user['vendor_id'])) { $sw[] = "vendor_id = ?"; $sp[] = $user['vendor_id']; }
    else { $sw[] = "requester_id = ?"; $sp[] = $user['id']; }
}
$swSql = $sw ? ' WHERE ' . implode(' AND ', $sw) : '';
$stats = dbFetch("SELECT SUM(status='pending') pending, SUM(status='authorized') authorized, SUM(status='approved') approved, SUM(status='returned') returned, SUM(status='partially_disbursed') partially_disbursed, SUM(status='disbursed') disbursed, SUM(status='rejected') rejected FROM payment_requests" . $swSql, $sp);

// Records available to link, scoped to the current user
if ($isVendor && !empty($user['vendor_id'])) {
    $linkInstalls = dbFetchAll("SELECT id,name FROM installation_profiles WHERE vendor_id=? ORDER BY created_at DESC", [$user['vendor_id']]);
    $linkTickets  = dbFetchAll("SELECT id,ticket_number,customer_name FROM tickets WHERE vendor_id=? ORDER BY created_at DESC", [$user['vendor_id']]);
} else {
    $linkInstalls = $canCreate ? dbFetchAll("SELECT id,name FROM installation_profiles ORDER BY created_at DESC LIMIT 500") : [];
    $linkTickets  = $canCreate ? dbFetchAll("SELECT id,ticket_number,customer_name FROM tickets ORDER BY created_at DESC LIMIT 500") : [];
}
$allCustomers = $canCreate ? dbFetchAll("SELECT id,name,account_number FROM customers ORDER BY name LIMIT 2000") : [];
$hubs      = $canCreate ? dbFetchAll("SELECT id,name FROM hubs ORDER BY name") : [];
$locations = $canCreate ? dbFetchAll("SELECT name FROM locations ORDER BY name") : [];

$STATUS_LABELS = ['pending'=>'Pending','authorized'=>'Authorized','approved'=>'Approved','returned'=>'Returned','partially_disbursed'=>'Partial Payment','disbursed'=>'Disbursed','rejected'=>'Rejected'];
$STATUS_COLORS = ['pending'=>'text-bg-warning','authorized'=>'text-bg-info','approved'=>'text-bg-primary','returned'=>'text-bg-secondary','partially_disbursed'=>'text-bg-warning','disbursed'=>'text-bg-success','rejected'=>'text-bg-danger'];

$pageTitle = 'Payment Requests';
require __DIR__ . '/../includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h2 class="fw-bold mb-0">Payment Requests</h2>
    <div class="text-muted small"><?= $canView ? 'All requests' : 'Your requests' ?></div>
  </div>
  <div class="d-flex gap-2">
    <a href="/payment-request-template" target="_blank" class="btn btn-outline-secondary">
      <i class="bi bi-printer me-1"></i>Blank Voucher (Print/Download)
    </a>
    <?php if ($canCreate): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newRequestModal" onclick="resetForCreate()">
      <i class="bi bi-plus-lg me-1"></i>New Payment Request
    </button>
    <?php endif; ?>
  </div>
</div>

<div class="row g-2 mb-3">
  <?php foreach ([
    ['Pending','#f59e0b',(int)($stats['pending']??0),'pending'],
    ['Authorized','#0ea5e9',(int)($stats['authorized']??0),'authorized'],
    ['Approved','#3b82f6',(int)($stats['approved']??0),'approved'],
    ['Returned','#64748b',(int)($stats['returned']??0),'returned'],
    ['Partial Payment','#eab308',(int)($stats['partially_disbursed']??0),'partially_disbursed'],
    ['Disbursed','#10b981',(int)($stats['disbursed']??0),'disbursed'],
    ['Rejected','#ef4444',(int)($stats['rejected']??0),'rejected'],
    ['All','#64748b',(int)(($stats['pending']??0)+($stats['authorized']??0)+($stats['approved']??0)+($stats['returned']??0)+($stats['partially_disbursed']??0)+($stats['disbursed']??0)+($stats['rejected']??0)),'all'],
  ] as [$lbl,$clr,$val,$sf]): ?>
  <div class="col-6 col-md-4 col-xl-2">
    <a href="?status=<?= $sf ?>" class="stat-card py-2 d-block text-center text-decoration-none <?= $status===$sf?'border-primary':'' ?>">
      <div class="fw-bold fs-5" style="color:<?= $clr ?>"><?= number_format($val) ?></div>
      <div style="font-size:.7rem;color:#64748b"><?= $lbl ?></div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="card-section">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr>
        <th class="ps-3">Requested By</th><th>Vendor</th><th>Type</th><th>Category</th><th>Priority</th><th>Linked To</th><th class="text-end">Amount</th>
        <th>Docs</th><th>Date</th><th class="text-center">Status</th><th></th>
        <?php if ($canReview || $canCreate): ?><th class="text-end pe-3">Actions</th><?php endif; ?>
      </tr></thead>
      <tbody>
        <?php if (!$requests): ?>
        <tr><td colspan="12" class="text-center text-muted py-5"><i class="bi bi-cash-coin fs-2 d-block mb-2 opacity-25"></i>No payment requests found.</td></tr>
        <?php endif; ?>
        <?php foreach ($requests as $r):
          $sc = $STATUS_COLORS[$r['status']] ?? 'text-bg-secondary';
          $linkLabel = $r['linked_type']==='installation' ? ($installLabels[$r['linked_id']] ?? null)
                     : ($r['linked_type']==='ticket' ? ($ticketLabels[$r['linked_id']] ?? null) : null);
          $reqDocs = $docsByRequest[$r['id']] ?? [];
          $prioColor = ['High'=>'text-danger','Medium'=>'text-warning','Low'=>'text-muted'][$r['priority']] ?? 'text-muted';
          $isOwnReturned = $r['status']==='returned' && $r['requester_id']===$user['id'];
        ?>
        <tr id="pr-<?= $r['id'] ?>">
          <td class="ps-3 small fw-semibold"><?= htmlspecialchars($r['requester_name'] ?? '—') ?></td>
          <td class="small"><?= htmlspecialchars($r['vendor_name'] ?? '—') ?></td>
          <td class="small"><?= htmlspecialchars(PR_REQUEST_TYPES[$r['request_type']] ?? '—') ?></td>
          <td class="small"><?= htmlspecialchars($r['category'] ?: '—') ?></td>
          <td class="small fw-semibold <?= $prioColor ?>"><?= htmlspecialchars($r['priority'] ?: '—') ?></td>
          <td class="small">
            <?php if ($linkLabel): ?>
            <span class="badge bg-light text-dark border"><i class="bi bi-<?= $r['linked_type']==='installation'?'wifi':'ticket-perforated' ?> me-1"></i><?= htmlspecialchars($linkLabel) ?></span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="text-end fw-semibold">
            ₦<?= number_format((float)$r['amount'], 2) ?>
            <?php if ($r['original_amount'] !== null): ?>
            <div class="small text-muted fw-normal text-decoration-line-through" title="Original requested amount, revised at Authorize">₦<?= number_format((float)$r['original_amount'], 2) ?></div>
            <?php endif; ?>
            <?php if ($r['status']==='partially_disbursed'): ?>
            <div class="small text-warning fw-normal">Paid ₦<?= number_format((float)$r['amount_paid'], 2) ?> · Bal ₦<?= number_format((float)$r['amount'] - (float)$r['amount_paid'], 2) ?></div>
            <?php endif; ?>
          </td>
          <td class="small">
            <?php if ($reqDocs): ?>
            <?php foreach ($reqDocs as $d): ?>
            <a href="/api/payment-request-document?id=<?= $d['id'] ?>" target="_blank" rel="noopener" class="d-block text-truncate" style="max-width:120px" title="<?= htmlspecialchars($d['original_name']) ?>">
              <i class="bi bi-<?= $d['mime_type']==='application/pdf'?'file-earmark-pdf':'file-earmark-image' ?> me-1"></i><?= htmlspecialchars($d['original_name']) ?>
            </a>
            <?php endforeach; ?>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="small text-muted text-nowrap"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
          <td class="text-center"><span class="badge <?= $sc ?>"><?= $STATUS_LABELS[$r['status']] ?? ucfirst($r['status']) ?></span></td>
          <td><a href="/payment-request-print?id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0" title="Print / Download this record"><i class="bi bi-printer"></i></a></td>
          <?php if ($canReview || $canCreate): ?>
          <td class="text-end pe-3">
            <?php if ($canAuthorize && $r['status']==='pending'): ?>
            <div class="d-inline-flex gap-1">
              <button class="btn btn-sm btn-info text-white" onclick="review('<?= $r['id'] ?>','authorize',<?= (float)$r['amount'] ?>)" title="Authorize"><i class="bi bi-check-lg"></i> Authorize</button>
              <button class="btn btn-sm btn-danger" onclick="review('<?= $r['id'] ?>','reject')" title="Reject"><i class="bi bi-x-lg"></i></button>
            </div>
            <?php elseif ($canApproveStage && $r['status']==='authorized'): ?>
            <div class="d-inline-flex gap-1">
              <button class="btn btn-sm btn-primary" onclick="review('<?= $r['id'] ?>','approve')" title="Approve"><i class="bi bi-check-lg"></i> Approve</button>
              <button class="btn btn-sm btn-danger" onclick="review('<?= $r['id'] ?>','reject')" title="Reject"><i class="bi bi-x-lg"></i></button>
            </div>
            <?php elseif ($canFinanceCheck && in_array($r['status'], ['approved','partially_disbursed'], true)): ?>
            <div class="d-inline-flex gap-1">
              <button class="btn btn-sm btn-outline-success" onclick="openDisburse('<?= $r['id'] ?>')" title="View &amp; Record Payment"><i class="bi bi-cash-stack me-1"></i><?= $r['status']==='partially_disbursed' ? 'Record Payment' : 'Disburse' ?></button>
              <?php if ($r['status']==='approved'): ?>
              <button class="btn btn-sm btn-warning" onclick="review('<?= $r['id'] ?>','return')" title="Return to requester for edits"><i class="bi bi-arrow-return-left"></i> Return</button>
              <?php endif; ?>
            </div>
            <?php elseif ($isOwnReturned): ?>
            <button class="btn btn-sm btn-outline-warning" onclick="openResubmit('<?= $r['id'] ?>')" title="Edit &amp; Resubmit"><i class="bi bi-pencil-square me-1"></i>Edit &amp; Resubmit</button>
            <?php else: ?><span class="small text-muted">—</span><?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php if ($r['review_notes']): ?>
        <tr class="<?= $sc==='text-bg-danger'?'table-danger':'' ?>">
          <td></td>
          <td colspan="<?= ($canReview||$canCreate)?11:10 ?>" class="small text-muted fst-italic py-1">
            <i class="bi bi-chat-left-quote me-1"></i><?= htmlspecialchars($r['reviewed_by_name'] ?? '') ?>: “<?= htmlspecialchars($r['review_notes']) ?>”
          </td>
        </tr>
        <?php endif; ?>
        <?php if ($r['status']==='returned' && $r['return_notes']): ?>
        <tr class="table-warning">
          <td></td>
          <td colspan="<?= ($canReview||$canCreate)?11:10 ?>" class="small text-muted fst-italic py-1">
            <i class="bi bi-arrow-return-left me-1"></i><?= htmlspecialchars($r['returned_by_name'] ?? '') ?> returned this for edits: “<?= htmlspecialchars($r['return_notes']) ?>”
          </td>
        </tr>
        <?php endif; ?>
        <?php if ($r['status']==='disbursed'): ?>
        <tr class="table-success">
          <td></td>
          <td colspan="<?= ($canReview||$canCreate)?11:10 ?>" class="small text-muted py-1">
            <i class="bi bi-check-circle me-1"></i>Disbursed <?= date('d M Y', strtotime($r['paid_at'])) ?><?= $r['payment_reference'] ? ' — Ref: '.htmlspecialchars($r['payment_reference']) : '' ?>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canCreate): ?>
<!-- ── New Payment Request Modal ──────────────────────────────────────────── -->
<div class="modal fade" id="newRequestModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="POST" enctype="multipart/form-data" id="prForm">
      <input type="hidden" name="_action" id="prFormAction" value="create">
      <input type="hidden" name="req_id" id="prReqId" value="">
      <?= csrfField() ?>
      <div class="modal-header">
        <h5 class="modal-title" id="prModalTitle"><i class="bi bi-cash-coin me-1 text-primary"></i>New Payment Request Voucher</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if ($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif; ?>
        <div class="alert alert-warning py-2 small d-none" id="prResubmitNotice"></div>

        <p class="small fw-semibold text-muted text-uppercase mb-2">Requester &amp; Job Details</p>
        <div class="row g-2 mb-3">
          <div class="col-6"><label class="form-label small fw-semibold">Date of Request</label>
            <input type="date" name="date_of_request" id="prDate" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">Department</label><input type="text" name="department" id="prDept" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small fw-semibold">Request Type <span class="text-danger">*</span></label>
            <select name="request_type" id="prRequestType" class="form-select form-select-sm" onchange="toggleRequestType(this.value)" required>
              <option value="">— Select —</option>
              <?php foreach (PR_REQUEST_TYPES as $rtKey => $rtLabel): ?><option value="<?=$rtKey?>"><?=$rtLabel?></option><?php endforeach; ?>
            </select>
            <div class="form-text">Operational — tied to one or more customers (required). Expansion / Deployment — customer optional. Admin Requests — no customer.</div>
          </div>
          <div class="col-12 d-none" id="prCustomersWrap">
            <label class="form-label small fw-semibold">Customer(s) <span class="text-danger d-none" id="prCustomersReq">*</span></label>
            <select name="customer_ids[]" id="prCustomerIds" class="form-select form-select-sm" multiple>
              <?php foreach ($allCustomers as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name'] . ($c['account_number'] ? ' — '.$c['account_number'] : '')) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">Location / City</label>
            <input type="text" name="location" id="prLocation" class="form-control form-control-sm" list="prLocationsList" onchange="autoSelectHub(this.value,'prHubId')">
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">POP (Hub)</label>
            <select name="hub_id" id="prHubId" class="form-select form-select-sm">
              <option value="">— Auto-detect or select —</option>
              <?php foreach ($hubs as $h): ?><option value="<?=$h['id']?>"><?= htmlspecialchars($h['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">Category <span class="text-danger">*</span></label>
            <select name="category" id="prCategory" class="form-select form-select-sm" onchange="document.getElementById('prCategoryOtherWrap').classList.toggle('d-none', this.value!=='Other')" required>
              <option value="">— Select —</option>
              <?php foreach (PR_CATEGORIES as $c): ?><option value="<?=$c?>"><?=$c?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 d-none" id="prCategoryOtherWrap"><label class="form-label small fw-semibold">Category — Other</label><input type="text" name="category_other" id="prCategoryOther" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small fw-semibold">Capex / Opex <span class="text-danger">*</span></label>
            <select name="capex_opex" id="prCapexOpex" class="form-select form-select-sm" required>
              <option value="">— Select —</option>
              <option value="Capex">Capex</option>
              <option value="Opex">Opex</option>
            </select>
          </div>
          <div class="col-6"><label class="form-label small fw-semibold">Priority <span class="text-danger">*</span></label>
            <select name="priority" id="prPriority" class="form-select form-select-sm" required>
              <option value="">— Select —</option>
              <option value="High">High</option>
              <option value="Medium" selected>Medium</option>
              <option value="Low">Low</option>
            </select>
          </div>
          <div class="col-12"><label class="form-label small fw-semibold">Reason / Description of Request <span class="text-danger">*</span></label>
            <textarea name="description" id="prDescription" class="form-control form-control-sm" rows="2" required></textarea>
          </div>
        </div>

        <p class="small fw-semibold text-muted text-uppercase mb-2">Request Breakdown</p>
        <table class="table table-sm mb-2" id="itemsTable">
          <thead class="table-light"><tr><th>Description</th><th style="width:90px">Qty</th><th style="width:130px">Unit Price (₦)</th><th style="width:130px" class="text-end">Total (₦)</th><th style="width:36px"></th></tr></thead>
          <tbody id="itemsBody">
            <tr>
              <td><input type="text" name="item_description[]" class="form-control form-control-sm"></td>
              <td><input type="number" step="0.01" min="0" name="item_qty[]" class="form-control form-control-sm item-qty" value="1" oninput="recalcItems()"></td>
              <td><input type="number" step="0.01" min="0" name="item_unit_price[]" class="form-control form-control-sm item-price" oninput="recalcItems()"></td>
              <td class="text-end small item-total pt-2">0.00</td>
              <td><button type="button" class="btn btn-sm btn-outline-danger py-0" onclick="removeItemRow(this)"><i class="bi bi-x"></i></button></td>
            </tr>
          </tbody>
        </table>
        <button type="button" class="btn btn-sm btn-outline-secondary mb-3" onclick="addItemRow()"><i class="bi bi-plus-lg me-1"></i>Add Line</button>
        <div class="text-end fw-bold mb-3">Grand Total: ₦<span id="grandTotalDisplay">0.00</span></div>

        <div class="row g-2 mb-3">
          <div class="col-6"><label class="form-label small fw-semibold">Receiver</label><input type="text" name="receiver" id="prReceiver" class="form-control form-control-sm" placeholder="Who will receive this payment/item?"></div>
          <div class="col-6"><label class="form-label small fw-semibold">Link Type</label>
            <select name="linked_type" id="linkType" class="form-select form-select-sm" onchange="toggleLinkPicker(this.value)">
              <option value="">— None —</option>
              <option value="installation">Installation Job</option>
              <option value="ticket">Ticket</option>
            </select>
          </div>
          <div class="col-6 d-none" id="linkInstallWrap">
            <label class="form-label small fw-semibold">Installation Job</label>
            <select name="linked_id" id="linkInstallSelect" class="form-select form-select-sm" disabled>
              <option value="">— Select —</option>
              <?php foreach ($linkInstalls as $ip): ?><option value="<?= $ip['id'] ?>"><?= htmlspecialchars($ip['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 d-none" id="linkTicketWrap">
            <label class="form-label small fw-semibold">Ticket</label>
            <select name="linked_id" id="linkTicketSelect" class="form-select form-select-sm" disabled>
              <option value="">— Select —</option>
              <?php foreach ($linkTickets as $tk): ?><option value="<?= $tk['id'] ?>"><?= htmlspecialchars($tk['ticket_number'].' — '.$tk['customer_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small fw-semibold">Backing Documents <span class="text-danger">*</span> <span class="text-muted fw-normal">(up to 5, PDF/JPG/PNG)</span></label>
            <input type="file" name="documents[]" id="docsInput" class="form-control form-control-sm" multiple accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" onchange="checkDocsCount(this)" required>
            <div class="form-text" id="docsExistingNote"></div>
            <div class="form-text text-danger d-none" id="docsCountWarn">You can attach at most 5 documents.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Submit Request</button>
      </div>
    </form>
  </div></div>
</div>
<datalist id="prLocationsList">
  <?php foreach ($locations as $l): ?><option value="<?= htmlspecialchars($l['name']) ?>"><?php endforeach; ?>
</datalist>
<?php endif; ?>

<?php if ($canReview): ?>
<div class="modal fade" id="reviewModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="rmTitle">Review Request</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="alert alert-danger py-2 d-none" id="rmErr"></div>
    <input type="hidden" id="rmId"><input type="hidden" id="rmAction">
    <div class="mb-3 d-none" id="rmAmountWrap">
      <label class="form-label fw-semibold small">Amount (₦) <span class="text-muted fw-normal">— you may revise the requested figure</span></label>
      <input type="number" id="rmAmount" step="0.01" min="0.01" class="form-control form-control-sm">
    </div>
    <label class="form-label fw-semibold small" id="rmNotesLabel">Note (optional)</label>
    <textarea id="rmNotes" rows="3" class="form-control form-control-sm" placeholder="Add a comment or reason…"></textarea>
  </div>
  <div class="modal-footer"><button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary btn-sm" id="rmConfirm" onclick="confirmReview()">Confirm</button></div>
</div></div></div>

<div class="modal fade" id="paidModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="bi bi-receipt me-1 text-success"></i>View &amp; Record Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="alert alert-danger py-2 d-none" id="pmErr"></div>
    <input type="hidden" id="pmId">

    <!-- Read-only bill view -->
    <div id="pmDetail" class="border rounded p-3 mb-3" style="background:#f8fafc;font-size:.85rem;"></div>

    <!-- Payment history -->
    <div id="pmHistoryWrap" class="mb-3 d-none">
      <p class="small fw-semibold text-muted text-uppercase mb-2">Payment History</p>
      <table class="table table-sm mb-0"><tbody id="pmHistoryBody"></tbody></table>
    </div>

    <!-- Balance summary -->
    <div class="d-flex justify-content-between align-items-center mb-3 p-2 rounded" style="background:#eef2f7;">
      <div class="small">Total: <strong>₦<span id="pmTotal">0.00</span></strong> &nbsp;·&nbsp; Paid: <strong class="text-success">₦<span id="pmPaidSoFar">0.00</span></strong></div>
      <div>Balance Due: <strong class="text-danger fs-6">₦<span id="pmBalance">0.00</span></strong></div>
    </div>

    <!-- Record a payment -->
    <p class="small fw-semibold text-muted text-uppercase mb-2">Record Payment</p>
    <div class="row g-2">
      <div class="col-6">
        <label class="form-label small fw-semibold">Amount (₦)</label>
        <input type="number" id="pmAmount" step="0.01" min="0.01" class="form-control form-control-sm">
        <div class="form-text"><a href="#" onclick="fillFullBalance();return false;">Pay full balance</a></div>
      </div>
      <div class="col-6">
        <label class="form-label small fw-semibold">Payment Reference <span class="text-muted fw-normal">(optional)</span></label>
        <input type="text" id="pmRef" class="form-control form-control-sm" placeholder="Transaction ID, cheque #, etc.">
      </div>
      <div class="col-12">
        <label class="form-label small fw-semibold">Note <span class="text-muted fw-normal">(optional)</span></label>
        <input type="text" id="pmNote" class="form-control form-control-sm" placeholder="e.g. First installment, final payment…">
      </div>
    </div>
  </div>
  <div class="modal-footer"><button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-sm btn-success" onclick="confirmMarkDisbursed()">Record Payment</button></div>
</div></div></div>

<script>
function reviewModalEl(){ return bootstrap.Modal.getOrCreateInstance(document.getElementById('reviewModal')); }
const ACTION_LABELS = { authorize:'Authorize', approve:'Approve', reject:'Reject', return:'Return to Requester' };
const NOTES_REQUIRED_ACTIONS = ['reject','return'];
function review(id, action, amount){
  document.getElementById('rmId').value=id; document.getElementById('rmAction').value=action;
  document.getElementById('rmNotes').value=''; document.getElementById('rmErr').classList.add('d-none');
  document.getElementById('rmTitle').textContent=(ACTION_LABELS[action]||action)+' Payment Request';
  document.getElementById('rmNotesLabel').textContent = NOTES_REQUIRED_ACTIONS.includes(action) ? 'Reason (required)' : 'Note (optional)';
  const amtWrap = document.getElementById('rmAmountWrap');
  amtWrap.classList.toggle('d-none', action !== 'authorize');
  if (action === 'authorize') document.getElementById('rmAmount').value = (amount || 0).toFixed(2);
  const b=document.getElementById('rmConfirm');
  b.className='btn btn-sm '+(action==='reject'?'btn-danger':(action==='return'?'btn-warning':'btn-primary'));
  b.textContent=ACTION_LABELS[action]||action;
  reviewModalEl().show();
}
async function confirmReview(){
  const id=document.getElementById('rmId').value,action=document.getElementById('rmAction').value,notes=document.getElementById('rmNotes').value.trim(),err=document.getElementById('rmErr');
  const fd=new FormData();fd.append('ajax','1');fd.append('action',action);fd.append('req_id',id);fd.append('review_notes',notes);
  if (action === 'authorize') fd.append('amount', document.getElementById('rmAmount').value);
  const d=await(await fetch('',{method:'POST',body:fd})).json();
  if(!d.ok){err.textContent=d.msg||'Error';err.classList.remove('d-none');return;}
  reviewModalEl().hide();
  location.reload();
}
function paidModalEl(){ return bootstrap.Modal.getOrCreateInstance(document.getElementById('paidModal')); }
const DISBURSE_DATA = <?= json_encode($disburseData, JSON_HEX_TAG) ?>;
function fmtMoney(n){ return (Number(n)||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(s){ const d=document.createElement('div'); d.textContent = s==null?'':String(s); return d.innerHTML; }
function openDisburse(id){
  const rec = DISBURSE_DATA[id];
  if (!rec) return;
  document.getElementById('pmId').value = id;
  document.getElementById('pmRef').value = '';
  document.getElementById('pmNote').value = '';
  document.getElementById('pmErr').classList.add('d-none');

  const itemsRows = (rec.items||[]).map(it =>
    `<tr><td>${esc(it.description)}</td><td class="text-end">${esc(it.qty)}</td><td class="text-end">₦${fmtMoney(it.unit_price)}</td><td class="text-end">₦${fmtMoney(it.line_total)}</td></tr>`
  ).join('') || '<tr><td colspan="4" class="text-muted">No line items</td></tr>';
  const docsHtml = (rec.docs||[]).map(d => `<a href="/api/payment-request-document?id=${esc(d.id)}" target="_blank" rel="noopener" class="d-block">${esc(d.name)}</a>`).join('') || '<span class="text-muted">—</span>';

  document.getElementById('pmDetail').innerHTML = `
    <div class="row g-2 mb-2">
      <div class="col-4"><div class="text-muted" style="font-size:.7rem">REQUESTED BY</div><div class="fw-semibold">${esc(rec.requester_name)||'—'}</div></div>
      <div class="col-4"><div class="text-muted" style="font-size:.7rem">DATE OF REQUEST</div><div>${esc(rec.date_of_request)||'—'}</div></div>
      <div class="col-4"><div class="text-muted" style="font-size:.7rem">DEPARTMENT</div><div>${esc(rec.department)||'—'}</div></div>
      <div class="col-4"><div class="text-muted" style="font-size:.7rem">CATEGORY</div><div>${esc(rec.category)||'—'}${rec.category==='Other' && rec.category_other ? ' ('+esc(rec.category_other)+')' : ''}</div></div>
      <div class="col-4"><div class="text-muted" style="font-size:.7rem">CAPEX/OPEX</div><div>${esc(rec.capex_opex)||'—'}</div></div>
      <div class="col-4"><div class="text-muted" style="font-size:.7rem">PRIORITY</div><div>${esc(rec.priority)||'—'}</div></div>
      <div class="col-4"><div class="text-muted" style="font-size:.7rem">CUSTOMER</div><div>${esc(rec.customer_name)||'—'} ${rec.customer_user_id?'('+esc(rec.customer_user_id)+')':''}</div></div>
      <div class="col-4"><div class="text-muted" style="font-size:.7rem">LOCATION / POP</div><div>${esc(rec.location)||'—'} ${rec.hub_name?'/ '+esc(rec.hub_name):''}</div></div>
      <div class="col-4"><div class="text-muted" style="font-size:.7rem">RECEIVER</div><div>${esc(rec.receiver)||'—'}</div></div>
      ${rec.vendor_name ? `<div class="col-4"><div class="text-muted" style="font-size:.7rem">VENDOR</div><div>${esc(rec.vendor_name)}</div></div>` : ''}
      ${rec.linked_label ? `<div class="col-4"><div class="text-muted" style="font-size:.7rem">LINKED TO</div><div>${esc(rec.linked_label)}</div></div>` : ''}
    </div>
    <div class="mb-2"><div class="text-muted" style="font-size:.7rem">REASON / DESCRIPTION</div><div>${esc(rec.description)||'—'}</div></div>
    <table class="table table-sm mb-1"><thead><tr><th>Description</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Total</th></tr></thead><tbody>${itemsRows}</tbody></table>
    <div class="mb-2"><div class="text-muted" style="font-size:.7rem">BACKING DOCUMENTS</div>${docsHtml}</div>
  `;

  const hist = rec.payments || [];
  document.getElementById('pmHistoryWrap').classList.toggle('d-none', hist.length === 0);
  document.getElementById('pmHistoryBody').innerHTML = hist.map(p =>
    `<tr><td class="small">${esc(p.paid_at)}</td><td class="small">${esc(p.paid_by_name)}</td><td class="small">${esc(p.note||p.payment_reference)}</td><td class="text-end fw-semibold">₦${fmtMoney(p.amount)}</td></tr>`
  ).join('');

  document.getElementById('pmTotal').textContent = fmtMoney(rec.amount);
  document.getElementById('pmPaidSoFar').textContent = fmtMoney(rec.amount_paid);
  document.getElementById('pmBalance').textContent = fmtMoney(rec.balance);
  document.getElementById('pmAmount').value = rec.balance > 0 ? rec.balance.toFixed(2) : '';
  document.getElementById('pmAmount').max = rec.balance;

  paidModalEl().show();
}
function fillFullBalance(){
  const id = document.getElementById('pmId').value;
  const rec = DISBURSE_DATA[id];
  if (rec) document.getElementById('pmAmount').value = rec.balance.toFixed(2);
}
async function confirmMarkDisbursed(){
  const id=document.getElementById('pmId').value,amt=document.getElementById('pmAmount').value,ref=document.getElementById('pmRef').value.trim(),note=document.getElementById('pmNote').value.trim(),err=document.getElementById('pmErr');
  const fd=new FormData();fd.append('ajax','1');fd.append('action','disburse');fd.append('req_id',id);fd.append('pay_amount',amt);fd.append('payment_reference',ref);fd.append('payment_note',note);
  const d=await(await fetch('',{method:'POST',body:fd})).json();
  if(!d.ok){err.textContent=d.msg||'Error';err.classList.remove('d-none');return;}
  paidModalEl().hide();
  location.reload();
}
</script>
<?php endif; ?>

<?php if ($canCreate): ?>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
let linkInstallTS = null, linkTicketTS = null;
const RESUBMIT_DATA = <?= json_encode($resubmitData, JSON_HEX_TAG) ?>;
let customerIdsTS = null;
document.addEventListener('DOMContentLoaded', function () {
  linkInstallTS = new TomSelect('#linkInstallSelect', { create:false, sortField:{field:'text',direction:'asc'} });
  linkTicketTS  = new TomSelect('#linkTicketSelect',  { create:false, sortField:{field:'text',direction:'asc'} });
  customerIdsTS = new TomSelect('#prCustomerIds', { plugins:['remove_button'], sortField:{field:'text',direction:'asc'} });
  <?php if ($err): ?>
  bootstrap.Modal.getOrCreateInstance(document.getElementById('newRequestModal')).show();
  <?php endif; ?>
});
// Types whose customer picker is required / hidden — kept in sync with
// PR_CUSTOMER_REQUIRED_TYPES / PR_CUSTOMER_HIDDEN_TYPES in payment-requests.php.
const PR_CUSTOMER_REQUIRED_TYPES = <?= json_encode(PR_CUSTOMER_REQUIRED_TYPES) ?>;
const PR_CUSTOMER_HIDDEN_TYPES   = <?= json_encode(PR_CUSTOMER_HIDDEN_TYPES) ?>;
function toggleRequestType(v) {
  const showFields = !PR_CUSTOMER_HIDDEN_TYPES.includes(v);
  const required   = PR_CUSTOMER_REQUIRED_TYPES.includes(v);
  document.getElementById('prCustomersWrap').classList.toggle('d-none', !showFields);
  document.getElementById('prCustomersReq').classList.toggle('d-none', !required);
  if (!showFields && customerIdsTS) customerIdsTS.clear();
}
function resetForCreate() {
  document.getElementById('prForm').reset();
  document.getElementById('prFormAction').value = 'create';
  document.getElementById('prReqId').value = '';
  document.getElementById('prModalTitle').innerHTML = '<i class="bi bi-cash-coin me-1 text-primary"></i>New Payment Request Voucher';
  document.getElementById('prResubmitNotice').classList.add('d-none');
  document.getElementById('prCategoryOtherWrap').classList.add('d-none');
  if (customerIdsTS) customerIdsTS.clear();
  toggleRequestType('');
  document.getElementById('docsInput').required = true;
  document.getElementById('docsExistingNote').textContent = '';
  // Collapse the item breakdown back to a single blank row.
  const tbody = document.getElementById('itemsBody');
  tbody.innerHTML = '';
  addItemRow();
  toggleLinkPicker('');
  recalcItems();
}
function openResubmit(id) {
  const rec = RESUBMIT_DATA[id];
  if (!rec) return;
  resetForCreate();
  document.getElementById('prFormAction').value = 'resubmit';
  document.getElementById('prReqId').value = id;
  document.getElementById('prModalTitle').innerHTML = '<i class="bi bi-pencil-square me-1 text-warning"></i>Edit &amp; Resubmit Payment Request';
  const notice = document.getElementById('prResubmitNotice');
  notice.textContent = (rec.returned_by_name || 'Finance') + ' returned this for edits: "' + (rec.return_notes || '') + '"';
  notice.classList.remove('d-none');

  document.getElementById('prDate').value = rec.date_of_request || '';
  document.getElementById('prDept').value = rec.department || '';
  document.getElementById('prRequestType').value = rec.request_type || '';
  toggleRequestType(rec.request_type || '');
  if (customerIdsTS) { customerIdsTS.clear(); (rec.customer_ids || []).forEach(id => customerIdsTS.addItem(id)); }
  document.getElementById('prLocation').value = rec.location || '';
  document.getElementById('prHubId').value = rec.hub_id || '';
  document.getElementById('prCategory').value = rec.category || '';
  document.getElementById('prCategoryOtherWrap').classList.toggle('d-none', rec.category !== 'Other');
  document.getElementById('prCategoryOther').value = rec.category_other || '';
  document.getElementById('prCapexOpex').value = rec.capex_opex || '';
  document.getElementById('prPriority').value = rec.priority || 'Medium';
  document.getElementById('prDescription').value = rec.description || '';
  document.getElementById('prReceiver').value = rec.receiver || '';
  const hasExistingDocs = (rec.doc_count || 0) > 0;
  document.getElementById('docsInput').required = !hasExistingDocs;
  document.getElementById('docsExistingNote').textContent = hasExistingDocs
    ? rec.doc_count + ' document(s) already attached — only upload here if adding more.' : '';

  if (rec.linked_type) {
    document.getElementById('linkType').value = rec.linked_type;
    toggleLinkPicker(rec.linked_type);
    const ts = rec.linked_type === 'installation' ? linkInstallTS : linkTicketTS;
    if (ts) ts.setValue(rec.linked_id || '');
  }

  const tbody = document.getElementById('itemsBody');
  tbody.innerHTML = '';
  const items = (rec.items && rec.items.length) ? rec.items : [{description:'',qty:1,unit_price:0}];
  items.forEach(it => addItemRow(it));
  recalcItems();

  bootstrap.Modal.getOrCreateInstance(document.getElementById('newRequestModal')).show();
}
function toggleLinkPicker(v) {
  // Both selects share name="linked_id" — only the visible one should be
  // submitted, so disable the hidden one (disabled inputs are excluded from
  // form submission) or it would silently overwrite the chosen value.
  document.getElementById('linkInstallWrap').classList.toggle('d-none', v !== 'installation');
  document.getElementById('linkInstallSelect').disabled = v !== 'installation';
  document.getElementById('linkTicketWrap').classList.toggle('d-none', v !== 'ticket');
  document.getElementById('linkTicketSelect').disabled = v !== 'ticket';
}
function checkDocsCount(input) {
  const warn = document.getElementById('docsCountWarn');
  const tooMany = input.files.length > 5;
  warn.classList.toggle('d-none', !tooMany);
  input.classList.toggle('is-invalid', tooMany);
}
var PR_CITY_HUB_MAP = <?= json_encode(array_column(dbFetchAll("SELECT LOWER(TRIM(city_name)) AS city_name, hub_id FROM hub_city_mappings"), 'hub_id', 'city_name')) ?>;
function autoSelectHub(cityValue, selectId) {
  var hubId = PR_CITY_HUB_MAP[(cityValue || '').trim().toLowerCase()];
  if (hubId) document.getElementById(selectId).value = hubId;
}

// ── Line-item breakdown ──────────────────────────────────────────────────
function addItemRow(prefill) {
  const tbody = document.getElementById('itemsBody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input type="text" name="item_description[]" class="form-control form-control-sm"></td>
    <td><input type="number" step="0.01" min="0" name="item_qty[]" class="form-control form-control-sm item-qty" value="1" oninput="recalcItems()"></td>
    <td><input type="number" step="0.01" min="0" name="item_unit_price[]" class="form-control form-control-sm item-price" oninput="recalcItems()"></td>
    <td class="text-end small item-total pt-2">0.00</td>
    <td><button type="button" class="btn btn-sm btn-outline-danger py-0" onclick="removeItemRow(this)"><i class="bi bi-x"></i></button></td>`;
  if (prefill) {
    tr.querySelector('[name="item_description[]"]').value = prefill.description || '';
    tr.querySelector('.item-qty').value = prefill.qty || 1;
    tr.querySelector('.item-price').value = prefill.unit_price || 0;
  }
  tbody.appendChild(tr);
}
function removeItemRow(btn) {
  const tbody = document.getElementById('itemsBody');
  if (tbody.rows.length > 1) btn.closest('tr').remove();
  recalcItems();
}
function recalcItems() {
  let grand = 0;
  document.querySelectorAll('#itemsBody tr').forEach(function (tr) {
    const qty = parseFloat(tr.querySelector('.item-qty')?.value) || 0;
    const price = parseFloat(tr.querySelector('.item-price')?.value) || 0;
    const total = qty * price;
    tr.querySelector('.item-total').textContent = total.toFixed(2);
    grand += total;
  });
  document.getElementById('grandTotalDisplay').textContent = grand.toFixed(2);
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
