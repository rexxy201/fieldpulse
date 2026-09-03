<?php
/**
 * Signup App Sync — call via cron every 5 minutes (or however often you want)
 * e.g. cPanel cron schedule "5 minutes" (or 0-59/5):
 *   curl -s "https://fieldpulse.mangonetonline.com/api/installations-sync?token=YOUR_TOKEN"
 *
 * Pulls paid signups from serviceorder.mangonetonline.com into
 * installation_profiles. Set SIGNUP_DB_NAME (and optionally SIGNUP_DB_USER /
 * SIGNUP_DB_PASS) in config.php first — see the comment above signupDb().
 *
 * Same token-guard pattern as api/sla-check.php: set 'installSyncToken' in
 * app_config to require it, or leave blank to allow unauthenticated calls
 * (fine for a cron job hitting localhost, riskier if this URL is public).
 */
require_once __DIR__ . '/../config.php';

// Token guard — required. No installSyncToken configured means this
// endpoint refuses every request; set it via Admin -> Automation & Cron Tokens.
$cfg        = getAppConfig();
$guardToken = trim($cfg['installSyncToken'] ?? '');
if ($guardToken === '' || ($_GET['token'] ?? '') !== $guardToken) {
    http_response_code(403);
    exit('Forbidden');
}

$result = syncInstallationsFromSignup();
jsonResponse([
    'ok'         => empty($result['errors']),
    'imported'   => $result['imported'],
    'skipped'    => $result['skipped'],
    'errors'     => $result['errors'],
    'checked_at' => date('Y-m-d H:i:s'),
]);
