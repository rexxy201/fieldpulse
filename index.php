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
    if (file_exists($file)) { require $file; }
    else { jsonResponse(['error' => 'Not found'], 404); }
}

// Public pages
if ($page === 'login')  { require __DIR__ . '/pages/login.php'; exit; }
if ($page === 'logout') { require __DIR__ . '/pages/logout.php'; exit; }
if ($page === 'portal') { require __DIR__ . '/pages/portal.php'; exit; }

// Protected pages
requireAuth();

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
    'admin'         => 'admin',
    'installations' => 'installations',
];

if (isset($pages[$page])) {
    $file = __DIR__ . '/pages/' . $pages[$page] . '.php';
    if (file_exists($file)) { require $file; exit; }
}

// Default redirect
header('Location: /dashboard'); exit;
