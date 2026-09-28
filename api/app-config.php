<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (in_array(method(), ['POST','PATCH','DELETE'], true)) verifyCsrf();
// Keys that are never exposed outside admin-level responses
const SENSITIVE_KEYS = ['smtpPassword', 'smtpUser', 'smtpHost', 'smtpPort', 'smtpFrom', 'smtpFromName', 'smtpSecure', 'openaiApiKey',
                        'smsApiKey', 'smsApiSecret', 'smsUsername'];
// Cron tokens are shown once when generated (Admin -> Automation & Cron
// Tokens) and never returned again, to admins included.
const WRITE_ONLY_KEYS = ['slaCheckToken', 'installSyncToken', 'atApiKey', 'voiceWebhookSecret', 'metaWaAccessToken', 'metaWaAppSecret', 'metaWaVerifyToken'];
if (method() === 'GET') {
    $_k  = dbKey();
    $rows = dbFetchAll("SELECT $_k, value FROM app_config");
    $out  = [];
    foreach ($rows as $r) $out[$r['key']] = $r['value'];
    foreach (WRITE_ONLY_KEYS as $k) unset($out[$k]);
    if (!isAdmin()) {
        foreach (SENSITIVE_KEYS as $k) unset($out[$k]);
    }
    jsonResponse($out);
}
if (method() === 'POST') {
    if (!isAdmin()) jsonResponse(['error' => 'Forbidden'], 403);
    $b = getBody();
    dbUpsertConfigs($b);
    jsonResponse(['ok' => true]);
}
