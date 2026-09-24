#!/usr/bin/env php
<?php
/**
 * OLT SNMP Poller — cron job (every 2 min recommended)
 * Polls all enabled OLT devices, updates onu_units status,
 * records state-change events, and auto-creates fault tickets.
 *
 * Crontab example:
 *   * /2 * * * * php /home/user/fieldpulse/scripts/poll-olt.php >> /tmp/olt-poll.log 2>&1
 */

define('CLI_CONTEXT', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/mikrotik-api.php';

if (!extension_loaded('snmp')) {
    echo "[" . date('Y-m-d H:i:s') . "] FATAL: PHP SNMP extension not loaded. Install php-snmp.\n";
    exit(1);
}

// Suppress SNMP library errors — we handle them explicitly
snmp_set_quick_print(true);
snmp_set_valueretrieval(SNMP_VALUE_PLAIN);

$olts = dbFetchAll(
    "SELECT * FROM network_devices WHERE device_type = 'olt' AND enabled = 1"
);
if (!$olts) {
    echo "[" . date('Y-m-d H:i:s') . "] No enabled OLT devices found.\n";
    exit(0);
}

$totalPolled = 0; $totalFaults = 0; $totalTickets = 0;

foreach ($olts as $olt) {
    echo "[" . date('H:i:s') . "] Polling OLT: {$olt['name']} ({$olt['ip_address']})\n";
    $community = $olt['snmp_community'] ? nocDecrypt($olt['snmp_community']) : 'public';
    $version   = $olt['snmp_version'] ?? '2c';

    $snmpPort = (int)($olt['snmp_port'] ?? 161);
    try {
        $onuData = fetchHuaweiGponOnuStatus($olt['ip_address'], $community, $version, $snmpPort);
    } catch (\Throwable $e) {
        $err = 'Poll error: ' . $e->getMessage();
        dbRun("UPDATE network_devices SET poll_error=?, last_polled_at=NOW() WHERE id=?",
              [substr($err, 0, 255), $olt['id']]);
        echo "[!] {$olt['name']}: $err\n";
        continue;
    }

    dbRun("UPDATE network_devices SET last_polled_at=NOW(), last_seen_at=NOW(), poll_error=NULL WHERE id=?",
          [$olt['id']]);

    foreach ($onuData as $onuIndex => $data) {
        $serial   = $data['serial']   ?? '';
        $newStatus = mapOnuStatus($data['status_code'] ?? 0);
        $rxPower  = $data['rx_power'] ?? null;

        if (!$serial) continue;

        // Load or create ONU record
        $existing = dbFetch("SELECT * FROM onu_units WHERE serial_number = ?", [$serial]);
        if (!$existing) {
            $onuId = newUuid();
            dbRun(
                "INSERT INTO onu_units (id,olt_device_id,serial_number,olt_port,onu_index,status,rx_power_dbm,last_polled_at)
                 VALUES (?,?,?,?,?,?,?,NOW())",
                [$onuId, $olt['id'], $serial, $data['port'] ?? null, $onuIndex, $newStatus, $rxPower]
            );
            $totalPolled++;
            continue;
        }

        $onuId     = $existing['id'];
        $prevStatus = $existing['status'];

        // Build UPDATE fields
        $updateFields = "status=?, rx_power_dbm=?, last_polled_at=NOW()";
        $updateParams = [$newStatus, $rxPower];
        if ($newStatus === 'working') {
            $updateFields .= ", last_online_at=NOW()";
        }
        $updateParams[] = $onuId;
        dbRun("UPDATE onu_units SET $updateFields WHERE id=?", $updateParams);

        // Record state change event
        if ($prevStatus !== $newStatus) {
            $eventId = null;
            $st = dbRun(
                "INSERT INTO onu_status_events (onu_id, from_status, to_status, detected_at) VALUES (?,?,?,NOW())",
                [$onuId, $prevStatus, $newStatus]
            );
            $eventId = db()->lastInsertId();

            if ($newStatus !== 'working') $totalFaults++;

            // Auto-create ticket when ONU goes into a fault state
            if (in_array($newStatus, ['offline', 'los', 'lof', 'dying_gasp'], true)
                && !in_array($prevStatus, ['offline', 'los', 'lof', 'dying_gasp'], true)
            ) {
                $ticketId = autoCreateFaultTicket($onuId, $olt, $data, $newStatus);
                if ($ticketId) {
                    dbRun("UPDATE onu_status_events SET auto_ticket_id=? WHERE id=?",
                          [$ticketId, $eventId]);
                    $totalTickets++;
                }
            }
        }
        $totalPolled++;
    }
}

echo "[" . date('H:i:s') . "] Done. Polled=$totalPolled, Faults=$totalFaults, TicketsCreated=$totalTickets\n";

// ─── Huawei GPON SNMP ─────────────────────────────────────────────────────────
/**
 * Returns array keyed by ONU index (onu_index):
 *   ['serial' => '...', 'status_code' => int, 'port' => '0/1/2', 'rx_power' => float|null]
 *
 * Huawei MA5600T/MA5800 GPON ONU MIB OIDs (hwGponDeviceMib):
 *   hwGponOntStateTable:    1.3.6.1.4.1.2011.6.128.1.1.2.46 (ONU run state)
 *   hwGponOntSn:            1.3.6.1.4.1.2011.6.128.1.1.2.43 (serial number)
 *   hwGponOptRxPower:       1.3.6.1.4.1.2011.6.128.1.1.2.51 (Rx power, × 0.01 dBm)
 */
function fetchHuaweiGponOnuStatus(string $ip, string $community, string $version, int $port = 161): array {
    // ONU run state: 1=initial,2=working,3=dying-gasp,4=auth-failed,5=offline,6=los,7=lof
    $stateOid  = '1.3.6.1.4.1.2011.6.128.1.1.2.46';
    $serialOid = '1.3.6.1.4.1.2011.6.128.1.1.2.43';
    $rxOid     = '1.3.6.1.4.1.2011.6.128.1.1.2.51';

    // PHP snmp functions accept "host:port" when port is non-standard
    $host = $port !== 161 ? "$ip:$port" : $ip;
    $states  = snmpWalkOid($host, $community, $version, $stateOid);
    $serials = snmpWalkOid($host, $community, $version, $serialOid);
    $rxPow   = snmpWalkOid($host, $community, $version, $rxOid);

    $result = [];
    foreach ($states as $oidSuffix => $stateVal) {
        // OID suffix encodes slot.port.onuId — e.g. .0.1.3 → port 0/1, onu 3
        $parts = explode('.', ltrim($oidSuffix, '.'));
        $portStr = implode('/', array_slice($parts, 0, 2));

        $serial  = $serials[$oidSuffix] ?? '';
        // Serial comes as hex string from Huawei — decode to printable if hex
        if ($serial && ctype_xdigit(str_replace(' ', '', $serial))) {
            $serial = strtoupper(preg_replace('/\s+/', '', $serial));
        }

        $rxRaw   = $rxPow[$oidSuffix]  ?? null;
        $rxPower = $rxRaw !== null ? round((float)$rxRaw / 100, 2) : null;

        $result[$oidSuffix] = [
            'serial'      => $serial,
            'status_code' => (int)$stateVal,
            'port'        => $portStr,
            'rx_power'    => $rxPower,
        ];
    }
    return $result;
}

/** Walk an OID table. Returns ['.suffix' => value]. */
function snmpWalkOid(string $ip, string $community, string $version, string $oid): array {
    if ($version === '1') {
        $raw = @snmprealwalk($ip, $community, $oid, 5000000, 2);
    } else {
        $raw = @snmp2_real_walk($ip, $community, $oid, 5000000, 2);
    }
    if (!is_array($raw)) return [];
    $result = [];
    foreach ($raw as $fullOid => $val) {
        // Strip base OID prefix to get the suffix (row index)
        $suffix = substr($fullOid, strlen($oid));
        $result[$suffix] = $val;
    }
    return $result;
}

/** Map Huawei ONU run-state integer to our ENUM value. */
function mapOnuStatus(int $code): string {
    return match($code) {
        2       => 'working',
        3       => 'dying_gasp',
        5       => 'offline',
        6       => 'los',
        7       => 'lof',
        default => 'unknown',
    };
}

// ─── Auto-ticket creation ─────────────────────────────────────────────────────
function autoCreateFaultTicket(string $onuId, array $olt, array $onuData, string $faultStatus): ?string {
    $onu = dbFetch("SELECT * FROM onu_units WHERE id = ?", [$onuId]);
    if (!$onu) return null;

    $customerId   = $onu['customer_id'];
    $customerName = null;
    if ($customerId) {
        $cust = dbFetch("SELECT name, account_number FROM customers WHERE id = ?", [$customerId]);
        $customerName = $cust ? $cust['name'] : null;
    }

    // Don't duplicate: check for an existing open/in-progress ticket for this ONU
    $existing = dbFetch(
        "SELECT id FROM tickets WHERE olt = ? AND status NOT IN ('closed','resolved') LIMIT 1",
        [$onu['serial_number']]
    );
    if ($existing) return null;

    $statusLabel = [
        'offline'     => 'Offline',
        'los'         => 'Loss of Signal (LOS)',
        'lof'         => 'Loss of Frame (LOF)',
        'dying_gasp'  => 'Dying Gasp (power loss at subscriber)',
    ][$faultStatus] ?? strtoupper($faultStatus);

    $port       = $onu['olt_port'] ?? 'unknown';
    $rxPower    = $onu['rx_power_dbm'] !== null ? $onu['rx_power_dbm'] . ' dBm' : 'N/A';
    $description = "AUTO-DETECTED ONU FAULT\n"
        . "ONU Serial:  {$onu['serial_number']}\n"
        . "Status:      $statusLabel\n"
        . "OLT:         {$olt['name']} ({$olt['ip_address']})\n"
        . "OLT Port:    $port\n"
        . "Rx Power:    $rxPower\n"
        . "Detected:    " . date('Y-m-d H:i:s') . "\n"
        . ($customerName ? "Customer:    $customerName\n" : "Customer:    Unregistered ONU\n");

    // Look up a NOC fault type
    $faultType = dbFetch(
        "SELECT id FROM fault_types WHERE LOWER(name) LIKE '%network%' OR LOWER(name) LIKE '%noc%' OR LOWER(category) LIKE '%noc%' LIMIT 1"
    );

    // Assign to NOC supervisor of the hub's team
    $assignTo = null;
    $hubId    = $olt['hub_id'] ?? null;
    if ($hubId) {
        $nocSup = dbFetch(
            "SELECT u.id FROM users u
             JOIN hubs h ON h.team_id = u.hub_id
             WHERE u.role = 'supervisor-noc' AND h.id = ?
             LIMIT 1",
            [$hubId]
        );
        $assignTo = $nocSup['id'] ?? null;
        if (!$assignTo) {
            // Fall back to any noc_engineer at this hub
            $eng = dbFetch("SELECT u.id FROM users u JOIN hubs h ON h.id=? WHERE u.role='noc_engineer' LIMIT 1", [$hubId]);
            $assignTo = $eng['id'] ?? null;
        }
    }

    $slaHours = 4; // Default SLA for NOC faults
    $_slaExpr = dbNowPlusInterval($slaHours, 'HOUR');
    $priority = in_array($faultStatus, ['los', 'lof'], true) ? 'p1' : 'p2';

    $newId    = newUuid();
    $ticketNum = withUniqueTicketNumber('INC', function(string $ticketNum) use (
        $newId, $description, $priority, $customerId, $customerName,
        $hubId, $assignTo, $faultType, $onu, $_slaExpr
    ) {
        dbRun(
            "INSERT INTO tickets
             (id,ticket_number,description,priority,type,status,ticket_scope,customer_id,customer_name,
              hub_id,assigned_to,fault_type_id,olt,created_by,sla_breach_at)
             VALUES (?,?,?,?,'noc','open',?,?,?,?,?,?,?,NULL,{$_slaExpr})",
            [$newId, $ticketNum, $description, $priority,
             $customerId ? 'customer' : 'internal',
             $customerId, $customerName,
             $hubId, $assignTo,
             $faultType['id'] ?? null,
             $onu['serial_number']]
        );
    });

    echo "  → Auto-ticket {$ticketNum} created for ONU {$onu['serial_number']}\n";
    return $newId;
}
