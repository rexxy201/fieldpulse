<?php
/**
 * POP availability check — cPanel cron, every minute:
 *   * * * * * php $HOME/fieldpulse.mangonetonline.com/scripts/pop-check.php >> $HOME/logs/fieldpulse-cron.log 2>&1
 *
 * Pings every enabled POP (NOC → POP Monitor) in parallel and records the
 * result; see includes/noc-pops.php. Prints only when a POP goes down or
 * recovers, or on error, so the log stays empty while all is well. Also
 * deletes check history older than 30 days.
 */
define('CLI_MODE', true);
require_once __DIR__ . '/../config.php';

// Never let two runs overlap if one is slow.
$lock = fopen(sys_get_temp_dir() . '/fieldpulse-pop-check.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

try {
    $r = nocCheckPops();
    foreach ($r['events'] as $name => $event) {
        echo '[' . date('Y-m-d H:i:s') . "] POP $name: " . ($event === 'down' ? 'DOWN' : 'recovered') . "\n";
    }
    if ((int)date('i') === 0) {   // hourly housekeeping
        dbRun("DELETE FROM noc_pop_checks WHERE checked_at < ?", [date('Y-m-d H:i:s', time() - 30 * 86400)]);
    }
} catch (\Throwable $e) {
    echo '[' . date('Y-m-d H:i:s') . '] POP check failed: ' . $e->getMessage() . "\n";
    exit(1);
}
