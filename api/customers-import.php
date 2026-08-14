<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit; }

// A stray PHP warning/notice printed before json_encode() (e.g. from a malformed
// CSV row) corrupts the response and breaks the client's r.json() parse, which
// then falls through to a generic "Upload failed" alert with no detail. Suppress
// display and catch everything so we always return valid, informative JSON.
ini_set('display_errors', '0');
header('Content-Type: application/json');

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']); exit;
}

$file = $_FILES['file'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'No file uploaded or upload error']); exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

$allowedMimes = ['text/plain', 'text/csv', 'application/csv', 'application/json',
                 'application/vnd.ms-excel', 'text/x-csv', 'text/comma-separated-values'];
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);
if (!in_array($mimeType, $allowedMimes, true) && !in_array($ext, ['csv', 'json'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid file type. Only CSV or JSON files are accepted.']); exit;
}
if (!in_array($ext, ['csv', 'json'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Only .csv and .json files are allowed.']); exit;
}

/** array_search() returns false (not null) when not found — normalize so a
 *  missing header can't silently alias to index 0 via `$idx ?? -1`. */
function headerIndex(array $headers, string ...$names): ?int {
    foreach ($names as $n) {
        $i = array_search($n, $headers, true);
        if ($i !== false) return $i;
    }
    return null;
}
function cell(array $row, ?int $idx): string {
    if ($idx === null || !array_key_exists($idx, $row)) return '';
    return trim((string)$row[$idx]);
}

$inserted = 0; $updated = 0; $skipped = 0; $errors = [];

try {
    if ($ext === 'csv') {
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) throw new Exception('Cannot read file');

        $headers = fgetcsv($handle);
        if (!$headers) throw new Exception('Empty CSV file');
        // Strip a UTF-8 BOM if present (common in spreadsheet-exported CSVs) —
        // otherwise the first header ("account_number") never matches.
        if (isset($headers[0])) $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        $headers = array_map('trim', array_map('strtolower', $headers));

        $nameIdx   = headerIndex($headers, 'name');
        $fnIdx     = headerIndex($headers, 'first_name');
        $lnIdx     = headerIndex($headers, 'last_name');
        $acctIdx   = headerIndex($headers, 'account_number');
        $emailIdx  = headerIndex($headers, 'email');
        $phoneIdx  = headerIndex($headers, 'phone');
        $addrIdx   = headerIndex($headers, 'address');
        $cityIdx   = headerIndex($headers, 'mailing_city', 'city');
        $stateIdx  = headerIndex($headers, 'mailing_state', 'state');
        $planIdx   = headerIndex($headers, 'plan', 'service');
        $statusIdx = headerIndex($headers, 'status');
        $expIdx    = headerIndex($headers, 'expiration');

        if ($acctIdx === null) {
            throw new Exception("CSV is missing the required 'account_number' column. Found columns: " . implode(', ', $headers));
        }

        $row = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $row++;
            // Skip fully blank lines (common trailing rows from spreadsheet exports)
            if ($data === [null] || (count($data) === 1 && trim((string)($data[0] ?? '')) === '')) continue;

            try {
                $fn     = cell($data, $fnIdx);
                $ln     = cell($data, $lnIdx);
                $name   = $nameIdx !== null ? cell($data, $nameIdx) : trim("$fn $ln");
                if (!$name && $fn) $name = trim("$fn $ln");
                $acct   = cell($data, $acctIdx) ?: null;
                $email  = cell($data, $emailIdx) ?: null;
                $phone  = cell($data, $phoneIdx) ?: null;
                $addr   = cell($data, $addrIdx) ?: null;
                $city   = cell($data, $cityIdx) ?: null;
                $state  = cell($data, $stateIdx) ?: null;
                $plan   = cell($data, $planIdx) ?: null;
                $status = cell($data, $statusIdx) ?: 'active';
                $exp    = cell($data, $expIdx) ?: null;

                if (!$name) { $errors[] = "Row {$row}: skipped — Name is required."; $skipped++; continue; }
                if (!$acct) { $errors[] = "Row {$row}: skipped — Account # is required."; $skipped++; continue; }
                if (!in_array($status, ['active','suspended','inactive'], true)) $status = 'active';

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
            } catch (\Throwable $rowErr) {
                // One bad row shouldn't abort the whole batch.
                $errors[] = "Row {$row}: error — " . $rowErr->getMessage();
                $skipped++;
            }
        }
        fclose($handle);

    } elseif ($ext === 'json') {
        $content = file_get_contents($file['tmp_name']);
        $records = json_decode($content, true);
        if (!is_array($records)) throw new Exception('Invalid JSON format — expected an array of objects');

        foreach ($records as $i => $r) {
            try {
                $name   = trim($r['name'] ?? '');
                $acct   = trim($r['account_number'] ?? '') ?: null;
                $email  = trim($r['email'] ?? '') ?: null;
                $phone  = trim($r['phone'] ?? '') ?: null;
                $addr   = trim($r['address'] ?? '') ?: null;
                $plan   = trim($r['plan'] ?? '') ?: null;
                $status = trim($r['status'] ?? 'active');

                if (!$name) { $errors[] = "Record " . ($i+1) . ": skipped — Name is required."; $skipped++; continue; }
                if (!$acct) { $errors[] = "Record " . ($i+1) . ": skipped — Account # is required."; $skipped++; continue; }
                if (!in_array($status, ['active','suspended','inactive'], true)) $status = 'active';

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
            } catch (\Throwable $rowErr) {
                $errors[] = "Record " . ($i+1) . ": error — " . $rowErr->getMessage();
                $skipped++;
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

} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
