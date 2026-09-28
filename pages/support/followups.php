<?php
/**
 * Customer Support — follow-ups (/support/followups): callbacks owed to
 * customers. Agents see their own; support.view_all sees everyone's and can
 * reassign.
 */
require_once __DIR__ . '/../../config.php';
requireAuth();
requirePermission('support.view');

$me      = currentUser();
$viewAll = hasPermission('support.view_all');
$h       = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);

if (method() === 'POST') {
    verifyCsrf();
    $f = dbFetch("SELECT * FROM cs_followups WHERE id = ?", [$_POST['followup_id'] ?? '']);
    $action = $_POST['_action'] ?? '';
    if ($f && $f['status'] === 'open' && ($f['assigned_to'] === $me['id'] || $viewAll)) {
        if ($action === 'done' || $action === 'cancel') {
            dbRun("UPDATE cs_followups SET status = ?, done_at = ? WHERE id = ?", [$action === 'done' ? 'done' : 'cancelled', date('Y-m-d H:i:s'), $f['id']]);
        } elseif ($action === 'reassign' && $viewAll) {
            $to = dbFetch("SELECT id FROM users WHERE id = ?", [$_POST['assigned_to'] ?? '']);
            if ($to) dbRun("UPDATE cs_followups SET assigned_to = ? WHERE id = ?", [$to['id'], $f['id']]);
        }
    }
    header('Location: /support/followups' . (!empty($_POST['back_qs']) && $_POST['back_qs'][0] === '?' ? $_POST['back_qs'] : '')); exit;
}

$status = in_array($_GET['status'] ?? 'open', ['open', 'done', 'cancelled'], true) ? ($_GET['status'] ?? 'open') : 'open';
$scope  = $viewAll && ($_GET['scope'] ?? '') === 'all' ? 'all' : 'mine';
$conds  = ['f.status = ?']; $params = [$status];
if ($scope === 'mine') { $conds[] = 'f.assigned_to = ?'; $params[] = $me['id']; }
$rows = dbFetchAll(
    "SELECT f.*, u.name AS assigned_name FROM cs_followups f LEFT JOIN users u ON u.id = f.assigned_to
     WHERE " . implode(' AND ', $conds) . " ORDER BY " . ($status === 'open' ? 'f.due_at ASC' : 'f.done_at DESC') . " LIMIT 200", $params);
$agents = $viewAll ? dbFetchAll("SELECT DISTINCT u.id, u.name FROM users u JOIN role_permissions rp ON rp.role = u.role
                                  WHERE rp.permission = 'support.view' OR u.role = 'admin' ORDER BY u.name") : [];
$backQs = '?' . http_build_query(['status' => $status, 'scope' => $scope]);

$pageTitle = 'Follow-ups';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
  <div>
    <h2 class="fw-bold mb-0">Follow-ups</h2>
    <div class="text-muted small">Callbacks and promises made to customers.</div>
  </div>
  <div class="d-flex gap-2">
    <?php if ($viewAll): ?>
    <div class="btn-group btn-group-sm">
      <a class="btn btn-outline-secondary <?= $scope === 'mine' ? 'active' : '' ?>" href="?status=<?= $status ?>&amp;scope=mine">Mine</a>
      <a class="btn btn-outline-secondary <?= $scope === 'all' ? 'active' : '' ?>" href="?status=<?= $status ?>&amp;scope=all">Everyone</a>
    </div>
    <?php endif; ?>
    <div class="btn-group btn-group-sm">
      <?php foreach (['open' => 'Open', 'done' => 'Done', 'cancelled' => 'Cancelled'] as $k => $l): ?>
      <a class="btn btn-outline-primary <?= $status === $k ? 'active' : '' ?>" href="?status=<?= $k ?>&amp;scope=<?= $scope ?>"><?= $l ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="card-section">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0 small">
      <thead class="table-light"><tr><th>Due</th><th>Contact</th><th>Note</th><?php if ($scope === 'all'): ?><th>Assigned to</th><?php endif; ?><th class="text-end"></th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">No <?= $h($status) ?> follow-ups.</td></tr><?php endif; ?>
        <?php foreach ($rows as $f): $overdue = $f['status'] === 'open' && strtotime($f['due_at']) < time(); ?>
        <tr>
          <td class="text-nowrap <?= $overdue ? 'text-danger fw-semibold' : '' ?>"><?= $h(date('d M Y H:i', strtotime($f['due_at']))) ?><?= $overdue ? '<div>Overdue</div>' : '' ?></td>
          <td>
            <?php if ($f['customer_id']): ?><a href="/support?customer=<?= $h($f['customer_id']) ?>"><?= $h($f['contact_name'] ?: 'Customer') ?></a><?php else: ?><?= $h($f['contact_name'] ?: '—') ?><?php endif; ?>
            <div class="text-muted"><?= $h($f['contact_phone']) ?></div>
          </td>
          <td style="max-width:380px"><?= $h($f['note']) ?></td>
          <?php if ($scope === 'all'): ?>
          <td>
            <?php if ($f['status'] === 'open'): ?>
            <form method="POST" class="d-flex gap-1">
              <?= csrfField() ?>
              <input type="hidden" name="followup_id" value="<?= $h($f['id']) ?>">
              <input type="hidden" name="back_qs" value="<?= $h($backQs) ?>">
              <select name="assigned_to" class="form-select form-select-sm" style="width:auto">
                <?php foreach ($agents as $a): ?><option value="<?= $h($a['id']) ?>" <?= $a['id'] === $f['assigned_to'] ? 'selected' : '' ?>><?= $h($a['name']) ?></option><?php endforeach; ?>
              </select>
              <button name="_action" value="reassign" class="btn btn-sm btn-outline-secondary py-0">Reassign</button>
            </form>
            <?php else: ?><?= $h($f['assigned_name']) ?><?php endif; ?>
          </td>
          <?php endif; ?>
          <td class="text-end text-nowrap">
            <?php if ($f['status'] === 'open'): ?>
            <form method="POST" class="d-inline">
              <?= csrfField() ?>
              <input type="hidden" name="followup_id" value="<?= $h($f['id']) ?>">
              <input type="hidden" name="back_qs" value="<?= $h($backQs) ?>">
              <button name="_action" value="done" class="btn btn-sm btn-outline-success py-0">Done</button>
              <button name="_action" value="cancel" class="btn btn-sm btn-outline-secondary py-0" onclick="return confirm('Cancel this follow-up?')">Cancel</button>
            </form>
            <?php else: ?>
            <span class="text-muted"><?= $f['done_at'] ? $h(date('d M H:i', strtotime($f['done_at']))) : '' ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
