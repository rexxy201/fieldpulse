<?php
require_once __DIR__ . '/../config.php';
requireAuth();
// Keys that are never exposed outside admin-level responses
const SENSITIVE_KEYS = ['smtpPassword', 'smtpUser', 'smtpHost', 'smtpPort', 'smtpFrom', 'smtpFromName', 'smtpSecure', 'openaiApiKey'];
if (method() === 'GET') {
    $_k  = dbKey();
    $rows = dbFetchAll("SELECT $_k, value FROM app_config");
    $out  = [];
    foreach ($rows as $r) $out[$r['key']] = $r['value'];
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
