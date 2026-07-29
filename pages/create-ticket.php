<?php
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('tickets.create');

$user = currentUser();
$role = $user['role'];

// MySQL: force both sides of the city JOIN to the same collation to avoid error 1267
$_cityJoin = DB_TYPE === 'mysql'
    ? "LOWER(TRIM(hcm.city_name)) COLLATE utf8mb4_general_ci = LOWER(TRIM(c.mailing_city)) COLLATE utf8mb4_general_ci"
    : "LOWER(TRIM(hcm.city_name)) = LOWER(TRIM(c.mailing_city))";
$customers  = dbFetchAll(
    "SELECT c.id, c.name, c.account_number, c.email, c.mailing_city,
            COALESCE(NULLIF(c.hub_id,''), hcm.hub_id) AS resolved_hub_id,
            h.name AS hub_name
     FROM customers c
     LEFT JOIN hub_city_mappings hcm ON {$_cityJoin}
     LEFT JOIN hubs h ON h.id = COALESCE(NULLIF(c.hub_id,''), hcm.hub_id)
     ORDER BY c.name"
);
$hubs       = dbFetchAll("SELECT id,name FROM hubs ORDER BY name");
$faultTypes = dbFetchAll("SELECT id,name,category,route_to FROM fault_types WHERE enabled=1 ORDER BY category,name");
$knownCities = dbFetchAll("SELECT TRIM(city_name) AS city_name FROM hub_city_mappings ORDER BY city_name");

$error = '';
if (method() === 'POST') {
    verifyCsrf();
    $b = $_POST;

    if (empty(trim($b['description'] ?? ''))) {
        $error = 'Description is required.';
    } elseif (empty($b['fault_type_id'])) {
        $error = 'Please select a fault type.';
    } else {
        // Derive ticket type from fault type category
        $ftRow = dbFetch("SELECT category FROM fault_types WHERE id = ?", [$b['fault_type_id']]);
        $cat   = strtolower($ftRow['category'] ?? 'fault');
        $type  = match(true) {
            str_contains($cat, 'install')     => 'installation',
            str_contains($cat, 'maintenance') => 'maintenance',
            default                           => 'fault',
        };

        $sla   = dbFetch("SELECT resolution_time_hours FROM sla_configs WHERE priority = ?", [$b['priority'] ?? 'p3']);
        $hours = $sla ? (int)$sla['resolution_time_hours'] : 24;

        // ─── Scope-based subject resolution ───────────────────────────────
        $scope      = $b['ticket_scope'] ?? 'customer';
        $cust       = null; $cname = ''; $hubId = null; $customerId = null;

        if ($scope === 'city') {
            $cityName = trim($b['city_name'] ?? '');
            if (empty($cityName)) {
                $error = 'City / area name is required for a city outage ticket.';
            } else {
                $cname = $cityName;
                $hubId = getHubIdForCity($cityName);
            }
        } elseif ($scope === 'hub') {
            $hubId = $b['hub_id_hub'] ?? null;
            if (empty($hubId)) {
                $error = 'Please select a hub for a hub outage ticket.';
            } else {
                $hubRow = dbFetch("SELECT name FROM hubs WHERE id=?", [$hubId]);
                $cname  = $hubRow['name'] ?? 'Hub Outage';
            }
        } else { // customer (default)
            $customerId = $b['customer_id'] ?? null;
            $cust  = $customerId ? dbFetch("SELECT id,name,email,mailing_city,hub_id FROM customers WHERE id=?", [$customerId]) : null;
            $cname = $cust['name'] ?? ($b['customer_name_manual'] ?? '');
            $hubId = $b['hub_id'] ?? null;
            if ($cust) {
                $mappedHub = !empty($cust['hub_id']) ? $cust['hub_id']
                           : getHubIdForCity($cust['mailing_city'] ?? '');
                if ($mappedHub) {
                    $hubId = $mappedHub;
                    if (empty($cust['hub_id'])) {
                        dbRun("UPDATE customers SET hub_id=? WHERE id=?", [$mappedHub, $cust['id']]);
                    }
                }
            }
        }

        if (empty($error)) {
            // Auto-assign: fiber/installation → hub team; others → supervisor
            $ftRoute = dbFetch("SELECT route_to FROM fault_types WHERE id=?", [$b['fault_type_id']]);
            $routeTo = strtolower($ftRoute['route_to'] ?? '');
            if (in_array($routeTo, ['fiber', 'installation']) && $hubId) {
                $assignee = getAutoAssignFiber($b['fault_type_id'], $hubId);
            } else {
                $assignee = getAutoAssignSupervisor($b['fault_type_id']);
            }
            $assignedTo = $assignee['id'] ?? null;

            $prefix    = $type === 'installation' ? 'ORD' : 'INC';
            $ticketNum = generateTicketNumber($prefix);
            $newId     = newUuid();

            $_slaExpr = dbNowPlusInterval($hours, 'HOUR');
            dbRun(
                "INSERT INTO tickets (id,ticket_number,description,priority,type,status,ticket_scope,customer_id,customer_name,hub_id,assigned_to,fault_type_id,olt,created_by,sla_breach_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, {$_slaExpr})",
                [$newId, $ticketNum, $b['description'], $b['priority'] ?? 'p3', $type, 'open', $scope,
                 $customerId, $cname, $hubId,
                 $assignedTo, $b['fault_type_id'], $b['olt'] ?? null, $user['id']]
            );

            auditLog('create', 'ticket', $newId);
            $ticket = dbFetch("SELECT * FROM tickets WHERE id = ?", [$newId]);
            $pLabels = ['p1'=>'Critical','p2'=>'High','p3'=>'Medium','p4'=>'Low'];
            $pLabel  = $pLabels[$b['priority'] ?? 'p3'] ?? 'Medium';

            if ($assignee) {
                if (!empty($assignee['email'])) emailTicketAssigned($ticket, $assignee);
                notifyUser(
                    $assignee['id'],
                    "Ticket Assigned — {$ticketNum}",
                    "{$cname}: {$pLabel} — " . substr($b['description'], 0, 80),
                    "/ticket/{$newId}"
                );
            }
            if ($cust) emailCustomerTicketCreated($ticket, $cust);
            notifyRoles(
                ['admin','project_admin'],
                "New Ticket — {$ticketNum}",
                "{$cname}: {$pLabel} — " . substr($b['description'], 0, 80),
                "/ticket/{$newId}"
            );
            header("Location: /ticket/{$newId}"); exit;
        }
    }
}

$pageTitle = 'Create Ticket';
require __DIR__ . '/../includes/header.php';
?>

<!-- Tom Select (searchable dropdown) -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

<div class="row justify-content-center">
<div class="col-lg-8">
<div class="card-section">
  <div class="card-header"><i class="bi bi-plus-circle me-1 text-primary"></i>New Ticket</div>
  <div class="p-4">
    <?php if ($error): ?><div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
      <?= csrfField() ?>

      <!-- Ticket Scope -->
      <div class="mb-4">
        <label class="form-label fw-semibold">Ticket For</label>
        <div class="btn-group w-100" role="group" id="scopeGroup">
          <input type="radio" class="btn-check" name="ticket_scope" id="scope_customer" value="customer" checked autocomplete="off">
          <label class="btn btn-outline-primary" for="scope_customer"><i class="bi bi-person me-1"></i>Customer</label>
          <input type="radio" class="btn-check" name="ticket_scope" id="scope_city" value="city" autocomplete="off">
          <label class="btn btn-outline-primary" for="scope_city"><i class="bi bi-map me-1"></i>City / Area Outage</label>
          <input type="radio" class="btn-check" name="ticket_scope" id="scope_hub" value="hub" autocomplete="off">
          <label class="btn btn-outline-primary" for="scope_hub"><i class="bi bi-hdd-network me-1"></i>Hub Outage</label>
        </div>
        <div class="form-text" id="scopeHint">Select an individual customer for a service fault, or <strong>City / Hub</strong> for a general area outage affecting all customers in that zone.</div>
      </div>

      <!-- Fault Type (required — drives routing) -->
      <div class="mb-3">
        <label class="form-label fw-semibold" for="fault_type_id">
          Fault Type <span class="text-danger">*</span>
          <span class="text-muted fw-normal small">— determines which team receives this ticket</span>
        </label>
        <select name="fault_type_id" id="fault_type_id" class="form-select" required>
          <option value="">— Select fault type —</option>
          <?php
          $lastCat = '';
          foreach ($faultTypes as $f):
            if ($f['category'] !== $lastCat) {
              if ($lastCat !== '') echo '</optgroup>';
              echo '<optgroup label="' . htmlspecialchars(ucfirst($f['category'])) . '">';
              $lastCat = $f['category'];
            }
          ?>
          <option value="<?= $f['id'] ?>" data-category="<?= htmlspecialchars($f['category']) ?>">
            <?= htmlspecialchars($f['name']) ?>
          </option>
          <?php endforeach; if ($lastCat !== '') echo '</optgroup>'; ?>
        </select>
        <div class="form-text"><span id="routeInfo" class="text-muted"></span></div>
      </div>

      <!-- Priority -->
      <div class="mb-3">
        <label class="form-label fw-semibold">Priority</label>
        <select name="priority" class="form-select">
          <option value="p1">P1 – Critical</option>
          <option value="p2">P2 – High</option>
          <option value="p3" selected>P3 – Medium</option>
          <option value="p4">P4 – Low</option>
        </select>
      </div>

      <!-- ── Customer scope ── -->
      <div id="section_customer">
        <div class="row g-3 mb-3">
          <div class="col-sm-7">
            <label class="form-label fw-semibold" for="customer_id">Customer</label>
            <select name="customer_id" id="customer_id" class="form-select">
              <option value="">— Select customer —</option>
              <?php foreach ($customers as $c): ?>
              <option value="<?= $c['id'] ?>"
                data-has-email="<?= !empty($c['email']) ? '1' : '0' ?>"
                data-hub="<?= htmlspecialchars($c['resolved_hub_id'] ?? '') ?>"
                data-hub-name="<?= htmlspecialchars($c['hub_name'] ?? '') ?>">
                <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['account_number']) ?>)
              </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text text-warning d-none" id="noEmailWarn">
              <i class="bi bi-exclamation-triangle me-1"></i>This customer has no email on file and won't receive a confirmation.
            </div>
          </div>
          <div class="col-sm-5">
            <label class="form-label fw-semibold">Hub <span class="text-muted fw-normal small">(optional)</span></label>
            <select name="hub_id" id="hub_id" class="form-select">
              <option value="">— Auto-detect or select —</option>
              <?php foreach ($hubs as $h): ?>
              <option value="<?= $h['id'] ?>"><?= htmlspecialchars($h['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <!-- ── City / Area scope ── -->
      <div id="section_city" style="display:none">
        <div class="mb-3">
          <label class="form-label fw-semibold">City / Area Name <span class="text-danger">*</span></label>
          <input type="text" name="city_name" id="city_name" class="form-control"
            list="citiesList" placeholder="e.g. Yaba, Surulere, Victoria Island" autocomplete="off">
          <datalist id="citiesList">
            <?php foreach ($knownCities as $c): ?>
            <option value="<?= htmlspecialchars($c['city_name']) ?>">
            <?php endforeach; ?>
          </datalist>
          <div class="form-text">Covers all customers in this city. Hub assignment resolves automatically from city mappings.</div>
        </div>
      </div>

      <!-- ── Hub scope ── -->
      <div id="section_hub" style="display:none">
        <div class="mb-3">
          <label class="form-label fw-semibold">Hub <span class="text-danger">*</span></label>
          <select name="hub_id_hub" id="hub_id_hub" class="form-select">
            <option value="">— Select hub —</option>
            <?php foreach ($hubs as $h): ?>
            <option value="<?= $h['id'] ?>"><?= htmlspecialchars($h['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Creates a hub-wide outage ticket assigned to the engineers on that hub's team.</div>
        </div>
      </div>

      <!-- OLT / Node (shared across all scopes) -->
      <div class="mb-3">
        <label class="form-label fw-semibold">OLT / Node <span class="text-muted fw-normal small">(optional)</span></label>
        <input type="text" name="olt" class="form-control" placeholder="e.g. OLT-A / Node 12">
      </div>

      <div class="mb-4">
        <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
        <textarea name="description" class="form-control" rows="4"
          placeholder="Detailed description of the issue…" required></textarea>
      </div>

      <!-- Auto-routing info box -->
      <div class="alert alert-info py-2 small mb-4" id="routingBox" style="display:none">
        <i class="bi bi-arrow-right-circle me-1"></i>
        This ticket will be <strong>automatically assigned</strong> to the <span id="routingTeam">appropriate supervisor</span> upon creation.
        <span id="hubRoutingNote" class="d-none ms-1 badge bg-success">Hub routing active</span>
      </div>

      <div class="d-flex gap-2">
        <a href="/tickets" class="btn btn-outline-secondary flex-grow-1">Cancel</a>
        <button type="submit" class="btn btn-primary flex-grow-1">
          <i class="bi bi-plus-lg me-1"></i>Create Ticket
        </button>
      </div>
    </form>
  </div>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
// Route-to label map
const routeLabels = {
  fiber:        'Fiber team (hub-based direct assignment)',
  noc:          'NOC Operations supervisor',
  installation: 'Fiber/Installation team (hub-based)',
  cx:           'CX supervisor',
};

const scopeHints = {
  customer: 'Select an individual customer for a service fault, or <strong>City / Hub</strong> for a general area outage.',
  city:     'Covers all customers in the named city or area. Hub is resolved automatically from city mappings.',
  hub:      'Creates a hub-wide outage ticket assigned to engineers on that hub\'s team.',
};

const ftSelect = document.getElementById('fault_type_id');
let selectedCustHub = '';
let currentScope = 'customer';

function updateRouteInfo() {
  const opt  = ftSelect.options[ftSelect.selectedIndex];
  const cat  = opt?.dataset?.category || '';
  const label = routeLabels[cat] || '';
  const routeInfo = document.getElementById('routeInfo');
  const box = document.getElementById('routingBox');
  const teamSpan = document.getElementById('routingTeam');
  const hubNote  = document.getElementById('hubRoutingNote');
  const isFiber  = (cat === 'fiber' || cat === 'installation');
  const hasHub   = currentScope === 'hub' || currentScope === 'city' || selectedCustHub;
  if (label) {
    routeInfo.textContent = 'Routes to: ' + label;
    teamSpan.textContent  = label;
    box.style.display = '';
    hubNote.classList.toggle('d-none', !(isFiber && hasHub));
  } else {
    routeInfo.textContent = '';
    box.style.display = 'none';
    hubNote.classList.add('d-none');
  }
}
ftSelect.addEventListener('change', updateRouteInfo);

function updateScope() {
  currentScope = document.querySelector('input[name="ticket_scope"]:checked')?.value || 'customer';
  document.getElementById('section_customer').style.display = currentScope === 'customer' ? '' : 'none';
  document.getElementById('section_city').style.display     = currentScope === 'city'     ? '' : 'none';
  document.getElementById('section_hub').style.display      = currentScope === 'hub'      ? '' : 'none';
  document.getElementById('scopeHint').innerHTML = scopeHints[currentScope] || '';
  updateRouteInfo();
}
document.querySelectorAll('input[name="ticket_scope"]').forEach(r => r.addEventListener('change', updateScope));

document.addEventListener('DOMContentLoaded', function () {
  const custSelect = document.getElementById('customer_id');
  const hubSelect  = document.getElementById('hub_id');
  const ts = new TomSelect('#customer_id', {
    placeholder: 'Search by name or account number…',
    maxOptions: 200,
    create: false,
    sortField: { field: 'text', direction: 'asc' }
  });
  ts.on('change', function(val) {
    const opt = custSelect.querySelector('option[value="' + val + '"]');
    document.getElementById('noEmailWarn').classList.toggle('d-none', !(val && opt && opt.dataset.hasEmail === '0'));
    if (opt && opt.dataset.hub) {
      selectedCustHub = opt.dataset.hub;
      hubSelect.value = opt.dataset.hub;
    } else {
      selectedCustHub = '';
      hubSelect.value = '';
    }
    updateRouteInfo();
  });
  new TomSelect('#fault_type_id', {
    placeholder: '— Select fault type —',
    create: false,
    onItemAdd: function() { updateRouteInfo(); }
  });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>