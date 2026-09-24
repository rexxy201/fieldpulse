#!/usr/bin/env php
<?php
/**
 * Mikrotik RouterOS Telnet Poller — cron job (every 5 min recommended)
 * Connects via Telnet (default port 2333), collects system resources
 * and active PPPoE sessions, stores hub-level stats.
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
    $port  = (int)($router['api_port'] ?? 2333);

    $client = new MikrotikTelnetClient($router['ip_address'], $port, 10);

    try {
        $client->connect();
        $client->login($user, $pass);

        // System resources
        $resOut = $client->command('/system resource print');
        $cpuLoad  = (int)parseMtVal($resOut, 'cpu-load',     0);
        $freeMemB = parseMtBytes($resOut, 'free-memory');
        $totalMemB= parseMtBytes($resOut, 'total-memory');
        $uptime   = trim(parseMtVal($resOut, 'uptime', ''));

        // Active PPPoE sessions count-only
        $pppOut       = $client->command('/ppp active print count-only');
        $sessionCount = (int)trim(strip_tags($pppOut));

        $memUsed  = max(0, $totalMemB - $freeMemB);

        echo "  CPU: {$cpuLoad}% | Mem: " . round($memUsed / 1048576) . "/" . round($totalMemB / 1048576) . " MB | PPPoE: $sessionCount\n";

        $client->disconnect();

        dbRun("UPDATE network_devices SET last_seen_at=NOW(), last_polled_at=NOW(), poll_error=NULL WHERE id=?",
              [$router['id']]);

        try {
            dbRun(
                "INSERT INTO hub_device_stats
                 (id,device_id,hub_id,cpu_load,mem_used_bytes,mem_total_bytes,pppoe_sessions,uptime_str,sampled_at)
                 VALUES (?,?,?,?,?,?,?,?,NOW())",
                [newUuid(), $router['id'], $router['hub_id'],
                 $cpuLoad, $memUsed, $totalMemB, $sessionCount, $uptime]
            );
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) !== 1146) throw $e;
        }

    } catch (\Throwable $e) {
        $err = $e->getMessage();
        dbRun("UPDATE network_devices SET poll_error=?, last_polled_at=NOW() WHERE id=?",
              [substr($err, 0, 255), $router['id']]);
        echo "  [!] Error: $err\n";
        try { $client->disconnect(); } catch (\Throwable $_) {}
    }
}

echo "[" . date('H:i:s') . "] Mikrotik poll complete.\n";

// ── Text-output parsers ───────────────────────────────────────────────────────

function parseMtVal(string $output, string $key, $default = ''): string {
    if (preg_match('/\b' . preg_quote($key, '/') . '\s*:\s*(.+)/i', $output, $m)) {
        return trim($m[1]);
    }
    return (string)$default;
}

function parseMtBytes(string $output, string $key): int {
    $raw = parseMtVal($output, $key, '0');
    // e.g. "238.1MiB", "1.0GiB", "512KiB"
    if (preg_match('/([\d.]+)\s*(G|M|K)?i?B/i', $raw, $m)) {
        $val  = (float)$m[1];
        $unit = strtoupper($m[2] ?? '');
        return (int)match($unit) {
            'G' => $val * 1073741824,
            'M' => $val * 1048576,
            'K' => $val * 1024,
            default => $val,
        };
    }
    return (int)$raw;
}
