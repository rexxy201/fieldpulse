<?php
/**
 * POP availability check — cron. One run checks every minute for about five
 * minutes, so the job can be scheduled every 5 minutes (the shortest interval
 * some shared hosts allow) and still check each POP once a minute:
 *   * /5 * * * * php -q $HOME/fieldpulse.mangonetonline.com/scripts/pop-check.php >> $HOME/logs/fieldpulse-cron.log 2>&1   (no space after the first * in cPanel)
 * Scheduling it every minute also works: the lock makes later runs exit while
 * one is still going. (-q keeps a CGI build of PHP from printing headers.)
 *
 * Pings every enabled POP (NOC → POP Monitor) in parallel and records the
 * result; see includes/noc-pops.php. Prints only when a POP goes down or
 * recovers, or on error, so the log stays empty while all is well. Also
 * deletes check history older than 30 days.
 */
define('CLI_MODE', true);
require_once __DIR__ . '/../config.php';

// Never let two runs overlap.
$lock = fopen(sys_get_temp_dir() . '/fieldpulse-pop-check.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);
@set_time_limit(330);

$start  = time();
$rounds = defined('POP_CHECK_ROUNDS') ? POP_CHECK_ROUNDS : 5;   // 0, 1, 2, 3 and 4 minutes in
for ($i = 0; $i < $rounds; $i++) {
    if ($i > 0) {
        $wait = $start + 60 * $i - time();
        if ($wait > 0) sleep($wait);
    }
    try {
        dbUpsertConfig('popCronLastRun', date('Y-m-d H:i:s'));   // the SLA cron's watchdog looks at this
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
}
