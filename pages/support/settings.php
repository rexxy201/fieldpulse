<?php
/**
 * Customer Support — settings (/support/settings): wrap-up codes, the reasons
 * agents pick when logging a contact. Codes are deactivated rather than
 * deleted so past interactions keep their reason.
 */
require_once __DIR__ . '/../../config.php';
requireAuth();
requirePermission('support.manage');

$h   = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$msg = null;

if (method() === 'POST') {
    verifyCsrf();
    $action   = $_POST['_action'] ?? '';
    $category = trim((string)($_POST['category'] ?? ''));
    $name     = trim((string)($_POST['name'] ?? ''));
    if ($action === 'add' && $category !== '' && $name !== '') {
        $max = (int)(dbFetch("SELECT MAX(sort_order) AS m FROM cs_wrap_codes")['m'] ?? 0);
        dbRun("INSERT INTO cs_wrap_codes (id,category,name,active,sort_order) VALUES (?,?,?,1,?)",
            [newUuid(), mb_substr($category, 0, 60), mb_substr($name, 0, 120), $max + 1]);
        auditLog('create', 'cs_wrap_code', '', "$category › $name");
    } elseif ($action === 'rename' && $category !== '' && $name !== '') {
        dbRun("UPDATE cs_wrap_codes SET category = ?, name = ? WHERE id = ?", [mb_substr($category, 0, 60), mb_substr($name, 0, 120), $_POST['id'] ?? '']);
        auditLog('update', 'cs_wrap_code', (string)($_POST['id'] ?? ''), "$category › $name");
    } elseif ($action === 'toggle') {
        dbRun("UPDATE cs_wrap_codes SET active = 1 - active WHERE id = ?", [$_POST['id'] ?? '']);
    } elseif ($action === 'voice') {
        $v = [
            'voiceProvider'               => isset(CS_VOICE_PROVIDERS[$_POST['voiceProvider'] ?? '']) ? $_POST['voiceProvider'] : 'none',
            'atUsername'                  => mb_substr(trim((string)($_POST['atUsername'] ?? '')), 0, 100),
            'atVoiceNumber'               => csE164(trim((string)($_POST['atVoiceNumber'] ?? ''))),
            'voiceRecordCalls'            => !empty($_POST['voiceRecordCalls']) ? '1' : '0',
            'voiceRecordingRetentionDays' => (string)max(1, min(365, (int)($_POST['voiceRecordingRetentionDays'] ?? 30))),
            'voiceRecordingNotice'        => mb_substr(trim((string)($_POST['voiceRecordingNotice'] ?? '')), 0, 300),
            'voiceGreeting'               => mb_substr(trim((string)($_POST['voiceGreeting'] ?? '')), 0, 300),
            'voiceIvrEnabled'             => !empty($_POST['voiceIvrEnabled']) ? '1' : '0',
            'voiceIvrOptions'             => mb_substr((string)($_POST['voiceIvrOptions'] ?? ''), 0, 1000),
            'voiceFallbackNumbers'        => implode(',', array_filter(array_map(fn($n) => csE164(trim($n)), explode(',', (string)($_POST['voiceFallbackNumbers'] ?? ''))))),
            'voiceMissedCallAssignee'     => (string)($_POST['voiceMissedCallAssignee'] ?? ''),
        ];
        // The API key is write-only: an empty field keeps the stored one.
        if (trim((string)($_POST['atApiKey'] ?? '')) !== '') $v['atApiKey'] = trim((string)$_POST['atApiKey']);
        dbUpsertConfigs($v);
        auditLog('update', 'support_voice_settings', '', 'Telephony settings saved');
    } elseif ($action === 'whatsapp') {
        $w = [
            'waProvider'          => isset(CS_WA_PROVIDERS[$_POST['waProvider'] ?? '']) ? $_POST['waProvider'] : 'none',
            'atWaNumber'          => csE164(trim((string)($_POST['atWaNumber'] ?? ''))),
            'metaWaPhoneNumberId' => preg_replace('/\D/', '', (string)($_POST['metaWaPhoneNumberId'] ?? '')),
            'metaWaTemplate'      => preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)($_POST['metaWaTemplate'] ?? '')))),
            'metaWaTemplateLang'  => preg_replace('/[^A-Za-z_]/', '', (string)($_POST['metaWaTemplateLang'] ?? '')) ?: 'en',
            'waAutoReply'         => mb_substr(trim((string)($_POST['waAutoReply'] ?? '')), 0, 1000),
        ];
        // Tokens are write-only: an empty field keeps the stored value.
        foreach (['metaWaAccessToken', 'metaWaAppSecret'] as $k) {
            if (trim((string)($_POST[$k] ?? '')) !== '') $w[$k] = trim((string)$_POST[$k]);
        }
        if (($w['waProvider'] === 'africastalking') && trim((string)($_POST['atApiKey'] ?? '')) !== '') $w['atApiKey'] = trim((string)$_POST['atApiKey']);
        if (($w['waProvider'] === 'africastalking') && trim((string)($_POST['atUsername'] ?? '')) !== '') $w['atUsername'] = mb_substr(trim((string)$_POST['atUsername']), 0, 100);
        dbUpsertConfigs($w);
        auditLog('update', 'support_whatsapp_settings', '', 'WhatsApp settings saved');
    } elseif ($action === 'wa_secret') {
        dbUpsertConfig('waWebhookSecret', bin2hex(random_bytes(20)));
        auditLog('update', 'support_whatsapp_settings', '', 'WhatsApp webhook secret regenerated');
    } elseif ($action === 'voice_secret') {
        dbUpsertConfig('voiceWebhookSecret', bin2hex(random_bytes(20)));
        auditLog('update', 'support_voice_settings', '', 'Callback secret regenerated');
    }
    header('Location: /support/settings'); exit;
}

$codes = csWrapCodesGrouped(false);
$usage = [];
foreach (dbFetchAll("SELECT wrap_code_id, COUNT(*) AS n FROM cs_interactions GROUP BY wrap_code_id") as $u) $usage[$u['wrap_code_id']] = (int)$u['n'];

$voice     = csVoiceSettings();
$wa        = csWaSettings();
$vcfg      = getAppConfig();
$assignees = dbFetchAll("SELECT DISTINCT u.id, u.name FROM users u JOIN role_permissions rp ON rp.role = u.role
                         WHERE rp.permission = 'support.view' OR u.role = 'admin' ORDER BY u.name");

$pageTitle = 'Support Settings';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
  <h2 class="fw-bold mb-0">Support Settings</h2>
  <div class="text-muted small">Wrap-up codes: the reasons agents choose when they log a contact. They drive the “top contact reasons” report.</div>
</div>

<div class="card-section mb-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-telephone me-1 text-primary"></i>Telephony</span>
    <span class="badge bg-<?= csVoiceEnabled($voice) ? 'success' : 'secondary' ?>"><?= csVoiceEnabled($voice) ? 'Voice active' : 'Voice off' ?></span>
  </div>
  <form method="POST" class="p-3">
    <?= csrfField() ?>
    <input type="hidden" name="_action" value="voice">
    <div class="row g-2">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Provider</label>
        <select name="voiceProvider" class="form-select form-select-sm">
          <?php foreach (CS_VOICE_PROVIDERS as $k => $l): ?><option value="<?= $k ?>" <?= $voice['provider'] === $k ? 'selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Africa's Talking username</label>
        <input type="text" name="atUsername" value="<?= $h($voice['at_username']) ?>" class="form-control form-control-sm" autocomplete="off">
      </div>
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">API key <span class="text-muted fw-normal"><?= $voice['at_api_key'] !== '' ? '(set — leave blank to keep)' : '(not set)' ?></span></label>
        <input type="password" name="atApiKey" value="" class="form-control form-control-sm" autocomplete="new-password">
      </div>
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Voice number</label>
        <input type="text" name="atVoiceNumber" value="<?= $h($voice['at_number']) ?>" class="form-control form-control-sm" placeholder="+234…">
      </div>
      <div class="col-sm-8">
        <label class="form-label small fw-semibold mb-1">Greeting</label>
        <input type="text" name="voiceGreeting" value="<?= $h($vcfg['voiceGreeting'] ?? '') ?>" class="form-control form-control-sm" placeholder="Thank you for calling MangoNet.">
      </div>
      <div class="col-sm-4 d-flex align-items-end">
        <div class="form-check"><input class="form-check-input" type="checkbox" name="voiceRecordCalls" id="vRec" value="1" <?= $voice['record'] ? 'checked' : '' ?>><label class="form-check-label small" for="vRec">Record calls</label></div>
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Keep recordings (days)</label>
        <input type="number" min="1" max="365" name="voiceRecordingRetentionDays" value="<?= (int)$voice['retention_days'] ?>" class="form-control form-control-sm">
      </div>
      <div class="col-sm-5">
        <label class="form-label small fw-semibold mb-1">Recording notice (played to callers)</label>
        <input type="text" name="voiceRecordingNotice" value="<?= $h($vcfg['voiceRecordingNotice'] ?? '') ?>" class="form-control form-control-sm" placeholder="<?= $h($voice['announcement']) ?>">
      </div>
      <div class="col-sm-4 d-flex align-items-end">
        <div class="form-check"><input class="form-check-input" type="checkbox" name="voiceIvrEnabled" id="vIvr" value="1" <?= $voice['ivr_enabled'] ? 'checked' : '' ?>><label class="form-check-label small" for="vIvr">Menu (IVR) before connecting</label></div>
      </div>
      <div class="col-sm-6">
        <label class="form-label small fw-semibold mb-1">Menu options <span class="text-muted fw-normal">(one per line: 1=Technical support)</span></label>
        <textarea name="voiceIvrOptions" rows="3" class="form-control form-control-sm"><?= $h(implode("\n", array_map(fn($d, $l) => "$d=$l", array_keys($voice['ivr_options']), $voice['ivr_options']))) ?></textarea>
      </div>
      <div class="col-sm-6">
        <label class="form-label small fw-semibold mb-1">Fallback mobiles <span class="text-muted fw-normal">(rung in order when no agent is available; comma-separated)</span></label>
        <input type="text" name="voiceFallbackNumbers" value="<?= $h(implode(', ', $voice['fallback_numbers'])) ?>" class="form-control form-control-sm" placeholder="+234803…, +234809…">
        <label class="form-label small fw-semibold mb-1 mt-2">Missed calls and voicemails go to</label>
        <select name="voiceMissedCallAssignee" class="form-select form-select-sm">
          <option value="">First supervisor (default)</option>
          <?php foreach ($assignees as $a): ?><option value="<?= $h($a['id']) ?>" <?= $voice['missed_assignee'] === $a['id'] ? 'selected' : '' ?>><?= $h($a['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="mt-3 d-flex justify-content-end"><button class="btn btn-sm btn-primary">Save telephony settings</button></div>
  </form>
  <div class="px-3 pb-3 small">
    <div class="fw-semibold">Callback URL <span class="text-muted fw-normal">— set this as your voice number's callback URL in the Africa's Talking dashboard</span></div>
    <div class="d-flex gap-2 align-items-center mt-1">
      <code class="flex-grow-1 text-break user-select-all p-2 bg-light border rounded"><?= $h(csVoiceCallbackUrl('answer', $voice)) ?></code>
      <form method="POST" onsubmit="return confirm('Regenerate the secret? Calls stop working until you paste the new URL into Africa\'s Talking.')">
        <?= csrfField() ?><input type="hidden" name="_action" value="voice_secret">
        <button class="btn btn-sm btn-outline-secondary">Regenerate</button>
      </form>
    </div>
    <div class="text-muted mt-1">Treat this URL like a password: anyone who has it can send fake call events.</div>
  </div>
</div>

<div class="card-section mb-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-whatsapp me-1 text-success"></i>WhatsApp</span>
    <span class="badge bg-<?= csWaEnabled($wa) ? 'success' : 'secondary' ?>"><?= csWaEnabled($wa) ? 'WhatsApp active' : 'WhatsApp off' ?></span>
  </div>
  <form method="POST" class="p-3">
    <?= csrfField() ?>
    <input type="hidden" name="_action" value="whatsapp">
    <div class="row g-2">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Provider</label>
        <select name="waProvider" class="form-select form-select-sm" id="waProv">
          <?php foreach (CS_WA_PROVIDERS as $k => $l): ?><option value="<?= $k ?>" <?= $wa['provider'] === $k ? 'selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-8 small text-muted d-flex align-items-end">Africa's Talking uses the username and API key from Telephony above.</div>

      <div class="col-sm-4 wa-at">
        <label class="form-label small fw-semibold mb-1">Africa's Talking WhatsApp number</label>
        <input type="text" name="atWaNumber" value="<?= $h($wa['at_wa_number']) ?>" class="form-control form-control-sm" placeholder="+234…">
      </div>
      <?php if ($wa['at_username'] === '' || $wa['at_api_key'] === ''): ?>
      <div class="col-sm-4 wa-at">
        <label class="form-label small fw-semibold mb-1">Africa's Talking username</label>
        <input type="text" name="atUsername" value="<?= $h($wa['at_username']) ?>" class="form-control form-control-sm" autocomplete="off">
      </div>
      <div class="col-sm-4 wa-at">
        <label class="form-label small fw-semibold mb-1">API key <span class="text-muted fw-normal"><?= $wa['at_api_key'] !== '' ? '(set)' : '(not set)' ?></span></label>
        <input type="password" name="atApiKey" value="" class="form-control form-control-sm" autocomplete="new-password">
      </div>
      <?php endif; ?>

      <div class="col-sm-4 wa-meta-f">
        <label class="form-label small fw-semibold mb-1">Phone number ID</label>
        <input type="text" name="metaWaPhoneNumberId" value="<?= $h($wa['meta_phone_id']) ?>" class="form-control form-control-sm" inputmode="numeric">
      </div>
      <div class="col-sm-4 wa-meta-f">
        <label class="form-label small fw-semibold mb-1">Access token <span class="text-muted fw-normal"><?= $wa['meta_token'] !== '' ? '(set — leave blank to keep)' : '(not set)' ?></span></label>
        <input type="password" name="metaWaAccessToken" value="" class="form-control form-control-sm" autocomplete="new-password">
      </div>
      <div class="col-sm-4 wa-meta-f">
        <label class="form-label small fw-semibold mb-1">App secret <span class="text-muted fw-normal"><?= $wa['meta_app_secret'] !== '' ? '(set — leave blank to keep)' : '(required)' ?></span></label>
        <input type="password" name="metaWaAppSecret" value="" class="form-control form-control-sm" autocomplete="new-password">
      </div>
      <div class="col-sm-4 wa-meta-f">
        <label class="form-label small fw-semibold mb-1">Template after 24h <span class="text-muted fw-normal">(approved name, optional)</span></label>
        <input type="text" name="metaWaTemplate" value="<?= $h($wa['meta_template']) ?>" class="form-control form-control-sm" placeholder="e.g. follow_up">
      </div>
      <div class="col-sm-2 wa-meta-f">
        <label class="form-label small fw-semibold mb-1">Language</label>
        <input type="text" name="metaWaTemplateLang" value="<?= $h($wa['meta_template_lang']) ?>" class="form-control form-control-sm" placeholder="en">
      </div>

      <div class="col-12">
        <label class="form-label small fw-semibold mb-1">Automatic reply <span class="text-muted fw-normal">(sent when a new or closed conversation starts; blank = none)</span></label>
        <textarea name="waAutoReply" rows="2" class="form-control form-control-sm" placeholder="Thanks for contacting MangoNet. An agent will reply shortly."><?= $h($wa['auto_reply']) ?></textarea>
      </div>
    </div>
    <div class="mt-3 d-flex justify-content-end"><button class="btn btn-sm btn-primary">Save WhatsApp settings</button></div>
  </form>
  <div class="px-3 pb-3 small">
    <div class="fw-semibold">Webhook URL <span class="text-muted fw-normal">— set this as the incoming-message webhook in the provider's dashboard</span></div>
    <div class="d-flex gap-2 align-items-center mt-1">
      <code class="flex-grow-1 text-break user-select-all p-2 bg-light border rounded"><?= $h(csWaWebhookUrl($wa)) ?></code>
      <form method="POST" onsubmit="return confirm('Regenerate the secret? Messages stop arriving until you paste the new URL into the provider.')">
        <?= csrfField() ?><input type="hidden" name="_action" value="wa_secret">
        <button class="btn btn-sm btn-outline-secondary">Regenerate</button>
      </form>
    </div>
    <div class="wa-meta-f mt-2"><span class="fw-semibold">Verify token</span> <span class="text-muted">(Meta asks for this with the webhook URL)</span>
      <code class="user-select-all ms-1"><?= $h($wa['meta_verify']) ?></code></div>
    <div class="text-muted mt-1">Treat the URL like a password: anyone who has it can inject messages<?= $wa['provider'] === 'meta' ? ' (Meta posts are also signature-checked)' : '' ?>.</div>
  </div>
</div>
<script>
(function () {
  var sel = document.getElementById('waProv');
  function sync() {
    document.querySelectorAll('.wa-at').forEach(function (e) { e.style.display = sel.value === 'africastalking' ? '' : 'none'; });
    document.querySelectorAll('.wa-meta-f').forEach(function (e) { e.style.display = sel.value === 'meta' ? '' : 'none'; });
  }
  sel.addEventListener('change', sync); sync();
})();
</script>

<div class="card-section mb-3">
  <div class="card-header"><i class="bi bi-plus-lg me-1 text-primary"></i>Add a wrap-up code</div>
  <form method="POST" class="p-3 d-flex flex-wrap gap-2 align-items-end">
    <?= csrfField() ?>
    <input type="hidden" name="_action" value="add">
    <div>
      <label class="form-label small fw-semibold mb-1">Category</label>
      <input type="text" name="category" list="csCats" class="form-control form-control-sm" required placeholder="e.g. Technical">
      <datalist id="csCats"><?php foreach (array_keys($codes) as $c): ?><option value="<?= $h($c) ?>"><?php endforeach; ?></datalist>
    </div>
    <div class="flex-grow-1">
      <label class="form-label small fw-semibold mb-1">Reason</label>
      <input type="text" name="name" class="form-control form-control-sm" required placeholder="e.g. Fibre cut">
    </div>
    <button class="btn btn-sm btn-primary">Add</button>
  </form>
</div>

<?php foreach ($codes as $cat => $list): ?>
<div class="card-section mb-3">
  <div class="card-header"><?= $h($cat) ?></div>
  <div class="p-2">
    <?php foreach ($list as $c): ?>
    <form method="POST" class="d-flex flex-wrap gap-2 align-items-center border-bottom p-2 small <?= $c['active'] ? '' : 'opacity-50' ?>">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= $h($c['id']) ?>">
      <input type="text" name="category" value="<?= $h($c['category']) ?>" class="form-control form-control-sm" style="width:160px" aria-label="Category">
      <input type="text" name="name" value="<?= $h($c['name']) ?>" class="form-control form-control-sm flex-grow-1" style="width:auto" aria-label="Reason">
      <span class="text-muted text-nowrap"><?= $usage[$c['id']] ?? 0 ?> uses</span>
      <button name="_action" value="rename" class="btn btn-sm btn-outline-primary py-0">Save</button>
      <button name="_action" value="toggle" class="btn btn-sm btn-outline-<?= $c['active'] ? 'secondary' : 'success' ?> py-0"><?= $c['active'] ? 'Deactivate' : 'Activate' ?></button>
    </form>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
