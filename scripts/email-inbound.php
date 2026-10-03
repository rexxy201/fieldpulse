#!/usr/local/bin/php -q
<?php
/**
 * Inbound support email — cPanel → Email → Forwarders → Add Forwarder for the
 * support address → "Pipe to a Program":
 *   fieldpulse.mangonetonline.com/scripts/email-inbound.php
 * The raw message arrives on stdin and is filed in the support inbox (see
 * includes/support-inbox.php). Exit code is always 0, so the sender never
 * gets a bounce; problems go to the PHP error log.
 */
define('CLI_MODE', true);
require_once __DIR__ . '/../config.php';

$raw = (string)stream_get_contents(STDIN, 25 * 1024 * 1024);
if ($raw === '') exit(0);
try {
    dbRun("INSERT INTO cs_inbound_events (id,channel,payload,created_at) VALUES (?,'email',?,?)", [newUuid(), mb_substr($raw, 0, 60000), date('Y-m-d H:i:s')]);
    if (csInboxSettings()['email_enabled']) csEmailReceive($raw);
} catch (\Throwable $e) {
    error_log('Inbound email failed: ' . $e->getMessage());
}
exit(0);
