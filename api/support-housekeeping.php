<?php
/**
 * Customer Support housekeeping — call from cron every 15–30 minutes:
 *   curl -m 120 -fsS -o /dev/null -H "X-Cron-Token: SLA_TOKEN" https://fieldops.mangonetonline.com/api/support-housekeeping
 * Downloads new call recordings, deletes recordings older than the retention
 * period (Support Settings), and prunes raw provider callbacks after 30 days.
 */
require_once __DIR__ . '/../config.php';
requireCronToken('slaCheckToken');

$s = csVoiceSettings();
$fetched = csFetchPendingRecordings(50);
$purged  = csPurgeOldRecordings($s['retention_days']);
dbRun("DELETE FROM cs_call_events WHERE created_at < ?", [date('Y-m-d H:i:s', time() - 30 * 86400)]);
jsonResponse(['fetched' => $fetched, 'purged' => $purged, 'retention_days' => $s['retention_days']]);
