<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (method() === 'POST') verifyCsrf();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── GET: ONU list for polling status endpoint ─────────────────────────────
if (method() === 'GET' && $action === 'onu_counts') {
    requirePermission('noc.view');
    $counts = dbFetchAll(
        "SELECT status, COUNT(*) AS cnt FROM onu_units
         JOIN network_devices nd ON nd.id = onu_units.olt_device_id
         GROUP BY status"
    );
    jsonResponse(['counts' => array_column($counts, 'cnt', 'status')]);
}

// ── POST actions ──────────────────────────────────────────────────────────
requirePermission('noc.view');

// save_device — create or update a network device
if ($action === 'save_device') {
    requirePermission('noc.devices.manage');
    $deviceId  = trim($_POST['device_id'] ?? '');
    $hubId     = trim($_POST['hub_id']     ?? '');
    $name      = trim($_POST['name']       ?? '');
    $type      = trim($_POST['device_type'] ?? 'olt');
    $ip        = trim($_POST['ip_address'] ?? '');
    $protocol  = $type === 'mikrotik' ? 'routeros_api' : 'snmp';
    $snmpVer   = trim($_POST['snmp_version'] ?? '2c');
    $apiPort   = (int)($_POST['api_port'] ?? 8728);
    $enabled   = isset($_POST['enabled']) ? 1 : 0;

    if (!$hubId || !$name || !$ip) jsonResponse(['error' => 'Hub, name, and IP are required'], 400);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) jsonResponse(['error' => 'Invalid IP address'], 400);

    // Encrypt credentials only if provided (blank = keep existing on edit)
    $snmpEnc = null; $apiCredsEnc = null;
    if ($type !== 'mikrotik') {
        $community = trim($_POST['snmp_community'] ?? '');
        if ($community) $snmpEnc = nocEncrypt($community);
    } else {
        $apiUser = trim($_POST['api_user'] ?? '');
        $apiPass = $_POST['api_pass'] ?? '';
        if ($apiUser || $apiPass) {
            $apiCredsEnc = nocEncrypt(json_encode(['user' => $apiUser, 'pass' => $apiPass]));
        }
    }

    if ($deviceId) {
        // Edit existing
        $existing = dbFetch("SELECT * FROM network_devices WHERE id = ?", [$deviceId]);
        if (!$existing) jsonResponse(['error' => 'Device not found'], 404);
        dbRun(
            "UPDATE network_devices SET hub_id=?, name=?, device_type=?, ip_address=?, protocol=?,
             snmp_version=?, api_port=?, enabled=?, updated_at=NOW()
             " . ($snmpEnc    !== null ? ", snmp_community=?"     : '') .
             "  " . ($apiCredsEnc !== null ? ", api_credentials=?" : '') .
             " WHERE id=?",
            array_filter(
                [$hubId, $name, $type, $ip, $protocol, $snmpVer, $apiPort, $enabled,
                 ...($snmpEnc    !== null ? [$snmpEnc]    : []),
                 ...($apiCredsEnc !== null ? [$apiCredsEnc] : []),
                 $deviceId],
                fn($v) => $v !== null
            )
        );
        auditLog('update', 'network_device', $deviceId);
        jsonResponse(['ok' => true]);
    } else {
        // Create new
        $id = newUuid();
        dbRun(
            "INSERT INTO network_devices (id,hub_id,name,device_type,ip_address,protocol,snmp_community,snmp_version,api_credentials,api_port,enabled,created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            [$id, $hubId, $name, $type, $ip, $protocol, $snmpEnc, $snmpVer, $apiCredsEnc, $apiPort, $enabled, currentUser()['id']]
        );
        auditLog('create', 'network_device', $id);
        jsonResponse(['ok' => true, 'id' => $id]);
    }
}

// delete_device — remove device + all ONU units
if ($action === 'delete_device') {
    requirePermission('noc.devices.manage');
    $deviceId = trim($_POST['device_id'] ?? '');
    if (!$deviceId) jsonResponse(['error' => 'device_id required'], 400);
    $d = dbFetch("SELECT id FROM network_devices WHERE id = ?", [$deviceId]);
    if (!$d) jsonResponse(['error' => 'Not found'], 404);
    // Delete ONU events first (FK cascade not guaranteed)
    dbRun("DELETE e FROM onu_status_events e JOIN onu_units o ON o.id = e.onu_id WHERE o.olt_device_id = ?", [$deviceId]);
    dbRun("DELETE FROM onu_units WHERE olt_device_id = ?", [$deviceId]);
    dbRun("DELETE FROM network_devices WHERE id = ?", [$deviceId]);
    auditLog('delete', 'network_device', $deviceId);
    jsonResponse(['ok' => true]);
}

// test_device — attempt a live connection to verify reachability
if ($action === 'test_device') {
    requirePermission('noc.devices.manage');
    $deviceId = trim($_POST['device_id'] ?? '');
    $d = dbFetch("SELECT * FROM network_devices WHERE id = ?", [$deviceId]);
    if (!$d) jsonResponse(['error' => 'Device not found'], 404);

    if ($d['protocol'] === 'routeros_api') {
        // RouterOS API ping — attempt login and immediately logout
        $creds = $d['api_credentials'] ? json_decode(nocDecrypt($d['api_credentials']), true) : [];
        $user  = $creds['user'] ?? 'admin';
        $pass  = $creds['pass'] ?? '';
        [$ok, $msg] = testMikrotikConnection($d['ip_address'], (int)$d['api_port'], $user, $pass);
    } else {
        // SNMP — read sysDescr OID
        $community = $d['snmp_community'] ? nocDecrypt($d['snmp_community']) : 'public';
        [$ok, $msg] = testSnmpConnection($d['ip_address'], $community, $d['snmp_version']);
    }

    // Update last_seen_at / poll_error regardless
    if ($ok) {
        dbRun("UPDATE network_devices SET last_seen_at=NOW(), poll_error=NULL WHERE id=?", [$deviceId]);
    } else {
        dbRun("UPDATE network_devices SET poll_error=? WHERE id=?", [substr($msg, 0, 255), $deviceId]);
    }

    jsonResponse($ok ? ['ok' => true, 'message' => $msg] : ['ok' => false, 'error' => $msg]);
}

// seed_onu — manual ONU entry for testing (admin only)
if ($action === 'seed_onu') {
    requirePermission('noc.devices.manage');
    $oltId  = trim($_POST['olt_id']       ?? '');
    $serial = trim($_POST['serial']       ?? '');
    $port   = trim($_POST['olt_port']     ?? '');
    $custId = trim($_POST['customer_id']  ?? '') ?: null;
    if (!$oltId || !$serial) jsonResponse(['error' => 'olt_id and serial required'], 400);
    $olt = dbFetch("SELECT id FROM network_devices WHERE id=? AND device_type='olt'", [$oltId]);
    if (!$olt) jsonResponse(['error' => 'OLT not found'], 404);
    // Upsert on serial
    $existing = dbFetch("SELECT id FROM onu_units WHERE serial_number=?", [$serial]);
    if ($existing) {
        dbRun("UPDATE onu_units SET olt_device_id=?,customer_id=?,olt_port=?,updated_at=NOW() WHERE id=?",
              [$oltId, $custId, $port ?: null, $existing['id']]);
        jsonResponse(['ok' => true, 'id' => $existing['id']]);
    } else {
        $id = newUuid();
        dbRun("INSERT INTO onu_units (id,olt_device_id,customer_id,serial_number,olt_port,status) VALUES (?,?,?,?,?,'unknown')",
              [$id, $oltId, $custId, $serial, $port ?: null]);
        auditLog('create', 'onu_unit', $id);
        jsonResponse(['ok' => true, 'id' => $id]);
    }
}

jsonResponse(['error' => 'Unknown action'], 400);

// ─── Connection test helpers ──────────────────────────────────────────────────
require_once __DIR__ . '/../includes/mikrotik-api.php';

function testSnmpConnection(string $ip, string $community, string $version): array {
    if (!extension_loaded('snmp')) {
        return [false, 'PHP SNMP extension not loaded on this server'];
    }
    $sysDescr = '.1.3.6.1.2.1.1.1.0';
    try {
        if ($version === '1') {
            $result = @snmpget($ip, $community, $sysDescr, 3000000, 1);
        } else {
            $result = @snmp2_get($ip, $community, $sysDescr, 3000000, 1);
        }
        if ($result === false) {
            return [false, 'SNMP unreachable or community string rejected (timeout 3s)'];
        }
        $desc = preg_replace('/^STRING:\s*/', '', $result);
        return [true, 'SNMP OK — ' . substr($desc, 0, 80)];
    } catch (\Throwable $e) {
        return [false, $e->getMessage()];
    }
}

function testMikrotikConnection(string $ip, int $port, string $user, string $pass): array {
    try {
        $sock = @fsockopen($ip, $port, $errno, $errstr, 3);
        if (!$sock) return [false, "TCP connect failed ($errno: $errstr)"];
        // Minimal RouterOS API login to verify credentials
        $client = new MikrotikApiClient($sock);
        $resp   = $client->login($user, $pass);
        fclose($sock);
        if ($resp) return [true, "RouterOS API authenticated as '$user'"];
        return [false, 'Authentication failed — check username/password'];
    } catch (\Throwable $e) {
        return [false, $e->getMessage()];
    }
}
