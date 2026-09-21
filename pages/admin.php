<?php
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('admin.access');

$msg = ''; $msgType = 'success';

// Permissions shown/managed on the Roles & Permissions matrix (tab-permissions
// below). Kept as a single source of truth so the save handler only ever
// touches permissions actually represented on screen — anything granted
// elsewhere (e.g. Payment Requests / Finance stage permissions, which aren't
// shown in this matrix) is left alone rather than being silently wiped on
// every save.
$PERMISSION_GROUPS = [
    'Tickets'       => ['tickets.view_all','tickets.view_department','tickets.create','tickets.update','tickets.assign','tickets.resolve','tickets.close','tickets.delete'],
    'Customers'     => ['customers.view','customers.create','customers.update','customers.delete'],
    'Installations' => ['installations.view','installations.create','installations.update','installations.delete'],
    'Field & Team'  => ['schedule.view','map.view','team.view','team.manage','analytics.view','reports.view'],
    // Who may raise a payment request, and who signs one off at each stage.
    'Payment Requests' => ['payment_requests.create','payment_requests.view','payment_requests.authorize','payment_requests.approve','payment_requests.finance_check'],
    'Finance'          => ['finance.view','installations.financial'],
    'Inventory'     => ['inventory.view','inventory.assets.view','inventory.assets.manage','inventory.items.view','inventory.items.manage','inventory.cabinets.view','inventory.cabinets.manage','inventory.categories.view','inventory.categories.manage','inventory.requests.create','inventory.requests.view','inventory.requests.approve','inventory.movements.view','inventory.refill'],
    'System'        => ['admin.access'],
];

if (method() === 'POST') {
    verifyCsrf();
    $b = $_POST;
    $action = $b['_action'] ?? '';

    if ($action === 'add_fault') {
        dbRun("INSERT INTO fault_types (id,name,category,route_to,enabled) VALUES (?,?,?,?,1)",
            [newUuid(),$b['name']??'',$b['category']??'',$b['route_to']??'']);
        $msg = 'Fault type added.';
    }
    if ($action === 'del_fault' && !empty($b['id'])) {
        dbRun("DELETE FROM fault_types WHERE id=?",[$b['id']]);
        $msg = 'Fault type deleted.';
    }
    if ($action === 'edit_fault' && !empty($b['id'])) {
        $enabled = isset($b['enabled']) ? 1 : 0;
        dbRun("UPDATE fault_types SET name=?,category=?,route_to=?,enabled=? WHERE id=?",
            [$b['name']??'',$b['category']??'',$b['route_to']??'',$enabled,$b['id']]);
        $msg = 'Fault type updated.';
    }
    if ($action === 'add_sla') {
        $slaPriority = $b['priority'] ?? 'p3';
        $slaRh = (int)($b['response_time_hours'] ?? 4);
        $slaResh = (int)($b['resolution_time_hours'] ?? 24);
        $existingSla = dbFetch("SELECT id FROM sla_configs WHERE priority=?", [$slaPriority]);
        if ($existingSla) {
            dbRun("UPDATE sla_configs SET response_time_hours=?, resolution_time_hours=? WHERE priority=?",
                [$slaRh, $slaResh, $slaPriority]);
        } else {
            dbRun("INSERT INTO sla_configs (id,priority,response_time_hours,resolution_time_hours) VALUES (?,?,?,?)",
                [newUuid(), $slaPriority, $slaRh, $slaResh]);
        }
        $msg = 'SLA config saved.';
    }
    if ($action === 'add_hub') {
        dbRun("INSERT INTO hubs (id,name,location,lat,lng) VALUES (?,?,?,?,?)",
            [newUuid(),$b['name']??'',$b['location']??'',$b['lat']??null,$b['lng']??null]);
        $msg = 'Hub added.';
    }
    if ($action === 'del_hub' && !empty($b['id'])) {
        dbRun("DELETE FROM hubs WHERE id=?",[$b['id']]);
        $msg = 'Hub deleted.';
    }
    if ($action === 'edit_hub' && !empty($b['id'])) {
        $teamId = !empty($b['team_id']) ? $b['team_id'] : null;
        dbRun("UPDATE hubs SET name=?,location=?,lat=?,lng=?,team_id=? WHERE id=?",
            [$b['name']??'',$b['location']??'',$b['lat']??null,$b['lng']??null,$teamId,$b['id']]);
        $msg = 'Hub updated.';
    }
    if ($action === 'assign_hub_team' && !empty($b['hub_id'])) {
        $teamId = !empty($b['team_id']) ? $b['team_id'] : null;
        dbRun("UPDATE hubs SET team_id=? WHERE id=?", [$teamId, $b['hub_id']]);
        $msg = 'Team assignment saved.';
    }
    if ($action === 'assign_hub_vendor' && !empty($b['hub_id'])) {
        $vendorId = !empty($b['maintenance_vendor_id']) ? $b['maintenance_vendor_id'] : null;
        dbRun("UPDATE hubs SET maintenance_vendor_id=? WHERE id=?", [$vendorId, $b['hub_id']]);
        $msg = 'Maintenance vendor assignment saved.';
    }
    if ($action === 'add_hub_city' && !empty($b['hub_id']) && trim($b['city_name'] ?? '') !== '') {
        try {
            dbRun("INSERT INTO hub_city_mappings (id,hub_id,city_name) VALUES (?,?,?)",
                [newUuid(), $b['hub_id'], trim($b['city_name'])]);
            $affectedCity = trim($b['city_name']);
            // Backfill hub_id on matching customers
            dbRun("UPDATE customers SET hub_id=? WHERE (hub_id IS NULL OR hub_id='') AND LOWER(TRIM(mailing_city))=LOWER(?)",
                [$b['hub_id'], $affectedCity]);
            $msg = "City '{$affectedCity}' mapped. Customers backfilled.";
        } catch (\Throwable $e) {
            $msg = 'City already mapped to this hub or an error occurred.'; $msgType = 'error';
        }
    }
    if ($action === 'del_hub_city' && !empty($b['id'])) {
        dbRun("DELETE FROM hub_city_mappings WHERE id=?", [$b['id']]);
        $msg = 'City mapping removed.';
    }
    // Shared canonical location list — used by Customers, Tickets, and Installations
    // so all three modules offer the same set of location names.
    if ($action === 'add_location' && trim($b['name'] ?? '') !== '') {
        try {
            dbRun("INSERT INTO locations (id,name) VALUES (?,?)", [newUuid(), trim($b['name'])]);
            $msg = 'Location added.';
        } catch (\Throwable $e) {
            $msg = 'That location already exists.'; $msgType = 'error';
        }
    }
    if ($action === 'del_location' && !empty($b['id'])) {
        dbRun("DELETE FROM locations WHERE id=?", [$b['id']]);
        $msg = 'Location removed.';
    }
    if ($action === 'save_branding') {
        // Handle logo file upload — overrides the URL field when a valid image is chosen
        if (!empty($_FILES['companyLogoFile']['tmp_name']) && $_FILES['companyLogoFile']['error'] === UPLOAD_ERR_OK) {
            $f   = $_FILES['companyLogoFile'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            // SVG intentionally excluded — it can carry embedded scripts (stored-XSS risk)
            if (in_array($ext, ['png','jpg','jpeg','gif','webp'], true) && $f['size'] <= 2 * 1024 * 1024) {
                $dir = __DIR__ . '/../assets/uploads/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $dest = $dir . 'logo.' . $ext;
                // Remove old logo files with other extensions
                foreach (glob($dir . 'logo.*') as $old) { @unlink($old); }
                if (move_uploaded_file($f['tmp_name'], $dest)) {
                    $b['companyLogo'] = '/assets/uploads/logo.' . $ext;
                }
            } else {
                $msg = 'Logo must be PNG, JPG, GIF, or WebP and under 2 MB.'; $msgType = 'error';
            }
        }
        // Favicon file upload — same override-the-URL-field pattern as the logo.
        if (!empty($_FILES['faviconFile']['tmp_name']) && $_FILES['faviconFile']['error'] === UPLOAD_ERR_OK) {
            $f   = $_FILES['faviconFile'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            // SVG intentionally excluded — same stored-XSS concern as the logo upload
            if (in_array($ext, ['ico','png'], true) && $f['size'] <= 512 * 1024) {
                $dir = __DIR__ . '/../assets/uploads/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $dest = $dir . 'favicon.' . $ext;
                foreach (glob($dir . 'favicon.*') as $old) { @unlink($old); }
                if (move_uploaded_file($f['tmp_name'], $dest)) {
                    $b['favicon'] = '/assets/uploads/favicon.' . $ext;
                }
            } else {
                $msg = 'Favicon must be ICO or PNG and under 512 KB.'; $msgType = 'error';
            }
        }
        if ($msgType !== 'error') {
            foreach (['companyName','companyLogo','favicon','primaryColor','loginNotice','timezone'] as $k) {
                if (array_key_exists($k, $b)) dbUpsertConfig($k, $b[$k]);
            }
            $msg = 'Branding saved. Reload the page to apply colour changes.';
        }
    }
    if ($action === 'save_notifications') {
        foreach (['smtpHost','smtpPort','smtpEncryption','smtpUser','smtpPassword','smtpFrom','smtpFromName'] as $k) {
            dbUpsertConfig($k, $b[$k] ?? '');
        }
        $msg = 'Email settings saved.';
    }
    if ($action === 'save_installation_sla') {
        dbUpsertConfig('installationSlaWorkingDays', (string)max(1, (int)($b['installationSlaWorkingDays'] ?? 10)));
        $msg = 'Installation SLA saved.';
    }
    // Cron/automation endpoints (sla-check, ops/finance/report digests,
    // webhook-retry, installations-sync) refuse every request until their
    // token is set — this regenerates one. The value only ever appears here
    // once, right after generating it; it's never re-displayed afterward.
    if ($action === 'generate_cron_token' && in_array($b['token_key'] ?? '', ['slaCheckToken', 'installSyncToken'], true)) {
        $newToken = bin2hex(random_bytes(24));
        dbUpsertConfig($b['token_key'], $newToken);
        // Every action here ends in a redirect (PRG pattern, below) before
        // anything renders, so a plain local var wouldn't survive to be
        // displayed — flash it through the session once, same as $msg.
        $_SESSION['admin_flash_token'] = $newToken;
        $_SESSION['admin_flash_token_key'] = $b['token_key'];
        $msg = 'Token generated — copy it into your cron command now, it won\'t be shown again.';
    }
    if ($action === 'save_sla_warning') {
        dbUpsertConfig('slaWarnHours', (string)(int)($b['slaWarnHours'] ?? 2));
        $msg = 'SLA warning timing saved.';
    }
    if ($action === 'save_ai_settings') {
        dbUpsertConfig('aiAssistantEnabled', !empty($b['aiAssistantEnabled']) ? '1' : '0');
        dbUpsertConfig('openaiModel', trim($b['openaiModel'] ?? '') ?: 'gpt-4o-mini');
        // Only overwrite the key if a new one was actually typed — the field is
        // always rendered blank, so an empty submit means "leave it as is".
        if (trim($b['openaiApiKey'] ?? '') !== '') {
            dbUpsertConfig('openaiApiKey', trim($b['openaiApiKey']));
        }
        $msg = 'AI Assistant settings saved.';
    }
    if ($action === 'save_digest_settings') {
        dbUpsertConfig('opsDigestEnabled', !empty($b['opsDigestEnabled']) ? '1' : '0');
        dbUpsertConfig('opsDigestEmails', trim($b['opsDigestEmails'] ?? ''));
        $msg = 'Ops digest settings saved.';
    }
    if ($action === 'save_finance_digest_settings') {
        dbUpsertConfig('financeDigestEnabled', !empty($b['financeDigestEnabled']) ? '1' : '0');
        dbUpsertConfig('financeDigestEmails', trim($b['financeDigestEmails'] ?? ''));
        $msg = 'Finance digest settings saved.';
    }
    if ($action === 'save_permissions') {
        // $b['perms'][role][permission] = '1'
        $submitted = $b['perms'] ?? [];
        // Only ever touch permissions actually shown on the matrix — leave
        // anything else (Payment Requests / Finance stage grants, etc.)
        // untouched so this save can't silently wipe grants made elsewhere.
        $managedPerms = array_merge(...array_values($PERMISSION_GROUPS));
        $ph = implode(',', array_fill(0, count($managedPerms), '?'));
        foreach (roleKeys() as $r) {
            if ($r === 'admin') continue; // admin always has all, skip
            // Remove only the managed permissions for this role
            dbRun("DELETE FROM role_permissions WHERE role = ? AND permission IN ($ph)", array_merge([$r], $managedPerms));
            // Re-insert checked ones
            $rolePerms = $submitted[$r] ?? [];
            foreach ($managedPerms as $p) {
                if (!empty($rolePerms[$p])) {
                    try {
                        dbInsertIgnore("INSERT INTO role_permissions (id,role,permission) VALUES (?,?,?)", [newUuid(),$r,$p]);
                    } catch (\Throwable $e) {}
                }
            }
        }
        $msg = 'Permissions updated successfully.';
        // The payment-request workflow has three sign-off stages. A save that
        // leaves one with no role holding it doesn't break the app — full
        // admins bypass role_permissions entirely and can still act — but it
        // does funnel every voucher at that stage through an admin, which is
        // rarely what was intended. Warn rather than block: an admin-only
        // stage is a legitimate setup for a small team.
        $stageLabels = [
            'payment_requests.authorize'     => 'Authorize',
            'payment_requests.approve'       => 'Approve',
            'payment_requests.finance_check' => 'Finance Check',
        ];
        $orphanStages = [];
        foreach ($stageLabels as $perm => $label) {
            $held = false;
            foreach ($submitted as $r => $rolePerms) {
                if ($r !== 'admin' && !empty($rolePerms[$perm])) { $held = true; break; }
            }
            if (!$held) $orphanStages[] = $label;
        }
        if ($orphanStages) {
            $msg .= ' Note: no role now holds ' . implode(' or ', $orphanStages)
                 . ' — payment requests at ' . (count($orphanStages) > 1 ? 'those stages' : 'that stage')
                 . ' can only be actioned by a full Admin.';
        }
    }
    // ── Integration API keys ──
    if ($action === 'create_api_key') {
        $name = trim($b['name'] ?? '');
        $scopes = array_values(array_intersect($b['scopes'] ?? [], array_keys(API_SCOPES)));
        $webhookUrl = trim($b['webhook_url'] ?? '');
        if ($name === '') {
            $msg = 'A name is required for the API key.'; $msgType = 'danger';
        } elseif (!$scopes) {
            $msg = 'Select at least one scope for the API key.'; $msgType = 'danger';
        } elseif ($webhookUrl !== '' && !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            $msg = 'Webhook URL is not a valid URL.'; $msgType = 'danger';
        } else {
            $gen = generateApiKey();
            $webhookSecret = $webhookUrl !== '' ? bin2hex(random_bytes(16)) : null;
            $rateLimit = max(0, (int)($b['rate_limit_per_min'] ?? 60));
            $_apiKeyUser = currentUser();
            dbRun("INSERT INTO api_keys (id,name,key_prefix,key_hash,scopes,webhook_url,webhook_secret,rate_limit_per_min,created_by,created_by_name) VALUES (?,?,?,?,?,?,?,?,?,?)",
                [newUuid(), $name, $gen['prefix'], $gen['hash'], implode(',', $scopes), $webhookUrl ?: null, $webhookSecret, $rateLimit, $_apiKeyUser['id'] ?? null, $_apiKeyUser['name'] ?? null]);
            auditLog('create', 'api_key', $gen['prefix']);
            // Shown exactly once — nobody, including us, can retrieve the raw
            // key again after this request (only its hash is stored).
            $_SESSION['new_api_key_reveal'] = ['key' => $gen['full'], 'webhook_secret' => $webhookSecret];
            $msg = 'API key created.';
        }
    }
    if ($action === 'revoke_api_key' && !empty($b['id'])) {
        dbRun("UPDATE api_keys SET revoked_at = NOW() WHERE id = ?", [$b['id']]);
        auditLog('revoke', 'api_key', $b['id']);
        $msg = 'API key revoked.';
    }
    // ── Role management ──
    if ($action === 'add_role') {
        $rname = strtolower(trim($b['name'] ?? ''));
        $rname = preg_replace('/[^a-z0-9_-]/', '', str_replace(' ', '-', $rname));
        $rlabel = trim($b['label'] ?? '');
        $rdept  = in_array($b['department'] ?? '', ['fiber','noc','installation','cx','finance','executive']) ? $b['department'] : '';
        if ($rname === '' || $rlabel === '') {
            $msg = 'Role name and label are required.'; $msgType = 'error';
        } elseif (isset(getRoles()[$rname])) {
            $msg = 'A role with that key already exists.'; $msgType = 'error';
        } else {
            try {
                dbRun("INSERT INTO roles (name,label,department,is_system) VALUES (?,?,?,0)", [$rname,$rlabel,$rdept]);
                $msg = 'Role created.';
            } catch (\Throwable $e) { $msg = 'Could not create role.'; $msgType = 'error'; }
        }
    }
    if ($action === 'edit_role' && !empty($b['name'])) {
        $rname  = $b['name'];
        $rlabel = trim($b['label'] ?? '');
        $rdept  = in_array($b['department'] ?? '', ['fiber','noc','installation','cx','finance','executive']) ? $b['department'] : '';
        if ($rlabel === '') { $msg = 'Label is required.'; $msgType = 'error'; }
        else {
            dbRun("UPDATE roles SET label=?, department=? WHERE name=?", [$rlabel,$rdept,$rname]);
            $msg = 'Role updated.';
        }
    }
    if ($action === 'del_role' && !empty($b['name'])) {
        $rname = $b['name'];
        $role  = getRoles()[$rname] ?? null;
        $inUse = (int)(dbFetch("SELECT COUNT(*) c FROM users WHERE role=?", [$rname])['c'] ?? 0);
        if (!$role || (int)$role['is_system'] === 1) { $msg = 'Built-in roles cannot be deleted.'; $msgType = 'error'; }
        elseif ($inUse > 0) { $msg = "Cannot delete: $inUse user(s) still have this role. Reassign them first."; $msgType = 'error'; }
        else {
            dbRun("DELETE FROM roles WHERE name=?", [$rname]);
            dbRun("DELETE FROM role_permissions WHERE role=?", [$rname]);
            $msg = 'Role deleted.';
        }
    }
    if ($msg !== '') { $_SESSION['admin_flash'] = $msg; $_SESSION['admin_flash_type'] = $msgType; }
    $_hubActions  = ['add_hub','del_hub','edit_hub','assign_hub_team','assign_hub_vendor','add_hub_city','del_hub_city','add_location','del_location'];
    $_permActions = ['save_permissions','add_role','edit_role','del_role'];
    $_apiKeyActions = ['create_api_key','revoke_api_key'];
    $_slaActions = ['save_installation_sla','save_sla_warning','generate_cron_token'];
    if (in_array($action, $_hubActions, true))  $_anchor = '#tab-hubs';
    elseif (in_array($action, $_permActions, true)) $_anchor = '#tab-permissions';
    elseif (in_array($action, $_apiKeyActions, true)) $_anchor = '#tab-integrations';
    elseif (in_array($action, $_slaActions, true)) $_anchor = '#tab-sla';
    else $_anchor = '';
    header('Location: /admin' . $_anchor); exit;
}

// Read one-time flash message after the post/redirect/get cycle
if ($msg === '' && !empty($_SESSION['admin_flash'])) {
    $msg     = $_SESSION['admin_flash'];
    $msgType = $_SESSION['admin_flash_type'] ?? 'success';
    unset($_SESSION['admin_flash'], $_SESSION['admin_flash_type']);
}
// The raw API key/webhook secret is shown exactly once, right after creation —
// pulled from session and unset immediately so a page refresh can't re-reveal it.
$newApiKeyReveal = $_SESSION['new_api_key_reveal'] ?? null;
unset($_SESSION['new_api_key_reveal']);

// Same one-time-reveal pattern for a freshly generated cron token.
$_generatedToken    = $_SESSION['admin_flash_token'] ?? null;
$_generatedTokenKey = $_SESSION['admin_flash_token_key'] ?? null;
unset($_SESSION['admin_flash_token'], $_SESSION['admin_flash_token_key']);

$faultTypes = dbFetchAll("SELECT * FROM fault_types ORDER BY category,name");
$slaConfigs = dbFetchAll("SELECT * FROM sla_configs ORDER BY priority");
$hubs       = dbFetchAll("SELECT * FROM hubs ORDER BY name");
$auditLogs  = dbFetchAll("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 100");
$apiKeys    = dbFetchAll("SELECT * FROM api_keys ORDER BY revoked_at IS NOT NULL, created_at DESC");
$webhookQueue = dbFetch("SELECT SUM(status='pending') pending, SUM(status='failed') failed FROM webhook_deliveries");
$webhookFailures = dbFetchAll(
    "SELECT wd.event, wd.attempts, wd.status, wd.last_error, wd.created_at, ak.name AS key_name
     FROM webhook_deliveries wd JOIN api_keys ak ON ak.id = wd.api_key_id
     WHERE wd.status IN ('pending','failed') ORDER BY wd.created_at DESC LIMIT 10"
);
$teams      = dbFetchAll("SELECT * FROM teams ORDER BY type, name");
$teamById   = [];
foreach ($teams as $t) $teamById[$t['id']] = $t;
$vendors    = dbFetchAll("SELECT id,name FROM vendors ORDER BY name");
$vendorById = [];
foreach ($vendors as $v) $vendorById[$v['id']] = $v;
$locations = dbFetchAll("SELECT * FROM locations ORDER BY name");
$hubCityMappings = dbFetchAll("SELECT * FROM hub_city_mappings ORDER BY city_name");
$cityByHub = [];
foreach ($hubCityMappings as $m) $cityByHub[$m['hub_id']][] = $m;
$hubCustomerCounts = [];
foreach (dbFetchAll("SELECT hub_id, COUNT(*) AS c FROM customers WHERE hub_id IS NOT NULL AND hub_id <> '' GROUP BY hub_id") as $row) {
    $hubCustomerCounts[$row['hub_id']] = (int)$row['c'];
}
$totalCitiesMapped = count($hubCityMappings);
$cfg        = getAppConfig();

$slaByPrio = [];
foreach ($slaConfigs as $s) $slaByPrio[$s['priority']] = $s;

// Branding
$primaryColor = $cfg['primaryColor'] ?? '#0ea5e9';
$companyName  = $cfg['companyName'] ?? 'FieldPulse';
$companyLogo  = $cfg['companyLogo'] ?? '';
$favicon      = $cfg['favicon'] ?? '';
$timezone     = $cfg['timezone'] ?? 'Africa/Lagos';

// Common timezones offered in the dropdown (Africa first, then global)
$tzOptions = [
    'Africa/Lagos'        => 'Lagos / Nigeria (WAT, UTC+1)',
    'Africa/Accra'        => 'Accra / Ghana (UTC+0)',
    'Africa/Abidjan'      => 'Abidjan (UTC+0)',
    'Africa/Cairo'        => 'Cairo / Egypt (UTC+2)',
    'Africa/Johannesburg' => 'Johannesburg (UTC+2)',
    'Africa/Nairobi'      => 'Nairobi (UTC+3)',
    'UTC'                 => 'UTC (UTC+0)',
    'Europe/London'       => 'London (UTC+0/+1)',
    'Europe/Paris'        => 'Paris / Berlin (UTC+1/+2)',
    'America/New_York'    => 'New York (UTC-5/-4)',
    'America/Los_Angeles' => 'Los Angeles (UTC-8/-7)',
    'Asia/Dubai'          => 'Dubai (UTC+4)',
    'Asia/Kolkata'        => 'India (UTC+5:30)',
    'Asia/Shanghai'       => 'China (UTC+8)',
];
// Make sure the currently-saved value always appears even if not in the list
if (!isset($tzOptions[$timezone])) $tzOptions[$timezone] = $timezone;

// SMTP / notifications
$smtp = [
    'smtpHost'       => $cfg['smtpHost'] ?? '',
    'smtpPort'       => $cfg['smtpPort'] ?? '587',
    'smtpEncryption' => $cfg['smtpEncryption'] ?? 'tls',
    'smtpUser'       => $cfg['smtpUser'] ?? '',
    'smtpPassword'   => $cfg['smtpPassword'] ?? '',
    'smtpFrom'       => $cfg['smtpFrom'] ?? '',
    'smtpFromName'   => $cfg['smtpFromName'] ?? '',
];

// AI Assistant — API key is intentionally never echoed back into the form
$aiCfg = [
    'aiAssistantEnabled' => $cfg['aiAssistantEnabled'] ?? '1',
    'openaiApiKey'       => $cfg['openaiApiKey'] ?? '', // only used to detect "already set", never rendered
    'openaiModel'        => $cfg['openaiModel'] ?? 'gpt-4o-mini',
];

// VAPID keys (for push notifications display)
$vapidPublic  = $cfg['vapidPublicKey'] ?? '(not generated)';

$pageTitle = 'Admin';
require __DIR__ . '/../includes/header.php';

$_deployedAt = dbFetch("SELECT value FROM app_config WHERE " . dbKey() . " = 'app_version_deployed_at'")['value'] ?? null;
?>

<div class="text-end mb-3">
  <span class="badge bg-light text-dark border small">
    <i class="bi bi-box-seam me-1"></i>App v<?= htmlspecialchars(APP_VERSION) ?>
    <?php if ($_deployedAt): ?><span class="text-muted">— deployed <?= date('d M Y H:i', strtotime($_deployedAt)) ?></span><?php endif; ?>
  </span>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?> alert-dismissible py-2 mb-3">
  <i class="bi bi-<?= $msgType === 'error' ? 'exclamation-triangle' : 'check-circle' ?> me-1"></i><?= htmlspecialchars($msg) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-4" id="adminTabs" style="flex-wrap:wrap">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-faults">
    <i class="bi bi-exclamation-diamond me-1"></i>Fault Types</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-sla">
    <i class="bi bi-stopwatch me-1"></i>SLAs &amp; Timers</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-hubs">
    <i class="bi bi-hdd-network me-1"></i>Hubs</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-branding">
    <i class="bi bi-palette me-1"></i>Branding</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-notifications">
    <i class="bi bi-bell me-1"></i>Notifications</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-ai">
    <i class="bi bi-stars me-1"></i>AI Assistant</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-permissions">
    <i class="bi bi-shield-lock me-1"></i>Permissions</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-customer-data">
    <i class="bi bi-person-lines-fill me-1"></i>Customer Data</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-audit">
    <i class="bi bi-journal-text me-1"></i>Audit Log</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-integrations">
    <i class="bi bi-plug me-1"></i>Integrations</a></li>
</ul>

<div class="tab-content">

  <!-- ── Fault Types ─────────────────────────────────────────────────────── -->
  <div class="tab-pane fade show active" id="tab-faults">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <span class="text-muted small"><?= count($faultTypes) ?> types configured</span>
      <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addFaultModal">
        <i class="bi bi-plus-lg me-1"></i>Add Fault Type
      </button>
    </div>
    <div class="card-section">
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead class="table-light">
            <tr><th>Name</th><th>Category</th><th>Routes To</th><th>Status</th><th style="width:40px"></th></tr>
          </thead>
          <tbody>
            <?php if (!$faultTypes): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No fault types yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($faultTypes as $f): ?>
            <tr>
              <td class="fw-semibold"><?= htmlspecialchars($f['name']) ?></td>
              <td><span class="badge bg-light text-dark border small"><?= htmlspecialchars($f['category']??'—') ?></span></td>
              <td class="small text-muted"><?= htmlspecialchars($f['route_to']??'—') ?></td>
              <td><span class="badge <?= $f['enabled']?'badge-resolved':'badge-closed' ?>"><?= $f['enabled']?'Active':'Disabled' ?></span></td>
              <td>
                <div class="d-flex gap-1">
                  <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                    onclick="openEditFault(<?= htmlspecialchars(json_encode($f)) ?>)"
                    title="Edit"><i class="bi bi-pencil"></i></button>
                  <form method="POST" onsubmit="return confirm('Delete this fault type?')" class="d-inline">
                    <input type="hidden" name="_action" value="del_fault">
                    <input type="hidden" name="id" value="<?= $f['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Delete"><i class="bi bi-trash3"></i></button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── SLAs & Timers ──────────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="tab-sla">
    <div class="card-section">
      <div class="card-header"><i class="bi bi-stopwatch me-1 text-primary"></i>SLA Thresholds by Priority</div>
      <div class="p-3">
        <p class="small text-muted mb-3">Set the response and resolution time targets for each priority level. Changes take effect immediately for new tickets.</p>
        <table class="table table-sm mb-0">
          <thead class="table-light">
            <tr><th>Priority</th><th>Response Target (hrs)</th><th>Resolution Target (hrs)</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach (['p1'=>'Critical','p2'=>'High','p3'=>'Medium','p4'=>'Low'] as $p => $label): ?>
            <?php $s = $slaByPrio[$p] ?? null; ?>
            <tr>
              <form method="POST">
                <input type="hidden" name="_action" value="add_sla">
                <input type="hidden" name="priority" value="<?= $p ?>">
                <td>
                  <span class="badge badge-<?= $p ?>"><?= strtoupper($p) ?></span>
                  <span class="text-muted small ms-1"><?= $label ?></span>
                </td>
                <td><input type="number" name="response_time_hours" class="form-control form-control-sm" style="width:100px"
                    value="<?= $s['response_time_hours'] ?? ($p==='p1'?1:($p==='p2'?2:($p==='p3'?4:8))) ?>" min="1"></td>
                <td><input type="number" name="resolution_time_hours" class="form-control form-control-sm" style="width:100px"
                    value="<?= $s['resolution_time_hours'] ?? ($p==='p1'?4:($p==='p2'?8:($p==='p3'?24:48))) ?>" min="1"></td>
                <td><button type="submit" class="btn btn-sm btn-outline-primary py-0 px-3">Save</button></td>
              </form>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Installation SLA -->
    <div class="card-section mt-3">
      <div class="card-header"><i class="bi bi-house-gear me-1 text-primary"></i>Installation SLA</div>
      <div class="p-3">
        <p class="small text-muted mb-3">Target turnaround from payment confirmation to a completed installation. Drives the SLA Due dates and overdue flags on the Installations board.</p>
        <form method="POST" class="d-flex align-items-end gap-3 flex-wrap">
          <input type="hidden" name="_action" value="save_installation_sla">
          <div>
            <label class="form-label fw-semibold mb-1 small">Installation completion target</label>
            <div class="input-group" style="max-width:200px">
              <input type="number" name="installationSlaWorkingDays" class="form-control form-control-sm"
                value="<?= htmlspecialchars($cfg['installationSlaWorkingDays'] ?? '10') ?>" min="1" max="60">
              <span class="input-group-text text-muted small">working days</span>
            </div>
          </div>
          <button type="submit" class="btn btn-sm btn-primary">Save</button>
        </form>
      </div>
    </div>

    <!-- SLA Warning Email Timing -->
    <div class="card-section mt-3">
      <div class="card-header"><i class="bi bi-envelope-exclamation me-1 text-primary"></i>SLA Warning Email Timing</div>
      <div class="p-3">
        <form method="POST" class="d-flex align-items-end gap-3 flex-wrap">
          <input type="hidden" name="_action" value="save_sla_warning">
          <div>
            <label class="form-label fw-semibold mb-1 small">Send warning email when SLA breach is within</label>
            <div class="input-group" style="max-width:200px">
              <input type="number" name="slaWarnHours" class="form-control form-control-sm"
                value="<?= htmlspecialchars($cfg['slaWarnHours'] ?? '2') ?>" min="1" max="72">
              <span class="input-group-text text-muted small">hours</span>
            </div>
          </div>
          <button type="submit" class="btn btn-sm btn-primary">Save</button>
        </form>
        <div class="form-text mt-1">When a ticket's SLA deadline is within this window, emails are sent to the assigned engineer, their supervisor, and the ticket creator. Requires the SLA cron to be running.</div>
      </div>
    </div>

    <!-- Automation & Cron Tokens -->
    <div class="card-section mt-3">
      <div class="card-header"><i class="bi bi-shield-lock me-1 text-primary"></i>Automation &amp; Cron Tokens</div>
      <div class="p-3">
        <p class="small text-muted mb-3">These endpoints are called by cron, not by anyone logged in — they now refuse every request until a token is set here. Generate one, then add <code>?token=...</code> to the matching cron command(s) in cPanel &rarr; Cron Jobs.</p>
        <?php if (!empty($_generatedToken)): ?>
        <div class="alert alert-warning py-2 small mb-3">
          <i class="bi bi-exclamation-triangle me-1"></i>
          New <?= $_generatedTokenKey === 'slaCheckToken' ? 'SLA/Digest' : 'Install Sync' ?> token — copy it now, it will not be shown again:
          <code class="d-block mt-1 p-2 bg-white border rounded user-select-all"><?= htmlspecialchars($_generatedToken) ?></code>
        </div>
        <?php endif; ?>
        <?php
        $_host = $_SERVER['HTTP_HOST'] ?? 'yourdomain.com';
        $_slaSet = !empty($cfg['slaCheckToken']);
        $_syncSet = !empty($cfg['installSyncToken']);
        ?>
        <table class="table table-sm mb-0 align-middle">
          <thead class="table-light"><tr><th>Token</th><th>Status</th><th>Used By</th><th style="width:120px"></th></tr></thead>
          <tbody>
            <tr>
              <td class="fw-semibold small">slaCheckToken</td>
              <td><span class="badge bg-<?= $_slaSet ? 'success' : 'danger' ?>"><?= $_slaSet ? 'Configured' : 'Not set — endpoints locked' ?></span></td>
              <td class="small text-muted">sla-check, installation-sla-check, ops-digest, finance-digest, report-digest, webhook-retry</td>
              <td>
                <form method="POST" onsubmit="return confirm('<?= $_slaSet ? 'Regenerating invalidates the current token — update every cron command that uses it, or they will start failing.' : 'Generate a token for these endpoints?' ?>')">
                  <?= csrfField() ?>
                  <input type="hidden" name="_action" value="generate_cron_token">
                  <input type="hidden" name="token_key" value="slaCheckToken">
                  <button class="btn btn-sm btn-outline-primary py-0 px-2"><?= $_slaSet ? 'Regenerate' : 'Generate' ?></button>
                </form>
              </td>
            </tr>
            <tr>
              <td class="fw-semibold small">installSyncToken</td>
              <td><span class="badge bg-<?= $_syncSet ? 'success' : 'danger' ?>"><?= $_syncSet ? 'Configured' : 'Not set — endpoint locked' ?></span></td>
              <td class="small text-muted">installations-sync</td>
              <td>
                <form method="POST" onsubmit="return confirm('<?= $_syncSet ? 'Regenerating invalidates the current token — update the cron command, or it will start failing.' : 'Generate a token for this endpoint?' ?>')">
                  <?= csrfField() ?>
                  <input type="hidden" name="_action" value="generate_cron_token">
                  <input type="hidden" name="token_key" value="installSyncToken">
                  <button class="btn btn-sm btn-outline-primary py-0 px-2"><?= $_syncSet ? 'Regenerate' : 'Generate' ?></button>
                </form>
              </td>
            </tr>
          </tbody>
        </table>
        <div class="form-text mt-2">
          Example cron command once a token is set: <code>curl -s "https://<?= htmlspecialchars($_host) ?>/api/sla-check?token=YOUR_TOKEN" &gt; /dev/null 2&gt;&amp;1</code>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Hubs ───────────────────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="tab-hubs">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <span class="text-muted small"><?= count($hubs) ?> hubs &middot; <?= $totalCitiesMapped ?> cities mapped</span>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#manageLocationsModal">
          <i class="bi bi-signpost-2 me-1"></i>Manage Locations
        </button>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addHubModal">
          <i class="bi bi-plus-lg me-1"></i>Add Hub
        </button>
      </div>
    </div>
    <div class="card-section">
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>Hub</th>
              <th>Location</th>
              <th>Assigned Team <span class="text-muted fw-normal small">(fiber routing)</span></th>
              <th>Maintenance Vendor <span class="text-muted fw-normal small">(ticket routing)</span></th>
              <th>Cities</th>
              <th>Customers</th>
              <th style="width:80px"></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$hubs): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No hubs yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($hubs as $h):
              $hCities   = $cityByHub[$h['id']] ?? [];
              $hCustCnt  = $hubCustomerCounts[$h['id']] ?? 0;
              $hTeam     = $teamById[$h['team_id'] ?? ''] ?? null;
            ?>
            <tr>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($h['name']) ?></div>
                <?php if ($h['lat']): ?>
                <div class="text-muted small font-monospace"><?= htmlspecialchars($h['lat']) ?>, <?= htmlspecialchars($h['lng']) ?></div>
                <?php endif; ?>
              </td>
              <td class="small text-muted"><?= htmlspecialchars($h['location']??'—') ?></td>
              <td>
                <form method="POST" class="d-flex gap-1 align-items-center">
                  <?= csrfField() ?>
                  <input type="hidden" name="_action" value="assign_hub_team">
                  <input type="hidden" name="hub_id" value="<?= $h['id'] ?>">
                  <select name="team_id" class="form-select form-select-sm" style="min-width:155px">
                    <option value="">— None —</option>
                    <?php foreach ($teams as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= ($h['team_id']===$t['id'])?'selected':'' ?>>
                      <?= htmlspecialchars($t['name']) ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm btn-outline-primary py-0 px-2" title="Save assignment">
                    <i class="bi bi-check-lg"></i>
                  </button>
                </form>
              </td>
              <td>
                <form method="POST" class="d-flex gap-1 align-items-center">
                  <?= csrfField() ?>
                  <input type="hidden" name="_action" value="assign_hub_vendor">
                  <input type="hidden" name="hub_id" value="<?= $h['id'] ?>">
                  <select name="maintenance_vendor_id" class="form-select form-select-sm" style="min-width:155px">
                    <option value="">— None (individual routing) —</option>
                    <?php foreach ($vendors as $v): ?>
                    <option value="<?= $v['id'] ?>" <?= ($h['maintenance_vendor_id']??'')===$v['id']?'selected':'' ?>>
                      <?= htmlspecialchars($v['name']) ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm btn-outline-primary py-0 px-2" title="Save assignment">
                    <i class="bi bi-check-lg"></i>
                  </button>
                </form>
              </td>
              <td>
                <button type="button"
                  class="btn btn-sm <?= $hCities ? 'btn-outline-primary' : 'btn-outline-secondary' ?> py-0 px-2"
                  onclick="openManageCities('<?= $h['id'] ?>','<?= htmlspecialchars(addslashes($h['name'])) ?>')">
                  <i class="bi bi-geo me-1"></i><?= count($hCities) ?>
                </button>
              </td>
              <td>
                <span class="badge bg-light text-dark border"><?= number_format($hCustCnt) ?></span>
              </td>
              <td>
                <div class="d-flex gap-1">
                  <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                    onclick="openEditHub(<?= htmlspecialchars(json_encode($h)) ?>)" title="Edit hub">
                    <i class="bi bi-pencil"></i>
                  </button>
                  <form method="POST" onsubmit="return confirm('Delete hub? Tickets and customers are not deleted.')">
                    <?= csrfField() ?>
                    <input type="hidden" name="_action" value="del_hub">
                    <input type="hidden" name="id" value="<?= $h['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Delete"><i class="bi bi-trash3"></i></button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card-section mt-3">
      <div class="card-header"><i class="bi bi-info-circle me-1 text-primary"></i>How Hub-Based Fiber Routing Works</div>
      <div class="p-3 small text-muted">
        <p class="mb-1">When a <strong>Fiber or Installation</strong> ticket is created, the system checks the customer's city, looks it up in the city mappings, and auto-assigns the ticket to an engineer from that hub's team — <strong>skipping the supervisor queue entirely</strong>.</p>
        <p class="mb-0"><strong>Setup:</strong> (1) Assign a team to each hub using the dropdown above. (2) Click the cities badge to add the city names covered by that hub. City names must match what's stored in the customer's mailing city field exactly (case-insensitive).</p>
      </div>
    </div>

    <div class="card-section mt-3">
      <div class="card-header"><i class="bi bi-info-circle me-1 text-primary"></i>How Maintenance Vendor Routing Works</div>
      <div class="p-3 small text-muted">
        <p class="mb-1">When a hub has a <strong>Maintenance Vendor</strong> assigned, every new ticket for that hub's location is routed to that vendor company instead of one individual engineer — <strong>every active user on the vendor's team can see and act on it</strong> (Team & Field &rarr; vendor accounts with that company set), not just whoever an auto-assign pass would have picked. This overrides the fiber/supervisor auto-assign above for that hub.</p>
        <p class="mb-0"><strong>Setup:</strong> (1) Make sure the vendor company exists under Finance &rarr; Vendors, and each of their engineers has a user account with that vendor selected. (2) Assign the vendor to each relevant hub using the dropdown above. Leave it as "None" for hubs that should keep individual auto-assign.</p>
      </div>
    </div>
  </div>

  <!-- ── Branding ───────────────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="tab-branding">
    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card-section">
          <div class="card-header"><i class="bi bi-palette me-1 text-primary"></i>Brand Settings</div>
          <div class="p-4">
            <form method="POST" enctype="multipart/form-data">
              <input type="hidden" name="_action" value="save_branding">
              <div class="mb-3">
                <label class="form-label fw-semibold">Company / Brand Name</label>
                <input type="text" name="companyName" class="form-control"
                  value="<?= htmlspecialchars($companyName) ?>" placeholder="FieldPulse">
                <div class="form-text">Shown in the sidebar, page titles, and customer portal.</div>
              </div>
              <div class="mb-3">
                <label class="form-label fw-semibold">Logo</label>
                <?php if ($companyLogo): ?>
                <div class="mb-2 p-2 border rounded d-inline-flex align-items-center gap-3 bg-light">
                  <img src="<?= htmlspecialchars($companyLogo) ?>?v=<?= time() ?>" alt="Logo"
                       style="height:40px;max-width:160px;object-fit:contain">
                  <div>
                    <div class="small fw-semibold text-dark">Current logo</div>
                    <a href="#" onclick="document.getElementById('logoUrlField').value='';document.getElementById('logoUrlField').closest('form').submit();return false"
                       class="small text-danger">Remove</a>
                  </div>
                </div>
                <?php endif; ?>
                <div class="mb-2">
                  <label class="form-label small fw-semibold mb-1">Upload file <span class="text-muted fw-normal">(PNG, JPG, SVG, WebP · max 2 MB)</span></label>
                  <input type="file" name="companyLogoFile" class="form-control form-control-sm" accept="image/png,image/jpeg,image/gif,image/svg+xml,image/webp">
                </div>
                <div class="d-flex align-items-center gap-2 text-muted small my-2">
                  <hr class="flex-grow-1 m-0"><span>or paste a URL</span><hr class="flex-grow-1 m-0">
                </div>
                <input type="url" id="logoUrlField" name="companyLogo" class="form-control form-control-sm"
                  value="<?= htmlspecialchars($companyLogo) ?>"
                  placeholder="https://example.com/logo.png">
                <div class="form-text">Leave both empty to restore the default icon.</div>
              </div>
              <div class="mb-3">
                <label class="form-label fw-semibold">Favicon <span class="text-muted fw-normal">(browser tab icon)</span></label>
                <?php if ($favicon): ?>
                <div class="mb-2 p-2 border rounded d-inline-flex align-items-center gap-3 bg-light">
                  <img src="<?= htmlspecialchars($favicon) ?>?v=<?= time() ?>" alt="Favicon"
                       style="height:24px;width:24px;object-fit:contain">
                  <div>
                    <div class="small fw-semibold text-dark">Current favicon</div>
                    <a href="#" onclick="document.getElementById('faviconUrlField').value='';document.getElementById('faviconUrlField').closest('form').submit();return false"
                       class="small text-danger">Remove</a>
                  </div>
                </div>
                <?php endif; ?>
                <div class="mb-2">
                  <label class="form-label small fw-semibold mb-1">Upload file <span class="text-muted fw-normal">(ICO or PNG · max 512 KB)</span></label>
                  <input type="file" name="faviconFile" class="form-control form-control-sm" accept="image/x-icon,image/png,.ico">
                </div>
                <div class="d-flex align-items-center gap-2 text-muted small my-2">
                  <hr class="flex-grow-1 m-0"><span>or paste a URL</span><hr class="flex-grow-1 m-0">
                </div>
                <input type="url" id="faviconUrlField" name="favicon" class="form-control form-control-sm"
                  value="<?= htmlspecialchars($favicon) ?>"
                  placeholder="https://example.com/favicon.ico">
                <div class="form-text">Leave both empty to fall back to the logo, or the browser's default icon if there isn't one.</div>
              </div>
              <div class="mb-4">
                <label class="form-label fw-semibold">Primary Colour</label>
                <div class="d-flex align-items-center gap-3 mb-2">
                  <input type="color" name="primaryColor" id="colorPicker"
                    value="<?= htmlspecialchars($primaryColor) ?>"
                    class="form-control form-control-color" style="width:52px;height:38px;cursor:pointer;padding:2px">
                  <div>
                    <code id="colorHex" class="fw-semibold"><?= htmlspecialchars($primaryColor) ?></code>
                    <div class="text-muted small">Buttons, links, sidebar accents, active states</div>
                  </div>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-2">
                  <?php foreach ([
                    '#0ea5e9'=>'Sky Blue','#6366f1'=>'Indigo','#8b5cf6'=>'Violet',
                    '#ec4899'=>'Pink','#f59e0b'=>'Amber','#10b981'=>'Emerald',
                    '#ef4444'=>'Red','#0f172a'=>'Navy'
                  ] as $hex => $name): ?>
                  <button type="button" onclick="setColor('<?= $hex ?>')" title="<?= $name ?>"
                    style="width:30px;height:30px;border-radius:50%;background:<?= $hex ?>;border:2px solid <?= $hex===$primaryColor?'#0f172a':'rgba(0,0,0,.12)' ?>;cursor:pointer;transition:transform .1s"
                    onmouseover="this.style.transform='scale(1.2)'" onmouseout="this.style.transform='scale(1)'"></button>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="mb-4">
                <label class="form-label fw-semibold"><i class="bi bi-clock me-1"></i>Timezone</label>
                <select name="timezone" class="form-select">
                  <?php foreach ($tzOptions as $tzVal => $tzLabel): ?>
                  <option value="<?= htmlspecialchars($tzVal) ?>" <?= $timezone === $tzVal ? 'selected' : '' ?>>
                    <?= htmlspecialchars($tzLabel) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">
                  Controls every date &amp; time shown across the app and the customer portal.
                  Current server time in this zone: <strong><?= date('d M Y, H:i') ?></strong>.
                </div>
              </div>
              <div class="mb-4">
                <label class="form-label fw-semibold">Login Page Notice</label>
                <textarea name="loginNotice" class="form-control" rows="3"
                  placeholder="Leave blank to hide the notice on the login page…"><?= htmlspecialchars($cfg['loginNotice'] ?? '') ?></textarea>
                <div class="form-text">Shown as an info banner below the Sign In button. Leave blank to hide it entirely.</div>
              </div>
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-floppy me-1"></i>Save Branding
              </button>
            </form>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card-section">
          <div class="card-header"><i class="bi bi-eye me-1 text-primary"></i>Live Preview</div>
          <div class="p-4">
            <p class="small text-muted mb-3">Preview of your colour and branding:</p>
            <div style="background:#0f172a;border-radius:.5rem;padding:1rem;margin-bottom:1rem">
              <div style="color:#fff;font-weight:700;margin-bottom:.75rem;font-size:.875rem">
                <span id="previewDot" style="color:<?= htmlspecialchars($primaryColor) ?>">●</span>
                <span id="previewName"> <?= htmlspecialchars($companyName) ?></span>
              </div>
              <div style="background:rgba(255,255,255,.07);border-radius:.3rem;padding:.4rem .75rem;color:rgba(255,255,255,.6);font-size:.8rem;margin-bottom:.3rem">Dashboard</div>
              <div id="previewActive" style="border-radius:.3rem;padding:.4rem .75rem;font-size:.8rem;font-weight:600;background:rgba(14,165,233,.15);color:#0ea5e9">Tickets</div>
              <div style="background:rgba(255,255,255,.07);border-radius:.3rem;padding:.4rem .75rem;color:rgba(255,255,255,.6);font-size:.8rem;margin-top:.3rem">Customers</div>
            </div>
            <button id="previewBtn" style="background:<?= htmlspecialchars($primaryColor) ?>;border:none;color:#fff;border-radius:.4rem;padding:.45rem 1.1rem;font-size:.85rem;font-weight:600;cursor:default">
              Sample Button
            </button>
            <span id="previewLink" style="color:<?= htmlspecialchars($primaryColor) ?>;font-size:.85rem;margin-left:.75rem;cursor:default">View ticket →</span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Notifications (SMTP + Push) ────────────────────────────────────── -->
  <div class="tab-pane fade" id="tab-notifications">

    <!-- Email Notifications -->
    <div class="card-section mb-3">
      <div class="card-header">
        <i class="bi bi-envelope me-1 text-primary"></i>Email Notifications (SMTP)
      </div>
      <div class="p-4">
        <p class="text-muted small mb-4">Configure outbound email for ticket alerts sent to engineers and supervisors.</p>
        <form method="POST" id="smtpForm">
          <input type="hidden" name="_action" value="save_notifications">
          <div class="row g-3 mb-3">
            <div class="col-md-8">
              <label class="form-label fw-semibold">SMTP Host</label>
              <input type="text" name="smtpHost" class="form-control"
                value="<?= htmlspecialchars($smtp['smtpHost']) ?>" placeholder="smtp.gmail.com">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">SMTP Port</label>
              <input type="number" name="smtpPort" class="form-control"
                value="<?= htmlspecialchars($smtp['smtpPort']) ?>" placeholder="587">
            </div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Username / Email</label>
              <input type="text" name="smtpUser" class="form-control" autocomplete="off"
                value="<?= htmlspecialchars($smtp['smtpUser']) ?>" placeholder="admin">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Password / App Password</label>
              <div class="input-group">
                <input type="password" name="smtpPassword" id="smtpPass" class="form-control" autocomplete="new-password"
                  value="<?= htmlspecialchars($smtp['smtpPassword']) ?>" placeholder="••••••••••••">
                <button type="button" class="btn btn-outline-secondary" onclick="togglePass()">
                  <i class="bi bi-eye" id="passEyeIcon"></i>
                </button>
              </div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">From Address</label>
            <input type="text" name="smtpFrom" class="form-control"
              value="<?= htmlspecialchars($smtp['smtpFrom'] ?: ($smtp['smtpFromName'] ? $smtp['smtpFromName'].' <'.$smtp['smtpUser'].'>' : '')) ?>"
              placeholder="FieldPulse Alerts &lt;noreply@yourcompany.com&gt;">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Encryption</label>
            <select name="smtpEncryption" class="form-select" style="max-width:340px">
              <?php foreach (['tls'=>'TLS / STARTTLS (port 587)','ssl'=>'SSL (port 465)','none'=>'None (not recommended)'] as $v => $l): ?>
              <option value="<?=$v?>" <?= $smtp['smtpEncryption']===$v?'selected':'' ?>><?=$l?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="d-flex gap-2 mt-4">
            <button type="button" class="btn btn-outline-secondary" onclick="sendTestEmail()">
              <i class="bi bi-send me-1"></i>Send Test Email
            </button>
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-floppy me-1"></i>Save Email Settings
            </button>
          </div>
        </form>

        <hr class="my-4">

        <p class="fw-semibold mb-2 small"><i class="bi bi-check-circle-fill text-success me-1"></i>Email Triggers</p>
        <ul class="list-unstyled small text-muted mb-0">
          <li class="mb-1"><i class="bi bi-check-circle text-success me-2"></i>Supervisor notified when a new ticket is created and auto-routed to their team</li>
          <li class="mb-1"><i class="bi bi-check-circle text-success me-2"></i>Engineer notified when a ticket is assigned directly to them</li>
          <li><i class="bi bi-check-circle text-success me-2"></i>Engineer notified when the status of their assigned ticket changes</li>
        </ul>
      </div>
    </div>

    <!-- Browser Push Notifications -->
    <div class="card-section">
      <div class="card-header">
        <i class="bi bi-bell me-1 text-primary"></i>Browser Push Notifications
      </div>
      <div class="p-4">
        <p class="text-muted small mb-4">Push notifications are sent to logged-in users' browsers for real-time alerts. Users enable them from the notification bell in the sidebar.</p>

        <div class="p-3 border rounded mb-4" style="background:#f8fafc">
          <div class="fw-semibold small mb-1">VAPID Keys (auto-generated)</div>
          <p class="text-muted small mb-2">VAPID keys are automatically generated when the server starts for the first time. They are stored securely and used to authenticate push messages sent from this server.</p>
          <?php if ($vapidPublic && $vapidPublic !== '(not generated)'): ?>
          <div class="small font-monospace text-muted" style="word-break:break-all;font-size:.72rem">
            <span class="text-muted fw-semibold">Public key:</span> <?= htmlspecialchars(substr($vapidPublic,0,40)) ?>…
          </div>
          <?php else: ?>
          <div class="small text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Keys not yet generated. Restart the server to generate them.</div>
          <?php endif; ?>
        </div>

        <p class="fw-semibold mb-2 small"><i class="bi bi-check-circle-fill text-success me-1"></i>Push Notification Triggers</p>
        <ul class="list-unstyled small text-muted mb-0">
          <li class="mb-1"><i class="bi bi-check-circle text-success me-2"></i>Supervisors receive push alerts for new tickets routed to their team</li>
          <li class="mb-1"><i class="bi bi-check-circle text-success me-2"></i>Engineers receive push alerts when assigned a ticket</li>
          <li><i class="bi bi-check-circle text-success me-2"></i>Engineers receive push alerts on ticket status changes</li>
        </ul>
      </div>
    </div>

  </div>

  <!-- ── AI Assistant ──────────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="tab-ai">
    <div class="card-section">
      <div class="card-header">
        <i class="bi bi-stars me-1 text-primary"></i>AI Assistant (OpenAI)
      </div>
      <div class="p-4">
        <p class="text-muted small mb-4">
          Powers AI-assisted features across the app — ticket triage suggestions, RCA drafting, and more as they're added.
          Uses your own OpenAI API key; nothing is sent anywhere unless a key is set below. Every AI feature falls back to
          normal manual behavior if this isn't configured or a request fails.
        </p>
        <form method="POST" id="aiForm">
          <input type="hidden" name="_action" value="save_ai_settings">
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="aiEnabledSwitch" name="aiAssistantEnabled" value="1"
              <?= ($aiCfg['aiAssistantEnabled'] ?? '1') !== '0' ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="aiEnabledSwitch">Enable AI Assistant features</label>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">OpenAI API Key</label>
            <div class="input-group">
              <input type="password" name="openaiApiKey" id="openaiKeyInput" class="form-control" autocomplete="new-password"
                value="" placeholder="<?= !empty($aiCfg['openaiApiKey']) ? 'sk-••••••••••••••••••••••••  (already saved — leave blank to keep it)' : 'sk-...' ?>">
              <button type="button" class="btn btn-outline-secondary" onclick="toggleOpenaiKey()">
                <i class="bi bi-eye" id="openaiKeyEyeIcon"></i>
              </button>
            </div>
            <div class="form-text">Get a key from <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com/api-keys</a>. Stored key is never shown again after saving — leave this blank on future saves to keep it unchanged.</div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Model</label>
            <select name="openaiModel" class="form-select" style="max-width:340px">
              <?php $curModel = $aiCfg['openaiModel'] ?? 'gpt-4o-mini'; ?>
              <?php foreach (['gpt-4o-mini' => 'gpt-4o-mini (recommended — fast & cheap)', 'gpt-4o' => 'gpt-4o (higher quality, more expensive)'] as $v => $l): ?>
              <option value="<?= $v ?>" <?= $curModel === $v ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-floppy me-1"></i>Save AI Settings
          </button>
        </form>

        <hr class="my-4">

        <p class="fw-semibold mb-2 small"><i class="bi bi-stars text-primary me-1"></i>AI Features</p>
        <ul class="list-unstyled small text-muted mb-0">
          <li class="mb-1"><i class="bi bi-check-circle text-success me-2"></i>Ticket triage — suggests fault type and priority from the description when creating a ticket</li>
          <li class="mb-1"><i class="bi bi-check-circle text-success me-2"></i>RCA drafting — expands an engineer's rough notes into a formal Root Cause / Observation / Corrective Action write-up</li>
          <li><i class="bi bi-check-circle text-success me-2"></i>Recurring-issue check — flags when a new ticket looks like a repeat of a customer's recent issue</li>
        </ul>
      </div>
    </div>

    <!-- Ops Digest -->
    <div class="card-section mt-3">
      <div class="card-header">
        <i class="bi bi-envelope-paper me-1 text-primary"></i>Automated Ops Digest
      </div>
      <div class="p-4">
        <p class="text-muted small mb-4">
          A narrative summary of ticket/installation performance, emailed to whoever you list below. Needs a cron job to
          actually send on a schedule — see the note after saving.
        </p>
        <form method="POST" id="digestForm">
          <input type="hidden" name="_action" value="save_digest_settings">
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="digestEnabledSwitch" name="opsDigestEnabled" value="1"
              <?= ($cfg['opsDigestEnabled'] ?? '0') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="digestEnabledSwitch">Enable ops digest</label>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Recipient Emails</label>
            <input type="text" name="opsDigestEmails" class="form-control"
              value="<?= htmlspecialchars($cfg['opsDigestEmails'] ?? '') ?>" placeholder="manager@mangonetonline.com, ops@mangonetonline.com">
            <div class="form-text">Comma-separated. Sent to all of them each time the digest runs.</div>
          </div>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-floppy me-1"></i>Save Digest Settings
          </button>
        </form>
        <div class="alert alert-light border mt-3 mb-0 small">
          <i class="bi bi-info-circle me-1"></i>Add a cPanel Cron Job (weekly is typical) hitting:
          <code>https://<?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'yourdomain.com') ?>/api/ops-digest</code>
        </div>
      </div>
    </div>

    <!-- Finance Digest -->
    <div class="card-section mt-3">
      <div class="card-header">
        <i class="bi bi-cash-coin me-1 text-primary"></i>Automated Finance Digest
      </div>
      <div class="p-4">
        <p class="text-muted small mb-4">
          A narrative summary of payment request + installation payment activity, emailed to whoever you list below.
          Needs a cron job to actually send on a schedule — see the note after saving.
        </p>
        <form method="POST" id="financeDigestForm">
          <input type="hidden" name="_action" value="save_finance_digest_settings">
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="financeDigestEnabledSwitch" name="financeDigestEnabled" value="1"
              <?= ($cfg['financeDigestEnabled'] ?? '0') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="financeDigestEnabledSwitch">Enable finance digest</label>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Recipient Emails</label>
            <input type="text" name="financeDigestEmails" class="form-control"
              value="<?= htmlspecialchars($cfg['financeDigestEmails'] ?? '') ?>" placeholder="accountant@mangonetonline.com, finance@mangonetonline.com">
            <div class="form-text">Comma-separated. Sent to all of them each time the digest runs.</div>
          </div>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-floppy me-1"></i>Save Finance Digest Settings
          </button>
        </form>
        <div class="alert alert-light border mt-3 mb-0 small">
          <i class="bi bi-info-circle me-1"></i>Add a cPanel Cron Job (weekly is typical) hitting:
          <code>https://<?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'yourdomain.com') ?>/api/finance-digest</code>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Permissions ───────────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="tab-permissions">

    <!-- ── Roles management ─────────────────────────────────────────────── -->
    <?php $rolesAll = getRoles(); $deptLabels = ['fiber'=>'Fiber','noc'=>'NOC','installation'=>'Installation','cx'=>'CX','finance'=>'Finance','executive'=>'Executive']; ?>
    <div class="card-section mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people me-1 text-primary"></i>Roles</span>
        <button class="btn btn-sm btn-primary" onclick="openRole()"><i class="bi bi-plus-lg me-1"></i>New Role</button>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead class="table-light"><tr><th class="ps-3">Role</th><th>Key</th><th>Department</th><th>Type</th><th>Users</th><th class="text-end pe-3">Actions</th></tr></thead>
          <tbody>
            <?php foreach ($rolesAll as $rn => $r):
              $uCount = (int)(dbFetch("SELECT COUNT(*) c FROM users WHERE role=?", [$rn])['c'] ?? 0);
            ?>
            <tr>
              <td class="ps-3 fw-semibold"><?= htmlspecialchars($r['label']) ?></td>
              <td class="small font-monospace text-muted"><?= htmlspecialchars($rn) ?></td>
              <td><?= $r['department'] ? '<span class="badge text-bg-info">'.htmlspecialchars($deptLabels[$r['department']] ?? $r['department']).'</span>' : '<span class="text-muted">—</span>' ?></td>
              <td><?= (int)$r['is_system'] === 1 ? '<span class="badge text-bg-secondary">Built-in</span>' : '<span class="badge text-bg-success">Custom</span>' ?></td>
              <td class="small"><?= $uCount ?></td>
              <td class="text-end pe-3">
                <div class="d-inline-flex gap-1">
                  <button class="btn btn-sm btn-outline-secondary" onclick='openRole(<?= htmlspecialchars(json_encode(["name"=>$rn,"label"=>$r["label"],"department"=>$r["department"],"is_system"=>(int)$r["is_system"]]), ENT_QUOTES) ?>)' title="Edit"><i class="bi bi-pencil"></i></button>
                  <?php if ($rn !== 'admin' && (int)$r['is_system'] === 0): ?>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Delete this role?<?= $uCount>0 ? ' It still has '.$uCount.' user(s).' : '' ?>')">
                    <input type="hidden" name="_action" value="del_role"><input type="hidden" name="name" value="<?= htmlspecialchars($rn) ?>">
                    <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash3"></i></button>
                  </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Role add/edit modal -->
    <div class="modal fade" id="roleModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
      <form method="POST">
        <input type="hidden" name="_action" id="roleAction" value="add_role">
        <div class="modal-header"><h5 class="modal-title" id="roleModalTitle">New Role</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Role Key <span class="text-danger">*</span></label>
            <input type="text" name="name" id="roleName" class="form-control" placeholder="e.g. supervisor-transmission">
            <div class="form-text">Lowercase letters, numbers, hyphens. Cannot be changed after creation.</div>
          </div>
          <div class="mb-3"><label class="form-label fw-semibold">Display Label <span class="text-danger">*</span></label>
            <input type="text" name="label" id="roleLabel" class="form-control" placeholder="e.g. Transmission Supervisor"></div>
          <div class="mb-0"><label class="form-label fw-semibold">Department</label>
            <select name="department" id="roleDept" class="form-select">
              <option value="">— None (sees all or own per permissions) —</option>
              <option value="fiber">Fiber</option><option value="noc">NOC</option>
              <option value="installation">Installation</option><option value="cx">CX</option>
              <option value="finance">Finance</option>
              <option value="executive">Executive</option>
            </select>
            <div class="form-text">If set and the role has “View own department tickets”, members see only this department's tickets.</div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary btn-sm">Save Role</button></div>
      </form>
    </div></div></div>

    <div class="card-section">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-shield-lock me-1 text-primary"></i>Role Permissions</span>
        <span class="text-muted small">Changes take effect on the member's next login</span>
      </div>
      <div class="p-3">
        <p class="small text-muted mb-3">
          Check a box to grant a permission to a role. <strong>Admin always has all permissions</strong>.
          <em>View all tickets</em> = everything; <em>View own department tickets</em> = only the role's department (set above); neither = only tickets the user created or is assigned.
        </p>
        <form method="POST">
          <input type="hidden" name="_action" value="save_permissions">
          <?php
          // Build current permissions map: [role][perm] = true
          $currentPerms = [];
          foreach (roleKeys() as $r) {
              $currentPerms[$r] = array_flip(getPermissionsForRole($r));
          }
          $editableRoles = array_values(array_filter(roleKeys(), fn($r) => $r !== 'admin'));
          ?>
          <div class="table-responsive">
            <table class="table table-sm table-bordered mb-3 align-middle" style="font-size:.8rem;min-width:700px">
              <thead class="table-dark">
                <tr>
                  <th style="min-width:200px">Permission</th>
                  <?php foreach ($editableRoles as $r): ?>
                  <th class="text-center" style="white-space:nowrap"><?= htmlspecialchars($rolesAll[$r]['label'] ?? $r) ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php
                foreach ($PERMISSION_GROUPS as $groupName => $perms):
                ?>
                <tr class="table-light">
                  <td colspan="<?= count($editableRoles)+1 ?>" class="fw-semibold text-muted small py-1 px-2">
                    <i class="bi bi-chevron-right me-1"></i><?= htmlspecialchars($groupName) ?>
                  </td>
                </tr>
                <?php foreach ($perms as $p):
                  $label = ALL_PERMISSIONS[$p] ?? $p;
                ?>
                <tr>
                  <td class="ps-3"><?= htmlspecialchars($label) ?></td>
                  <?php foreach ($editableRoles as $r): ?>
                  <td class="text-center">
                    <input type="checkbox" class="form-check-input"
                      name="perms[<?= $r ?>][<?= $p ?>]" value="1"
                      <?= isset($currentPerms[$r][$p]) ? 'checked' : '' ?>>
                  </td>
                  <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-floppy me-1"></i>Save Permissions
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Customer Data ─────────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="tab-customer-data">
    <div class="card-section mb-3">
      <div class="card-header"><i class="bi bi-person-lines-fill me-1 text-primary"></i>Customer Data Management</div>
      <div class="p-4">
        <p class="text-muted small mb-4">Import and export customer contact lists for the CX team.</p>

        <div class="row g-3">

          <!-- Import -->
          <div class="col-md-6">
            <div class="border rounded p-4 h-100 text-center" style="background:#f8fafc">
              <div style="width:52px;height:52px;border-radius:50%;background:rgba(14,165,233,.1);display:flex;align-items:center;justify-content:center;margin:0 auto 1rem">
                <i class="bi bi-upload" style="font-size:1.4rem;color:var(--primary)"></i>
              </div>
              <h6 class="fw-bold mb-1">Import Contacts</h6>
              <p class="text-muted small mb-3">Upload CSV or JSON file to update customer database.</p>
              <form method="POST" action="/api/customers-import" enctype="multipart/form-data" id="importForm">
                <input type="file" name="file" id="importFile" accept=".csv,.json" style="display:none"
                  onchange="handleImportFile(this)">
                <button type="button" class="btn btn-primary btn-sm px-4" onclick="document.getElementById('importFile').click()">
                  <i class="bi bi-folder2-open me-1"></i>Select File
                </button>
                <div id="importFileName" class="small text-muted mt-2"></div>
              </form>
              <div id="importStats" class="mt-3" style="display:none">
                <div class="alert alert-success alert-dismissible py-2 mb-0">
                  <span id="importStatsText"></span>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" onclick="document.getElementById('importStats').style.display='none'"></button>
                </div>
                <div id="importSkipList" class="mt-2 small text-muted" style="display:none"></div>
              </div>
              <div class="mt-3 pt-3 border-top text-start">
                <p class="small fw-semibold mb-1 text-muted">CSV Format (headers required):</p>
                <code class="small d-block text-muted" style="font-size:.72rem">account_number, name, email, phone, address, mailing_city, mailing_state, plan, status, expiration</code>
                <div class="small text-muted mt-1"><i class="bi bi-info-circle me-1"></i><strong>account_number</strong> and <strong>name</strong> are required — account_number is used to match existing records. (first_name/last_name columns are also accepted instead of name.)</div>
                <a href="/api/customers-export?template=1" class="small text-primary text-decoration-none mt-1 d-inline-block">
                  <i class="bi bi-download me-1"></i>Download template
                </a>
              </div>
            </div>
          </div>

          <!-- Export -->
          <div class="col-md-6">
            <div class="border rounded p-4 h-100 text-center" style="background:#f8fafc">
              <div style="width:52px;height:52px;border-radius:50%;background:rgba(16,185,129,.1);display:flex;align-items:center;justify-content:center;margin:0 auto 1rem">
                <i class="bi bi-download" style="font-size:1.4rem;color:#10b981"></i>
              </div>
              <h6 class="fw-bold mb-1">Export Contacts</h6>
              <p class="text-muted small mb-3">Download current customer database as CSV.</p>
              <a href="/api/customers-export" class="btn btn-outline-secondary btn-sm px-4">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i>Download CSV
              </a>
              <div class="mt-3 pt-3 border-top text-start">
                <p class="small fw-semibold mb-1 text-muted">Export includes:</p>
                <ul class="small text-muted mb-0 ps-3">
                  <li>Account number, name, email, phone</li>
                  <li>Address, plan, and account status</li>
                  <li>Created date</li>
                </ul>
              </div>
            </div>
          </div>

        </div>

        <!-- Import stats -->
        <div id="importStats" class="mt-3" style="display:none">
          <div class="alert alert-success alert-dismissible py-2 mb-0">
            <i class="bi bi-check-circle me-1"></i><span id="importStatsText"></span>
            <button type="button" class="btn-close" data-bs-dismiss="alert" onclick="document.getElementById('importStats').style.display='none'"></button>
          </div>
        </div>
      </div>
    </div>

    <!-- Customer count summary -->
    <?php
    $custTotal   = (int)(dbFetch("SELECT COUNT(*) AS c FROM customers")['c'] ?? 0);
    $custActive  = (int)(dbFetch("SELECT COUNT(*) AS c FROM customers WHERE status='active'")['c'] ?? 0);
    $custWithEmail = (int)(dbFetch("SELECT COUNT(*) AS c FROM customers WHERE email IS NOT NULL AND email<>''")['c'] ?? 0);
    ?>
    <div class="card-section">
      <div class="card-header"><i class="bi bi-bar-chart me-1 text-primary"></i>Database Summary</div>
      <div class="p-4">
        <div class="row g-3">
          <div class="col-sm-4">
            <div class="stat-card text-center py-3">
              <div class="stat-value"><?= $custTotal ?></div>
              <div class="stat-label">Total Customers</div>
            </div>
          </div>
          <div class="col-sm-4">
            <div class="stat-card text-center py-3">
              <div class="stat-value text-success"><?= $custActive ?></div>
              <div class="stat-label">Active</div>
            </div>
          </div>
          <div class="col-sm-4">
            <div class="stat-card text-center py-3">
              <div class="stat-value"><?= $custWithEmail ?></div>
              <div class="stat-label">Have Email</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Audit Log ──────────────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="tab-audit">
    <div class="card-section">
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead class="table-light">
            <tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>Entity ID</th><th>Details</th></tr>
          </thead>
          <tbody>
            <?php if (!$auditLogs): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No audit events yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($auditLogs as $l): ?>
            <tr>
              <td class="small text-nowrap text-muted"><?= date('d M Y H:i', strtotime($l['created_at'])) ?></td>
              <td class="small"><?= htmlspecialchars($l['user_name'] ?? '—') ?></td>
              <td><span class="badge bg-light text-dark border small"><?= htmlspecialchars($l['action'] ?? '') ?></span></td>
              <td class="small"><?= htmlspecialchars($l['entity'] ?? '') ?></td>
              <td class="small font-monospace text-muted" style="font-size:.7rem"><?= htmlspecialchars(substr($l['entity_id']??'—',0,8)) ?>…</td>
              <td class="small text-muted text-truncate" style="max-width:200px"><?= htmlspecialchars($l['details'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ((int)($webhookQueue['pending']??0) > 0 || (int)($webhookQueue['failed']??0) > 0): ?>
    <div class="card-section mt-3">
      <div class="card-header">
        <i class="bi bi-arrow-repeat me-1 text-warning"></i>Webhook Delivery Queue
        <span class="text-muted small ms-1">(<?= (int)($webhookQueue['pending']??0) ?> retrying, <?= (int)($webhookQueue['failed']??0) ?> gave up)</span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th class="ps-3">Key</th><th>Event</th><th>Attempts</th><th>Status</th><th>Last Error</th><th class="pe-3">First Failed</th></tr></thead>
          <tbody>
            <?php foreach ($webhookFailures as $wf): ?>
            <tr>
              <td class="ps-3 small"><?= htmlspecialchars($wf['key_name']) ?></td>
              <td class="small font-monospace"><?= htmlspecialchars($wf['event']) ?></td>
              <td class="small"><?= (int)$wf['attempts'] ?> / <?= WEBHOOK_MAX_ATTEMPTS ?></td>
              <td><span class="badge <?= $wf['status']==='failed'?'bg-danger':'bg-warning' ?>"><?= htmlspecialchars($wf['status']) ?></span></td>
              <td class="small text-muted text-truncate" style="max-width:220px" title="<?= htmlspecialchars($wf['last_error']??'') ?>"><?= htmlspecialchars($wf['last_error']??'—') ?></td>
              <td class="pe-3 small text-muted text-nowrap"><?= date('d M Y H:i', strtotime($wf['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Integrations (API Keys) ─────────────────────────────────────────── -->
  <div class="tab-pane fade" id="tab-integrations">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <span class="text-muted small d-block"><?= count($apiKeys) ?> key<?= count($apiKeys)!==1?'s':'' ?> total</span>
        <span class="text-muted small">External systems read/write FieldPulse data (customers, payment requests) via <code>/api/v1/*</code>, authenticated with these keys.</span>
      </div>
      <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createApiKeyModal">
        <i class="bi bi-plus-lg me-1"></i>New API Key
      </button>
    </div>

    <?php if ($newApiKeyReveal): ?>
    <div class="alert alert-warning">
      <strong><i class="bi bi-exclamation-triangle me-1"></i>Copy this key now — it will not be shown again.</strong>
      <div class="input-group mt-2">
        <input type="text" class="form-control font-monospace" readonly value="<?= htmlspecialchars($newApiKeyReveal['key']) ?>" id="revealedApiKey">
        <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('revealedApiKey').value)"><i class="bi bi-clipboard"></i> Copy</button>
      </div>
      <?php if (!empty($newApiKeyReveal['webhook_secret'])): ?>
      <div class="small text-muted mt-2">Webhook secret (used to verify the <code>X-FieldPulse-Signature</code> header on deliveries to your endpoint):</div>
      <div class="input-group mt-1">
        <input type="text" class="form-control font-monospace" readonly value="<?= htmlspecialchars($newApiKeyReveal['webhook_secret']) ?>" id="revealedWebhookSecret">
        <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('revealedWebhookSecret').value)"><i class="bi bi-clipboard"></i> Copy</button>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card-section">
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead class="table-light">
            <tr><th>Name</th><th>Key</th><th>Scopes</th><th>Webhook</th><th>Rate Limit</th><th>Created</th><th>Last Used</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
            <?php if (!$apiKeys): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No API keys yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($apiKeys as $k): $isRevoked = !empty($k['revoked_at']); ?>
            <tr class="<?= $isRevoked ? 'text-muted' : '' ?>">
              <td class="small fw-semibold"><?= htmlspecialchars($k['name']) ?></td>
              <td class="small font-monospace"><?= htmlspecialchars($k['key_prefix']) ?>…</td>
              <td class="small"><?php foreach (explode(',', $k['scopes']) as $s): ?><span class="badge bg-light text-dark border me-1"><?= htmlspecialchars($s) ?></span><?php endforeach; ?></td>
              <td class="small"><?= $k['webhook_url'] ? '<span class="text-truncate d-inline-block" style="max-width:180px" title="'.htmlspecialchars($k['webhook_url']).'">'.htmlspecialchars($k['webhook_url']).'</span>' : '<span class="text-muted">—</span>' ?></td>
              <td class="small text-muted"><?= (int)($k['rate_limit_per_min'] ?? 60) ?>/min</td>
              <td class="small text-muted text-nowrap"><?= date('d M Y', strtotime($k['created_at'])) ?><br><span style="font-size:.7rem">by <?= htmlspecialchars($k['created_by_name'] ?? '—') ?></span></td>
              <td class="small text-muted text-nowrap"><?= $k['last_used_at'] ? date('d M Y H:i', strtotime($k['last_used_at'])) : 'Never' ?></td>
              <td><?php if ($isRevoked): ?><span class="badge bg-secondary">Revoked</span><?php else: ?><span class="badge bg-success">Active</span><?php endif; ?></td>
              <td class="text-end pe-2">
                <?php if (!$isRevoked): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Revoke this API key? Any integration using it will stop working immediately.')">
                  <input type="hidden" name="_action" value="revoke_api_key">
                  <input type="hidden" name="id" value="<?= $k['id'] ?>">
                  <?= csrfField() ?>
                  <button type="submit" class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-x-lg"></i></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- Add Fault Type Modal -->
<div class="modal fade" id="addFaultModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST"><input type="hidden" name="_action" value="add_fault">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-exclamation-diamond me-1 text-primary"></i>Add Fault Type</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" required placeholder="e.g. Fiber Cut"></div>
        <div class="mb-3"><label class="form-label fw-semibold">Category</label>
          <input type="text" name="category" class="form-control" placeholder="e.g. fiber, noc, installation, maintenance"></div>
        <div class="mb-3"><label class="form-label fw-semibold">Routes To <span class="text-danger">*</span></label>
          <select name="route_to" class="form-select" required>
            <option value="">— Select department —</option>
            <option value="fiber">Fiber Operations (Fiber Supervisor)</option>
            <option value="noc">NOC Operations (NOC Supervisor)</option>
            <option value="installation">Installation (Fiber Supervisor)</option>
            <option value="cx">Customer Experience (CX Supervisor)</option>
          </select>
          <div class="form-text">Determines which supervisor a new ticket of this type is auto-assigned to.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Add Fault Type</button>
      </div>
    </form>
  </div></div>
</div>

<!-- Edit Fault Type Modal -->
<div class="modal fade" id="editFaultModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <input type="hidden" name="_action" value="edit_fault">
      <input type="hidden" name="id" id="ef_id">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-1 text-primary"></i>Edit Fault Type</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
          <input type="text" name="name" id="ef_name" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Category</label>
          <input type="text" name="category" id="ef_category" class="form-control" placeholder="e.g. fiber, noc, installation">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Routes To <span class="text-danger">*</span></label>
          <select name="route_to" id="ef_route_to" class="form-select" required>
            <option value="">— Select department —</option>
            <option value="fiber">Fiber Operations (Fiber Supervisor)</option>
            <option value="noc">NOC Operations (NOC Supervisor)</option>
            <option value="installation">Installation (Fiber Supervisor)</option>
            <option value="cx">Customer Experience (CX Supervisor)</option>
          </select>
        </div>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" name="enabled" id="ef_enabled" role="switch">
          <label class="form-check-label fw-semibold" for="ef_enabled">Active</label>
          <div class="form-text">Uncheck to disable this fault type without deleting it.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-floppy me-1"></i>Save Changes</button>
      </div>
    </form>
  </div></div>
</div>

<script>
function openEditFault(f) {
  document.getElementById('ef_id').value       = f.id;
  document.getElementById('ef_name').value     = f.name || '';
  document.getElementById('ef_category').value = f.category || '';
  document.getElementById('ef_route_to').value = f.route_to || '';
  document.getElementById('ef_enabled').checked = f.enabled == true || f.enabled === 't' || f.enabled === 'true' || f.enabled === '1';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('editFaultModal')).show();
}

function openRole(r) {
  const editing = !!r;
  document.getElementById('roleModalTitle').textContent = editing ? 'Edit Role' : 'New Role';
  document.getElementById('roleAction').value = editing ? 'edit_role' : 'add_role';
  const nameEl = document.getElementById('roleName');
  nameEl.value = editing ? r.name : '';
  nameEl.readOnly = editing;                       // key is immutable once created
  document.getElementById('roleLabel').value = editing ? r.label : '';
  document.getElementById('roleDept').value  = editing ? (r.department || '') : '';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('roleModal')).show();
}

</script>

<!-- Add Hub Modal -->
<div class="modal fade" id="addHubModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="_action" value="add_hub">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-hdd-network me-1 text-primary"></i>Add Hub</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label fw-semibold">Hub Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" required placeholder="e.g. Yaba OLT"></div>
        <div class="mb-3"><label class="form-label fw-semibold">Location / Description</label>
          <input type="text" name="location" class="form-control" placeholder="e.g. Yaba, Lagos"></div>
        <div class="row g-2">
          <div class="col">
            <label class="form-label fw-semibold">Latitude</label>
            <input type="number" step="any" name="lat" class="form-control" placeholder="6.5028">
          </div>
          <div class="col">
            <label class="form-label fw-semibold">Longitude</label>
            <input type="number" step="any" name="lng" class="form-control" placeholder="3.3694">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Add Hub</button>
      </div>
    </form>
  </div></div>
</div>

<!-- ── Edit Hub Modal ─────────────────────────────────────────────────────── -->
<div class="modal fade" id="editHubModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="_action" value="edit_hub">
      <input type="hidden" name="id" id="editHubId">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil me-1 text-primary"></i>Edit Hub</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label fw-semibold">Hub Name <span class="text-danger">*</span></label>
          <input type="text" name="name" id="editHubName" class="form-control" required></div>
        <div class="mb-3"><label class="form-label fw-semibold">Location / Description</label>
          <input type="text" name="location" id="editHubLocation" class="form-control"></div>
        <div class="row g-2">
          <div class="col">
            <label class="form-label fw-semibold">Latitude</label>
            <input type="number" step="any" name="lat" id="editHubLat" class="form-control">
          </div>
          <div class="col">
            <label class="form-label fw-semibold">Longitude</label>
            <input type="number" step="any" name="lng" id="editHubLng" class="form-control">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
      </div>
    </form>
  </div></div>
</div>

<!-- ── Manage Cities Modal ────────────────────────────────────────────────── -->
<div class="modal fade" id="manageCitiesModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title"><i class="bi bi-geo me-1 text-primary"></i>City Mappings — <span id="manageCitiesHubName"></span></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <p class="text-muted small mb-3">Add the city names that belong to this hub. City names are matched case-insensitively against the customer's mailing city. Adding a city will immediately backfill <code>hub_id</code> on all matching customers.</p>
      <form method="POST" class="d-flex gap-2 mb-3" id="addCityForm">
        <?= csrfField() ?>
        <input type="hidden" name="_action" value="add_hub_city">
        <input type="hidden" name="hub_id" id="manageCitiesHubId">
        <input type="text" name="city_name" id="manageCitiesCityInput" class="form-control" placeholder="City name, e.g. Yaba" required>
        <button type="submit" class="btn btn-primary text-nowrap"><i class="bi bi-plus-lg me-1"></i>Add City</button>
      </form>
      <div id="manageCitiesList"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
    </div>
  </div></div>
</div>

<!-- ── Manage Locations Modal — shared canonical location list used by Customers,
     Tickets, and Installations so all three modules offer the same names. ── -->
<div class="modal fade" id="manageLocationsModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title"><i class="bi bi-signpost-2 me-1 text-primary"></i>Locations</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <p class="text-muted small mb-3">These are the location names offered across Customers, Tickets, and Installations. Add a location here to make it available everywhere; it doesn't automatically map to a hub — use City Mappings above for that.</p>
      <form method="POST" class="d-flex gap-2 mb-3">
        <?= csrfField() ?>
        <input type="hidden" name="_action" value="add_location">
        <input type="text" name="name" class="form-control" placeholder="Location name, e.g. Yaba" required>
        <button type="submit" class="btn btn-primary text-nowrap"><i class="bi bi-plus-lg me-1"></i>Add Location</button>
      </form>
      <div style="max-height:320px;overflow-y:auto">
        <?php if (!$locations): ?>
        <p class="text-muted small">No locations yet.</p>
        <?php endif; ?>
        <?php foreach ($locations as $loc): ?>
        <div class="d-flex justify-content-between align-items-center border-bottom py-2">
          <span class="small"><?= htmlspecialchars($loc['name']) ?></span>
          <form method="POST" onsubmit="return confirm('Remove this location?')">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="del_location">
            <input type="hidden" name="id" value="<?= $loc['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
    </div>
  </div></div>
</div>

<!-- Create API Key Modal -->
<div class="modal fade" id="createApiKeyModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST"><input type="hidden" name="_action" value="create_api_key">
      <?= csrfField() ?>
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-plug me-1 text-primary"></i>New API Key</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" required placeholder="e.g. Zoho Books integration">
          <div class="form-text">A label to identify this key later — not shown to the external system.</div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Scopes <span class="text-danger">*</span></label>
          <?php foreach (API_SCOPES as $scopeKey => $scopeLabel): ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="scopes[]" value="<?= $scopeKey ?>" id="scope-<?= $scopeKey ?>">
            <label class="form-check-label small" for="scope-<?= $scopeKey ?>"><code><?= $scopeKey ?></code> — <?= htmlspecialchars($scopeLabel) ?></label>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Webhook URL <span class="text-muted fw-normal">(optional)</span></label>
          <input type="url" name="webhook_url" class="form-control" placeholder="https://example.com/webhooks/fieldpulse">
          <div class="form-text">If set, FieldPulse will POST an event here when a customer is created/updated or a payment request is fully disbursed. A signing secret is generated and shown once, alongside the key. Failed deliveries retry automatically for up to 12 hours.</div>
        </div>
        <div class="mb-0">
          <label class="form-label fw-semibold">Rate Limit <span class="text-muted fw-normal">(requests/minute)</span></label>
          <input type="number" name="rate_limit_per_min" class="form-control" value="60" min="0">
          <div class="form-text">0 = unlimited. Requests over the limit get a 429 response.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Create Key</button>
      </div>
    </form>
  </div></div>
</div>

<script>
// ── Hash-based tab activation (after POST redirect) ───────────────────────
document.addEventListener('DOMContentLoaded', function () {
  const hash = window.location.hash;
  if (hash) {
    const tab = document.querySelector('[data-bs-toggle="tab"][href="' + hash + '"]');
    if (tab) new bootstrap.Tab(tab).show();
  }
});

// ── Hub city management ────────────────────────────────────────────────────
const hubCityData = <?= json_encode($cityByHub, JSON_UNESCAPED_UNICODE) ?>;
const adminCsrf   = <?= json_encode(csrfToken()) ?>;

function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function openManageCities(hubId, hubName) {
  document.getElementById('manageCitiesHubName').textContent = hubName;
  document.getElementById('manageCitiesHubId').value = hubId;
  document.getElementById('manageCitiesCityInput').value = '';

  const cities = hubCityData[hubId] || [];
  const list   = document.getElementById('manageCitiesList');
  if (!cities.length) {
    list.innerHTML = '<p class="text-muted small">No cities mapped yet. Add one above.</p>';
  } else {
    let html = '<div class="list-group list-group-flush border rounded">';
    cities.forEach(c => {
      html += `<div class="list-group-item d-flex justify-content-between align-items-center py-2">
        <span class="small fw-semibold">${escHtml(c.city_name)}</span>
        <form method="POST" class="d-inline" onsubmit="return confirm('Remove city mapping for ${escHtml(c.city_name)}?')">
          <input type="hidden" name="_action" value="del_hub_city">
          <input type="hidden" name="id" value="${escHtml(c.id)}">
          <input type="hidden" name="_csrf" value="${adminCsrf}">
          <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Remove"><i class="bi bi-x-lg"></i></button>
        </form>
      </div>`;
    });
    html += '</div>';
    list.innerHTML = html;
  }
  new bootstrap.Modal(document.getElementById('manageCitiesModal')).show();
}

function openEditHub(hub) {
  document.getElementById('editHubId').value       = hub.id     || '';
  document.getElementById('editHubName').value     = hub.name   || '';
  document.getElementById('editHubLocation').value = hub.location|| '';
  document.getElementById('editHubLat').value      = hub.lat    || '';
  document.getElementById('editHubLng').value      = hub.lng    || '';
  new bootstrap.Modal(document.getElementById('editHubModal')).show();
}

// ── Branding colour picker ─────────────────────────────────────────────────
function setColor(hex) {
  document.getElementById('colorPicker').value = hex;
  document.getElementById('colorHex').textContent = hex;
  document.getElementById('previewDot').style.color = hex;
  document.getElementById('previewActive').style.color = hex;
  document.getElementById('previewActive').style.background = hex + '22';
  document.getElementById('previewBtn').style.background = hex;
  document.getElementById('previewLink').style.color = hex;
  document.querySelectorAll('[onclick^="setColor"]').forEach(b => {
    const bHex = b.getAttribute('onclick').match(/'(#[^']+)'/)?.[1];
    b.style.border = '2px solid ' + (bHex === hex ? '#0f172a' : 'rgba(0,0,0,.12)');
  });
}
document.getElementById('colorPicker').addEventListener('input', function() {
  setColor(this.value);
});

// ── SMTP password toggle ───────────────────────────────────────────────────
function togglePass() {
  const inp  = document.getElementById('smtpPass');
  const icon = document.getElementById('passEyeIcon');
  if (inp.type === 'password') { inp.type = 'text';     icon.className = 'bi bi-eye-slash'; }
  else                         { inp.type = 'password'; icon.className = 'bi bi-eye'; }
}

function toggleOpenaiKey() {
  const inp  = document.getElementById('openaiKeyInput');
  const icon = document.getElementById('openaiKeyEyeIcon');
  if (inp.type === 'password') { inp.type = 'text';     icon.className = 'bi bi-eye-slash'; }
  else                         { inp.type = 'password'; icon.className = 'bi bi-eye'; }
}

// ── Customer import ───────────────────────────────────────────────────────
function handleImportFile(input) {
  if (!input.files.length) return;
  const file = input.files[0];
  document.getElementById('importFileName').textContent = file.name + ' (' + (file.size/1024).toFixed(1) + ' KB)';
  const btn = input.closest('form').querySelector('button');
  const origText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading…';

  const fd = new FormData();
  fd.append('file', file);
  fetch('/api/customers-import', { method: 'POST', body: fd })
    .then(r => r.text().then(bodyText => ({ status: r.status, bodyText })))
    .then(({ status, bodyText }) => {
      let d;
      try {
        d = JSON.parse(bodyText);
      } catch (parseErr) {
        // Server returned something other than clean JSON (e.g. a PHP error page) —
        // surface the raw response instead of a silent generic failure.
        const stats    = document.getElementById('importStats');
        const text     = document.getElementById('importStatsText');
        const skipList = document.getElementById('importSkipList');
        const alertBox = stats.querySelector('.alert');
        text.textContent = `Import failed (HTTP ${status}) — server returned an unexpected response:`;
        alertBox.className = 'alert alert-danger alert-dismissible py-2 mb-0';
        skipList.innerHTML = '<pre class="mb-0 small" style="white-space:pre-wrap">' +
          bodyText.slice(0, 2000).replace(/</g, '&lt;') + '</pre>';
        skipList.style.display = '';
        stats.style.display = '';
        return;
      }
      const stats    = document.getElementById('importStats');
      const text     = document.getElementById('importStatsText');
      const skipList = document.getElementById('importSkipList');
      const alertBox = stats.querySelector('.alert');
      if (d.ok) {
        const parts = [];
        if (d.inserted) parts.push(`${d.inserted} added`);
        if (d.updated)  parts.push(`${d.updated} updated`);
        if (d.skipped)  parts.push(`${d.skipped} skipped`);
        text.textContent = 'Import complete — ' + (parts.join(', ') || 'no changes') + '.';
        alertBox.className = 'alert alert-success alert-dismissible py-2 mb-0';
        if (d.errors && d.errors.length) {
          skipList.innerHTML = '<strong>Skipped rows:</strong><br>' + d.errors.map(e => '• ' + e).join('<br>');
          skipList.style.display = '';
        } else {
          skipList.style.display = 'none';
        }
      } else {
        text.textContent = 'Import failed: ' + (d.error || 'Unknown error');
        alertBox.className = 'alert alert-danger alert-dismissible py-2 mb-0';
        skipList.style.display = 'none';
      }
      stats.style.display = '';
    })
    .catch(err => alert('Upload failed — network error: ' + err.message))
    .finally(() => {
      btn.disabled = false;
      btn.innerHTML = origText;
      input.value = '';
    });
}

// ── Send test email ────────────────────────────────────────────────────────
async function sendTestEmail() {
  const btn = event.target;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending…';
  // Save form first, then send test
  const form = document.getElementById('smtpForm');
  const data = new FormData(form);
  data.set('_action', 'save_notifications');
  await fetch(window.location.href, { method: 'POST', body: data });
  // Then attempt test send
  try {
    const r = await fetch('/api/test-email', { method: 'POST' });
    const d = await r.json();
    if (d.ok) {
      btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Sent!';
      btn.className = 'btn btn-outline-success';
    } else {
      alert('Test email failed: ' + (d.error || 'Unknown error'));
      btn.innerHTML = '<i class="bi bi-send me-1"></i>Send Test Email';
      btn.disabled = false;
    }
  } catch(e) {
    alert('Could not connect. Please save settings and try again.');
    btn.innerHTML = '<i class="bi bi-send me-1"></i>Send Test Email';
    btn.disabled = false;
  }
}
</script>

