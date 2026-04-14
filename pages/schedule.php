<?php
require_once __DIR__ . '/../config.php';
requireAuth();

// ── Week navigation ────────────────────────────────────────────────────────
$weekParam = $_GET['week'] ?? null;
if ($weekParam) {
    $monday = new DateTime($weekParam);
    $monday->modify('Monday this week');
} else {
    $monday = new DateTime();
    $monday->modify('Monday this week');
}
$friday = clone $monday; $friday->modify('+4 days');
$prevWeek = clone $monday; $prevWeek->modify('-7 days');
$nextWeek = clone $monday; $nextWeek->modify('+7 days');
$today = date('Y-m-d');
$isThisWeek = $monday->format('Y-m-d') === (new DateTime())->modify('Monday this week')->format('Y-m-d');

// Mon–Fri day array
$days = [];
for ($i = 0; $i < 5; $i++) {
    $d = clone $monday;
    $d->modify("+$i days");
    $days[] = $d;
}

// ── Data ─────────────────────────────────────────────────────────────────
$technicians = dbFetchAll(
    "SELECT * FROM users WHERE role IN ('engineer','vendor') ORDER BY name"
);
$unassignedCount = (int)(dbFetch(
    "SELECT COUNT(*) AS c FROM tickets WHERE assigned_to IS NULL AND status NOT IN ('resolved','closed','pending_confirmation')"
)['c'] ?? 0);

// Tickets for this week (by created_at), with assignment info
$weekStart = $monday->format('Y-m-d') . ' 00:00:00';
$weekEnd   = $friday->format('Y-m-d') . ' 23:59:59';
$tickets = dbFetchAll(
    "SELECT t.*, u.name AS assigned_name
     FROM tickets t
     LEFT JOIN users u ON u.id = t.assigned_to
     WHERE t.status NOT IN ('closed')
       AND t.created_at BETWEEN ? AND ?
     ORDER BY t.created_at",
    [$weekStart, $weekEnd]
);

// Organise: $grid[$techId][$date] = [ticket, ...]
$grid = [];
foreach ($technicians as $tech) {
    $grid[$tech['id']] = [];
    foreach ($days as $d) $grid[$tech['id']][$d->format('Y-m-d')] = [];
}
// Unassigned bucket for display
$unassignedGrid = [];
foreach ($days as $d) $unassignedGrid[$d->format('Y-m-d')] = [];

foreach ($tickets as $t) {
    $dateKey = date('Y-m-d', strtotime($t['created_at']));
    if ($t['assigned_to'] && isset($grid[$t['assigned_to']][$dateKey])) {
        $grid[$t['assigned_to']][$dateKey][] = $t;
    } elseif (!$t['assigned_to'] && isset($unassignedGrid[$dateKey])) {
        $unassignedGrid[$dateKey][] = $t;
    }
}

$prioColor = ['p1'=>'#dc2626','p2'=>'#ea580c','p3'=>'#3b82f6','p4'=>'#22c55e'];

$pageTitle = 'Schedule';
require __DIR__ . '/../includes/header.php';
?>

<!-- Page heading -->
<div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
  <div>
    <h2 class="fw-bold mb-1">Resource Schedule</h2>
    <div class="small">
      <span class="text-muted">Week of <?= $monday->format('d M') ?> – <?= $friday->format('d M Y') ?></span>
      <?php if ($unassignedCount): ?>
      &nbsp;·&nbsp;
      <a href="/tickets?status=open" class="fw-semibold text-decoration-none" style="color:var(--primary)">
        <?= $unassignedCount ?> unassigned ticket<?= $unassignedCount !== 1 ? 's' : '' ?>
      </a>
      <?php endif; ?>
    </div>
  </div>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <a href="/schedule?week=<?= $prevWeek->format('Y-m-d') ?>"
       class="btn btn-outline-secondary" style="width:36px;height:36px;padding:0;display:flex;align-items:center;justify-content:center">
      <i class="bi bi-chevron-left"></i>
    </a>
    <a href="/schedule" class="btn btn-outline-secondary d-flex align-items-center gap-1" style="height:36px">
      <i class="bi bi-calendar3"></i> Today
    </a>
    <a href="/schedule?week=<?= $nextWeek->format('Y-m-d') ?>"
       class="btn btn-outline-secondary" style="width:36px;height:36px;padding:0;display:flex;align-items:center;justify-content:center">
      <i class="bi bi-chevron-right"></i>
    </a>
    <button onclick="autoDispatch()" class="btn btn-primary d-flex align-items-center gap-2" style="height:36px" id="dispatchBtn">
      <i class="bi bi-lightning-charge-fill"></i> Auto-Dispatch
    </button>
  </div>
</div>

<!-- Calendar grid -->
<div class="card-section" style="overflow-x:auto">
  <table style="width:100%;border-collapse:collapse;min-width:700px">
    <thead>
      <tr>
        <!-- Technician column header -->
        <th style="width:175px;border:1px solid #e2e8f0;padding:.75rem 1rem;background:#f8fafc;color:#64748b;font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em">
          Technicians (<?= count($technicians) ?>)
        </th>
        <!-- Day headers -->
        <?php foreach ($days as $d):
          $isToday = $d->format('Y-m-d') === $today;
        ?>
        <th style="border:1px solid #e2e8f0;padding:.6rem .5rem;text-align:center;background:<?= $isToday ? '#eff6ff' : '#f8fafc' ?>;min-width:130px">
          <div style="color:#94a3b8;font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em">
            <?= $d->format('D') ?>
          </div>
          <div style="color:<?= $isToday ? 'var(--primary)' : '#0f172a' ?>;font-size:1.1rem;font-weight:700;line-height:1.2">
            <?= $d->format('j') ?>
          </div>
        </th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (!$technicians): ?>
      <tr>
        <td colspan="6" class="text-center text-muted py-5" style="border:1px solid #e2e8f0">
          <i class="bi bi-person-x fs-2 d-block mb-2 opacity-25"></i>No technicians found. Add engineers or vendors in the Team module.
        </td>
      </tr>
      <?php endif; ?>

      <?php foreach ($technicians as $tech):
        $initials = implode('', array_map(fn($w) => strtoupper($w[0]), array_filter(explode(' ', $tech['name']))));
        $initials = substr($initials, 0, 2);
      ?>
      <tr>
        <!-- Technician info cell -->
        <td style="border:1px solid #e2e8f0;padding:.75rem 1rem;vertical-align:top;background:#fff">
          <div class="d-flex align-items-center gap-2">
            <div style="width:34px;height:34px;border-radius:50%;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;flex-shrink:0">
              <?= htmlspecialchars($initials) ?>
            </div>
            <div>
              <div style="font-weight:600;font-size:.875rem;line-height:1.2"><?= htmlspecialchars($tech['name']) ?></div>
              <div style="font-size:.75rem;color:#94a3b8"><?= ucfirst(str_replace('_',' ',$tech['role'])) ?></div>
            </div>
          </div>
        </td>
        <!-- Day cells -->
        <?php foreach ($days as $d):
          $dateKey = $d->format('Y-m-d');
          $isToday = $d->format('Y-m-d') === $today;
          $dayTickets = $grid[$tech['id']][$dateKey] ?? [];
        ?>
        <td style="border:1px solid #e2e8f0;padding:.5rem;vertical-align:top;background:<?= $isToday ? '#f8fbff' : '#fff' ?>;min-height:80px">
          <?php foreach ($dayTickets as $t):
            $pc = $prioColor[$t['priority']] ?? '#64748b';
          ?>
          <a href="/ticket/<?= $t['id'] ?>" class="text-decoration-none d-block mb-1"
             style="font-size:.72rem;padding:.3rem .5rem;border-radius:.3rem;border-left:3px solid <?= $pc ?>;background:<?= $pc ?>18;color:#0f172a;line-height:1.3">
            <div class="fw-semibold text-truncate" style="max-width:110px"><?= htmlspecialchars($t['ticket_number'] ?? '') ?></div>
            <div class="text-truncate" style="color:#64748b;max-width:110px"><?= htmlspecialchars(substr($t['description']??'',0,35)) ?></div>
            <span style="font-size:.65rem;font-weight:700;color:<?= $pc ?>"><?= strtoupper($t['priority']??'') ?></span>
          </a>
          <?php endforeach; ?>
        </td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>

      <!-- Unassigned row (if any this week) -->
      <?php $hasUnassignedThisWeek = array_sum(array_map('count', $unassignedGrid)) > 0; ?>
      <?php if ($hasUnassignedThisWeek): ?>
      <tr>
        <td style="border:1px solid #e2e8f0;padding:.75rem 1rem;vertical-align:top;background:#fffbeb">
          <div class="d-flex align-items-center gap-2">
            <div style="width:34px;height:34px;border-radius:50%;background:#fef3c7;color:#d97706;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700">
              ?
            </div>
            <div>
              <div style="font-weight:600;font-size:.875rem;color:#92400e">Unassigned</div>
              <div style="font-size:.75rem;color:#b45309">Needs dispatch</div>
            </div>
          </div>
        </td>
        <?php foreach ($days as $d):
          $isToday = $d->format('Y-m-d') === $today;
          $dayUnassigned = $unassignedGrid[$d->format('Y-m-d')] ?? [];
        ?>
        <td style="border:1px solid #e2e8f0;padding:.5rem;vertical-align:top;background:<?= $isToday ? '#fffef0' : '#fffbeb' ?>">
          <?php foreach ($dayUnassigned as $t):
            $pc = $prioColor[$t['priority']] ?? '#64748b';
          ?>
          <a href="/ticket/<?= $t['id'] ?>" class="text-decoration-none d-block mb-1"
             style="font-size:.72rem;padding:.3rem .5rem;border-radius:.3rem;border-left:3px solid <?= $pc ?>;background:#fff;color:#0f172a;line-height:1.3">
            <div class="fw-semibold text-truncate" style="max-width:110px"><?= htmlspecialchars($t['ticket_number']??'') ?></div>
            <span style="font-size:.65rem;font-weight:700;color:<?= $pc ?>"><?= strtoupper($t['priority']??'') ?></span>
          </a>
          <?php endforeach; ?>
        </td>
        <?php endforeach; ?>
      </tr>
      <?php endif; ?>

    </tbody>
  </table>
</div>

<!-- Legend -->
<div class="d-flex gap-3 mt-3 flex-wrap">
  <?php foreach (['p1'=>['#dc2626','P1 Critical'],'p2'=>['#ea580c','P2 High'],'p3'=>['#3b82f6','P3 Medium'],'p4'=>['#22c55e','P4 Low']] as $p => [$c,$l]): ?>
  <span class="d-flex align-items-center gap-1 small text-muted">
    <span style="width:10px;height:10px;border-radius:2px;background:<?=$c?>;display:inline-block"></span><?=$l?>
  </span>
  <?php endforeach; ?>
</div>

<script>
async function autoDispatch() {
  const btn = document.getElementById('dispatchBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Dispatching…';
  try {
    const r = await fetch('/api/tickets/auto-dispatch', { method: 'POST' });
    const d = await r.json();
    alert('✓ Auto-dispatched ' + d.dispatched + ' ticket(s). Reloading…');
    window.location.reload();
  } catch(e) {
    alert('Dispatch failed. Please try again.');
  }
  btn.disabled = false;
  btn.innerHTML = '<i class="bi bi-lightning-charge-fill"></i> Auto-Dispatch';
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
