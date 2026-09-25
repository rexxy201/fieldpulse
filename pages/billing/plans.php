<?php
requirePermission('billing.invoices.manage');
$pageTitle = 'Billing Plans';
require_once __DIR__ . '/../../includes/header.php';

$plans = dbFetchAll("SELECT *, (SELECT COUNT(*) FROM customers WHERE billing_plan_id=billing_plans.id AND billing_active=1) AS active_customers FROM billing_plans ORDER BY is_active DESC, name");
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h4 class="mb-0">Billing Plans</h4>
    <p class="text-muted small mb-0">Define subscription packages for recurring invoice generation.</p>
  </div>
  <button class="btn btn-primary" onclick="openPlanModal()">
    <i class="bi bi-plus-lg me-1"></i>New Plan
  </button>
</div>

<div class="card-section">
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Plan Name</th>
          <th class="text-end">Amount</th>
          <th>Cycle</th>
          <th>Billing Day</th>
          <th>Tax %</th>
          <th>Active Customers</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="plansTbody">
        <?php if (!$plans): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No billing plans yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($plans as $p): ?>
        <tr>
          <td class="fw-semibold"><?= htmlspecialchars($p['name']) ?></td>
          <td class="text-end font-monospace"><?= number_format((float)$p['amount'], 2) ?></td>
          <td><?= ucfirst($p['billing_cycle']) ?></td>
          <td>Day <?= (int)$p['billing_day'] ?></td>
          <td><?= number_format((float)$p['tax_rate'], 1) ?>%</td>
          <td><span class="badge bg-light text-dark border"><?= (int)$p['active_customers'] ?></span></td>
          <td><?= $p['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-secondary py-0"
              onclick='editPlan(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)'>
              <i class="bi bi-pencil"></i>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Plan Modal -->
<div class="modal fade" id="planModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="planModalTitle">New Billing Plan</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="planId">
        <div class="mb-3">
          <label class="form-label fw-semibold">Plan Name <span class="text-danger">*</span></label>
          <input type="text" id="planName" class="form-control" placeholder="e.g. Home 5Mbps">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Description</label>
          <input type="text" id="planDesc" class="form-control" placeholder="Optional notes">
        </div>
        <div class="row g-3 mb-3">
          <div class="col-7">
            <label class="form-label fw-semibold">Monthly Amount <span class="text-danger">*</span></label>
            <input type="number" id="planAmount" class="form-control" min="0" step="0.01" placeholder="0.00">
          </div>
          <div class="col-5">
            <label class="form-label fw-semibold">Tax Rate (%)</label>
            <input type="number" id="planTax" class="form-control" min="0" max="100" step="0.01" value="0">
          </div>
        </div>
        <div class="row g-3 mb-3">
          <div class="col-7">
            <label class="form-label fw-semibold">Billing Cycle</label>
            <select id="planCycle" class="form-select">
              <option value="monthly">Monthly</option>
              <option value="quarterly">Quarterly</option>
              <option value="annually">Annually</option>
            </select>
          </div>
          <div class="col-5">
            <label class="form-label fw-semibold">Billing Day</label>
            <input type="number" id="planDay" class="form-control" min="1" max="28" value="1">
            <div class="form-text">Day of month (1–28)</div>
          </div>
        </div>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="planActive" checked>
          <label class="form-check-label" for="planActive">Active</label>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" onclick="savePlan()">Save Plan</button>
      </div>
    </div>
  </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const modal = new bootstrap.Modal(document.getElementById('planModal'));

function openPlanModal() {
  document.getElementById('planModalTitle').textContent = 'New Billing Plan';
  document.getElementById('planId').value = '';
  document.getElementById('planName').value = '';
  document.getElementById('planDesc').value = '';
  document.getElementById('planAmount').value = '';
  document.getElementById('planTax').value = '0';
  document.getElementById('planCycle').value = 'monthly';
  document.getElementById('planDay').value = '1';
  document.getElementById('planActive').checked = true;
  modal.show();
}

function editPlan(p) {
  document.getElementById('planModalTitle').textContent = 'Edit Billing Plan';
  document.getElementById('planId').value = p.id;
  document.getElementById('planName').value = p.name;
  document.getElementById('planDesc').value = p.description || '';
  document.getElementById('planAmount').value = p.amount;
  document.getElementById('planTax').value = p.tax_rate;
  document.getElementById('planCycle').value = p.billing_cycle;
  document.getElementById('planDay').value = p.billing_day;
  document.getElementById('planActive').checked = p.is_active == 1;
  modal.show();
}

async function savePlan() {
  const fd = new FormData();
  fd.append('_csrf', csrf);
  fd.append('action', 'save_plan');
  fd.append('id', document.getElementById('planId').value);
  fd.append('name', document.getElementById('planName').value.trim());
  fd.append('description', document.getElementById('planDesc').value.trim());
  fd.append('amount', document.getElementById('planAmount').value);
  fd.append('tax_rate', document.getElementById('planTax').value);
  fd.append('billing_cycle', document.getElementById('planCycle').value);
  fd.append('billing_day', document.getElementById('planDay').value);
  fd.append('is_active', document.getElementById('planActive').checked ? '1' : '0');

  const r = await fetch('/api/billing-plans', {method:'POST', body:fd});
  const d = await r.json();
  if (d.ok) { modal.hide(); location.reload(); }
  else alert(d.error || 'Save failed');
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
