<?php
/**
 * Customer Support — settings (/support/settings): wrap-up codes, the reasons
 * agents pick when logging a contact. Codes are deactivated rather than
 * deleted so past interactions keep their reason.
 */
require_once __DIR__ . '/../../config.php';
requireAuth();
requirePermission('support.manage');

$h   = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$msg = null;

if (method() === 'POST') {
    verifyCsrf();
    $action   = $_POST['_action'] ?? '';
    $category = trim((string)($_POST['category'] ?? ''));
    $name     = trim((string)($_POST['name'] ?? ''));
    if ($action === 'add' && $category !== '' && $name !== '') {
        $max = (int)(dbFetch("SELECT MAX(sort_order) AS m FROM cs_wrap_codes")['m'] ?? 0);
        dbRun("INSERT INTO cs_wrap_codes (id,category,name,active,sort_order) VALUES (?,?,?,1,?)",
            [newUuid(), mb_substr($category, 0, 60), mb_substr($name, 0, 120), $max + 1]);
        auditLog('create', 'cs_wrap_code', '', "$category › $name");
    } elseif ($action === 'rename' && $category !== '' && $name !== '') {
        dbRun("UPDATE cs_wrap_codes SET category = ?, name = ? WHERE id = ?", [mb_substr($category, 0, 60), mb_substr($name, 0, 120), $_POST['id'] ?? '']);
        auditLog('update', 'cs_wrap_code', (string)($_POST['id'] ?? ''), "$category › $name");
    } elseif ($action === 'toggle') {
        dbRun("UPDATE cs_wrap_codes SET active = 1 - active WHERE id = ?", [$_POST['id'] ?? '']);
    }
    header('Location: /support/settings'); exit;
}

$codes = csWrapCodesGrouped(false);
$usage = [];
foreach (dbFetchAll("SELECT wrap_code_id, COUNT(*) AS n FROM cs_interactions GROUP BY wrap_code_id") as $u) $usage[$u['wrap_code_id']] = (int)$u['n'];

$pageTitle = 'Support Settings';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
  <h2 class="fw-bold mb-0">Support Settings</h2>
  <div class="text-muted small">Wrap-up codes: the reasons agents choose when they log a contact. They drive the “top contact reasons” report.</div>
</div>

<div class="card-section mb-3">
  <div class="card-header"><i class="bi bi-plus-lg me-1 text-primary"></i>Add a wrap-up code</div>
  <form method="POST" class="p-3 d-flex flex-wrap gap-2 align-items-end">
    <?= csrfField() ?>
    <input type="hidden" name="_action" value="add">
    <div>
      <label class="form-label small fw-semibold mb-1">Category</label>
      <input type="text" name="category" list="csCats" class="form-control form-control-sm" required placeholder="e.g. Technical">
      <datalist id="csCats"><?php foreach (array_keys($codes) as $c): ?><option value="<?= $h($c) ?>"><?php endforeach; ?></datalist>
    </div>
    <div class="flex-grow-1">
      <label class="form-label small fw-semibold mb-1">Reason</label>
      <input type="text" name="name" class="form-control form-control-sm" required placeholder="e.g. Fibre cut">
    </div>
    <button class="btn btn-sm btn-primary">Add</button>
  </form>
</div>

<?php foreach ($codes as $cat => $list): ?>
<div class="card-section mb-3">
  <div class="card-header"><?= $h($cat) ?></div>
  <div class="p-2">
    <?php foreach ($list as $c): ?>
    <form method="POST" class="d-flex flex-wrap gap-2 align-items-center border-bottom p-2 small <?= $c['active'] ? '' : 'opacity-50' ?>">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= $h($c['id']) ?>">
      <input type="text" name="category" value="<?= $h($c['category']) ?>" class="form-control form-control-sm" style="width:160px" aria-label="Category">
      <input type="text" name="name" value="<?= $h($c['name']) ?>" class="form-control form-control-sm flex-grow-1" style="width:auto" aria-label="Reason">
      <span class="text-muted text-nowrap"><?= $usage[$c['id']] ?? 0 ?> uses</span>
      <button name="_action" value="rename" class="btn btn-sm btn-outline-primary py-0">Save</button>
      <button name="_action" value="toggle" class="btn btn-sm btn-outline-<?= $c['active'] ? 'secondary' : 'success' ?> py-0"><?= $c['active'] ? 'Deactivate' : 'Activate' ?></button>
    </form>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
