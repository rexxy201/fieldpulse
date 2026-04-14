<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (!isAdmin()) { http_response_code(403); echo '<h2>Access Denied</h2>'; exit; }

$msg = ''; $msgType = 'success';

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
    if ($action === 'save_branding') {
        foreach (['companyName','companyLogo','primaryColor','loginNotice'] as $k) {
            if (isset($b[$k])) dbUpsertConfig($k, $b[$k]);
        }
        $msg = 'Branding saved. Reload the page to apply colour changes.';
    }
    if ($action === 'save_notifications') {
        foreach (['smtpHost','smtpPort','smtpEncryption','smtpUser','smtpPassword','smtpFrom','smtpFromName'] as $k) {
            dbUpsertConfig($k, $b[$k] ?? '');
        }
        $msg = 'Email settings saved.';
    }
    header('Location: /admin'); exit;
}

$faultTypes = dbFetchAll("SELECT * FROM fault_types ORDER BY category,name");
$slaConfigs = dbFetchAll("SELECT * FROM sla_configs ORDER BY priority");
$hubs       = dbFetchAll("SELECT * FROM hubs ORDER BY name");
$auditLogs  = dbFetchAll("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 100");
$cfg        = getAppConfig();

$slaByPrio = [];
foreach ($slaConfigs as $s) $slaByPrio[$s['priority']] = $s;

// Branding
$primaryColor = $cfg['primaryColor'] ?? '#0ea5e9';
$companyName  = $cfg['companyName'] ?? 'FieldPulse';
$companyLogo  = $cfg['companyLogo'] ?? '';

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

// VAPID keys (for push notifications display)
$vapidPublic  = $cfg['vapidPublicKey'] ?? '(not generated)';

$pageTitle = 'Admin';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-success alert-dismissible py-2 mb-3">
  <i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($msg) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-4" id="adminTabs">
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
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-customer-data">
    <i class="bi bi-person-lines-fill me-1"></i>Customer Data</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-audit">
    <i class="bi bi-journal-text me-1"></i>Audit Log</a></li>
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
  </div>

  <!-- ── Hubs ───────────────────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="tab-hubs">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <span class="text-muted small"><?= count($hubs) ?> hubs configured</span>
      <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addHubModal">
        <i class="bi bi-plus-lg me-1"></i>Add Hub
      </button>
    </div>
    <div class="card-section">
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead class="table-light">
            <tr><th>Hub Name</th><th>Location</th><th>Latitude</th><th>Longitude</th><th style="width:40px"></th></tr>
          </thead>
          <tbody>
            <?php if (!$hubs): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No hubs yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($hubs as $h): ?>
            <tr>
              <td class="fw-semibold"><?= htmlspecialchars($h['name']) ?></td>
              <td class="small text-muted"><?= htmlspecialchars($h['location']??'—') ?></td>
              <td class="small font-monospace"><?= htmlspecialchars($h['lat'] ?? '—') ?></td>
              <td class="small font-monospace"><?= htmlspecialchars($h['lng'] ?? '—') ?></td>
              <td>
                <form method="POST" onsubmit="return confirm('Delete this hub?')">
                  <input type="hidden" name="_action" value="del_hub">
                  <input type="hidden" name="id" value="<?= $h['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash3"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
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
            <form method="POST">
              <input type="hidden" name="_action" value="save_branding">
              <div class="mb-3">
                <label class="form-label fw-semibold">Company / Brand Name</label>
                <input type="text" name="companyName" class="form-control"
                  value="<?= htmlspecialchars($companyName) ?>" placeholder="FieldPulse">
                <div class="form-text">Shown in the sidebar, page titles, and customer portal.</div>
              </div>
              <div class="mb-3">
                <label class="form-label fw-semibold">Logo URL</label>
                <input type="url" name="companyLogo" class="form-control"
                  value="<?= htmlspecialchars($companyLogo) ?>"
                  placeholder="https://example.com/logo.png">
                <div class="form-text">Direct link to your logo (PNG / SVG). Leave blank to use the default icon.</div>
                <?php if ($companyLogo): ?>
                <div class="mt-2 p-2 border rounded d-inline-block bg-light">
                  <img src="<?= htmlspecialchars($companyLogo) ?>" alt="Logo" style="height:32px">
                </div>
                <?php endif; ?>
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
              <div id="importResult" class="mt-2"></div>
              <div class="mt-3 pt-3 border-top text-start">
                <p class="small fw-semibold mb-1 text-muted">CSV Format (headers required):</p>
                <code class="small d-block text-muted" style="font-size:.72rem">name, account_number, email, phone, address, plan, status</code>
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
          <input type="text" name="category" class="form-control" placeholder="e.g. Network, Hardware, Physical"></div>
        <div class="mb-3"><label class="form-label fw-semibold">Routes To</label>
          <input type="text" name="route_to" class="form-control" placeholder="e.g. NOC Team, Field Engineers"></div>
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
          <label class="form-label fw-semibold">Routes To</label>
          <input type="text" name="route_to" id="ef_route_to" class="form-control" placeholder="e.g. fiber, noc">
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
  new bootstrap.Modal(document.getElementById('editFaultModal')).show();
}
</script>

<!-- Add Hub Modal -->
<div class="modal fade" id="addHubModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST"><input type="hidden" name="_action" value="add_hub">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-hdd-network me-1 text-primary"></i>Add Hub</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label fw-semibold">Hub Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" required placeholder="e.g. KL North Hub"></div>
        <div class="mb-3"><label class="form-label fw-semibold">Location / Description</label>
          <input type="text" name="location" class="form-control" placeholder="e.g. Kuala Lumpur City Center"></div>
        <div class="row g-2">
          <div class="col">
            <label class="form-label fw-semibold">Latitude</label>
            <input type="number" step="any" name="lat" class="form-control" placeholder="3.1390">
          </div>
          <div class="col">
            <label class="form-label fw-semibold">Longitude</label>
            <input type="number" step="any" name="lng" class="form-control" placeholder="101.6869">
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

<script>
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
    .then(r => r.json())
    .then(d => {
      const stats = document.getElementById('importStats');
      const text  = document.getElementById('importStatsText');
      if (d.ok) {
        text.textContent = `Import complete — ${d.inserted} inserted, ${d.updated} updated, ${d.skipped} skipped.`;
        stats.style.display = '';
        stats.querySelector('.alert').className = 'alert alert-success alert-dismissible py-2 mb-0';
      } else {
        text.textContent = 'Import failed: ' + (d.error || 'Unknown error');
        stats.style.display = '';
        stats.querySelector('.alert').className = 'alert alert-danger alert-dismissible py-2 mb-0';
      }
    })
    .catch(() => alert('Upload failed. Please try again.'))
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

<?php require __DIR__ . '/../includes/footer.php'; ?>
