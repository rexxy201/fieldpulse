<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$user = currentUser();
$role = $user['role'];
if (in_array($role, ['vendor','cx'])) { header('Location: /tickets'); exit; }

$customers  = dbFetchAll("SELECT id,name,account_number FROM customers ORDER BY name");
$hubs       = dbFetchAll("SELECT id,name FROM hubs ORDER BY name");
$engineers  = dbFetchAll("SELECT id,name FROM users WHERE role='engineer' ORDER BY name");
$faultTypes = dbFetchAll("SELECT id,name,category FROM fault_types ORDER BY category,name");

$error = '';
if (method() === 'POST') {
    verifyCsrf();
    $b = $_POST;
    if (empty(trim($b['description']??''))) { $error = 'Description is required.'; }
    else {
        $sla   = dbFetch("SELECT resolution_time_hours FROM sla_configs WHERE priority = ?", [$b['priority']??'p3']);
        $hours = $sla ? (int)$sla['resolution_time_hours'] : 24;
        $cust  = !empty($b['customer_id']) ? dbFetch("SELECT name FROM customers WHERE id=?",[$b['customer_id']]) : null;
        $cname = $cust['name'] ?? ($b['customer_name_manual'] ?? '');
        $prefix    = ($b['type']??'fault') === 'order' ? 'ORD' : 'INC';
        $ticketNum = generateTicketNumber($prefix);
        $newId = newUuid();
        $_slaExpr = dbNowPlusInterval($hours, 'HOUR');
        dbRun(
            "INSERT INTO tickets (id,ticket_number,description,priority,type,status,customer_id,customer_name,hub_id,assigned_to,fault_type_id,olt,created_by,sla_breach_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, {$_slaExpr})",
            [$newId,$ticketNum,$b['description'],$b['priority']??'p3',$b['type']??'fault','open',
             $b['customer_id']??null,$cname,$b['hub_id']??null,
             $b['assigned_to']??null,$b['fault_type_id']??null,$b['olt']??null,$user['id']]
        );
        auditLog('create','ticket',$newId);
        header("Location: /ticket/{$newId}"); exit;
    }
}

$pageTitle = 'Create Ticket';
require __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-8">
<div class="card-section">
  <div class="card-header"><i class="bi bi-plus-circle me-1 text-primary"></i>New Ticket</div>
  <div class="p-4">
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Priority</label>
          <select name="priority" class="form-select">
            <option value="p1">P1 – Critical</option>
            <option value="p2">P2 – High</option>
            <option value="p3" selected>P3 – Medium</option>
            <option value="p4">P4 – Low</option>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Type</label>
          <select name="type" class="form-select">
            <option value="fault">Fault</option>
            <option value="installation">Installation</option>
            <option value="maintenance">Maintenance</option>
          </select>
        </div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Customer</label>
          <select name="customer_id" class="form-select">
            <option value="">— Select customer —</option>
            <?php foreach($customers as $c): ?>
            <option value="<?=$c['id']?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['account_number']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Fault Type</label>
          <select name="fault_type_id" class="form-select">
            <option value="">— Select —</option>
            <?php foreach($faultTypes as $f): ?>
            <option value="<?=$f['id']?>">[<?= htmlspecialchars($f['category']) ?>] <?= htmlspecialchars($f['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Hub</label>
          <select name="hub_id" class="form-select">
            <option value="">— Select hub —</option>
            <?php foreach($hubs as $h): ?>
            <option value="<?=$h['id']?>"><?= htmlspecialchars($h['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Assign To</label>
          <select name="assigned_to" class="form-select">
            <option value="">Unassigned</option>
            <?php foreach($engineers as $e): ?>
            <option value="<?=$e['id']?>"><?= htmlspecialchars($e['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">OLT / Node</label>
        <input type="text" name="olt" class="form-control" placeholder="e.g. OLT-A / Node 12">
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
        <textarea name="description" class="form-control" rows="4" placeholder="Detailed description of the issue…" required></textarea>
      </div>
      <div class="d-flex gap-2">
        <a href="/tickets" class="btn btn-outline-secondary flex-grow-1">Cancel</a>
        <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-plus-lg me-1"></i>Create Ticket</button>
      </div>
    </form>
  </div>
</div>
</div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
