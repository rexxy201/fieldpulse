<?php
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('map.view');

$hubs      = dbFetchAll("SELECT * FROM hubs WHERE lat IS NOT NULL AND lng IS NOT NULL");
[$_mapScope, $_mapParams] = ticketScopeSql('t');
$allTickets = dbFetchAll(
    "SELECT t.*, h.lat, h.lng
     FROM tickets t
     JOIN hubs h ON h.id = t.hub_id
     WHERE t.status NOT IN ('closed','resolved')
       AND h.lat IS NOT NULL AND h.lng IS NOT NULL"
     . ($_mapScope ? " AND $_mapScope" : ''),
    $_mapParams
);
$engineers = dbFetchAll(
    "SELECT u.*, h.lat, h.lng
     FROM users u
     JOIN hubs h ON h.id = u.hub_id
     WHERE u.role IN ('engineer','noc_engineer','vendor')
       AND h.lat IS NOT NULL AND h.lng IS NOT NULL"
);

$activeCount   = count($allTickets);
$engineerCount = count($engineers);

$hubsJson     = json_encode(array_values($hubs));
$ticketsJson  = json_encode(array_values($allTickets));
$engJson      = json_encode(array_values($engineers));

$pageTitle = 'Field Map';
require __DIR__ . '/../includes/header.php';
?>

<style>
#mapWrapper { position:relative; }
#map        { height: calc(100vh - 220px); min-height: 480px; border-radius: .75rem; }
#mapOverlay {
  position:absolute; top:12px; left:12px; z-index:500;
  background:rgba(255,255,255,.96); border-radius:.5rem;
  box-shadow:0 2px 8px rgba(0,0,0,.12); padding:.6rem .85rem;
  font-size:.8rem; min-width:140px;
  border:1px solid rgba(0,0,0,.06);
}
.map-filter-bar {
  display:flex; align-items:center; gap:.5rem; flex-wrap:wrap;
  margin-bottom:.75rem;
}
.map-legend {
  display:flex; align-items:center; gap:1.25rem; flex-wrap:wrap;
  font-size:.82rem;
}
.legend-dot {
  width:10px; height:10px; border-radius:50%; display:inline-block; flex-shrink:0;
}
</style>

<!-- Toolbar -->
<div class="map-filter-bar">
  <select id="ticketFilter" class="form-select" style="width:auto;min-width:170px" onchange="filterMarkers()">
    <option value="all">All Active Tickets</option>
    <option value="new">New</option>
    <option value="assigned">Assigned</option>
    <option value="in_progress">In Progress</option>
    <option value="open">Open</option>
    <option value="pending_confirmation">Pending</option>
  </select>
  <button class="btn btn-outline-secondary d-flex align-items-center gap-1" onclick="refreshMap()">
    <i class="bi bi-arrow-clockwise"></i> Refresh
  </button>
  <button id="dispatchBtn" class="btn btn-primary d-flex align-items-center gap-1" onclick="autoDispatch()">
    <i class="bi bi-lightning-charge-fill"></i> Auto-Dispatch
  </button>

  <!-- Legend (right side) -->
  <div class="ms-auto map-legend">
    <span class="d-flex align-items-center gap-1"><span class="legend-dot" style="background:#f59e0b"></span> New</span>
    <span class="d-flex align-items-center gap-1"><span class="legend-dot" style="background:#3b82f6"></span> Assigned</span>
    <span class="d-flex align-items-center gap-1"><span class="legend-dot" style="background:#8b5cf6"></span> In Progress</span>
    <span class="d-flex align-items-center gap-1"><span class="legend-dot" style="background:#06b6d4"></span> Engineer</span>
  </div>
</div>

<!-- Map -->
<div id="mapWrapper" class="card-section" style="padding:0;overflow:hidden">
  <div id="map"></div>
  <!-- Live overlay info box -->
  <div id="mapOverlay">
    <div class="d-flex align-items-center gap-2 mb-1">
      <span style="width:8px;height:8px;background:#f59e0b;border-radius:50%;display:inline-block"></span>
      <strong id="activeCount"><?= $activeCount ?></strong> active ticket<?= $activeCount!==1?'s':'' ?>
    </div>
    <div class="d-flex align-items-center gap-2">
      <span style="width:8px;height:8px;background:#06b6d4;border-radius:50%;display:inline-block"></span>
      <strong id="engineerCount"><?= $engineerCount ?></strong> engineer<?= $engineerCount!==1?'s':'' ?> online
    </div>
  </div>
</div>

<script>
const hubs      = <?= $hubsJson ?>;
const allTickets = <?= $ticketsJson ?>;
const engineers  = <?= $engJson ?>;

// Lagos, Nigeria center
const map = L.map('map', { zoomControl: true }).setView([6.45, 3.40], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
  maxZoom: 18
}).addTo(map);

// ── Icon factory ──────────────────────────────────────────────────────────
function mkIcon(color, label, size = 26) {
  return L.divIcon({
    className: '',
    html: `<div style="width:${size}px;height:${size}px;border-radius:50%;background:${color};border:2.5px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.32);display:flex;align-items:center;justify-content:center;color:#fff;font-size:${size*0.35}px;font-weight:800">${label}</div>`,
    iconSize:   [size, size],
    iconAnchor: [size/2, size/2]
  });
}

// Status → colour mapping
const statusColor = {
  new:                  '#f59e0b',
  open:                 '#3b82f6',
  assigned:             '#2563eb',
  in_progress:          '#8b5cf6',
  pending_confirmation: '#d97706',
};

// ── Hub markers ───────────────────────────────────────────────────────────
const hubLayer = L.layerGroup();
hubs.forEach(h => {
  L.marker([+h.lat, +h.lng], { icon: mkIcon('#0f172a', 'H', 22) })
    .bindPopup(`<strong>${h.name}</strong><br><span class="text-muted">${h.location??''}</span>`)
    .addTo(hubLayer);
});
hubLayer.addTo(map);

// ── Ticket markers ────────────────────────────────────────────────────────
let ticketMarkers = [];

function buildTicketMarkers(filterStatus = 'all') {
  ticketMarkers.forEach(m => map.removeLayer(m));
  ticketMarkers = [];
  const filtered = filterStatus === 'all' ? allTickets : allTickets.filter(t => t.status === filterStatus);
  document.getElementById('activeCount').textContent = filtered.length;

  filtered.forEach(t => {
    const c   = statusColor[t.status] || '#64748b';
    const lbl = (t.status === 'in_progress') ? 'IP' : (t.status||'?')[0].toUpperCase();
    const desc = (t.description||'').substring(0,60);
    const m = L.marker([+t.lat, +t.lng], { icon: mkIcon(c, lbl) })
      .bindPopup(
        `<div style="min-width:180px">
          <div style="font-weight:700;margin-bottom:4px">${t.ticket_number||''}</div>
          <div style="font-size:.8rem;color:#475569;margin-bottom:6px">${desc}</div>
          <div style="margin-bottom:4px">
            <span style="background:${c};color:#fff;font-size:.7rem;font-weight:700;padding:2px 6px;border-radius:3px">${(t.status||'').replace(/_/g,' ').toUpperCase()}</span>
          </div>
          <a href="/ticket/${t.id}" style="font-size:.8rem;color:#2563eb">View ticket →</a>
        </div>`
      );
    m.addTo(map);
    ticketMarkers.push(m);
  });
}

// ── Engineer markers ──────────────────────────────────────────────────────
engineers.forEach(e => {
  const initials = e.name.split(' ').map(w => w[0]||'').join('').substring(0,2).toUpperCase();
  L.marker([+e.lat, +e.lng], { icon: mkIcon('#06b6d4', initials, 30) })
    .bindPopup(`<strong>${e.name}</strong><br><span style="font-size:.8rem;color:#64748b">${e.role}</span>`)
    .addTo(map);
});

// ── Init ─────────────────────────────────────────────────────────────────
buildTicketMarkers('all');

function filterMarkers() {
  buildTicketMarkers(document.getElementById('ticketFilter').value);
}

function refreshMap() {
  window.location.reload();
}

async function autoDispatch() {
  const btn = document.getElementById('dispatchBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Dispatching…';
  try {
    const r = await fetch('/api/tickets/auto-dispatch', { method: 'POST' });
    const d = await r.json();
    alert(`✓ Auto-dispatched ${d.dispatched} ticket(s). Refreshing map…`);
    window.location.reload();
  } catch(e) {
    alert('Dispatch failed. Please try again.');
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-lightning-charge-fill"></i> Auto-Dispatch';
  }
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>