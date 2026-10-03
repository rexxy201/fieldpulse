<?php
/**
 * Customer Support housekeeping — call from cron every 5 minutes:
 *   curl -m 120 -fsS -o /dev/null -H "X-Cron-Token: SLA_TOKEN" https://fieldpulse.mangonetonline.com/api/support-housekeeping
 * Downloads new call recordings, deletes recordings older than the retention
 * period (Support Settings), sends ticket updates to customers that are due
 * (a backup for changes made outside the web app, and for updates held back
 * overnight), and prunes raw provider callbacks after 30 days.
 */
require_once __DIR__ . '/../config.php';
requireCronToken('slaCheckToken');

$s = csVoiceSettings();
$fetched = csFetchPendingRecordings(50);
$purged  = csPurgeOldRecordings($s['retention_days']);
$notices = csTicketNoticeSweep();
$cutoff  = date('Y-m-d H:i:s', time() - 30 * 86400);
dbRun("DELETE FROM cs_call_events WHERE created_at < ?", [$cutoff]);
dbRun("DELETE FROM cs_wa_events WHERE created_at < ?", [$cutoff]);
dbRun("DELETE FROM cs_inbound_events WHERE created_at < ?", [$cutoff]);
jsonResponse(['fetched' => $fetched, 'purged' => $purged, 'notices' => $notices, 'retention_days' => $s['retention_days']]);
