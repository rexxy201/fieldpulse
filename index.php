<?php
// Let PHP built-in server serve static files (CSS, JS, images, fonts) natively
$_staticPath = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_file($_staticPath)) { return false; }

require_once __DIR__ . '/config.php';

$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$segments = explode('/', $path);
$page = $segments[0] ?: 'dashboard';

// API routes
if ($page === 'api') {
    $endpoint = $segments[1] ?? '';
    $file = __DIR__ . "/api/{$endpoint}.php";
    // Always exit after an API file runs — if it doesn't exit on its own (not every
    // endpoint uses the jsonResponse() helper, which exits internally), falling
    // through here would hit the page router below and get redirected to
    // /dashboard, silently discarding whatever the endpoint already echoed.
    if (file_exists($file)) { require $file; exit; }
    else { jsonResponse(['error' => 'Not found'], 404); }
}

// Public pages
if ($page === 'login')  { require __DIR__ . '/pages/login.php'; exit; }
if ($page === 'logout') { require __DIR__ . '/pages/logout.php'; exit; }
if ($page === 'portal') { require __DIR__ . '/pages/portal.php'; exit; }

// Public inventory QR-scan landing pages (no auth)
if ($page === 'asset')   { require __DIR__ . '/pages/inventory/public-asset.php';   exit; }
if ($page === 'cabinet') { require __DIR__ . '/pages/inventory/public-cabinet.php'; exit; }

// Protected pages
requireAuth();

// ── Installations module dispatch (list + SLA analytics sub-page) ─────────────
if ($page === 'installations') {
    $sub = $segments[1] ?? '';
    if ($sub === 'analytics') { require __DIR__ . '/pages/installations-analytics.php'; exit; }
    require __DIR__ . '/pages/installations.php'; exit;
}

// ── Inventory module dispatch ────────────────────────────────────────────────
if ($page === 'inventory') {
    $sub = $segments[1] ?? 'dashboard';
    $invPages = ['dashboard','assets','asset-form','items','item-form','cabinets',
                 'categories','requests','request-new','movements','refill'];
    if (in_array($sub, $invPages, true)) {
        $f = __DIR__ . '/pages/inventory/' . $sub . '.php';
        if (file_exists($f)) { require $f; exit; }
    }
    header('Location: /inventory'); exit;
}

$pages = [
    'dashboard'     => 'dashboard',
    'tickets'       => 'tickets',
    'ticket'        => 'ticket-detail',
    'create-ticket' => 'create-ticket',
    'customers'     => 'customers',
    'schedule'      => 'schedule',
    'map'           => 'map',
    'team'          => 'team',
    'analytics'     => 'analytics',
    'reports'       => 'reports',
    'admin'         => 'admin',
    'payment-requests' => 'payment-requests',
];

if (isset($pages[$page])) {
    $file = __DIR__ . '/pages/' . $pages[$page] . '.php';
    if (file_exists($file)) { require $file; exit; }
}

// Default redirect
header('Location: /dashboard'); exit;
