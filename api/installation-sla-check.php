<?php
/**
 * Installation SLA Warning/Breach Checker — call via cron every 15–30 minutes
 * e.g. cPanel cron schedule "30 minutes":
 *   curl -s "https://fieldpulse.mangonetonline.com/api/installation-sla-check?token=YOUR_TOKEN"
 *
 * Emails the assigned vendor when an installation's SLA due date is
 * approaching (once) and when it's breached (once) — same guarded-token
 * pattern as api/sla-check.php (the ticket version of this).
 */
require_once __DIR__ . '/../config.php';

$cfg        = getAppConfig();
$guardToken = trim($cfg['slaCheckToken'] ?? '');
if ($guardToken && ($_GET['token'] ?? '') !== $guardToken) {
    http_response_code(403);
    exit('Forbidden');
}

$warnHours = max(1, (int)($cfg['installationSlaWarnHours'] ?? 24));
$warned    = 0;
$breached  = 0;
$errors    = [];

// ── Approaching due date: not completed, due within warnHours, not yet warned ─
$approaching = dbFetchAll(
    "SELECT p.*, v.name AS vendor_name, v.email AS vendor_email
     FROM installation_profiles p
     LEFT JOIN vendors v ON v.id = p.vendor_id
     WHERE p.status NOT IN ('completed')
       AND p.sla_due_at IS NOT NULL
       AND p.sla_warned_at IS NULL
       AND p.sla_due_at > NOW()
       AND p.sla_due_at <= " . dbNowPlusInterval($warnHours, 'HOUR')
);

foreach ($approaching as $p) {
    if (!empty($p['vendor_email'])) {
        try {
            emailVendorInstallationSlaWarning($p, ['name' => $p['vendor_name'], 'email' => $p['vendor_email']]);
            $warned++;
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
    dbRun("UPDATE installation_profiles SET sla_warned_at = NOW() WHERE id = ?", [$p['id']]);
}

// ── Newly breached: due date passed, not completed, not yet breach-notified ──
$newlyBreached = dbFetchAll(
    "SELECT p.*, v.name AS vendor_name, v.email AS vendor_email
     FROM installation_profiles p
     LEFT JOIN vendors v ON v.id = p.vendor_id
     WHERE p.status NOT IN ('completed')
       AND p.sla_due_at IS NOT NULL
       AND p.sla_due_at <= NOW()
       AND p.sla_breached_notified_at IS NULL"
);

foreach ($newlyBreached as $p) {
    if (!empty($p['vendor_email'])) {
        try {
            emailVendorInstallationSlaBreached($p, ['name' => $p['vendor_name'], 'email' => $p['vendor_email']]);
            $breached++;
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
    dbRun("UPDATE installation_profiles SET sla_breached_notified_at = NOW() WHERE id = ?", [$p['id']]);
}

jsonResponse([
    'ok'         => true,
    'warnings'   => $warned,
    'breached'   => $breached,
    'errors'     => $errors,
    'checked_at' => date('Y-m-d H:i:s'),
]);
