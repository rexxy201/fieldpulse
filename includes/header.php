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
<link href="<?= assetUrl('/assets/style.css') ?>" rel="stylesheet">
<script src="<?= assetUrl('/assets/date-range.js') ?>"></script>
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

  <?php
  // ── Navigation ──────────────────────────────────────────────────────────────
  // Each module is a collapsible group; the group holding the current page
  // starts open. A module with only one visible page is shown as a plain link.
  // Visibility rules are unchanged from the flat menu.
  $_invAny = hasPermission('inventory.view') || hasPermission('inventory.assets.view')
          || hasPermission('inventory.items.view') || hasPermission('inventory.cabinets.view')
          || hasPermission('inventory.categories.view') || hasPermission('inventory.requests.view')
          || hasPermission('inventory.requests.create') || hasPermission('inventory.movements.view');
  $_invSub = ($activePath === 'inventory' || str_starts_with($activePath, 'inventory/')) ? explode('/', $activePath)[1] ?? 'dashboard' : '';
  $_fieldAny = hasPermission('schedule.view') || hasPermission('map.view');
  $_navItem = fn(string $href, string $icon, string $label, bool $active, bool $show = true): array
      => ['href' => $href, 'icon' => $icon, 'label' => $label, 'active' => $active, 'show' => $show];

  $_navGroups = [
    ['label' => 'Operations', 'icon' => 'bi-clipboard2-pulse', 'items' => [
      $_navItem('/tickets',   'bi-ticket-perforated', 'Tickets',   str_starts_with($activePath, 'ticket')),
      $_navItem('/customers', 'bi-people',            'Customers', $activePath === 'customers', hasPermission('customers.view')),
    ]],
    ['label' => 'Customer Support', 'icon' => 'bi-headset', 'items' => [
      $_navItem('/support',              'bi-person-lines-fill', 'Agent Workspace', $activePath === 'support',              hasPermission('support.view')),
      $_navItem('/support/interactions', 'bi-chat-left-text',    'Interactions',    $activePath === 'support/interactions', hasPermission('support.view')),
      $_navItem('/support/followups',    'bi-alarm',             'Follow-ups',      $activePath === 'support/followups',    hasPermission('support.view')),
      $_navItem('/support/settings',     'bi-sliders',           'Support Settings', $activePath === 'support/settings',    hasPermission('support.manage')),
    ]],
    ['label' => 'Field', 'icon' => 'bi-geo-alt', 'items' => [
      $_navItem('/schedule', 'bi-calendar3', 'Schedule',  $activePath === 'schedule', $_fieldAny && hasPermission('schedule.view')),
      $_navItem('/map',      'bi-map',       'Field Map', $activePath === 'map',      $_fieldAny && hasPermission('map.view')),
      $_navItem('/my-jobs',  'bi-briefcase', 'My Jobs',   $activePath === 'my-jobs',  $_fieldAny && hasPermission('tickets.checkin')),
    ]],
    ['label' => 'Installations', 'icon' => 'bi-wifi', 'items' => [
      $_navItem('/installations', 'bi-wifi', 'Installations', $activePath === 'installations', hasPermission('installations.view')),
    ]],
    ['label' => 'Finance', 'icon' => 'bi-wallet2', 'items' => [
      $_navItem('/finance',          'bi-graph-up-arrow', 'Finance Dashboard', $activePath === 'finance', hasPermission('finance.view')),
      $_navItem('/payment-requests', 'bi-cash-coin',      'Payment Requests',  $activePath === 'payment-requests',
                hasPermission('finance.view') || hasPermission('payment_requests.create') || hasPermission('payment_requests.view')),
    ]],
    ['label' => 'Inventory', 'icon' => 'bi-box-seam', 'items' => [
      $_navItem('/inventory',                 'bi-box-seam',          'Overview',        $activePath === 'inventory',                             $_invAny && hasPermission('inventory.view')),
      // Assets and Cabinets are hidden from the menu (per request); their pages still exist.
      $_navItem('/inventory/items',           'bi-boxes',             'Stock Items',     in_array($_invSub, ['items', 'item-form', 'refill']),    $_invAny && hasPermission('inventory.items.view')),
      $_navItem('/inventory/categories',      'bi-tags',              'Categories',      $_invSub === 'categories',                               $_invAny && hasPermission('inventory.categories.view')),
      $_navItem('/inventory/requests',        'bi-clipboard-check',   'Stock Requests',  in_array($_invSub, ['requests', 'request-new']),         $_invAny && (hasPermission('inventory.requests.view') || hasPermission('inventory.requests.create'))),
      $_navItem('/inventory/movements',       'bi-arrow-left-right',  'Movements',       $_invSub === 'movements',                                $_invAny && hasPermission('inventory.movements.view')),
      $_navItem('/inventory/purchase-orders', 'bi-receipt',           'Purchase Orders', $_invSub === 'purchase-orders',                          $_invAny && hasPermission('inventory.po.view')),
      $_navItem('/inventory/serials',         'bi-upc-scan',          'Serial Numbers',  $_invSub === 'serials',                                  $_invAny && hasPermission('inventory.serials.view')),
    ]],
    ['label' => 'NOC', 'icon' => 'bi-broadcast', 'items' => [
      $_navItem('/noc', 'bi-broadcast', 'Network Status', str_starts_with($activePath, 'noc'), hasPermission('noc.view')),
    ]],
    ['label' => 'Team', 'icon' => 'bi-people', 'items' => [
      $_navItem('/team',      'bi-person-badge',          'Team',      $activePath === 'team',      hasPermission('team.view')),
      $_navItem('/analytics', 'bi-bar-chart-line',        'Analytics', $activePath === 'analytics', hasPermission('analytics.view')),
      $_navItem('/reports',   'bi-file-earmark-bar-graph', 'Reports',  $activePath === 'reports',   hasPermission('reports.view')),
    ]],
    ['label' => 'System', 'icon' => 'bi-gear', 'items' => [
      $_navItem('/admin',     'bi-gear',          'Admin',     $activePath === 'admin',                   hasPermission('admin.access')),
      $_navItem('/audit-log', 'bi-journal-text',  'Audit Log', str_starts_with($activePath, 'audit-log'), hasPermission('admin.audit.view')),
    ]],
  ];
  ?>

  <nav class="sidebar-nav" aria-label="Main">
  <a href="/dashboard" class="nav-link <?= $activePath === 'dashboard' ? 'active' : '' ?>">
    <i class="bi bi-grid-1x2"></i> Dashboard
  </a>

  <?php foreach ($_navGroups as $_gi => $_group):
    $_items = array_values(array_filter($_group['items'], fn($i) => $i['show']));
    if (!$_items) continue;
    if (count($_items) === 1):
      $_it = $_items[0]; ?>
  <a href="<?= $_it['href'] ?>" class="nav-link <?= $_it['active'] ? 'active' : '' ?>">
    <?php // Single-page modules (NOC, Installations) keep the module name; a module that
          // just has one page visible to this user shows that page's name. ?>
    <?php if (count($_group['items']) === 1): ?>
    <i class="bi <?= $_group['icon'] ?>"></i> <?= htmlspecialchars($_group['label']) ?>
    <?php else: ?>
    <i class="bi <?= $_it['icon'] ?>"></i> <?= htmlspecialchars($_it['label']) ?>
    <?php endif; ?>
  </a>
    <?php continue; endif;
    $_open = (bool)array_filter($_items, fn($i) => $i['active']);
    $_gid  = 'navgroup-' . $_gi; ?>
  <div class="nav-group<?= $_open ? ' open has-active' : '' ?>">
    <button type="button" class="nav-group-toggle" aria-expanded="<?= $_open ? 'true' : 'false' ?>" aria-controls="<?= $_gid ?>">
      <i class="bi <?= $_group['icon'] ?>"></i>
      <span class="flex-grow-1 text-start"><?= htmlspecialchars($_group['label']) ?></span>
      <i class="bi bi-chevron-right nav-group-chevron" aria-hidden="true"></i>
    </button>
    <div class="nav-group-items" id="<?= $_gid ?>">
      <div>
      <?php foreach ($_items as $_it): ?>
        <a href="<?= $_it['href'] ?>" class="nav-link <?= $_it['active'] ? 'active' : '' ?>">
          <i class="bi <?= $_it['icon'] ?>"></i> <?= htmlspecialchars($_it['label']) ?>
        </a>
      <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  </nav>

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
