<?php
require_once __DIR__ . '/../../config.php';
requireAuth();
if (!hasPermission('billing.invoices.view') && !hasPermission('billing.invoices.manage')) {
    header('Location: /dashboard'); exit;
}
$canManage  = hasPermission('billing.invoices.manage');
$canPayment = hasPermission('billing.invoices.record_payment');

// Mark overdue before loading
try {
    db()->exec("UPDATE invoices SET status='overdue'
                WHERE status='sent' AND due_date < CURDATE() AND amount_paid < total");
} catch (\PDOException $e) {
    if (($e->errorInfo[1] ?? 0) !== 1146) throw $e;
}

// Summary counts
try {
    $counts = dbFetch(
        "SELECT
            SUM(status='draft')    AS draft,
            SUM(status='sent')     AS sent,
            SUM(status='partial')  AS partial,
            SUM(status='overdue')  AS overdue,
            SUM(status='paid')     AS paid,
            COUNT(*)               AS total_count,
            SUM(total)             AS total_billed,
            SUM(amount_paid)       AS total_collected,
            SUM(total - amount_paid) AS total_outstanding
         FROM invoices"
    );
} catch (\PDOException $e) {
    if (($e->errorInfo[1] ?? 0) !== 1146) throw $e;
    $counts = [];
}

// Customer list for the create modal
$customers = [];
try {
    $customers = dbFetchAll("SELECT id, name, email, phone, address FROM customers ORDER BY name LIMIT 500");
} catch (\PDOException $e) {}

$activePath = 'billing';
require __DIR__ . '/../../includes/header.php';

$fmt = fn($v) => number_format((float)($v ?? 0), 2);
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold">Invoices</h4>
    <div class="text-muted small">Customer billing &amp; accounts receivable</div>
  </div>
  <?php if ($canManage): ?>
  <button class="btn btn-primary" onclick="openCreateModal()">
    <i class="bi bi-plus-lg"></i> New Invoice
  </button>
  <?php endif; ?>
</div>

<!-- Summary cards -->
<?php if (!empty($counts)): ?>
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card text-center py-3">
      <div class="fs-4 fw-bold text-primary"><?= $fmt($counts['total_billed']) ?></div>
      <div class="small text-muted">Total Billed</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-3">
      <div class="fs-4 fw-bold text-success"><?= $fmt($counts['total_collected']) ?></div>
      <div class="small text-muted">Collected</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-3">
      <div class="fs-4 fw-bold text-danger"><?= $fmt($counts['total_outstanding']) ?></div>
      <div class="small text-muted">Outstanding</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-3">
      <div class="fs-4 fw-bold text-warning"><?= (int)($counts['overdue'] ?? 0) ?></div>
      <div class="small text-muted">Overdue</div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Status filter tabs -->
<ul class="nav nav-tabs mb-3" id="statusTabs">
  <li class="nav-item"><a class="nav-link active" href="#" data-status="">All</a></li>
  <li class="nav-item"><a class="nav-link" href="#" data-status="draft">Draft <span class="badge bg-secondary ms-1"><?= (int)($counts['draft'] ?? 0) ?></span></a></li>
  <li class="nav-item"><a class="nav-link" href="#" data-status="sent">Sent <span class="badge bg-info ms-1"><?= (int)($counts['sent'] ?? 0) ?></span></a></li>
  <li class="nav-item"><a class="nav-link" href="#" data-status="partial">Partial <span class="badge bg-warning text-dark ms-1"><?= (int)($counts['partial'] ?? 0) ?></span></a></li>
  <li class="nav-item"><a class="nav-link" href="#" data-status="overdue">Overdue <span class="badge bg-danger ms-1"><?= (int)($counts['overdue'] ?? 0) ?></span></a></li>
  <li class="nav-item"><a class="nav-link" href="#" data-status="paid">Paid <span class="badge bg-success ms-1"><?= (int)($counts['paid'] ?? 0) ?></span></a></li>
</ul>

<!-- Invoice table -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0" id="invoiceTable">
        <thead class="table-light">
          <tr>
            <th>Invoice #</th>
            <th>Customer</th>
            <th>Issue Date</th>
            <th>Due Date</th>
            <th class="text-end">Total</th>
            <th class="text-end">Balance Due</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="invoiceBody">
          <tr><td colspan="8" class="text-center py-4 text-muted">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── Create Invoice Modal ───────────────────────────────────────────────────-->
<?php if ($canManage): ?>
<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">New Invoice</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Customer -->
        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Customer</label>
            <select id="cCustomer" class="form-select" onchange="fillCustomer(this)">
              <option value="">— select or type manually —</option>
              <?php foreach ($customers as $c): ?>
              <option value="<?= htmlspecialchars($c['id']) ?>"
                data-name="<?= htmlspecialchars($c['name']) ?>"
                data-email="<?= htmlspecialchars($c['email'] ?? '') ?>"
                data-phone="<?= htmlspecialchars($c['phone'] ?? '') ?>"
                data-addr="<?= htmlspecialchars($c['address'] ?? '') ?>">
                <?= htmlspecialchars($c['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Customer Name <span class="text-danger">*</span></label>
            <input type="text" id="cName" class="form-control" placeholder="Name on invoice" required>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Email</label>
            <input type="email" id="cEmail" class="form-control" placeholder="customer@example.com">
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Phone</label>
            <input type="text" id="cPhone" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Tax Rate (%)</label>
            <input type="number" id="cTax" class="form-control" value="0" min="0" max="100" step="0.5" oninput="recalc()">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Issue Date</label>
            <input type="date" id="cIssueDate" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Due Date</label>
            <input type="date" id="cDueDate" class="form-control">
          </div>
        </div>

        <!-- Line items -->
        <div class="fw-semibold mb-2">Line Items</div>
        <div id="lineItems"></div>
        <button type="button" class="btn btn-outline-secondary btn-sm mt-2" onclick="addLine()">
          <i class="bi bi-plus"></i> Add Line
        </button>

        <!-- Totals -->
        <div class="d-flex justify-content-end mt-3">
          <table class="table table-borderless table-sm w-auto text-end mb-0">
            <tr><td class="text-muted pe-3">Subtotal</td><td id="subtotalDisp" class="fw-semibold">0.00</td></tr>
            <tr><td class="text-muted pe-3">Tax</td><td id="taxDisp">0.00</td></tr>
            <tr class="border-top"><td class="text-muted pe-3 pt-2">Total</td><td id="totalDisp" class="fw-bold fs-5 pt-2">0.00</td></tr>
          </table>
        </div>

        <!-- Notes / Terms -->
        <div class="row g-3 mt-1">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Notes</label>
            <textarea id="cNotes" class="form-control" rows="2" placeholder="Visible on invoice…"></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Payment Terms</label>
            <textarea id="cTerms" class="form-control" rows="2" placeholder="e.g. Payment due within 30 days…"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" onclick="submitInvoice()">Create Invoice</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Record Payment Modal ───────────────────────────────────────────────────-->
<?php if ($canPayment): ?>
<div class="modal fade" id="paymentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">Record Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="pInvoiceId">
        <div class="mb-2 text-muted small" id="pInvoiceRef"></div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Amount <span class="text-danger">*</span></label>
            <input type="number" id="pAmount" class="form-control" min="0.01" step="0.01" placeholder="0.00">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Method</label>
            <select id="pMethod" class="form-select">
              <option value="cash">Cash</option>
              <option value="bank_transfer">Bank Transfer</option>
              <option value="mobile_money">Mobile Money</option>
              <option value="cheque">Cheque</option>
              <option value="card">Card</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Reference / Receipt #</label>
            <input type="text" id="pReference" class="form-control" placeholder="Optional">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Payment Date</label>
            <input type="date" id="pDate" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Notes</label>
            <textarea id="pNotes" class="form-control" rows="2"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-success" onclick="submitPayment()">Record Payment</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const CAN_MANAGE  = <?= $canManage  ? 'true' : 'false' ?>;
const CAN_PAYMENT = <?= $canPayment ? 'true' : 'false' ?>;

const STATUS_BADGE = {
  draft:     'bg-secondary',
  sent:      'bg-info text-dark',
  partial:   'bg-warning text-dark',
  overdue:   'bg-danger',
  paid:      'bg-success',
  void:      'bg-dark',
  cancelled: 'bg-dark',
};

let currentStatus = '';

// ── Tab switching ─────────────────────────────────────────────────────────────
document.querySelectorAll('#statusTabs .nav-link').forEach(a => {
  a.addEventListener('click', e => {
    e.preventDefault();
    document.querySelectorAll('#statusTabs .nav-link').forEach(x => x.classList.remove('active'));
    a.classList.add('active');
    currentStatus = a.dataset.status;
    loadInvoices();
  });
});

// ── Load invoices ─────────────────────────────────────────────────────────────
function loadInvoices() {
  const url = '/api/billing?action=list' + (currentStatus ? '&status=' + currentStatus : '');
  fetch(url).then(r => r.json()).then(data => {
    const tbody = document.getElementById('invoiceBody');
    if (!data.invoices || !data.invoices.length) {
      tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">No invoices found</td></tr>';
      return;
    }
    tbody.innerHTML = data.invoices.map(inv => {
      const badge = STATUS_BADGE[inv.status] || 'bg-secondary';
      const balance = parseFloat(inv.balance_due || 0);
      return `<tr>
        <td><a href="/billing/invoice-print?id=${inv.id}" target="_blank" class="fw-semibold text-decoration-none font-monospace">${inv.invoice_number}</a></td>
        <td>${esc(inv.customer_name)}</td>
        <td class="small">${inv.issue_date || '—'}</td>
        <td class="small ${inv.status === 'overdue' ? 'text-danger fw-semibold' : ''}">${inv.due_date || '—'}</td>
        <td class="text-end font-monospace">${fmt(inv.total)}</td>
        <td class="text-end font-monospace ${balance > 0 ? 'text-danger' : 'text-muted'}">${fmt(balance)}</td>
        <td><span class="badge ${badge}">${inv.status}</span></td>
        <td class="text-end">
          ${CAN_PAYMENT && !['void','cancelled','paid'].includes(inv.status)
            ? `<button class="btn btn-sm btn-outline-success me-1" onclick='openPayment(${JSON.stringify(inv)})'>Pay</button>`
            : ''}
          ${CAN_MANAGE && ['draft'].includes(inv.status)
            ? `<button class="btn btn-sm btn-outline-primary me-1" onclick="markSent('${inv.id}')">Send</button>`
            : ''}
          ${CAN_MANAGE && ['draft','void','cancelled'].includes(inv.status)
            ? `<button class="btn btn-sm btn-outline-danger" onclick="deleteInv('${inv.id}','${esc(inv.invoice_number)}')">Del</button>`
            : ''}
          ${CAN_MANAGE && ['sent','partial','overdue'].includes(inv.status)
            ? `<button class="btn btn-sm btn-outline-secondary" onclick="voidInv('${inv.id}')">Void</button>`
            : ''}
        </td>
      </tr>`;
    }).join('');
  });
}
loadInvoices();

function esc(s) {
  const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML;
}
function fmt(v) {
  return parseFloat(v || 0).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
}

// ── Create invoice ────────────────────────────────────────────────────────────
function openCreateModal() {
  document.getElementById('cCustomer').value = '';
  document.getElementById('cName').value     = '';
  document.getElementById('cEmail').value    = '';
  document.getElementById('cPhone').value    = '';
  document.getElementById('cTax').value      = '0';
  document.getElementById('cNotes').value    = '';
  document.getElementById('cTerms').value    = '';
  document.getElementById('lineItems').innerHTML = '';
  addLine(); addLine(); // start with 2 empty lines
  recalc();
  new bootstrap.Modal(document.getElementById('createModal')).show();
}

function fillCustomer(sel) {
  const opt = sel.options[sel.selectedIndex];
  if (!opt.value) return;
  document.getElementById('cName').value  = opt.dataset.name  || '';
  document.getElementById('cEmail').value = opt.dataset.email || '';
  document.getElementById('cPhone').value = opt.dataset.phone || '';
}

let lineSeq = 0;
function addLine() {
  const id = 'line' + (++lineSeq);
  const div = document.createElement('div');
  div.className = 'row g-2 mb-2 align-items-center';
  div.id = id;
  div.innerHTML = `
    <div class="col-6">
      <input type="text" class="form-control form-control-sm line-desc" placeholder="Description" oninput="recalc()">
    </div>
    <div class="col-2">
      <input type="number" class="form-control form-control-sm line-qty" placeholder="Qty" value="1" min="0.001" step="any" oninput="recalc()">
    </div>
    <div class="col-3">
      <input type="number" class="form-control form-control-sm line-price" placeholder="Unit Price" min="0" step="0.01" oninput="recalc()">
    </div>
    <div class="col-1 text-center">
      <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="document.getElementById('${id}').remove(); recalc()">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>`;
  document.getElementById('lineItems').appendChild(div);
}

function recalc() {
  let sub = 0;
  document.querySelectorAll('#lineItems .row').forEach(row => {
    const qty   = parseFloat(row.querySelector('.line-qty')?.value || 0);
    const price = parseFloat(row.querySelector('.line-price')?.value || 0);
    sub += qty * price;
  });
  const taxRate = parseFloat(document.getElementById('cTax').value || 0);
  const tax   = sub * taxRate / 100;
  const total = sub + tax;
  document.getElementById('subtotalDisp').textContent = fmt(sub);
  document.getElementById('taxDisp').textContent      = fmt(tax);
  document.getElementById('totalDisp').textContent    = fmt(total);
}

function submitInvoice() {
  const name = document.getElementById('cName').value.trim();
  if (!name) { alert('Customer name is required'); return; }

  const lines = [];
  document.querySelectorAll('#lineItems .row').forEach(row => {
    const desc  = row.querySelector('.line-desc')?.value.trim();
    const qty   = parseFloat(row.querySelector('.line-qty')?.value || 0);
    const price = parseFloat(row.querySelector('.line-price')?.value || 0);
    if (desc) lines.push({description: desc, qty, unit_price: price});
  });
  if (!lines.length) { alert('Add at least one line item'); return; }

  const fd = new FormData();
  fd.append('_csrf', CSRF);
  fd.append('action', 'create_invoice');
  fd.append('customer_id',    document.getElementById('cCustomer').value);
  fd.append('customer_name',  name);
  fd.append('customer_email', document.getElementById('cEmail').value);
  fd.append('customer_phone', document.getElementById('cPhone').value);
  fd.append('issue_date',     document.getElementById('cIssueDate').value);
  fd.append('due_date',       document.getElementById('cDueDate').value);
  fd.append('tax_rate',       document.getElementById('cTax').value);
  fd.append('notes',          document.getElementById('cNotes').value);
  fd.append('terms',          document.getElementById('cTerms').value);
  fd.append('lines',          JSON.stringify(lines));

  fetch('/api/billing', {method:'POST', body: fd})
    .then(r => r.json()).then(d => {
      if (d.ok) {
        bootstrap.Modal.getInstance(document.getElementById('createModal')).hide();
        loadInvoices();
      } else {
        alert(d.error || 'Error creating invoice');
      }
    });
}

// ── Status changes ────────────────────────────────────────────────────────────
function updateStatus(id, status) {
  const fd = new FormData();
  fd.append('_csrf', CSRF);
  fd.append('action', 'update_status');
  fd.append('id', id);
  fd.append('status', status);
  fetch('/api/billing', {method:'POST', body: fd})
    .then(r => r.json()).then(d => {
      if (d.ok) loadInvoices(); else alert(d.error);
    });
}
function markSent(id)  { updateStatus(id, 'sent'); }
function voidInv(id)   { if (confirm('Void this invoice?')) updateStatus(id, 'void'); }
function deleteInv(id, num) {
  if (!confirm(`Delete invoice ${num}? This cannot be undone.`)) return;
  const fd = new FormData();
  fd.append('_csrf', CSRF); fd.append('action', 'delete_invoice'); fd.append('id', id);
  fetch('/api/billing', {method:'POST', body: fd})
    .then(r => r.json()).then(d => { if (d.ok) loadInvoices(); else alert(d.error); });
}

// ── Record payment ────────────────────────────────────────────────────────────
function openPayment(inv) {
  document.getElementById('pInvoiceId').value = inv.id;
  document.getElementById('pInvoiceRef').textContent =
    inv.invoice_number + ' — Balance: ' + fmt(inv.balance_due);
  document.getElementById('pAmount').value    = parseFloat(inv.balance_due || 0).toFixed(2);
  document.getElementById('pMethod').value    = 'cash';
  document.getElementById('pReference').value = '';
  document.getElementById('pDate').value      = new Date().toISOString().slice(0,10);
  document.getElementById('pNotes').value     = '';
  new bootstrap.Modal(document.getElementById('paymentModal')).show();
}

function submitPayment() {
  const id     = document.getElementById('pInvoiceId').value;
  const amount = document.getElementById('pAmount').value;
  if (!amount || parseFloat(amount) <= 0) { alert('Enter a valid amount'); return; }

  const fd = new FormData();
  fd.append('_csrf', CSRF);
  fd.append('action',    'record_payment');
  fd.append('id',        id);
  fd.append('amount',    amount);
  fd.append('method',    document.getElementById('pMethod').value);
  fd.append('reference', document.getElementById('pReference').value);
  fd.append('paid_at',   document.getElementById('pDate').value);
  fd.append('notes',     document.getElementById('pNotes').value);

  fetch('/api/billing', {method:'POST', body: fd})
    .then(r => r.json()).then(d => {
      if (d.ok) {
        bootstrap.Modal.getInstance(document.getElementById('paymentModal')).hide();
        loadInvoices();
      } else {
        alert(d.error || 'Error recording payment');
      }
    });
}
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
