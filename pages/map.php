<?php
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('map.view');

// Coverage layers (Phase 2 — silently skip if table absent)
$coverageLayers = [];
try {
    $coverageLayers = dbFetchAll(
        "SELECT cl.id, cl.name, cl.color, cl.opacity, h.name AS hub_name
         FROM coverage_layers cl
         LEFT JOIN hubs h ON h.id = cl.hub_id
         WHERE cl.enabled = 1
         ORDER BY cl.name"
    );
} catch (\PDOException $e) {
    if (($e->errorInfo[1] ?? 0) !== 1146) throw $e;
}

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

  <!-- Coverage layer toggles -->
  <?php if (!empty($coverageLayers)): ?>
  <div class="d-flex align-items-center gap-1 flex-wrap">
    <span class="text-muted small me-1"><i class="bi bi-layers me-1"></i>Coverage:</span>
    <?php foreach ($coverageLayers as $cl): ?>
    <button class="btn btn-outline-secondary btn-sm coverage-btn"
            data-id="<?= htmlspecialchars($cl['id']) ?>"
            data-color="<?= htmlspecialchars($cl['color']) ?>"
            data-opacity="<?= htmlspecialchars($cl['opacity']) ?>"
            onclick="toggleCoverage(this)"
            title="<?= htmlspecialchars($cl['hub_name'] ? 'Hub: '.$cl['hub_name'] : 'Network-wide') ?>">
      <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:<?= htmlspecialchars($cl['color']) ?>;margin-right:4px"></span>
      <?= htmlspecialchars($cl['name']) ?>
    </button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (hasPermission('map.coverage')): ?>
  <button class="btn btn-outline-success btn-sm d-flex align-items-center gap-1"
          data-bs-toggle="modal" data-bs-target="#coverageUploadModal">
    <i class="bi bi-cloud-upload"></i> Upload KMZ/KML
  </button>
  <?php endif; ?>

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

// ── Coverage KML/KMZ layers ───────────────────────────────────────────────────
const coverageLayers = {};  // id → { layer, active }

function toggleCoverage(btn) {
  const id      = btn.dataset.id;
  const color   = btn.dataset.color;
  const opacity = parseFloat(btn.dataset.opacity) || 0.35;

  if (coverageLayers[id]?.active) {
    map.removeLayer(coverageLayers[id].layer);
    coverageLayers[id].active = false;
    btn.classList.remove('active', 'btn-secondary');
    btn.classList.add('btn-outline-secondary');
    return;
  }

  if (coverageLayers[id]?.layer) {
    // Already loaded, just re-add
    coverageLayers[id].layer.addTo(map);
    coverageLayers[id].active = true;
    btn.classList.add('active', 'btn-secondary');
    btn.classList.remove('btn-outline-secondary');
    return;
  }

  btn.disabled = true;
  btn.innerHTML = btn.innerHTML.replace(/^/, '<span class="spinner-border spinner-border-sm me-1"></span>');

  // Fetch and parse KML via Fetch API then parse with DOMParser
  fetch(`/api/coverage?action=serve&id=${encodeURIComponent(id)}`)
    .then(r => { if (!r.ok) throw new Error('Failed to load coverage layer'); return r.text(); })
    .then(kmlText => {
      const parser  = new DOMParser();
      const kmlDoc  = parser.parseFromString(kmlText, 'application/xml');
      const layer   = kmlToLeaflet(kmlDoc, color, opacity);
      layer.addTo(map);
      coverageLayers[id] = { layer, active: true };
      btn.disabled = false;
      btn.innerHTML = btn.innerHTML.replace(/<span class="spinner[^<]*<\/span>/g, '');
      btn.classList.add('active', 'btn-secondary');
      btn.classList.remove('btn-outline-secondary');
    })
    .catch(err => {
      btn.disabled = false;
      btn.innerHTML = btn.innerHTML.replace(/<span class="spinner[^<]*<\/span>/g, '');
      alert('Could not load coverage layer: ' + err.message);
    });
}

// Minimal KML → Leaflet GeoJSON converter (handles Polygon and MultiPolygon placemarks)
function kmlToLeaflet(kmlDoc, color, opacity) {
  const group = L.layerGroup();
  const placemarks = kmlDoc.querySelectorAll('Placemark');
  placemarks.forEach(pm => {
    const name  = pm.querySelector('name')?.textContent || '';
    const desc  = pm.querySelector('description')?.textContent || '';
    const popup = name ? `<strong>${name}</strong>${desc ? '<br><small>' + desc + '</small>' : ''}` : null;

    // Polygon
    pm.querySelectorAll('Polygon').forEach(poly => {
      const coords = parseKmlCoords(poly.querySelector('outerBoundaryIs coordinates')?.textContent || '');
      if (!coords.length) return;
      const lPoly = L.polygon(coords, { color, fillColor: color, fillOpacity: opacity, weight: 2 });
      if (popup) lPoly.bindPopup(popup);
      lPoly.addTo(group);

      // Inner holes
      poly.querySelectorAll('innerBoundaryIs coordinates').forEach(inner => {
        const hole = parseKmlCoords(inner.textContent);
        if (hole.length) L.polygon(hole, { color, fillColor: '#fff', fillOpacity: 0.6, weight: 1 }).addTo(group);
      });
    });

    // LineString
    pm.querySelectorAll('LineString').forEach(ls => {
      const coords = parseKmlCoords(ls.querySelector('coordinates')?.textContent || '');
      if (!coords.length) return;
      const line = L.polyline(coords, { color, weight: 2.5, opacity: 0.85 });
      if (popup) line.bindPopup(popup);
      line.addTo(group);
    });

    // Point
    pm.querySelectorAll('Point').forEach(pt => {
      const raw = (pt.querySelector('coordinates')?.textContent || '').trim().split(',');
      if (raw.length < 2) return;
      const m = L.circleMarker([+raw[1], +raw[0]], { radius: 5, color, fillColor: color, fillOpacity: 0.8 });
      if (popup) m.bindPopup(popup);
      m.addTo(group);
    });
  });
  return group;
}

function parseKmlCoords(raw) {
  return (raw || '').trim().split(/\s+/).map(t => {
    const p = t.split(',');
    return p.length >= 2 ? [+p[1], +p[0]] : null;
  }).filter(Boolean);
}
</script>

<?php if (hasPermission('map.coverage')): ?>
<!-- Coverage Upload Modal -->
<div class="modal fade" id="coverageUploadModal" tabindex="-1">
  <div class="modal-dialog">
    <form id="coverageUploadForm" class="modal-content" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="action" value="upload">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-layers me-2 text-success"></i>Upload Coverage Layer</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">Layer Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" placeholder="e.g. Lekki Phase 1 Coverage" required>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">KMZ / KML File <span class="text-danger">*</span></label>
          <input type="file" name="kml_file" class="form-control" accept=".kml,.kmz" required>
          <div class="form-text">Export your coverage area from Google Maps or Google Earth as .kmz or .kml</div>
        </div>
        <div class="row g-2 mb-3">
          <div class="col">
            <label class="form-label fw-semibold">Overlay Colour</label>
            <input type="color" name="color" class="form-control form-control-color" value="#3b82f6">
          </div>
          <div class="col">
            <label class="form-label fw-semibold">Hub (optional)</label>
            <select name="hub_id" class="form-select">
              <option value="">Network-wide</option>
              <?php foreach (dbFetchAll("SELECT id, name FROM hubs ORDER BY name") as $h): ?>
              <option value="<?= htmlspecialchars($h['id']) ?>"><?= htmlspecialchars($h['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Description (optional)</label>
          <textarea name="description" class="form-control" rows="2" placeholder="Coverage area notes…"></textarea>
        </div>
        <div id="coverageUploadError" class="alert alert-danger d-none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-success" id="coverageUploadBtn">
          <i class="bi bi-cloud-upload me-1"></i>Upload
        </button>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('coverageUploadForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn = document.getElementById('coverageUploadBtn');
  const err = document.getElementById('coverageUploadError');
  err.classList.add('d-none');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading…';
  try {
    const r = await fetch('/api/coverage', { method: 'POST', body: new FormData(this) });
    const d = await r.json();
    if (d.ok) {
      bootstrap.Modal.getInstance(document.getElementById('coverageUploadModal')).hide();
      window.location.reload();
    } else {
      err.textContent = d.error || 'Upload failed';
      err.classList.remove('d-none');
    }
  } catch(ex) {
    err.textContent = 'Network error — please try again';
    err.classList.remove('d-none');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i>Upload';
  }
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>