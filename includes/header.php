<?php
// Load app config for theme + branding
$_appCfg      = getAppConfig();
$_primaryColor = !empty($_appCfg['primaryColor']) ? $_appCfg['primaryColor'] : '#0ea5e9';
$_primaryDark  = darkenColor($_primaryColor, 20);
$_primaryLight = $_primaryColor . '1f'; // ~12% opacity hex not ideal, use rgba below
$_companyName  = $_appCfg['companyName'] ?? 'FieldPulse';
$_companyLogo  = $_appCfg['companyLogo'] ?? '';
$_favicon      = $_appCfg['favicon'] ?? ($_appCfg['companyLogo'] ?? '');
// Convert hex to RGB for rgba usage
$_hex = ltrim($_primaryColor, '#');
if (strlen($_hex) === 3) $_hex = $_hex[0].$_hex[0].$_hex[1].$_hex[1].$_hex[2].$_hex[2];
$_pr = hexdec(substr($_hex,0,2));
$_pg = hexdec(substr($_hex,2,2));
$_pb = hexdec(substr($_hex,4,2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">
<title><?= htmlspecialchars($pageTitle ?? $_companyName) ?> – <?= htmlspecialchars($_companyName) ?></title>
<?php if ($_favicon): ?><link rel="icon" href="<?= htmlspecialchars($_favicon) ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
<link href="/assets/style.css" rel="stylesheet">
<script src="/assets/date-range.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
:root {
  --primary:       <?= htmlspecialchars($_primaryColor) ?>;
  --primary-dark:  <?= htmlspecialchars($_primaryDark) ?>;
  --primary-rgb:   <?= $_pr ?>, <?= $_pg ?>, <?= $_pb ?>;
  --primary-light: rgba(<?= $_pr ?>, <?= $_pg ?>, <?= $_pb ?>, .12);
}
</style>
</head>
<body>

<?php
$user       = currentUser();
$role       = $user['role'] ?? '';
$activePath = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
?>

<!-- Sidebar -->
<div id="sidebar">
  <div class="brand" style="justify-content:space-between">
    <div class="d-flex align-items-center gap-2">
      <?php if ($_companyLogo): ?>
      <img src="<?= htmlspecialchars($_companyLogo) ?>" alt="Logo" style="height:28px;border-radius:4px">
      <?php else: ?>
      <i class="bi bi-broadcast-pin" style="color:var(--primary)"></i>
      <?php endif; ?>
      <span><span style="color:var(--primary)"><?= htmlspecialchars(explode(' ', $_companyName)[0]) ?></span><?= htmlspecialchars(implode(' ', array_slice(explode(' ', $_companyName), 1)) ?: '') ?></span>
    </div>
    <!-- Close button — only visible on mobile -->
    <button onclick="closeSidebar()" aria-label="Close menu"
      style="display:none;background:rgba(255,255,255,.08);border:none;border-radius:.3rem;color:rgba(255,255,255,.7);padding:.2rem .4rem;cursor:pointer;font-size:1.1rem;line-height:1"
      id="sidebarClose">
      <i class="bi bi-x-lg"></i>
    </button>
  </div>

  <div class="nav-section">Operations</div>
  <a href="/dashboard" class="nav-link <?= $activePath === 'dashboard' ? 'active' : '' ?>">
    <i class="bi bi-grid-1x2"></i> Dashboard
  </a>
  <a href="/tickets" class="nav-link <?= str_starts_with($activePath, 'ticket') ? 'active' : '' ?>">
    <i class="bi bi-ticket-perforated"></i> Tickets
  </a>

  <?php if (hasPermission('customers.view')): ?>
  <a href="/customers" class="nav-link <?= $activePath === 'customers' ? 'active' : '' ?>">
    <i class="bi bi-people"></i> Customers
  </a>
  <?php endif; ?>

  <?php if (hasPermission('schedule.view') || hasPermission('map.view')): ?>
  <div class="nav-section">Field</div>
  <?php if (hasPermission('schedule.view')): ?>
  <a href="/schedule" class="nav-link <?= $activePath === 'schedule' ? 'active' : '' ?>">
    <i class="bi bi-calendar3"></i> Schedule
  </a>
  <?php endif; ?>
  <?php if (hasPermission('map.view')): ?>
  <a href="/map" class="nav-link <?= $activePath === 'map' ? 'active' : '' ?>">
    <i class="bi bi-map"></i> Field Map
  </a>
  <?php endif; ?>
  <?php endif; ?>

  <?php if (hasPermission('installations.view')): ?>
  <div class="nav-section">Installations</div>
  <a href="/installations" class="nav-link <?= $activePath === 'installations' ? 'active' : '' ?>">
    <i class="bi bi-wifi"></i> Installations
  </a>
  <?php endif; ?>

  <?php if (hasPermission('finance.view')): ?>
  <div class="nav-section">Finance</div>
  <a href="/finance" class="nav-link <?= $activePath === 'finance' ? 'active' : '' ?>">
    <i class="bi bi-graph-up-arrow"></i> Finance Dashboard
  </a>
  <a href="/payment-requests" class="nav-link <?= $activePath === 'payment-requests' ? 'active' : '' ?>">
    <i class="bi bi-cash-coin"></i> Payment Requests
  </a>
  <?php elseif (hasPermission('payment_requests.create') || hasPermission('payment_requests.view')): ?>
  <a href="/payment-requests" class="nav-link <?= $activePath === 'payment-requests' ? 'active' : '' ?>">
    <i class="bi bi-cash-coin"></i> Payment Requests
  </a>
  <?php endif; ?>
  <?php if (hasPermission('billing.invoices.view') || hasPermission('billing.invoices.manage')): ?>
  <?php if (!hasPermission('finance.view')): ?>
  <div class="nav-section">Billing</div>
  <?php endif; ?>
  <a href="/billing/invoices" class="nav-link <?= str_starts_with($activePath, 'billing') ? 'active' : '' ?>">
    <i class="bi bi-receipt"></i> Invoices
  </a>
  <?php endif; ?>

  <?php
  $_invAny = hasPermission('inventory.view') || hasPermission('inventory.assets.view')
          || hasPermission('inventory.items.view') || hasPermission('inventory.cabinets.view')
          || hasPermission('inventory.categories.view') || hasPermission('inventory.requests.view')
          || hasPermission('inventory.requests.create') || hasPermission('inventory.movements.view');
  $_invSub = ($activePath === 'inventory' || str_starts_with($activePath, 'inventory/')) ? explode('/', $activePath)[1] ?? 'dashboard' : '';
  if ($_invAny):
  ?>
  <div class="nav-section">Inventory</div>
  <?php if (hasPermission('inventory.view')): ?>
  <a href="/inventory" class="nav-link <?= ($activePath === 'inventory') ? 'active' : '' ?>">
    <i class="bi bi-box-seam"></i> Overview
  </a>
  <?php endif; ?>
  <?php /* Assets — hidden from menu (commented out per request)
  if (hasPermission('inventory.assets.view')): ?>
  <a href="/inventory/assets" class="nav-link <?= in_array($_invSub,['assets','asset-form'])?'active':'' ?>">
    <i class="bi bi-pc-display"></i> Assets
  </a>
  <?php endif; */ ?>
  <?php if (hasPermission('inventory.items.view')): ?>
  <a href="/inventory/items" class="nav-link <?= in_array($_invSub,['items','item-form','refill'])?'active':'' ?>">
    <i class="bi bi-boxes"></i> Stock Items
  </a>
  <?php endif; ?>
  <?php /* Cabinets — hidden from menu (commented out per request)
  if (hasPermission('inventory.cabinets.view')): ?>
  <a href="/inventory/cabinets" class="nav-link <?= $_invSub==='cabinets'?'active':'' ?>">
    <i class="bi bi-archive"></i> Cabinets
  </a>
  <?php endif; */ ?>
  <?php if (hasPermission('inventory.categories.view')): ?>
  <a href="/inventory/categories" class="nav-link <?= $_invSub==='categories'?'active':'' ?>">
    <i class="bi bi-tags"></i> Categories
  </a>
  <?php endif; ?>
  <?php if (hasPermission('inventory.requests.view') || hasPermission('inventory.requests.create')): ?>
  <a href="/inventory/requests" class="nav-link <?= in_array($_invSub,['requests','request-new'])?'active':'' ?>">
    <i class="bi bi-clipboard-check"></i> Stock Requests
  </a>
  <?php endif; ?>
  <?php if (hasPermission('inventory.movements.view')): ?>
  <a href="/inventory/movements" class="nav-link <?= $_invSub==='movements'?'active':'' ?>">
    <i class="bi bi-arrow-left-right"></i> Movements
  </a>
  <?php endif; ?>
  <?php if (hasPermission('inventory.po.view')): ?>
  <a href="/inventory/purchase-orders" class="nav-link <?= $_invSub==='purchase-orders'?'active':'' ?>">
    <i class="bi bi-receipt"></i> Purchase Orders
  </a>
  <?php endif; ?>
  <?php endif; ?>

  <?php if (hasPermission('noc.view')): ?>
  <div class="nav-section">NOC</div>
  <a href="/noc" class="nav-link <?= str_starts_with($activePath, 'noc') ? 'active' : '' ?>">
    <i class="bi bi-broadcast"></i> Network Status
  </a>
  <?php endif; ?>

  <?php if (hasPermission('team.view') || hasPermission('analytics.view') || hasPermission('reports.view')): ?>
  <div class="nav-section">Team</div>
  <?php if (hasPermission('team.view')): ?>
  <a href="/team" class="nav-link <?= $activePath === 'team' ? 'active' : '' ?>">
    <i class="bi bi-person-badge"></i> Team
  </a>
  <?php endif; ?>
  <?php if (hasPermission('analytics.view')): ?>
  <a href="/analytics" class="nav-link <?= $activePath === 'analytics' ? 'active' : '' ?>">
    <i class="bi bi-bar-chart-line"></i> Analytics
  </a>
  <?php endif; ?>
  <?php if (hasPermission('reports.view')): ?>
  <a href="/reports" class="nav-link <?= $activePath === 'reports' ? 'active' : '' ?>">
    <i class="bi bi-file-earmark-bar-graph"></i> Reports
  </a>
  <?php endif; ?>
  <?php endif; ?>

  <?php if (hasPermission('admin.access')): ?>
  <div class="nav-section">System</div>
  <a href="/admin" class="nav-link <?= $activePath === 'admin' ? 'active' : '' ?>">
    <i class="bi bi-gear"></i> Admin
  </a>
  <?php endif; ?>

  <!-- Logout as last nav item — visible only on mobile (desktop uses the icon in user-panel) -->
  <a href="/logout" class="nav-link d-lg-none" style="margin-top:.25rem;color:#ef4444 !important">
    <i class="bi bi-box-arrow-right"></i> Logout
  </a>

  <div class="user-panel">
    <a href="/account" class="user-avatar text-decoration-none" title="My Account"><?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?></a>
    <a href="/account" class="flex-grow-1 overflow-hidden text-decoration-none" title="My Account">
      <div class="user-name text-truncate"><?= htmlspecialchars($user['name'] ?? '') ?></div>
      <div class="user-role"><?= htmlspecialchars($role) ?></div>
    </a>
    <a href="/logout" title="Logout" class="logout-btn">
      <i class="bi bi-box-arrow-right"></i>
    </a>
  </div>
  <div class="text-center" style="font-size:.65rem;color:rgba(255,255,255,.35);padding:.25rem 0 .5rem">v<?= htmlspecialchars(APP_VERSION) ?></div>
</div>

<!-- Main -->
<!-- Mobile backdrop -->
<div id="sidebarBackdrop" onclick="closeSidebar()"></div>

<div id="main">
<div class="topbar">
  <div class="d-flex align-items-center gap-2">
    <button id="sidebarToggle" onclick="toggleSidebar()" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div class="fw-semibold" style="font-size:.9375rem"><?= htmlspecialchars($pageTitle ?? '') ?></div>
  </div>

  <!-- Global search — jump to a ticket/customer/installation from any page -->
  <div class="global-search d-none d-md-block" id="globalSearchWrap">
    <i class="bi bi-search"></i>
    <input type="text" id="globalSearchInput" class="form-control form-control-sm" placeholder="Search tickets, customers, installations…" autocomplete="off">
    <div class="global-search-results d-none" id="globalSearchResults"></div>
  </div>

  <div class="d-flex align-items-center gap-2">
    <span class="badge bg-light text-dark border small d-none d-sm-inline"><?= htmlspecialchars($role) ?></span>

    <!-- Notification bell -->
    <div class="notif-wrap" id="notifWrap">
      <button class="notif-btn" id="notifBtn" onclick="toggleNotifPanel()" aria-label="Notifications">
        <i class="bi bi-bell"></i>
        <span class="notif-badge d-none" id="notifBadge">0</span>
      </button>
      <div class="notif-panel" id="notifPanel">
        <div class="notif-panel-header">
          <span class="fw-semibold" style="font-size:.875rem">Notifications</span>
          <button class="btn btn-link btn-sm p-0 text-muted" onclick="markAllRead()" style="font-size:.75rem">Mark all read</button>
        </div>
        <div id="notifList"><div class="notif-empty">Loading…</div></div>
      </div>
    </div>

    <?php if (hasPermission('tickets.create')): ?>
    <a href="/create-ticket" class="btn btn-sm btn-primary">
      <i class="bi bi-plus-lg me-1"></i><span class="d-none d-sm-inline">New Ticket</span><span class="d-sm-none">+</span>
    </a>
    <?php endif; ?>
  </div>
</div>
<div class="page-content">
