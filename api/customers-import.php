<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit; }
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'POST required']); exit;
}

$file = $_FILES['file'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'No file uploaded or upload error']); exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

$allowedMimes = ['text/plain', 'text/csv', 'application/csv', 'application/json',
                 'application/vnd.ms-excel', 'text/x-csv', 'text/comma-separated-values'];
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);
if (!in_array($mimeType, $allowedMimes, true) && !in_array($ext, ['csv', 'json'], true)) {
    echo json_encode(['error' => 'Invalid file type. Only CSV or JSON files are accepted.']); exit;
}
if (!in_array($ext, ['csv', 'json'], true)) {
    echo json_encode(['error' => 'Only .csv and .json files are allowed.']); exit;
}

$inserted = 0; $updated = 0; $skipped = 0; $errors = [];

try {
    if ($ext === 'csv') {
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) throw new Exception('Cannot read file');

        $headers = fgetcsv($handle);
        if (!$headers) throw new Exception('Empty CSV file');
        $headers = array_map('trim', array_map('strtolower', $headers));

        $nameIdx   = array_search('name', $headers);
        $fnIdx     = array_search('first_name', $headers);
        $lnIdx     = array_search('last_name', $headers);
        $acctIdx   = array_search('account_number', $headers);
        $emailIdx  = array_search('email', $headers);
        $phoneIdx  = array_search('phone', $headers);
        $addrIdx   = array_search('address', $headers);
        $cityIdx   = array_search('mailing_city', $headers) !== false ? array_search('mailing_city', $headers) : array_search('city', $headers);
        $stateIdx  = array_search('mailing_state', $headers) !== false ? array_search('mailing_state', $headers) : array_search('state', $headers);
        $planIdx   = array_search('plan', $headers) !== false ? array_search('plan', $headers) : array_search('service', $headers);
        $statusIdx = array_search('status', $headers);
        $expIdx    = array_search('expiration', $headers);

        $row = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $row++;
            $fn     = trim($data[$fnIdx ?? -1] ?? '');
            $ln     = trim($data[$lnIdx ?? -1] ?? '');
            $name   = $nameIdx !== false ? trim($data[$nameIdx] ?? '') : trim("$fn $ln");
            if (!$name && $fn) $name = trim("$fn $ln");
            $acct   = trim($data[$acctIdx ?? -1] ?? '') ?: null;
            $email  = trim($data[$emailIdx ?? -1] ?? '') ?: null;
            $phone  = trim($data[$phoneIdx ?? -1] ?? '') ?: null;
            $addr   = trim($data[$addrIdx ?? -1] ?? '') ?: null;
            $city   = $cityIdx !== false ? trim($data[$cityIdx] ?? '') : null;
            $state  = $stateIdx !== false ? trim($data[$stateIdx] ?? '') : null;
            $plan   = $planIdx !== false ? trim($data[$planIdx] ?? '') : null;
            $status = trim($data[$statusIdx ?? -1] ?? '') ?: 'active';
            $exp    = $expIdx !== false ? (trim($data[$expIdx] ?? '') ?: null) : null;

            if (!$name) { $skipped++; continue; }
            if (!$acct) { $errors[] = "Row {$row}: skipped — Account # is required."; $skipped++; continue; }
            if (!in_array($status, ['active','suspended','inactive'])) $status = 'active';

            // Account number is the sole unique identifier
            $existing = dbFetch("SELECT id FROM customers WHERE account_number=?", [$acct]);

            if ($existing) {
                dbRun("UPDATE customers SET name=?,first_name=?,last_name=?,email=?,phone=?,address=?,mailing_city=?,mailing_state=?,plan=?,status=?,expiration=? WHERE id=?",
                    [$name,$fn,$ln,$email,$phone,$addr,$city,$state,$plan,$status,$exp,$existing['id']]);
                $updated++;
            } else {
                dbRun("INSERT INTO customers (id,name,first_name,last_name,account_number,email,phone,address,mailing_city,mailing_state,plan,status,expiration) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [newUuid(),$name,$fn,$ln,$acct,$email,$phone,$addr,$city,$state,$plan,$status,$exp]);
                $inserted++;
            }
        }
        fclose($handle);

    } elseif ($ext === 'json') {
        $content = file_get_contents($file['tmp_name']);
        $records = json_decode($content, true);
        if (!is_array($records)) throw new Exception('Invalid JSON format — expected an array of objects');

        foreach ($records as $i => $r) {
            $name   = trim($r['name'] ?? '');
            $acct   = trim($r['account_number'] ?? '') ?: null;
            $email  = trim($r['email'] ?? '') ?: null;
            $phone  = trim($r['phone'] ?? '') ?: null;
            $addr   = trim($r['address'] ?? '') ?: null;
            $plan   = trim($r['plan'] ?? '') ?: null;
            $status = trim($r['status'] ?? 'active');

            if (!$name) { $skipped++; continue; }
            if (!$acct) { $errors[] = "Record " . ($i+1) . ": skipped — Account # is required."; $skipped++; continue; }
            if (!in_array($status, ['active','suspended','inactive'])) $status = 'active';

            // Account number is the sole unique identifier
            $existing = dbFetch("SELECT id FROM customers WHERE account_number=?", [$acct]);

            if ($existing) {
                dbRun("UPDATE customers SET name=?,email=?,phone=?,address=?,plan=?,status=? WHERE id=?",
                    [$name,$email,$phone,$addr,$plan,$status,$existing['id']]);
                $updated++;
            } else {
                dbRun("INSERT INTO customers (id,name,account_number,email,phone,address,plan,status) VALUES (?,?,?,?,?,?,?,?)",
                    [newUuid(),$name,$acct,$email,$phone,$addr,$plan,$status]);
                $inserted++;
            }
        }
    } else {
        throw new Exception('Unsupported file type. Please upload a .csv or .json file.');
    }

    echo json_encode([
        'ok'       => true,
        'inserted' => $inserted,
        'updated'  => $updated,
        'skipped'  => $skipped,
        'errors'   => $errors,
    ]);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}