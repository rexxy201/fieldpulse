<?php
require_once __DIR__ . '/../../config.php';
requireAuth();
requirePermission('inventory.movements.view');

$typeF = $_GET['type'] ?? 'all';
$itemF = (int)($_GET['item'] ?? 0);
$from  = trim($_GET['from'] ?? '');
$to    = trim($_GET['to'] ?? '');
if ($from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
if ($to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = '';

$where = ['1=1']; $params = [];
if ($typeF === 'inbound')  { $where[] = "sm.type='inbound'"; }
if ($typeF === 'outbound') { $where[] = "sm.type='outbound'"; }
if ($itemF > 0) { $where[] = "sm.item_id=?"; $params[] = $itemF; }
if ($from) { $where[] = "DATE(sm.created_at) >= ?"; $params[] = $from; }
if ($to)   { $where[] = "DATE(sm.created_at) <= ?"; $params[] = $to; }
$w = implode(' AND ', $where);

$movements = dbFetchAll(
    "SELECT sm.*, i.name AS item_name, i.unique_code AS item_code, u.name AS performed_name
     FROM inv_stock_movements sm JOIN inv_items i ON i.id=sm.item_id
     LEFT JOIN users u ON u.id=sm.performed_by
     WHERE $w ORDER BY sm.created_at DESC LIMIT 500", $params
);
$stats = dbFetch(
    "SELECT SUM(type='inbound') total_in, SUM(type='outbound') total_out,
            SUM(CASE WHEN type='inbound' THEN quantity ELSE 0 END) units_in,
            SUM(CASE WHEN type='outbound' THEN quantity ELSE 0 END) units_out
     FROM inv_stock_movements sm WHERE $w", $params
);
$itemsList = dbFetchAll("SELECT id,name FROM inv_items ORDER BY name");

$pageTitle = 'Stock Movements';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div><h2 class="fw-bold mb-0">Stock Movements</h2><div class="text-muted small">Inbound / outbound ledger</div></div>
  <?php if (hasPermission('inventory.refill')): ?><a href="/inventory/refill" class="btn btn-primary"><i class="bi bi-box-arrow-in-down me-1"></i>Refill Stock</a><?php endif; ?>
</div>

<div class="row g-2 mb-3">
  <?php foreach ([
    ['Inbound entries','#10b981',(int)($stats['total_in']??0)],
    ['Outbound entries','#ef4444',(int)($stats['total_out']??0)],
    ['Units received','#3b82f6',(int)($stats['units_in']??0)],
    ['Units issued','#f59e0b',(int)($stats['units_out']??0)],
  ] as [$lbl,$clr,$val]): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card py-3"><div class="stat-value" style="font-size:1.5rem;color:<?= $clr ?>"><?= number_format($val) ?></div><div class="stat-label"><?= $lbl ?></div></div>
  </div>
  <?php endforeach; ?>
</div>

<form method="GET" class="d-flex gap-2 mb-3 flex-wrap">
  <select name="type" class="form-select" style="width:auto">
    <option value="all" <?= $typeF==='all'?'selected':'' ?>>All Types</option>
    <option value="inbound" <?= $typeF==='inbound'?'selected':'' ?>>Inbound</option>
    <option value="outbound" <?= $typeF==='outbound'?'selected':'' ?>>Outbound</option>
  </select>
  <select name="item" class="form-select" style="width:auto;min-width:150px">
    <option value="0">All Items</option>
    <?php foreach ($itemsList as $it): ?><option value="<?= $it['id'] ?>" <?= $itemF===(int)$it['id']?'selected':'' ?>><?= htmlspecialchars($it['name']) ?></option><?php endforeach; ?>
  </select>
  <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="form-control" style="width:auto">
  <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="form-control" style="width:auto">
  <button class="btn btn-outline-secondary"><i class="bi bi-funnel"></i></button>
  <?php if ($typeF!=='all'||$itemF||$from||$to): ?><a href="/inventory/movements" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
</form>

<div class="card-section">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th class="ps-3">#</th><th>Type</th><th>Item</th><th class="text-center">Qty</th><th>Source</th><th>Notes</th><th>By</th><th>Date</th></tr></thead>
      <tbody>
        <?php if (!$movements): ?><tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-arrow-left-right fs-2 d-block mb-2 opacity-25"></i>No movements found.</td></tr><?php endif; ?>
        <?php foreach ($movements as $m): $in=$m['type']==='inbound';
          $src=['manual_refill'=>'Refill','request_approved'=>'Request #'.$m['reference_id'],'adjustment'=>'Adjustment','initial'=>'Opening'][$m['source']]??$m['source'];
        ?>
        <tr>
          <td class="ps-3 small font-monospace text-muted">#<?= $m['id'] ?></td>
          <td><span class="badge <?= $in?'text-bg-success':'text-bg-danger' ?>"><i class="bi bi-arrow-<?= $in?'down':'up' ?> me-1"></i><?= strtoupper($m['type']) ?></span></td>
          <td><div class="fw-semibold small"><?= htmlspecialchars($m['item_name']) ?></div><?php if ($m['item_code']): ?><div class="small font-monospace text-muted"><?= htmlspecialchars($m['item_code']) ?></div><?php endif; ?></td>
          <td class="text-center"><span class="fw-bold <?= $in?'text-success':'text-danger' ?>"><?= $in?'+':'-' ?><?= $m['quantity'] ?></span></td>
          <td class="small"><?= htmlspecialchars($src) ?></td>
          <td class="small text-muted text-truncate" style="max-width:160px" title="<?= htmlspecialchars($m['notes'] ?? '') ?>"><?= $m['notes'] ? htmlspecialchars(mb_substr($m['notes'],0,55)) : '—' ?></td>
          <td class="small"><?= $m['performed_name'] ? htmlspecialchars($m['performed_name']) : '<span class="text-muted">System</span>' ?></td>
          <td class="small text-muted text-nowrap"><?= date('d M Y H:i', strtotime($m['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
