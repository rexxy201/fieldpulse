#!/usr/bin/env php
<?php
/**
 * Mikrotik RouterOS Poller — cron job (every 5 min recommended)
 * Collects active PPPoE sessions, CPU load, memory, and interface
 * throughput from each Mikrotik router, stores hub-level stats.
 *
 * Crontab example:
 *   * /5 * * * * php /home/user/fieldpulse/scripts/poll-mikrotik.php >> /tmp/mikrotik-poll.log 2>&1
 */

define('CLI_CONTEXT', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/mikrotik-api.php';

$routers = dbFetchAll(
    "SELECT * FROM network_devices WHERE device_type = 'mikrotik' AND enabled = 1"
);
if (!$routers) {
    echo "[" . date('Y-m-d H:i:s') . "] No enabled Mikrotik devices found.\n";
    exit(0);
}

foreach ($routers as $router) {
    echo "[" . date('H:i:s') . "] Polling Mikrotik: {$router['name']} ({$router['ip_address']})\n";

    $creds = $router['api_credentials'] ? json_decode(nocDecrypt($router['api_credentials']), true) : [];
    $user  = $creds['user'] ?? 'admin';
    $pass  = $creds['pass'] ?? '';
    $port  = (int)($router['api_port'] ?? 8728);

    $sock = @fsockopen($router['ip_address'], $port, $errno, $errstr, 5);
    if (!$sock) {
        $err = "TCP connect failed ($errno: $errstr)";
        dbRun("UPDATE network_devices SET poll_error=?, last_polled_at=NOW() WHERE id=?",
              [substr($err, 0, 255), $router['id']]);
        echo "  [!] $err\n";
        continue;
    }

    try {
        $api = new MikrotikApiClient($sock);
        if (!$api->login($user, $pass)) {
            throw new \RuntimeException('RouterOS API authentication failed');
        }

        // System resources
        $res = $api->command('/system/resource/print');
        $sysRes = $res[0] ?? [];

        $cpuLoad    = (int)($sysRes['cpu-load']      ?? 0);   // %
        $freeMemory = (int)($sysRes['free-memory']   ?? 0);   // bytes
        $totalMem   = (int)($sysRes['total-memory']  ?? 1);
        $uptime     = $sysRes['uptime']               ?? '';

        // Active PPPoE sessions
        $activeSessions = $api->command('/ppp/active/print');
        $sessionCount   = count($activeSessions);

        // Interface summary (only ether + eoip + vlan flagged as running)
        $interfaces = $api->command('/interface/print', ['where' => 'running=yes']);
        $ifCount    = count($interfaces);

        echo "  CPU: {$cpuLoad}% | Mem: " . round(($totalMem - $freeMemory) / 1048576) . "/" . round($totalMem/1048576) . " MB | PPPoE sessions: $sessionCount\n";

        // Store stats — we write to a lightweight hub_device_stats table if it exists,
        // otherwise just update the device's last_seen_at.
        // Hub_device_stats is optional Phase 2 data for the NOC dashboard charts.
        dbRun(
            "UPDATE network_devices SET last_seen_at=NOW(), last_polled_at=NOW(), poll_error=NULL WHERE id=?",
            [$router['id']]
        );

        // If hub_device_stats exists (Phase 2 migration), record the sample
        try {
            dbRun(
                "INSERT INTO hub_device_stats
                 (id,device_id,hub_id,cpu_load,mem_used_bytes,mem_total_bytes,pppoe_sessions,uptime_str,sampled_at)
                 VALUES (?,?,?,?,?,?,?,?,NOW())",
                [newUuid(), $router['id'], $router['hub_id'],
                 $cpuLoad, ($totalMem - $freeMemory), $totalMem,
                 $sessionCount, $uptime]
            );
        } catch (\PDOException $e) {
            // Table doesn't exist yet — silently skip (Phase 2 adds it)
            if (($e->errorInfo[1] ?? 0) !== 1146) throw $e;
        }

    } catch (\Throwable $e) {
        $err = $e->getMessage();
        dbRun("UPDATE network_devices SET poll_error=?, last_polled_at=NOW() WHERE id=?",
              [substr($err, 0, 255), $router['id']]);
        echo "  [!] Error: $err\n";
    } finally {
        fclose($sock);
    }
}

echo "[" . date('H:i:s') . "] Mikrotik poll complete.\n";
