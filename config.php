<?php
declare(strict_types=1);

// ─── Composer autoloader ─────────────────────────────────────────────────────
$_autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($_autoload)) require_once $_autoload;

// ─── Database ───────────────────────────────────────────────────────────────
// Auto-detects environment:
//   Replit  → uses DATABASE_URL (PostgreSQL, no SSL for internal connections)
//   cPanel  → uses MySQL credentials below
define('DB_HOST', 'localhost');
define('DB_NAME', 'mangonetcom_fieldpulse');
define('DB_USER', 'mangonetcom_fieldpulse');
define('DB_PASS', 'YOUR_DATABASE_PASSWORD');   // ← change this for cPanel

$_dbUrl = getenv('DATABASE_URL');
if ($_dbUrl) {
    // ── Replit PostgreSQL ────────────────────────────────────────────────────
    $_p   = parse_url($_dbUrl);
    $_dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s',
                    $_p['host'], $_p['port'] ?? 5432, ltrim($_p['path'], '/'));
    $pdo  = new PDO($_dsn, $_p['user'], $_p['pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    define('DB_TYPE', 'pgsql');
} else {
    // ── cPanel MySQL ─────────────────────────────────────────────────────────
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME),
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    define('DB_TYPE', 'mysql');
}

// ─── Security Headers ────────────────────────────────────────────────────────
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    // Allow CDN resources (Bootstrap, Chart.js, Leaflet, Google Fonts)
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' cdn.jsdelivr.net unpkg.com; style-src 'self' 'unsafe-inline' cdn.jsdelivr.net unpkg.com fonts.googleapis.com; font-src 'self' fonts.gstatic.com cdn.jsdelivr.net; img-src 'self' data: *.tile.openstreetmap.org; connect-src 'self'");
}

// ─── Session ─────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
             || ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on';

    session_set_cookie_params([
        'lifetime' => 86400 * 7,
        'path'     => '/',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => $isSecure ? 'None' : 'Lax',
    ]);
    session_start();

    // Generate CSRF token once per session
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// ─── App config helper ───────────────────────────────────────────────────────
function getAppConfig(): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [];
        $k = dbKey();
        foreach (dbFetchAll("SELECT $k, value FROM app_config") as $r) {
            $cfg[$r['key']] = $r['value'];
        }
    }
    return $cfg;
}

function darkenColor(string $hex, int $amount = 20): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    $r = max(0, hexdec(substr($hex,0,2)) - $amount);
    $g = max(0, hexdec(substr($hex,2,2)) - $amount);
    $b = max(0, hexdec(substr($hex,4,2)) - $amount);
    return '#' . sprintf('%02x%02x%02x', $r, $g, $b);
}

// ─── Password helpers ────────────────────────────────────────────────────────
function hashPassword(string $plain): string {
    return password_hash($plain, PASSWORD_BCRYPT);
}

function verifyPassword(string $plain, string $stored): bool {
    // Bcrypt hashes start with $2y$
    if (str_starts_with($stored, '$2y$')) {
        return password_verify($plain, $stored);
    }
    // Legacy scrypt format from Node.js — accept "admin123" and migrate
    return $plain === 'admin123';
}

// ─── Auth helpers ─────────────────────────────────────────────────────────────
function isLoggedIn(): bool { return !empty($_SESSION['user_id']); }

function requireAuth(): void {
    if (!isLoggedIn()) {
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            jsonResponse(['error' => 'Not authenticated'], 401);
        }
        header('Location: /login'); exit;
    }
}

function currentUser(): ?array { return $_SESSION['user'] ?? null; }

function hasRole(string ...$roles): bool {
    $u = currentUser();
    return $u && in_array($u['role'], $roles);
}

function isAdmin(): bool {
    return hasRole('admin', 'project_admin');
}

// ─── SQL dialect helpers (MySQL ↔ PostgreSQL) ─────────────────────────────────
/** Quoted `key` identifier for app_config table — backtick in MySQL, plain in PostgreSQL */
function dbKey(): string {
    return DB_TYPE === 'pgsql' ? 'key' : '`key`';
}

/** Returns the right NOW() + interval expression (e.g. hours=24, unit='HOUR') */
function dbNowPlusInterval(int $n, string $unit): string {
    if (DB_TYPE === 'pgsql') {
        $pgUnit = match(strtoupper($unit)) {
            'HOUR'  => 'hours',
            'DAY'   => 'days',
            'MONTH' => 'months',
            default => strtolower($unit) . 's',
        };
        return "NOW() + INTERVAL '$n $pgUnit'";
    }
    return "NOW() + INTERVAL $n $unit";
}

/** Returns the right NOW() - interval expression (e.g. n=30, unit='DAY') */
function dbNowMinusInterval(int $n, string $unit): string {
    if (DB_TYPE === 'pgsql') {
        $pgUnit = match(strtoupper($unit)) {
            'HOUR'  => 'hours',
            'DAY'   => 'days',
            'MONTH' => 'months',
            default => strtolower($unit) . 's',
        };
        return "NOW() - INTERVAL '$n $pgUnit'";
    }
    return "NOW() - INTERVAL $n $unit";
}

/** Returns diff in seconds between two timestamp columns */
function dbSecondsDiff(string $start, string $end): string {
    if (DB_TYPE === 'pgsql') {
        return "EXTRACT(EPOCH FROM ($end - $start))";
    }
    return "TIMESTAMPDIFF(SECOND, $start, $end)";
}

/** Returns date format expression — converts MySQL strftime to TO_CHAR for PostgreSQL */
function dbDateFormat(string $col, string $mysqlFmt): string {
    if (DB_TYPE === 'pgsql') {
        $pgFmt = str_replace(
            ['%b', '%d', '%Y', '%m', '%H', '%i', '%s'],
            ['Mon', 'DD', 'YYYY', 'MM', 'HH24', 'MI', 'SS'],
            $mysqlFmt
        );
        return "TO_CHAR($col, '$pgFmt')";
    }
    return "DATE_FORMAT($col, '$mysqlFmt')";
}

/** Upsert a single app_config row — handles ON DUPLICATE KEY (MySQL) vs ON CONFLICT (PostgreSQL) */
function dbUpsertConfig(string $key, string $value): void {
    if (DB_TYPE === 'pgsql') {
        dbRun('INSERT INTO app_config (id, key, value) VALUES (?,?,?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value',
              [newUuid(), $key, $value]);
    } else {
        dbRun('INSERT INTO app_config (id,`key`,value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
              [newUuid(), $key, $value]);
    }
}

/** Upsert multiple app_config rows from an associative array */
function dbUpsertConfigs(array $map): void {
    foreach ($map as $k => $v) {
        dbUpsertConfig((string)$k, (string)$v);
    }
}

// ─── Security helpers ─────────────────────────────────────────────────────────
/** Strip password hash before returning user data to client */
function sanitizeUser(array $user): array {
    unset($user['password']);
    return $user;
}

/** Output hidden CSRF input field for HTML forms */
function csrfField(): string {
    $token = $_SESSION['csrf_token'] ?? '';
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
}

/** Return CSRF token string (for JS meta tag) */
function csrfToken(): string {
    return $_SESSION['csrf_token'] ?? '';
}

/** Verify CSRF token on state-changing requests — call in all page POST handlers */
function verifyCsrf(): void {
    $token    = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!$expected || !hash_equals($expected, $token)) {
        http_response_code(403);
        exit('Request blocked: invalid security token. Please refresh and try again.');
    }
}

// ─── HTTP helpers ─────────────────────────────────────────────────────────────
function jsonResponse(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function getBody(): array {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    return is_array($json) ? $json : $_POST;
}

function method(): string { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }

// ─── DB helpers ───────────────────────────────────────────────────────────────
function db(): PDO { global $pdo; return $pdo; }

function dbFetch(string $sql, array $params = []): ?array {
    $st = db()->prepare($sql); $st->execute($params);
    return $st->fetch() ?: null;
}

function dbFetchAll(string $sql, array $params = []): array {
    $st = db()->prepare($sql); $st->execute($params);
    return $st->fetchAll();
}

function dbRun(string $sql, array $params = []): \PDOStatement {
    $st = db()->prepare($sql); $st->execute($params);
    return $st;
}

// ─── Ticket number generator ──────────────────────────────────────────────────
function generateTicketNumber(string $prefix = 'INC'): string {
    $year = date('Y');
    $last = dbFetch(
        "SELECT ticket_number FROM tickets WHERE ticket_number LIKE ? ORDER BY ticket_number DESC LIMIT 1",
        ["$prefix-$year-%"]
    );
    $seq = 1;
    if ($last) {
        $parts = explode('-', $last['ticket_number']);
        $seq = (int)end($parts) + 1;
    }
    return sprintf('%s-%s-%03d', $prefix, $year, $seq);
}

// ─── Notification helper ──────────────────────────────────────────────────────
function notifyRoles(array $roles, string $title, string $message, string $link = ''): void {
    try {
        if (empty($roles)) return;
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $users = dbFetchAll("SELECT id FROM users WHERE role IN ($placeholders)", $roles);
        foreach ($users as $u) {
            dbRun(
                "INSERT INTO notifications (id, user_id, title, message, link) VALUES (?,?,?,?,?)",
                [newUuid(), $u['id'], $title, $message, $link]
            );
        }
    } catch (\Throwable $e) {
        error_log('notifyRoles error: ' . $e->getMessage());
    }
}

// ─── Email helper ─────────────────────────────────────────────────────────────
/**
 * Send an email via the configured SMTP settings.
 * Returns true on success, throws \Exception with a message on failure.
 * Requires PHPMailer (installed via Composer).
 */
function sendEmail(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): bool {
    if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
        throw new \Exception('PHPMailer not available — run: composer require phpmailer/phpmailer');
    }

    $cfg = getAppConfig();
    $host  = trim($cfg['smtpHost']     ?? '');
    $port  = (int)($cfg['smtpPort']    ?? 587);
    $user  = trim($cfg['smtpUser']     ?? '');
    $pass  = trim($cfg['smtpPassword'] ?? '');
    $from  = trim($cfg['smtpFrom']     ?? $user);
    $enc   = strtolower($cfg['smtpEncryption'] ?? 'tls');

    if (!$host || !$user) {
        throw new \Exception('SMTP not configured. Please set Host and Username in Admin → Email Settings.');
    }

    // Parse "Display Name <email@example.com>" format
    $fromName  = '';
    $fromEmail = $from;
    if (preg_match('/^(.+?)\s*<([^>]+)>$/', $from, $m)) {
        $fromName  = trim($m[1]);
        $fromEmail = trim($m[2]);
    }
    if (!$fromName) $fromName = $cfg['smtpFromName'] ?? 'FieldPulse';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $host;
    $mail->Port       = $port;
    $mail->SMTPAuth   = ($user !== '');
    $mail->Username   = $user;
    $mail->Password   = $pass;
    $mail->CharSet    = 'UTF-8';

    match ($enc) {
        'ssl'  => $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS,
        'tls'  => $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
        default => $mail->SMTPSecure = '',
    };
    if ($enc === 'none') $mail->SMTPAutoTLS = false;

    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($toEmail, $toName);
    $mail->Subject  = $subject;
    $mail->isHTML(true);
    $mail->Body     = $htmlBody;
    $mail->AltBody  = $textBody ?: strip_tags($htmlBody);

    $mail->send();
    return true;
}

// ─── Audit log ────────────────────────────────────────────────────────────────
function auditLog(string $action, string $entity, string $entityId = '', string $details = ''): void {
    try {
        $u = currentUser();
        dbRun(
            "INSERT INTO audit_logs (id, user_id, user_name, action, entity, entity_id, details) VALUES (?,?,?,?,?,?,?)",
            [newUuid(), $u['id'] ?? '', $u['name'] ?? '', $action, $entity, $entityId, $details]
        );
    } catch (\Throwable $e) {
        // Never let audit logging crash a user-facing page
        error_log('auditLog error: ' . $e->getMessage());
    }
}

// ─── UUID generator (replaces PostgreSQL's gen_random_uuid()) ────────────────
function newUuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
}

// ─── Role constants ───────────────────────────────────────────────────────────
define('ROLES', ['admin','project_admin','supervisor-fiber','supervisor-noc','cx_supervisor','cx','engineer','vendor']);

// ─── One-time PHP password migration ─────────────────────────────────────────
$_k = dbKey();
$_migrated = dbFetch("SELECT value FROM app_config WHERE $_k = 'php_pwd_migrated'");
if (!$_migrated) {
    $_users = dbFetchAll("SELECT id, password FROM users");
    foreach ($_users as $_u) {
        if (!str_starts_with($_u['password'], '$2y$')) {
            dbRun("UPDATE users SET password = ? WHERE id = ?", [hashPassword('admin123'), $_u['id']]);
        }
    }
    dbUpsertConfig('php_pwd_migrated', 'true');
}
