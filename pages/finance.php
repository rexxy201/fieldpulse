<?php
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('finance.view');

if (method() === 'POST') {
    verifyCsrf();
    $b = $_POST;
    $action = $b['_action'] ?? '';
    if ($action === 'add_budget') {
        $dept = trim($b['department'] ?? '');
        $type = in_array($b['capex_opex'] ?? '', ['Capex','Opex'], true) ? $b['capex_opex'] : null;
        $amt  = (float)($b['monthly_amount'] ?? 0);
        if ($amt > 0) {
            dbRun("INSERT INTO payment_budgets (id,department,capex_opex,monthly_amount,created_by) VALUES (?,?,?,?,?)",
                [newUuid(), $dept !== '' ? $dept : null, $type, $amt, currentUser()['id'] ?? null]);
            auditLog('create', 'payment_budget', '');
        }
    }
    if ($action === 'delete_budget' && !empty($b['id'])) {
        dbRun("DELETE FROM payment_budgets WHERE id=?", [$b['id']]);
        auditLog('delete', 'payment_budget', $b['id']);
    }
    header('Location: /finance#budgets'); exit;
}

// ─── Payment Requests aggregates ────────────────────────────────────────────
// 4-stage flow: pending -> authorized -> approved -> [finance check] -> disbursed
// (which may pass through partially_disbursed if paid in installments, like a
// Zoho Books bill), or returned (sent back to requester for edits) / rejected
// (terminal, Authorize/Approve stage only).
$prByStatus = dbFetch(
    "SELECT SUM(status='pending') AS pending, SUM(status='authorized') AS authorized, SUM(status='approved') AS approved,
            SUM(status='returned') AS returned, SUM(status='partially_disbursed') AS partial, SUM(status='disbursed') AS paid, SUM(status='rejected') AS rejected
     FROM payment_requests"
);
$prAmounts = dbFetch(
    "SELECT
        SUM(CASE WHEN status='pending'  THEN amount ELSE 0 END) AS pending_amount,
        SUM(CASE WHEN status IN ('authorized','approved','partially_disbursed') THEN amount - amount_paid ELSE 0 END) AS approved_amount,
        SUM(amount_paid) AS paid_amount
     FROM payment_requests"
);
$prAmounts['paid_last_30d'] = (float)(dbFetch(
    "SELECT SUM(amount) AS total FROM payment_request_payments WHERE paid_at >= " . dbNowMinusInterval(30, 'DAY')
)['total'] ?? 0);
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

// ─── Vendor scorecard — SLA performance alongside spend ─────────────────────
// The same data Installations Analytics already computes, surfaced here too
// so a manager doesn't need to know that page exists to see it. Finance is
// where "which vendor to keep paying" decisions actually get made.
$_terminalIn = "'" . implode("','", INSTALLATION_TERMINAL_STATUSES) . "'";
$_diffPaymentToCompleted = dbSecondsDiff('p.payment_confirmed_at', 'p.completed_at');
$vendorScorecard = dbFetchAll(
    "SELECT v.id, v.name,
            COUNT(p.id) AS total_assigned,
            SUM(CASE WHEN p.status='connected' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN p.status NOT IN ({$_terminalIn}) AND p.payment_confirmed_at IS NOT NULL AND p.sla_due_at < NOW() THEN 1 ELSE 0 END) AS overdue_now,
            ROUND(AVG(CASE WHEN p.status='connected' AND p.payment_confirmed_at IS NOT NULL THEN ({$_diffPaymentToCompleted})/3600.0 END), 1) AS avg_hours,
            SUM(CASE WHEN p.status='connected' AND p.sla_due_at IS NOT NULL AND p.completed_at <= p.sla_due_at THEN 1 ELSE 0 END) AS on_time,
            SUM(CASE WHEN p.status='connected' AND p.sla_due_at IS NOT NULL THEN 1 ELSE 0 END) AS with_sla
     FROM vendors v
     LEFT JOIN installation_profiles p ON p.vendor_id = v.id
     WHERE v.type = 'installation'
     GROUP BY v.id, v.name
     HAVING total_assigned > 0
     ORDER BY total_assigned DESC
     LIMIT 8"
);
$vendorReassignCounts = dbFetchAll(
    "SELECT old_vendor_id, COUNT(*) AS c FROM installation_vendor_history WHERE old_vendor_id IS NOT NULL GROUP BY old_vendor_id"
);
$vendorReassignById = array_column($vendorReassignCounts, 'c', 'old_vendor_id');

// ─── Budget ceilings ─────────────────────────────────────────────────────────
$budgetStatuses = getBudgetStatuses();
$budgetsNearOrOver = array_filter($budgetStatuses, fn($b) => $b['pct'] >= 80);

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

<?php if ($budgetsNearOrOver): ?>
<div class="alert alert-warning py-2 mb-3">
  <i class="bi bi-speedometer2 me-1"></i>
  <strong>Budget watch — <?= date('F Y') ?>:</strong>
  <?php $_parts = []; foreach ($budgetsNearOrOver as $bw):
    $_label = htmlspecialchars(trim(($bw['department'] ?: 'All Departments') . ($bw['capex_opex'] ? ' · '.$bw['capex_opex'] : '')));
    $_parts[] = "<strong>{$_label}</strong> has used " . (int)$bw['pct'] . "% of its ₦" . number_format($bw['monthly_amount']) . " monthly budget (₦" . number_format($bw['spent']) . " committed)";
  endforeach; echo implode(' &nbsp;·&nbsp; ', $_parts); ?>
  — this doesn't block approvals, it's visibility only.
</div>
<?php endif; ?>

<!-- Payment Requests summary -->
<div class="card-section mb-3">
  <div class="card-header"><i class="bi bi-receipt me-1 text-primary"></i>Payment Requests</div>
  <div class="p-3">
    <div class="row g-2 mb-3">
      <div class="col-6 col-md-3">
        <a href="/payment-requests?status=pending" class="stat-card py-2 text-center text-decoration-none d-block">
          <div class="fw-bold fs-5" style="color:#f59e0b">₦<?= number_format((float)($prAmounts['pending_amount']??0)) ?></div>
          <div style="font-size:.7rem;color:#64748b"><?= (int)($prByStatus['pending']??0) ?> Pending</div>
        </a>
      </div>
      <div class="col-6 col-md-3">
        <a href="/payment-requests?status=authorized" class="stat-card py-2 text-center text-decoration-none d-block">
          <div class="fw-bold fs-5" style="color:#3b82f6">₦<?= number_format((float)($prAmounts['approved_amount']??0)) ?></div>
          <div style="font-size:.7rem;color:#64748b"><?= (int)($prByStatus['authorized']??0) + (int)($prByStatus['approved']??0) + (int)($prByStatus['partial']??0) ?> Authorized/Approved (outstanding balance)<?php if (($prByStatus['partial']??0) > 0): ?> · <?= (int)$prByStatus['partial'] ?> Partial<?php endif; ?><?php if (($prByStatus['returned']??0) > 0): ?> · <?= (int)$prByStatus['returned'] ?> Returned<?php endif; ?></div>
        </a>
      </div>
      <div class="col-6 col-md-3">
        <a href="/payment-requests?status=disbursed" class="stat-card py-2 text-center text-decoration-none d-block">
          <div class="fw-bold fs-5" style="color:#10b981">₦<?= number_format((float)($prAmounts['paid_amount']??0)) ?></div>
          <div style="font-size:.7rem;color:#64748b"><?= (int)($prByStatus['paid']??0) ?> Fully Disbursed (all-time)</div>
        </a>
      </div>
      <div class="col-6 col-md-3">
        <a href="/payment-requests?status=disbursed" class="stat-card py-2 text-center text-decoration-none d-block">
          <div class="fw-bold fs-5" style="color:#10b981">₦<?= number_format((float)($prAmounts['paid_last_30d']??0)) ?></div>
          <div style="font-size:.7rem;color:#64748b">Disbursed — last 30 days</div>
        </a>
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
        <a href="/installations?paid=Yes" class="stat-card py-2 text-center text-decoration-none d-block">
          <div class="fw-bold fs-5" style="color:#10b981">₦<?= number_format((float)($installMoney['total_amount_paid']??0)) ?></div>
          <div style="font-size:.7rem;color:#64748b">Total Amount Paid</div>
        </a>
      </div>
      <div class="col-6 col-md-3">
        <a href="/installations" class="stat-card py-2 text-center text-decoration-none d-block">
          <div class="fw-bold fs-5" style="color:#64748b">₦<?= number_format((float)($installMoney['total_cost']??0)) ?></div>
          <div style="font-size:.7rem;color:#64748b">Total Installation Cost</div>
        </a>
      </div>
      <div class="col-6 col-md-3">
        <a href="/installations?paid=Yes" class="stat-card py-2 text-center text-decoration-none d-block">
          <div class="fw-bold fs-5" style="color:#10b981"><?= (int)($installMoney['paid_count']??0) ?></div>
          <div style="font-size:.7rem;color:#64748b">Installation Paid</div>
        </a>
      </div>
      <div class="col-6 col-md-3">
        <a href="/installations?paid=No" class="stat-card py-2 text-center text-decoration-none d-block">
          <div class="fw-bold fs-5" style="color:#ef4444"><?= (int)($installMoney['unpaid_count']??0) ?></div>
          <div style="font-size:.7rem;color:#64748b">Installation Not Paid</div>
        </a>
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

<!-- ── Vendor Scorecard ──────────────────────────────────────────────────── -->
<?php if ($vendorScorecard): ?>
<div class="card-section mb-3">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span><i class="bi bi-clipboard-data me-1 text-primary"></i>Vendor Scorecard — Installation SLA</span>
    <a href="/installations/analytics" class="small">Full breakdown →</a>
  </div>
  <div class="p-3">
    <p class="text-muted small mb-3">Who's actually delivering, not just who's getting paid — SLA clock starts at payment confirmation, target: <?= INSTALLATION_SLA_WORKING_DAYS ?> working days.</p>
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead class="table-light"><tr>
          <th>Vendor</th><th class="text-center">Assigned</th><th class="text-center">Completed</th>
          <th class="text-center">On-Time %</th><th class="text-center">Avg Hours</th>
          <th class="text-center">Overdue Now</th><th class="text-center">Reassigned</th>
        </tr></thead>
        <tbody>
          <?php foreach ($vendorScorecard as $v):
            $onTimePct = $v['with_sla'] > 0 ? round($v['on_time'] / $v['with_sla'] * 100) : null;
            $pctColor  = $onTimePct === null ? 'text-muted' : ($onTimePct >= 80 ? 'text-success' : ($onTimePct >= 50 ? 'text-warning' : 'text-danger'));
            $reassigned = (int)($vendorReassignById[$v['id']] ?? 0);
          ?>
          <tr>
            <td class="small fw-semibold"><?= htmlspecialchars($v['name']) ?></td>
            <td class="text-center small"><?= (int)$v['total_assigned'] ?></td>
            <td class="text-center small"><?= (int)$v['completed'] ?></td>
            <td class="text-center small fw-semibold <?= $pctColor ?>"><?= $onTimePct !== null ? $onTimePct.'%' : '—' ?></td>
            <td class="text-center small"><?= $v['avg_hours'] !== null ? $v['avg_hours'] : '—' ?></td>
            <td class="text-center small <?= (int)$v['overdue_now'] > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= (int)$v['overdue_now'] ?></td>
            <td class="text-center small <?= $reassigned > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= $reassigned ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Budget Ceilings ───────────────────────────────────────────────────── -->
<div class="card-section mb-3" id="budgets">
  <div class="card-header"><i class="bi bi-speedometer2 me-1 text-primary"></i>Budget Ceilings</div>
  <div class="p-3">
    <p class="text-muted small mb-3">Optional monthly ceilings by department and/or Capex/Opex. This is a soft warning shown above — approvals are never blocked by it.</p>
    <?php if ($budgetStatuses): ?>
    <div class="table-responsive mb-3">
      <table class="table table-sm mb-0 align-middle">
        <thead class="table-light"><tr><th>Department</th><th>Type</th><th class="text-end">Monthly Budget</th><th class="text-end">Committed (<?= date('M') ?>)</th><th style="width:140px">Used</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($budgetStatuses as $bs):
            $barColor = $bs['pct'] >= 100 ? 'bg-danger' : ($bs['pct'] >= 80 ? 'bg-warning' : 'bg-success');
          ?>
          <tr>
            <td class="small"><?= htmlspecialchars($bs['department'] ?: 'All Departments') ?></td>
            <td class="small"><?= htmlspecialchars($bs['capex_opex'] ?: 'All') ?></td>
            <td class="text-end small">₦<?= number_format($bs['monthly_amount']) ?></td>
            <td class="text-end small">₦<?= number_format($bs['spent']) ?></td>
            <td>
              <div class="progress" style="height:14px">
                <div class="progress-bar <?= $barColor ?>" style="width:<?= min(100,$bs['pct']) ?>%"></div>
              </div>
              <div class="small text-muted text-center"><?= (int)$bs['pct'] ?>%</div>
            </td>
            <td class="text-end">
              <form method="POST" onsubmit="return confirm('Remove this budget ceiling?')">
                <input type="hidden" name="_action" value="delete_budget">
                <input type="hidden" name="id" value="<?= $bs['id'] ?>">
                <?= csrfField() ?>
                <button type="submit" class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <p class="text-muted small mb-3">No budget ceilings set yet.</p>
    <?php endif; ?>
    <form method="POST" class="d-flex gap-2 flex-wrap align-items-end">
      <input type="hidden" name="_action" value="add_budget">
      <?= csrfField() ?>
      <div>
        <label class="form-label small fw-semibold mb-1">Department <span class="text-muted fw-normal">(blank = all)</span></label>
        <input type="text" name="department" class="form-control form-control-sm" style="width:180px" placeholder="e.g. Operations">
      </div>
      <div>
        <label class="form-label small fw-semibold mb-1">Type</label>
        <select name="capex_opex" class="form-select form-select-sm" style="width:120px">
          <option value="">All</option>
          <option value="Capex">Capex</option>
          <option value="Opex">Opex</option>
        </select>
      </div>
      <div>
        <label class="form-label small fw-semibold mb-1">Monthly Budget (₦)</label>
        <input type="number" name="monthly_amount" min="1" step="0.01" class="form-control form-control-sm" style="width:160px" required>
      </div>
      <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Ceiling</button>
    </form>
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
    'vendor_sla_scorecard'       => $vendorScorecard,
    'budget_ceilings'            => $budgetStatuses,
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
