<?php
require_once __DIR__ . '/../../config.php';
requireAuth();
requirePermission('inventory.po.view');

$canManage = hasPermission('inventory.po.manage');
$canReceive = hasPermission('inventory.po.receive');

$statusFilter = trim($_GET['status'] ?? '');
$validStatuses = ['draft','sent','partial','received','cancelled'];
$where = ['1=1']; $params = [];
if ($statusFilter && in_array($statusFilter, $validStatuses)) {
    $where[] = 'po.status = ?'; $params[] = $statusFilter;
}

$orders = [];
try {
    $orders = dbFetchAll(
        "SELECT po.*, v.name AS vendor_name,
                (SELECT SUM(poi.qty_ordered) FROM inv_po_items poi WHERE poi.po_id = po.id) AS total_items,
                (SELECT COUNT(*) FROM inv_po_items poi WHERE poi.po_id = po.id) AS line_count
         FROM inv_purchase_orders po
         LEFT JOIN vendors v ON v.id = po.vendor_id
         WHERE " . implode(' AND ', $where) .
        " ORDER BY po.created_at DESC",
        $params
    );
} catch (\PDOException $e) {
    if (($e->errorInfo[1] ?? 0) !== 1146) throw $e;
}

$STATUS_BADGE = [
    'draft'     => 'text-bg-secondary',
    'sent'      => 'text-bg-primary',
    'partial'   => 'text-bg-warning',
    'received'  => 'text-bg-success',
    'cancelled' => 'text-bg-danger',
];

$pageTitle = 'Purchase Orders';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h2 class="fw-bold mb-0">Purchase Orders</h2>
    <div class="text-muted small"><?= count($orders) ?> order<?= count($orders)!==1?'s':'' ?><?= $statusFilter ? ' · '.ucfirst($statusFilter) : '' ?></div>
  </div>
  <div class="d-flex gap-2">
    <?php if ($canManage): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#poModal"><i class="bi bi-plus-lg me-1"></i>New PO</button>
    <?php endif; ?>
  </div>
</div>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i>Purchase order saved.</div><?php endif; ?>
<?php if (isset($_GET['received'])): ?><div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i>Goods received — stock updated.</div><?php endif; ?>

<!-- Status filter tabs -->
<ul class="nav nav-pills mb-3 gap-1">
  <li class="nav-item"><a class="nav-link <?= !$statusFilter?'active':'' ?>" href="/inventory/purchase-orders">All</a></li>
  <?php foreach ($validStatuses as $s): ?>
  <li class="nav-item"><a class="nav-link <?= $statusFilter===$s?'active':'' ?>" href="/inventory/purchase-orders?status=<?= $s ?>"><?= ucfirst($s) ?></a></li>
  <?php endforeach; ?>
</ul>

<div class="card-section">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr>
        <th class="ps-3">PO #</th><th>Vendor</th><th>Lines</th><th>Status</th><th>Ordered</th><th>Expected</th><th class="text-end pe-3">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (!$orders): ?>
        <tr><td colspan="7" class="text-center text-muted py-5"><i class="bi bi-receipt fs-2 d-block mb-2 opacity-25"></i>No purchase orders found.</td></tr>
        <?php endif; ?>
        <?php foreach ($orders as $po): ?>
        <tr>
          <td class="ps-3 fw-semibold font-monospace"><?= htmlspecialchars($po['po_number']) ?></td>
          <td><?= $po['vendor_name'] ? htmlspecialchars($po['vendor_name']) : '<span class="text-muted">—</span>' ?></td>
          <td class="small text-muted"><?= (int)$po['line_count'] ?> line<?= $po['line_count']!=1?'s':'' ?> / <?= (int)$po['total_items'] ?> units</td>
          <td><span class="badge <?= $STATUS_BADGE[$po['status']] ?? 'text-bg-secondary' ?>"><?= ucfirst($po['status']) ?></span></td>
          <td class="small text-muted"><?= $po['ordered_at'] ? date('d M Y', strtotime($po['ordered_at'])) : '—' ?></td>
          <td class="small text-muted"><?= $po['expected_at'] ? date('d M Y', strtotime($po['expected_at'])) : '—' ?></td>
          <td class="text-end pe-3">
            <div class="d-inline-flex gap-1">
              <a href="/inventory/purchase-orders?view=<?= urlencode($po['id']) ?>" class="btn btn-sm btn-outline-secondary" title="View"><i class="bi bi-eye"></i></a>
              <?php if ($canReceive && in_array($po['status'], ['sent','partial'])): ?>
              <a href="/inventory/purchase-orders?receive=<?= urlencode($po['id']) ?>" class="btn btn-sm btn-outline-success" title="Receive goods"><i class="bi bi-box-arrow-in-down"></i></a>
              <?php endif; ?>
              <?php if ($canManage && $po['status'] === 'draft'): ?>
              <a href="/inventory/purchase-orders?edit=<?= urlencode($po['id']) ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
<!-- Create PO Modal -->
<div class="modal fade" id="poModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-receipt me-2"></i>New Purchase Order</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="poForm">
        <div class="modal-body">
          <div id="poError" class="alert alert-danger d-none py-2"></div>
          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Vendor</label>
              <select name="vendor_id" class="form-select" id="poVendor">
                <option value="">— Select vendor —</option>
                <?php
                $vendors = dbFetchAll("SELECT id,name FROM vendors WHERE status='active' ORDER BY name");
                foreach ($vendors as $v): ?>
                <option value="<?= htmlspecialchars($v['id']) ?>"><?= htmlspecialchars($v['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-3">
              <label class="form-label fw-semibold">Order Date</label>
              <input type="date" name="ordered_at" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-sm-3">
              <label class="form-label fw-semibold">Expected By</label>
              <input type="date" name="expected_at" class="form-control">
            </div>
          </div>

          <!-- Line items -->
          <div class="fw-semibold mb-2">Line Items</div>
          <div id="poLines">
            <div class="po-line row g-2 mb-2 align-items-center">
              <div class="col-5">
                <select name="item_id[]" class="form-select form-select-sm po-item-sel" required>
                  <option value="">— Select item —</option>
                  <?php
                  $allItems = dbFetchAll("SELECT id, name, sku, unit, purchase_price, reorder_qty FROM inv_items WHERE item_type='inventory' ORDER BY name");
                  foreach ($allItems as $it): ?>
                  <option value="<?= $it['id'] ?>"
                          data-unit="<?= htmlspecialchars($it['unit'] ?? 'Pcs') ?>"
                          data-price="<?= $it['purchase_price'] ?? '' ?>"
                          data-reorder="<?= (int)($it['reorder_qty'] ?? 1) ?>">
                    <?= htmlspecialchars($it['name']) ?><?= $it['sku'] ? ' ('.$it['sku'].')' : '' ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-2"><input type="number" name="qty_ordered[]" min="1" value="1" class="form-control form-control-sm" placeholder="Qty" required></div>
              <div class="col-3">
                <div class="input-group input-group-sm">
                  <span class="input-group-text">$</span>
                  <input type="number" name="unit_price[]" step="0.01" min="0" class="form-control" placeholder="Unit price">
                </div>
              </div>
              <div class="col-2"><button type="button" class="btn btn-sm btn-outline-danger remove-line w-100"><i class="bi bi-x"></i></button></div>
            </div>
          </div>
          <button type="button" class="btn btn-sm btn-outline-secondary mt-1" id="addLine"><i class="bi bi-plus me-1"></i>Add Line</button>

          <div class="mt-3">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="notes" rows="2" class="form-control" placeholder="Optional…"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Create PO</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  const lineTemplate = document.querySelector('.po-line').outerHTML;

  document.getElementById('addLine').addEventListener('click', () => {
    const div = document.createElement('div');
    div.innerHTML = lineTemplate;
    document.getElementById('poLines').appendChild(div.firstElementChild);
    bindLine(div.firstElementChild);
  });

  function bindLine(row) {
    row.querySelector('.remove-line').addEventListener('click', () => {
      if (document.querySelectorAll('.po-line').length > 1) row.remove();
    });
    row.querySelector('.po-item-sel').addEventListener('change', function () {
      const opt = this.options[this.selectedIndex];
      const price = opt.dataset.price;
      const reorder = opt.dataset.reorder;
      row.querySelector('[name="unit_price[]"]').value = price || '';
      row.querySelector('[name="qty_ordered[]"]').value = reorder || 1;
    });
  }

  document.querySelectorAll('.po-line').forEach(bindLine);

  document.getElementById('poForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = this.querySelector('[type=submit]');
    btn.disabled = true;
    const fd = new FormData(this);
    fd.append('action', 'create_po');
    fd.append('csrf_token', '<?= csrfToken() ?>');
    const res = await fetch('/api/purchase-orders', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      window.location.href = '/inventory/purchase-orders?saved=1';
    } else {
      document.getElementById('poError').textContent = data.error || 'Error saving PO.';
      document.getElementById('poError').classList.remove('d-none');
      btn.disabled = false;
    }
  });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
