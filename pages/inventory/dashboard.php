<?php
require_once __DIR__ . '/../../config.php';
requireAuth();
requirePermission('inventory.view');

// $totAssets  = (int)(dbFetch("SELECT COUNT(*) c FROM inv_products")['c'] ?? 0);  // hidden per request
$totItems   = (int)(dbFetch("SELECT COUNT(*) c FROM inv_items")['c'] ?? 0);
$totCats    = (int)(dbFetch("SELECT COUNT(*) c FROM inv_categories")['c'] ?? 0);
// $totCabs    = (int)(dbFetch("SELECT COUNT(*) c FROM inv_cabinets")['c'] ?? 0);  // hidden per request
$pendingReq = (int)(dbFetch("SELECT COUNT(*) c FROM inv_stock_requests WHERE status='pending'")['c'] ?? 0);
$lowStock   = (int)(dbFetch("SELECT COUNT(*) c FROM inv_items WHERE quantity<=5")['c'] ?? 0);
$outStock   = (int)(dbFetch("SELECT COUNT(*) c FROM inv_items WHERE quantity=0")['c'] ?? 0);

$recentMv = dbFetchAll(
    "SELECT m.type, m.quantity, m.created_at, i.name AS item_name
     FROM inv_stock_movements m JOIN inv_items i ON i.id=m.item_id
     ORDER BY m.created_at DESC LIMIT 6"
);
// Recent assets — hidden per request
// $recentAssets = dbFetchAll(
//     "SELECT p.id,p.unique_code,p.name,p.image,c.name AS cat_name,p.created_at
//      FROM inv_products p LEFT JOIN inv_categories c ON c.id=p.category_id
//      ORDER BY p.created_at DESC LIMIT 6"
// );
$recentAssets = [];

$pageTitle = 'Inventory';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h2 class="fw-bold mb-0">Inventory Overview</h2>
    <div class="text-muted small">Stock levels and movements at a glance.</div>
  </div>
  <?php /* Add Asset — hidden per request
  if (hasPermission('inventory.assets.manage')): ?>
  <a href="/inventory/asset-form" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Asset</a>
  <?php endif; */ ?>
</div>

<?php if ($pendingReq > 0 && hasPermission('inventory.requests.approve')): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
  <span><i class="bi bi-clock-history me-1"></i><strong><?= $pendingReq ?></strong> stock request<?= $pendingReq!==1?'s are':' is' ?> awaiting approval.</span>
  <a href="/inventory/requests?status=pending" class="btn btn-sm btn-primary">Review now</a>
</div>
<?php endif; ?>
<?php if ($outStock > 0): ?>
<div class="alert alert-danger d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
  <span><i class="bi bi-exclamation-triangle me-1"></i><strong><?= $outStock ?></strong> item<?= $outStock!==1?'s are':' is' ?> out of stock.</span>
  <?php if (hasPermission('inventory.refill')): ?><a href="/inventory/refill" class="btn btn-sm btn-outline-danger">Refill</a><?php endif; ?>
</div>
<?php endif; ?>

<!-- Stat cards -->
<div class="row g-3 mb-4">
  <?php foreach ([
    // ['Assets','bi-pc-display','#3b82f6',$totAssets,'/inventory/assets'],   // hidden per request
    ['Stock Items','bi-boxes','#0ea5e9',$totItems,'/inventory/items'],
    ['Categories','bi-tags','#10b981',$totCats,'/inventory/categories'],
    // ['Cabinets','bi-archive','#8b5cf6',$totCabs,'/inventory/cabinets'],   // hidden per request
    ['Low Stock','bi-exclamation-triangle','#f59e0b',$lowStock,'/inventory/items'],
    ['Pending Reqs','bi-clipboard-check','#6366f1',$pendingReq,'/inventory/requests?status=pending'],
  ] as [$lbl,$ico,$clr,$val,$href]): ?>
  <div class="col-6 col-md-4 col-xl-2">
    <a href="<?= $href ?>" class="stat-card h-100">
      <div class="d-flex align-items-center gap-3">
        <div style="font-size:1.5rem;color:<?= $clr ?>"><i class="bi <?= $ico ?>"></i></div>
        <div>
          <div class="stat-value" style="font-size:1.5rem"><?= number_format($val) ?></div>
          <div class="stat-label"><?= $lbl ?></div>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <?php if (false): // Recent Assets — hidden per request ?>
  <!-- Recent assets -->
  <div class="col-lg-8">
    <div class="card-section">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-pc-display me-1 text-primary"></i>Recent Assets</span>
        <a href="/inventory/assets" class="btn btn-sm btn-outline-primary">View all</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead class="table-light"><tr><th class="ps-3">Asset</th><th>Code</th><th>Category</th><th>Added</th></tr></thead>
          <tbody>
            <?php if (!$recentAssets): ?>
            <tr><td colspan="4" class="text-center text-muted py-4">No assets yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($recentAssets as $r): ?>
            <tr onclick="location.href='/inventory/asset-form?id=<?= $r['id'] ?>'" style="cursor:pointer">
              <td class="ps-3 fw-semibold"><?= htmlspecialchars($r['name']) ?></td>
              <td class="small font-monospace text-muted"><?= htmlspecialchars($r['unique_code'] ?? '—') ?></td>
              <td><?= $r['cat_name'] ? '<span class="badge text-bg-light border">'.htmlspecialchars($r['cat_name']).'</span>' : '—' ?></td>
              <td class="small text-muted text-nowrap"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Recent movements -->
  <div class="col-12">
    <div class="card-section">
      <div class="card-header"><i class="bi bi-arrow-left-right me-1 text-primary"></i>Recent Movements</div>
      <div class="p-2">
        <?php if (!$recentMv): ?>
        <p class="text-muted small text-center py-3 mb-0">No movements yet.</p>
        <?php endif; ?>
        <?php foreach ($recentMv as $m): $in = $m['type']==='inbound'; ?>
        <div class="d-flex align-items-center gap-2 px-2 py-2 border-bottom">
          <span class="badge <?= $in?'text-bg-success':'text-bg-danger' ?>"><i class="bi bi-arrow-<?= $in?'down':'up' ?>"></i></span>
          <div class="flex-grow-1 min-w-0">
            <div class="small fw-semibold text-truncate"><?= htmlspecialchars($m['item_name']) ?></div>
            <div class="text-muted" style="font-size:.72rem"><?= date('d M', strtotime($m['created_at'])) ?></div>
          </div>
          <span class="fw-bold <?= $in?'text-success':'text-danger' ?>"><?= $in?'+':'-' ?><?= $m['quantity'] ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
