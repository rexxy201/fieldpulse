<?php
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('admin.audit.view');

$perPage = 50;
$page    = max(1, (int)($_GET['p'] ?? 1));
$offset  = ($page - 1) * $perPage;

$filterUser   = trim($_GET['user']   ?? '');
$filterAction = trim($_GET['action'] ?? '');
$filterEntity = trim($_GET['entity'] ?? '');
$filterFrom   = trim($_GET['from']   ?? '');
$filterTo     = trim($_GET['to']     ?? '');

$conds  = [];
$params = [];
if ($filterUser)   { $conds[] = 'user_name LIKE ?';   $params[] = '%' . $filterUser . '%'; }
if ($filterAction) { $conds[] = 'action = ?';          $params[] = $filterAction; }
if ($filterEntity) { $conds[] = 'entity = ?';          $params[] = $filterEntity; }
if ($filterFrom)   { $conds[] = 'created_at >= ?';     $params[] = $filterFrom . ' 00:00:00'; }
if ($filterTo)     { $conds[] = 'created_at <= ?';     $params[] = $filterTo   . ' 23:59:59'; }

$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$total = (int)(dbFetch("SELECT COUNT(*) AS n FROM audit_logs $where", $params)['n'] ?? 0);
$pages = max(1, (int)ceil($total / $perPage));

// CSV export — output before any HTML
if (($_GET['export'] ?? '') === 'csv') {
    $allRows = dbFetchAll("SELECT user_name,action,entity,entity_id,details,created_at FROM audit_logs $where ORDER BY created_at DESC", $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-log-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['User','Action','Entity','Entity ID','Details','Timestamp']);
    foreach ($allRows as $r) {
        fputcsv($out, [$r['user_name'] ?? '', $r['action'], $r['entity'], $r['entity_id'] ?? '', $r['details'] ?? '', $r['created_at']]);
    }
    fclose($out);
    exit;
}

$rows = dbFetchAll(
    "SELECT id,user_name,action,entity,entity_id,details,created_at FROM audit_logs $where ORDER BY created_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

// For filter dropdowns
$distinctActions = dbFetchAll("SELECT DISTINCT action FROM audit_logs ORDER BY action");
$distinctEntities = dbFetchAll("SELECT DISTINCT entity FROM audit_logs ORDER BY entity");

function auditActionBadge(string $action): string {
    return match ($action) {
        'create'  => '<span class="badge bg-success">create</span>',
        'update'  => '<span class="badge bg-primary">update</span>',
        'delete'  => '<span class="badge bg-danger">delete</span>',
        'login'   => '<span class="badge bg-info text-dark">login</span>',
        'logout'  => '<span class="badge bg-secondary">logout</span>',
        'view'    => '<span class="badge bg-light text-dark border">view</span>',
        'import'  => '<span class="badge bg-warning text-dark">import</span>',
        'export'  => '<span class="badge bg-warning text-dark">export</span>',
        default   => '<span class="badge bg-secondary">' . htmlspecialchars($action) . '</span>',
    };
}

function auditQs(array $extra = []): string {
    global $filterUser, $filterAction, $filterEntity, $filterFrom, $filterTo, $page;
    $base = array_filter([
        'user'   => $filterUser,
        'action' => $filterAction,
        'entity' => $filterEntity,
        'from'   => $filterFrom,
        'to'     => $filterTo,
        'p'      => $page > 1 ? $page : null,
    ]);
    return '?' . http_build_query(array_filter(array_merge($base, $extra)));
}

$pageTitle = 'Audit Log';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h2 class="fw-bold mb-0">Audit Log</h2>
    <div class="text-muted small">All system actions: who did what, when.</div>
  </div>
  <a href="<?= htmlspecialchars(auditQs(['export' => 'csv', 'p' => null])) ?>" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-download me-1"></i>Export CSV
  </a>
</div>

<!-- Filters -->
<div class="card-section mb-3">
  <form class="p-3 d-flex flex-wrap gap-2 align-items-end" method="GET">
    <div>
      <label class="form-label small fw-semibold mb-1">User</label>
      <input type="text" name="user" value="<?= htmlspecialchars($filterUser) ?>" class="form-control form-control-sm" placeholder="Name…" style="width:140px">
    </div>
    <div>
      <label class="form-label small fw-semibold mb-1">Action</label>
      <select name="action" class="form-select form-select-sm" style="width:130px">
        <option value="">All actions</option>
        <?php foreach ($distinctActions as $a): ?>
        <option value="<?= htmlspecialchars($a['action']) ?>" <?= $filterAction === $a['action'] ? 'selected' : '' ?>><?= htmlspecialchars($a['action']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label small fw-semibold mb-1">Entity</label>
      <select name="entity" class="form-select form-select-sm" style="width:160px">
        <option value="">All entities</option>
        <?php foreach ($distinctEntities as $e): ?>
        <option value="<?= htmlspecialchars($e['entity']) ?>" <?= $filterEntity === $e['entity'] ? 'selected' : '' ?>><?= htmlspecialchars($e['entity']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label small fw-semibold mb-1">From</label>
      <input type="date" name="from" value="<?= htmlspecialchars($filterFrom) ?>" class="form-control form-control-sm">
    </div>
    <div>
      <label class="form-label small fw-semibold mb-1">To</label>
      <input type="date" name="to" value="<?= htmlspecialchars($filterTo) ?>" class="form-control form-control-sm">
    </div>
    <button type="submit" class="btn btn-sm btn-primary">Filter</button>
    <?php if ($filterUser || $filterAction || $filterEntity || $filterFrom || $filterTo): ?>
    <a href="/audit-log" class="btn btn-sm btn-outline-secondary">Clear</a>
    <?php endif; ?>
  </form>
</div>

<div class="card-section">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-journal-text me-1 text-primary"></i>
      <?= number_format($total) ?> record<?= $total !== 1 ? 's' : '' ?>
      <?php if ($pages > 1): ?> <span class="text-muted small">— page <?= $page ?> of <?= $pages ?></span><?php endif; ?>
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th class="ps-3" style="width:160px">Timestamp</th>
          <th style="width:140px">User</th>
          <th style="width:90px">Action</th>
          <th style="width:130px">Entity</th>
          <th>Details / ID</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">No audit records match your filters.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="ps-3 text-muted small text-nowrap"><?= date('d M Y, H:i', strtotime($r['created_at'])) ?></td>
          <td class="small fw-semibold"><?= htmlspecialchars($r['user_name'] ?? '—') ?></td>
          <td><?= auditActionBadge($r['action']) ?></td>
          <td class="small text-muted"><?= htmlspecialchars($r['entity']) ?></td>
          <td class="small">
            <?php if ($r['entity_id']): ?>
            <span class="font-monospace text-muted" style="font-size:.7rem"><?= htmlspecialchars(substr($r['entity_id'], 0, 8)) ?>…</span>
            <?php endif; ?>
            <?php if ($r['details']): ?>
            <span class="ms-1"><?= htmlspecialchars(mb_strimwidth($r['details'], 0, 120, '…')) ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="p-3 d-flex justify-content-between align-items-center border-top">
    <div class="small text-muted">Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $total)) ?> of <?= number_format($total) ?></div>
    <nav>
      <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= htmlspecialchars(auditQs(['p' => $page - 1])) ?>">‹</a>
        </li>
        <?php
        $start = max(1, $page - 2);
        $end   = min($pages, $page + 2);
        if ($start > 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif;
        for ($i = $start; $i <= $end; $i++): ?>
        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
          <a class="page-link" href="<?= htmlspecialchars(auditQs(['p' => $i])) ?>"><?= $i ?></a>
        </li>
        <?php endfor;
        if ($end < $pages): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= htmlspecialchars(auditQs(['p' => $page + 1])) ?>">›</a>
        </li>
      </ul>
    </nav>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
