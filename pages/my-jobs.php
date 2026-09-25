<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$user = currentUser();
$uid  = $user['id'];

// Field techs need checkin permission; managers with schedule.view can also see this page
if (!hasPermission('tickets.checkin') && !hasPermission('schedule.view')) {
    header('Location: /dashboard'); exit;
}

// ── Data ───────────────────────────────────────────────────────────────────
$now = new DateTime();

$tickets = dbFetchAll(
    "SELECT t.*, c.name AS customer_name, c.phone AS customer_phone,
            c.address AS customer_address, c.mailing_street, c.mailing_city, c.mailing_state,
            u.name AS assigned_name,
            ci.id AS active_checkin_id,
            ci.checked_in_at AS active_checkin_at
     FROM tickets t
     LEFT JOIN customers c ON c.id = t.customer_id
     LEFT JOIN users u ON u.id = t.assigned_to
     LEFT JOIN ticket_checkins ci ON ci.ticket_id = t.id
         AND ci.user_id = ? AND ci.checked_out_at IS NULL
     WHERE t.assigned_to = ?
       AND t.status NOT IN ('resolved','closed')
     ORDER BY
       CASE WHEN t.sla_breach_at IS NOT NULL AND t.sla_breach_at < NOW() THEN 0 ELSE 1 END,
       FIELD(t.priority,'p1','p2','p3','p4'),
       t.created_at ASC",
    [$uid, $uid]
);

$prioLabel = ['p1'=>'Critical','p2'=>'High','p3'=>'Medium','p4'=>'Low'];
$prioBadge = ['p1'=>'danger','p2'=>'warning','p3'=>'primary','p4'=>'success'];
$statusLabel = ['open'=>'Open','in_progress'=>'In Progress','pending_confirmation'=>'Pending Confirm'];
$todayStr = $now->format('Y-m-d');

// Bucket: overdue, today-new, rest
$overdue = $today_tickets = $other = [];
foreach ($tickets as $t) {
    $breachAt = $t['sla_breach_at'] ? new DateTime($t['sla_breach_at']) : null;
    if ($breachAt && $breachAt < $now) {
        $overdue[] = $t;
    } elseif (date('Y-m-d', strtotime($t['created_at'])) === $todayStr) {
        $today_tickets[] = $t;
    } else {
        $other[] = $t;
    }
}

$total = count($tickets);
$checkedIn = count(array_filter($tickets, fn($t) => !empty($t['active_checkin_id'])));

$pageTitle = 'My Jobs';
require __DIR__ . '/../includes/header.php';
?>

<style>
.job-card { border-radius:.6rem; margin-bottom:1rem; border:1px solid #e2e8f0; transition:box-shadow .15s; }
.job-card:hover { box-shadow:0 2px 12px rgba(0,0,0,.08); }
.job-meta { font-size:.78rem; color:#64748b; }
.job-desc { font-size:.88rem; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
.action-strip { border-top:1px solid #f1f5f9; padding:.6rem .75rem; display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }
.action-strip a, .action-strip button { font-size:.78rem; }
.section-label { font-weight:600; font-size:.75rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; margin:.5rem 0 .4rem; }
.checkin-badge { font-size:.7rem; }
@media print { body { display:none } }
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h4 class="fw-bold mb-0">My Jobs</h4>
    <span class="text-muted" style="font-size:.82rem"><?= $total ?> open &bull; <?= $checkedIn ?> checked in</span>
  </div>
  <a href="/tickets?assigned=me" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-list-task"></i> <span class="d-none d-sm-inline">All Tickets</span>
  </a>
</div>

<?php if ($total === 0): ?>
<div class="text-center py-5 text-muted">
  <i class="bi bi-check2-circle" style="font-size:2.5rem"></i>
  <p class="mt-2 mb-0">No open jobs assigned to you.</p>
</div>
<?php else: ?>

<?php
$sections = [];
if ($overdue)        $sections[] = ['label'=>'Overdue / SLA Breached', 'color'=>'danger',  'tickets'=>$overdue];
if ($today_tickets)  $sections[] = ['label'=>'New Today',               'color'=>'primary', 'tickets'=>$today_tickets];
if ($other)          $sections[] = ['label'=>'Open',                    'color'=>'secondary','tickets'=>$other];

foreach ($sections as $sec):
    $badge = $sec['color'];
?>
<div class="section-label text-<?= $badge ?>"><?= htmlspecialchars($sec['label']) ?></div>

<?php foreach ($sec['tickets'] as $t):
    $prio     = $t['priority'] ?? 'p3';
    $status   = $t['status'] ?? 'open';
    $desc     = $t['description'] ?? '';
    $cust     = htmlspecialchars($t['customer_name'] ?? '—');
    $phone    = $t['customer_phone'] ?? '';
    $addr     = trim(implode(', ', array_filter([
        $t['mailing_street'] ?: $t['customer_address'],
        $t['mailing_city'],
        $t['mailing_state'],
    ])));
    $mapsUrl  = $addr ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($addr) : '';
    $isCheckedIn = !empty($t['active_checkin_id']);
    $breach   = $t['sla_breach_at'] ? new DateTime($t['sla_breach_at']) : null;
    $breachStr = $breach ? $breach->format('M j, g:i A') : null;
    $tn       = $t['ticket_number'] ?? ('#' . substr($t['id'],0,8));
?>
<div class="job-card bg-white">
  <div class="p-3">
    <div class="d-flex align-items-start justify-content-between gap-2 mb-1">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="badge bg-<?= htmlspecialchars($prioBadge[$prio] ?? 'secondary') ?>"><?= htmlspecialchars($prioLabel[$prio] ?? $prio) ?></span>
        <span class="badge bg-light text-dark border"><?= htmlspecialchars($statusLabel[$status] ?? ucfirst($status)) ?></span>
        <?php if ($isCheckedIn): ?>
        <span class="badge bg-success checkin-badge"><i class="bi bi-geo-alt-fill"></i> Checked In</span>
        <?php endif; ?>
      </div>
      <a href="/ticket/<?= $t['id'] ?>" class="text-muted" style="font-size:.78rem;white-space:nowrap"><?= htmlspecialchars($tn) ?></a>
    </div>
    <div class="job-desc text-dark"><?= htmlspecialchars($desc) ?></div>
    <div class="job-meta mt-1 d-flex flex-wrap gap-2">
      <span><i class="bi bi-person"></i> <?= $cust ?></span>
      <?php if ($breachStr): ?>
      <span class="text-danger fw-semibold"><i class="bi bi-clock-history"></i> SLA: <?= htmlspecialchars($breachStr) ?></span>
      <?php endif; ?>
    </div>
    <?php if ($addr): ?>
    <div class="job-meta mt-1 text-truncate"><i class="bi bi-geo"></i> <?= htmlspecialchars($addr) ?></div>
    <?php endif; ?>
  </div>
  <div class="action-strip">
    <a href="/ticket/<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-eye"></i> View
    </a>
    <?php if ($isCheckedIn): ?>
    <button class="btn btn-sm btn-outline-danger" onclick="doCheckout('<?= $t['id'] ?>')">
      <i class="bi bi-box-arrow-right"></i> Check Out
    </button>
    <?php elseif (hasPermission('tickets.checkin')): ?>
    <button class="btn btn-sm btn-success" onclick="doCheckin('<?= $t['id'] ?>')">
      <i class="bi bi-geo-alt"></i> Check In
    </button>
    <?php endif; ?>
    <?php if ($phone): ?>
    <a href="tel:<?= htmlspecialchars($phone) ?>" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-telephone"></i> Call
    </a>
    <?php endif; ?>
    <?php if ($mapsUrl): ?>
    <a href="<?= htmlspecialchars($mapsUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-map"></i> Navigate
    </a>
    <?php endif; ?>
    <a href="/ticket/<?= $t['id'] ?>/job-sheet" target="_blank" class="btn btn-sm btn-outline-secondary ms-auto">
      <i class="bi bi-printer"></i> Job Sheet
    </a>
  </div>
</div>
<?php endforeach; ?>
<?php endforeach; ?>

<?php endif; ?>

<!-- GPS spinner modal -->
<div class="modal fade" id="gpsModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content text-center p-4">
      <div class="spinner-border text-primary mx-auto mb-3" role="status"></div>
      <p class="mb-0 text-muted" id="gpsModalMsg">Getting your location…</p>
    </div>
  </div>
</div>

<script>
const _gpsModal = new bootstrap.Modal(document.getElementById('gpsModal'));

function doCheckin(ticketId) {
    if (!navigator.geolocation) { alert('Geolocation not supported on this device.'); return; }
    document.getElementById('gpsModalMsg').textContent = 'Getting your location…';
    _gpsModal.show();
    navigator.geolocation.getCurrentPosition(
        pos => {
            document.getElementById('gpsModalMsg').textContent = 'Checking in…';
            fetch('/api/tickets/' + ticketId + '/checkin', {
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body: JSON.stringify({
                    latitude:  pos.coords.latitude,
                    longitude: pos.coords.longitude,
                    accuracy:  pos.coords.accuracy
                })
            })
            .then(r => r.json())
            .then(d => { _gpsModal.hide(); if (d.id) location.reload(); else alert(d.error || 'Check-in failed'); })
            .catch(() => { _gpsModal.hide(); alert('Network error during check-in.'); });
        },
        err => { _gpsModal.hide(); alert('Location error: ' + err.message); },
        { enableHighAccuracy:true, timeout:15000 }
    );
}

function doCheckout(ticketId) {
    fetch('/api/tickets/' + ticketId + '/checkin', { method:'DELETE' })
        .then(r => r.json())
        .then(d => { if (d.ok) location.reload(); else alert(d.error || 'Check-out failed'); })
        .catch(() => alert('Network error during check-out.'));
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
