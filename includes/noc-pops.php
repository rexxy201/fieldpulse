<?php
/**
 * NOC — POP availability.
 *
 * Each point of presence has a router with a public IP. scripts/pop-check.php
 * (cron, every minute) pings every enabled POP in parallel and records the
 * result. A POP turns DOWN only after `down_after` failed checks in a row, so
 * one lost packet burst doesn't page anyone; it is UP again on the first good
 * check. Down and recovery alert the NOC supervisors and admins.
 *
 * Where ping can't run (exec/proc_open disabled for the web server, e.g. the
 * "Check now" button), the probe falls back to a TCP connect on `tcp_port`.
 */

const NOC_POP_STATUSES = ['unknown' => 'Not checked', 'up' => 'Online', 'degraded' => 'Degraded', 'down' => 'Down'];

/** Hostname or IP accepted for a POP (also what makes it safe to pass to ping). */
function nocPopValidHost(string $host): bool {
    if (filter_var($host, FILTER_VALIDATE_IP)) return true;
    return (bool)preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host);
}

/**
 * Reads iputils/busybox ping output. Returns ['loss' => %, 'latency' => avg ms|null],
 * or null when the output has no summary line (ping couldn't run).
 */
function nocParsePing(string $out): ?array {
    if (!preg_match('/(\d+(?:\.\d+)?)% packet loss/', $out, $m)) return null;
    $lat = preg_match('#(?:rtt|round-trip) min/avg/max(?:/mdev)? = [\d.]+/([\d.]+)/#', $out, $r) ? (float)$r[1] : null;
    return ['loss' => (float)$m[1], 'latency' => $lat];
}

function nocPingCommand(string $host): string {
    return 'ping -n -c 3 -W 2 -i 0.5 ' . escapeshellarg($host) . ' 2>&1';
}

function nocCanRun(string $fn): bool {
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return function_exists($fn) && !in_array($fn, $disabled, true);
}

/** TCP connect fallback: ['loss' => 0|100, 'latency' => ms|null]. */
function nocTcpProbe(string $host, int $port): array {
    $t = microtime(true);
    $s = @fsockopen($host, $port, $errno, $errstr, 3);
    if (!$s) return ['loss' => 100.0, 'latency' => null, 'method' => "tcp:$port", 'error' => $errstr ?: 'no connection'];
    fclose($s);
    return ['loss' => 0.0, 'latency' => round((microtime(true) - $t) * 1000, 1), 'method' => "tcp:$port"];
}

/**
 * Probes POPs in parallel. Returns [pop_id => ['loss','latency','method','error'?]].
 * Tests set $GLOBALS['nocProbeFake'] = fn(array $pop): array.
 */
function nocProbePops(array $pops): array {
    $out = [];
    if (isset($GLOBALS['nocProbeFake']) && is_callable($GLOBALS['nocProbeFake'])) {
        foreach ($pops as $p) $out[$p['id']] = ($GLOBALS['nocProbeFake'])($p);
        return $out;
    }
    $tcp = fn($p) => nocTcpProbe($p['ip_address'], (int)($p['tcp_port'] ?: 8291));

    if (nocCanRun('proc_open')) {
        $procs = [];
        foreach ($pops as $p) {
            $pipes = [];
            $proc = @proc_open(nocPingCommand($p['ip_address']), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (is_resource($proc)) $procs[$p['id']] = [$proc, $pipes, $p];
            else $out[$p['id']] = $tcp($p);
        }
        foreach ($procs as $id => [$proc, $pipes, $p]) {
            $text = stream_get_contents($pipes[1]);
            fclose($pipes[1]); fclose($pipes[2]);
            proc_close($proc);
            $r = nocParsePing((string)$text);
            $out[$id] = $r ? $r + ['method' => 'ping'] : $tcp($p);
        }
        return $out;
    }
    foreach ($pops as $p) {
        $r = null;
        if (nocCanRun('exec')) {
            $lines = [];
            @exec(nocPingCommand($p['ip_address']), $lines);
            $r = nocParsePing(implode("\n", $lines));
        }
        $out[$p['id']] = $r ? $r + ['method' => 'ping'] : $tcp($p);
    }
    return $out;
}

/** People told when a POP goes down or recovers: NOC supervisors and admins. */
function nocPopAlertRecipients(): array {
    $people = [];
    foreach (array_merge(departmentSupervisors('noc'),
                         dbFetchAll("SELECT id, name, email FROM users WHERE role = 'admin' AND status = 'active'")) as $u) {
        $people[$u['id']] = $u;
    }
    return array_values($people);
}

function nocPopAlert(array $pop, string $event, string $detail): void {
    $hub   = !empty($pop['hub_id']) ? (dbFetch("SELECT name FROM hubs WHERE id = ?", [$pop['hub_id']])['name'] ?? '') : '';
    $where = $pop['name'] . ($hub !== '' ? " ($hub)" : '');
    $title = $event === 'down' ? "POP DOWN: $where" : "POP recovered: $where";
    $msg   = $event === 'down' ? "{$pop['ip_address']} is not responding. $detail" : "{$pop['ip_address']} is responding again. $detail";
    $body  = "<p><strong>" . htmlspecialchars($title) . "</strong></p><p>" . htmlspecialchars($msg) . "</p>"
           . "<p><a href='" . siteBaseUrl() . "/noc/pops'>Open POP Monitor →</a></p>"
           . "<p style='color:#64748b;font-size:.85rem'>FieldPulse · MangoNet</p>";
    foreach (nocPopAlertRecipients() as $u) {
        notifyUser($u['id'], $title, $msg, '/noc/pops');
        if (!empty($u['email'])) {
            try { sendEmail($u['email'], $u['name'], $title, $body); }
            catch (\Throwable $e) { error_log("POP alert email failed ({$u['email']}): " . $e->getMessage()); }
        }
    }
}

/** Human duration: 3725 → "1h 2m". */
function nocDuration(int $sec): string {
    if ($sec < 60) return $sec . 's';
    $d = intdiv($sec, 86400); $h = intdiv($sec % 86400, 3600); $m = intdiv($sec % 3600, 60);
    return trim(($d ? "{$d}d " : '') . ($h ? "{$h}h " : '') . ($d ? '' : "{$m}m"));
}

/**
 * Applies one probe result to a POP: logs the check, moves the status, opens
 * or closes an outage and sends alerts. Returns the event: 'down', 'up' or ''.
 */
function nocApplyPopResult(array $pop, array $r): string {
    $now      = date('Y-m-d H:i:s');
    $failed   = $r['loss'] >= 100;
    $degraded = !$failed && ($r['loss'] > 0 || (!empty($pop['latency_warn_ms']) && $r['latency'] !== null && $r['latency'] > (int)$pop['latency_warn_ms']));
    dbRun("INSERT INTO noc_pop_checks (pop_id, checked_at, ok, packet_loss, latency_ms, method) VALUES (?,?,?,?,?,?)",
        [$pop['id'], $now, $failed ? 0 : 1, $r['loss'], $r['latency'], mb_substr((string)($r['method'] ?? ''), 0, 20)]);

    $event = '';
    if ($failed) {
        $fails = (int)$pop['consecutive_failures'] + 1;
        if ($pop['status'] !== 'down' && $fails >= max(1, (int)$pop['down_after'])) {
            dbRun("UPDATE noc_pops SET status='down', consecutive_failures=?, down_since=?, last_check_at=?, last_latency_ms=NULL, last_packet_loss=?, last_error=? WHERE id=?",
                [$fails, $now, $now, $r['loss'], mb_substr((string)($r['error'] ?? 'No reply'), 0, 255), $pop['id']]);
            dbRun("INSERT INTO noc_pop_outages (id, pop_id, started_at) VALUES (?,?,?)", [newUuid(), $pop['id'], $now]);
            $event = 'down';
            nocPopAlert($pop, 'down', "No reply to $fails checks in a row.");
        } else {
            dbRun("UPDATE noc_pops SET consecutive_failures=?, last_check_at=?, last_latency_ms=NULL, last_packet_loss=?, last_error=? WHERE id=?",
                [$fails, $now, $r['loss'], mb_substr((string)($r['error'] ?? 'No reply'), 0, 255), $pop['id']]);
        }
    } else {
        if ($pop['status'] === 'down') {
            $o = dbFetch("SELECT id, started_at FROM noc_pop_outages WHERE pop_id = ? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1", [$pop['id']]);
            $dur = $o ? time() - strtotime($o['started_at']) : null;
            if ($o) dbRun("UPDATE noc_pop_outages SET ended_at = ?, duration_sec = ? WHERE id = ?", [$now, $dur, $o['id']]);
            $event = 'up';
            nocPopAlert($pop, 'up', $dur !== null ? 'Down for ' . nocDuration($dur) . '.' : '');
        }
        dbRun("UPDATE noc_pops SET status=?, consecutive_failures=0, down_since=NULL, last_check_at=?, last_up_at=?, last_latency_ms=?, last_packet_loss=?, last_error=NULL WHERE id=?",
            [$degraded ? 'degraded' : 'up', $now, $now, $r['latency'], $r['loss'], $pop['id']]);
    }
    return $event;
}

/** Checks every enabled POP (or the given ids). Returns ['checked' => n, 'events' => [name => event]]. */
function nocCheckPops(?array $ids = null): array {
    $sql = "SELECT * FROM noc_pops WHERE enabled = 1";
    $params = [];
    if ($ids) {
        $sql .= " AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
        $params = array_values($ids);
    }
    $pops = dbFetchAll($sql, $params);
    $results = nocProbePops($pops);
    $events = [];
    foreach ($pops as $p) {
        if (!isset($results[$p['id']])) continue;
        if ($e = nocApplyPopResult($p, $results[$p['id']])) $events[$p['name']] = $e;
    }
    return ['checked' => count($pops), 'events' => $events];
}

/** Uptime % per POP since $since (only POPs with checks). */
function nocPopUptime(string $since): array {
    $out = [];
    foreach (dbFetchAll("SELECT pop_id, COUNT(*) AS n, SUM(ok) AS up FROM noc_pop_checks WHERE checked_at >= ? GROUP BY pop_id", [$since]) as $r) {
        $out[$r['pop_id']] = $r['n'] ? round(100 * $r['up'] / $r['n'], 2) : null;
    }
    return $out;
}
