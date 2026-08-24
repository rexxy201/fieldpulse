<?php
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('finance.view');

// ─── Payment Requests aggregates ────────────────────────────────────────────
// Now a 3-stage flow: pending -> authorized -> approved -> disbursed, or rejected.
$prByStatus = dbFetch(
    "SELECT SUM(status='pending') AS pending, SUM(status='authorized') AS authorized, SUM(status='approved') AS approved,
            SUM(status='disbursed') AS paid, SUM(status='rejected') AS rejected
     FROM payment_requests"
);
$prAmounts = dbFetch(
    "SELECT
        SUM(CASE WHEN status='pending'  THEN amount ELSE 0 END) AS pending_amount,
        SUM(CASE WHEN status IN ('authorized','approved') THEN amount ELSE 0 END) AS approved_amount,
        SUM(CASE WHEN status='disbursed'     THEN amount ELSE 0 END) AS paid_amount,
        SUM(CASE WHEN status='disbursed' AND paid_at >= " . dbNowMinusInterval(30, 'DAY') . " THEN amount ELSE 0 END) AS paid_last_30d
     FROM payment_requests"
);
$prByVendor = dbFetchAll(
    "SELECT v.name, COUNT(pr.id) AS cnt, SUM(pr.amount) AS total
     FROM payment_requests pr JOIN vendors v ON v.id = pr.vendor_id
     GROUP BY v.id, v.name ORDER BY total DESC LIMIT 8"
);

// ─── Installation financials aggregates ─────────────────────────────────────
$installMoney = dbFetch(
    "SELECT
        SUM(amount_paid) AS total_amount_paid,
        SUM(installation_cost) AS total_cost,
        SUM(installation_paid='Yes') AS paid_count,
        SUM(installation_paid='No') AS unpaid_count,
        SUM(installation_paid IS NULL OR installation_paid='') AS unset_count,
        COUNT(*) AS total_jobs
     FROM installation_profiles"
);
$installByVendor = dbFetchAll(
    "SELECT v.name, COUNT(p.id) AS cnt, SUM(p.amount_paid) AS total_paid, SUM(p.installation_cost) AS total_cost
     FROM installation_profiles p JOIN vendors v ON v.id = p.vendor_id
     WHERE p.amount_paid IS NOT NULL OR p.installation_cost IS NOT NULL
     GROUP BY v.id, v.name ORDER BY total_paid DESC LIMIT 8"
);

$pageTitle = 'Finance';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h2 class="fw-bold mb-0">Finance</h2>
    <div class="text-muted small">Payment requests + installation payment records</div>
  </div>
  <a href="/payment-requests" class="btn btn-outline-primary btn-sm"><i class="bi bi-cash-coin me-1"></i>Payment Requests</a>
</div>

<!-- Payment Requests summary -->
<div class="card-section mb-3">
  <div class="card-header"><i class="bi bi-receipt me-1 text-primary"></i>Payment Requests</div>
  <div class="p-3">
    <div class="row g-2 mb-3">
      <div class="col-6 col-md-3">
        <div class="stat-card py-2 text-center">
          <div class="fw-bold fs-5" style="color:#f59e0b">₦<?= number_format((float)($prAmounts['pending_amount']??0)) ?></div>
          <div style="font-size:.7rem;color:#64748b"><?= (int)($prByStatus['pending']??0) ?> Pending</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card py-2 text-center">
          <div class="fw-bold fs-5" style="color:#3b82f6">₦<?= number_format((float)($prAmounts['approved_amount']??0)) ?></div>
          <div style="font-size:.7rem;color:#64748b"><?= (int)($prByStatus['authorized']??0) + (int)($prByStatus['approved']??0) ?> Authorized/Approved (undisbursed)</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card py-2 text-center">
          <div class="fw-bold fs-5" style="color:#10b981">₦<?= number_format((float)($prAmounts['paid_amount']??0)) ?></div>
          <div style="font-size:.7rem;color:#64748b"><?= (int)($prByStatus['paid']??0) ?> Disbursed (all-time)</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card py-2 text-center">
          <div class="fw-bold fs-5" style="color:#10b981">₦<?= number_format((float)($prAmounts['paid_last_30d']??0)) ?></div>
          <div style="font-size:.7rem;color:#64748b">Disbursed — last 30 days</div>
        </div>
      </div>
    </div>
    <?php if ($prByVendor): ?>
    <p class="small fw-semibold text-muted text-uppercase mb-2">By Vendor</p>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Vendor</th><th class="text-center">Requests</th><th class="text-end">Total</th></tr></thead>
        <tbody>
          <?php foreach ($prByVendor as $v): ?>
          <tr><td class="small"><?= htmlspecialchars($v['name']) ?></td><td class="text-center small"><?= (int)$v['cnt'] ?></td><td class="text-end small fw-semibold">₦<?= number_format((float)$v['total']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Installation financials summary -->
<div class="card-section mb-3">
  <div class="card-header"><i class="bi bi-wifi me-1 text-primary"></i>Installation Payments</div>
  <div class="p-3">
    <div class="row g-2 mb-3">
      <div class="col-6 col-md-3">
        <div class="stat-card py-2 text-center">
          <div class="fw-bold fs-5" style="color:#10b981">₦<?= number_format((float)($installMoney['total_amount_paid']??0)) ?></div>
          <div style="font-size:.7rem;color:#64748b">Total Amount Paid</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card py-2 text-center">
          <div class="fw-bold fs-5" style="color:#64748b">₦<?= number_format((float)($installMoney['total_cost']??0)) ?></div>
          <div style="font-size:.7rem;color:#64748b">Total Installation Cost</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card py-2 text-center">
          <div class="fw-bold fs-5" style="color:#10b981"><?= (int)($installMoney['paid_count']??0) ?></div>
          <div style="font-size:.7rem;color:#64748b">Installation Paid</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card py-2 text-center">
          <div class="fw-bold fs-5" style="color:#ef4444"><?= (int)($installMoney['unpaid_count']??0) ?></div>
          <div style="font-size:.7rem;color:#64748b">Installation Not Paid</div>
        </div>
      </div>
    </div>
    <?php if ($installByVendor): ?>
    <p class="small fw-semibold text-muted text-uppercase mb-2">By Vendor</p>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Vendor</th><th class="text-center">Jobs</th><th class="text-end">Amount Paid</th><th class="text-end">Cost</th></tr></thead>
        <tbody>
          <?php foreach ($installByVendor as $v): ?>
          <tr><td class="small"><?= htmlspecialchars($v['name']) ?></td><td class="text-center small"><?= (int)$v['cnt'] ?></td><td class="text-end small fw-semibold">₦<?= number_format((float)$v['total_paid']) ?></td><td class="text-end small">₦<?= number_format((float)$v['total_cost']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if (aiEnabled()): ?>
<div class="card-section">
  <div class="p-3">
    <label class="form-label small fw-semibold mb-1"><i class="bi bi-stars text-primary me-1"></i>Ask a question about this data</label>
    <div class="d-flex gap-2 flex-wrap">
      <input type="text" id="askDataInput" class="form-control form-control-sm" style="max-width:480px"
        placeholder="e.g. which vendor has the most unpaid requests?">
      <button type="button" class="btn btn-sm btn-primary" id="askDataBtn" onclick="askAboutFinance()">
        <i class="bi bi-send me-1"></i>Ask
      </button>
    </div>
    <div class="small mt-2" id="askDataAnswer"></div>
  </div>
</div>

<script>
const FINANCE_SNAPSHOT = <?= json_encode([
    'payment_requests_by_status' => $prByStatus,
    'payment_requests_amounts'   => $prAmounts,
    'payment_requests_by_vendor' => $prByVendor,
    'installation_money'         => $installMoney,
    'installations_by_vendor'    => $installByVendor,
]) ?>;

function askAboutFinance() {
  const input = document.getElementById('askDataInput');
  const btn = document.getElementById('askDataBtn');
  const answerBox = document.getElementById('askDataAnswer');
  const question = input.value.trim();
  if (!question) return;
  btn.disabled = true;
  answerBox.innerHTML = '<span class="text-muted">Thinking…</span>';

  fetch('/api/ai-ask-finance', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' },
    body: JSON.stringify({ question, snapshot: FINANCE_SNAPSHOT })
  })
    .then(r => r.json().then(data => ({ ok: r.ok, data })))
    .then(({ ok, data }) => {
      if (!ok || data.error) {
        answerBox.innerHTML = '<span class="text-danger">' + (data.error || 'Could not answer that.') + '</span>';
        return;
      }
      answerBox.innerHTML = '<i class="bi bi-stars text-primary me-1"></i>' + data.answer.replace(/\n/g, '<br>');
    })
    .catch(() => { answerBox.innerHTML = '<span class="text-danger">Request failed — try again.</span>'; })
    .finally(() => { btn.disabled = false; });
}
document.getElementById('askDataInput')?.addEventListener('keydown', function (e) {
  if (e.key === 'Enter') { e.preventDefault(); askAboutFinance(); }
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
