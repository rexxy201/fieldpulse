<?php
declare(strict_types=1);

// ─── App version ──────────────────────────────────────────────────────────────
// Bump this on every release. Auto-recorded into app_config (with a deploy
// timestamp) below once the DB connection is up, so it's queryable/reportable
// and Admin can show "last deployed" without a manual migration each time.
define('APP_VERSION', '2.8');

// ─── Composer autoloader ─────────────────────────────────────────────────────
$_autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($_autoload)) require_once $_autoload;

// ─── AI helper (OpenAI wrapper) ────────────────────────────────────────────────
require_once __DIR__ . '/includes/ai.php';

// ─── Database ───────────────────────────────────────────────────────────────
// Auto-detects environment:
//   Replit  → uses DATABASE_URL (PostgreSQL, no SSL for internal connections)
//   cPanel  → uses MySQL credentials below
//
// The real DB password lives ONLY in secrets.php, a file that exists solely on
// the live server, is gitignored, and is never touched by git pull/deploy —
// this file (config.php) is now identical between GitHub and the live site,
// safe to auto-deploy, and can never again silently overwrite the live
// password with this placeholder (the cause of two past outages and a git
// merge conflict). See secrets.example.php for the one-time server setup.
$_secretsFile = __DIR__ . '/secrets.php';
if (file_exists($_secretsFile)) require_once $_secretsFile;

define('DB_HOST', 'localhost');
define('DB_NAME', 'mangonetcom_fieldpulse');
define('DB_USER', 'mangonetcom_fieldpulse');
define('DB_PASS', defined('DB_PASS_FROM_SECRETS') ? DB_PASS_FROM_SECRETS : 'YOUR_DATABASE_PASSWORD');

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
        [PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"]
    );
    define('DB_TYPE', 'mysql');
}

// ─── Timezone (configurable, default Nigeria / Lagos = WAT, UTC+1) ────────────
$_tzName = 'Africa/Lagos';
try {
    $_kq    = DB_TYPE === 'pgsql' ? 'key' : '`key`';
    $_tzRow = $pdo->query("SELECT value FROM app_config WHERE $_kq = 'timezone' LIMIT 1")->fetch();
    if ($_tzRow && !empty($_tzRow['value']) && in_array($_tzRow['value'], DateTimeZone::listIdentifiers())) {
        $_tzName = $_tzRow['value'];
    }
} catch (\Throwable $e) { /* app_config may not exist on first run — keep Lagos default */ }

date_default_timezone_set($_tzName);

// Keep the DB session clock in the same zone so NOW()/CURRENT_TIMESTAMP match PHP
try {
    if (DB_TYPE === 'pgsql') {
        // PostgreSQL: use the named timezone so DST is handled correctly
        $pdo->exec("SET TIME ZONE '$_tzName'");
    } else {
        // MySQL: SET time_zone requires an offset string (+HH:MM)
        $_off  = (new DateTime('now', new DateTimeZone($_tzName)))->getOffset();
        $_sign = $_off < 0 ? '-' : '+';
        $_off  = abs($_off);
        $pdo->exec(sprintf("SET time_zone = '%s%02d:%02d'", $_sign, intdiv($_off, 3600), intdiv($_off % 3600, 60)));
    }
} catch (\Throwable $e) { /* ignore if the host disallows SET time_zone */ }

// ─── Security Headers ────────────────────────────────────────────────────────
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    // Allow CDN resources (Bootstrap, Chart.js, Leaflet, Google Fonts)
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' cdn.jsdelivr.net unpkg.com; style-src 'self' 'unsafe-inline' cdn.jsdelivr.net unpkg.com fonts.googleapis.com; font-src 'self' fonts.gstatic.com cdn.jsdelivr.net; img-src 'self' data: *.tile.openstreetmap.org; connect-src 'self'");
    // Tell browsers to always use HTTPS for this host going forward (only sent over an actual HTTPS request)
    $_isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    if ($_isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
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
    // Bcrypt hashes start with $2y$ — this is the only format ever written by
    // hashPassword(). Anything else (empty, corrupted, legacy) fails closed:
    // no plaintext fallback, since that would be a permanent backdoor.
    if (str_starts_with($stored, '$2y$')) {
        return password_verify($plain, $stored);
    }
    return false;
}

// ─── Login brute-force lockout ─────────────────────────────────────────────────
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);

/**
 * Shared by pages/login.php and api/auth.php so both entry points get the same
 * lockout behavior. Returns ['ok'=>bool, 'user'=>array|null, 'error'=>?string].
 */
function attemptLogin(string $username, string $password): array {
    $user = dbFetch("SELECT * FROM users WHERE username = ?", [$username]);

    if ($user && !empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        $mins = (int)ceil((strtotime($user['locked_until']) - time()) / 60);
        return ['ok' => false, 'user' => null, 'error' => "Too many failed attempts. Try again in {$mins} minute" . ($mins===1?'':'s') . "."];
    }

    if ($user && verifyPassword($password, $user['password'])) {
        if ((int)($user['failed_login_attempts'] ?? 0) !== 0 || !empty($user['locked_until'])) {
            dbRun("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?", [$user['id']]);
        }
        return ['ok' => true, 'user' => $user, 'error' => null];
    }

    if ($user) {
        $attempts = (int)($user['failed_login_attempts'] ?? 0) + 1;
        if ($attempts >= LOGIN_MAX_ATTEMPTS) {
            $lockUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_MINUTES * 60);
            dbRun("UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?", [$attempts, $lockUntil, $user['id']]);
        } else {
            dbRun("UPDATE users SET failed_login_attempts = ? WHERE id = ?", [$attempts, $user['id']]);
        }
    }
    return ['ok' => false, 'user' => null, 'error' => 'Invalid username or password.'];
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
            'HOUR'   => 'hours',
            'DAY'    => 'days',
            'MONTH'  => 'months',
            'MINUTE' => 'minutes',
            default  => strtolower($unit) . 's',
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

/**
 * Cast a datetime column to a date.
 * PostgreSQL: col::date  |  MySQL: DATE(col)
 */
function dbDate(string $col): string {
    return DB_TYPE === 'pgsql' ? "{$col}::date" : "DATE({$col})";
}

/**
 * Extract the hour (0-23) from a datetime column.
 * PostgreSQL: EXTRACT(HOUR FROM col)  |  MySQL: HOUR(col)
 */
function dbHour(string $col): string {
    return DB_TYPE === 'pgsql' ? "EXTRACT(HOUR FROM {$col})" : "HOUR({$col})";
}

// ─── Installation SLA ──────────────────────────────────────────────────────────
define('INSTALLATION_SLA_WORKING_DAYS', 7);
// Stages that mean "no longer pending" — excluded from SLA/overdue tracking
// everywhere. 'connected' is the terminal "done" stage (drives completed_at,
// same role 'completed' used to play); 'refunded' means the job isn't
// happening, so it shouldn't count as outstanding either.
define('INSTALLATION_TERMINAL_STATUSES', ['connected', 'refunded']);

/** Add N working days (Mon–Fri) to a datetime string; returns 'Y-m-d H:i:s'. */
function addWorkingDays(string $fromDateTime, int $days): string {
    $dt = new DateTime($fromDateTime);
    $added = 0;
    while ($added < $days) {
        $dt->modify('+1 day');
        if ((int)$dt->format('N') < 6) { $added++; } // Mon(1)–Fri(5)
    }
    return $dt->format('Y-m-d H:i:s');
}

/** SLA badge for an installation_profiles row (['label'=>string,'class'=>bootstrap-color]). */
function installationSlaBadge(array $p): array {
    // Refunded stops the SLA clock, same as Connected — the job isn't happening, so
    // there's nothing left to be overdue against.
    if (($p['status'] ?? '') === 'refunded') {
        return ['label' => 'Refunded', 'class' => 'dark'];
    }
    if (empty($p['payment_confirmed_at'])) {
        return ['label' => 'Awaiting Payment', 'class' => 'secondary'];
    }
    $due = !empty($p['sla_due_at']) ? new DateTime($p['sla_due_at']) : null;
    if (!empty($p['completed_at'])) {
        $completed = new DateTime($p['completed_at']);
        if ($due && $completed > $due) {
            $lateDays = $due->diff($completed)->days;
            return ['label' => "Completed — {$lateDays}d Late", 'class' => 'danger'];
        }
        return ['label' => 'Completed — On Time', 'class' => 'success'];
    }
    if (!$due) return ['label' => 'SLA Pending', 'class' => 'secondary'];
    $now = new DateTime();
    if ($now > $due) {
        $lateDays = $due->diff($now)->days;
        return ['label' => "Overdue {$lateDays}d", 'class' => 'danger'];
    }
    $daysLeft = $now->diff($due)->days;
    return ['label' => $daysLeft === 0 ? 'Due Today' : "Due in {$daysLeft}d", 'class' => $daysLeft <= 1 ? 'warning' : 'info'];
}

/**
 * Days pending for an installation: payment_confirmed_at → connection_date
 * (if connected), refunded_at (if refunded), or today (if still pending).
 * Null if payment isn't confirmed yet, since there's no start date to count from.
 */
function installationDaysPending(array $p): ?int {
    if (empty($p['payment_confirmed_at'])) return null;
    $start = new DateTime($p['payment_confirmed_at']);
    $end   = !empty($p['refunded_at']) ? new DateTime($p['refunded_at'])
           : (!empty($p['connection_date']) ? new DateTime($p['connection_date']) : new DateTime());
    return $start->diff($end)->days;
}

// ─── Signup-app integration (serviceorder.mangonetonline.com) ─────────────────
// Same cPanel account / MySQL server as this app. Set SIGNUP_DB_NAME to the
// signup app's real database name (cPanel → MySQL Databases), and either grant
// this app's own DB_USER access to that database (cPanel → MySQL Databases →
// Add User to Database, SELECT privilege is enough), or set SIGNUP_DB_USER /
// SIGNUP_DB_PASS below to a separate credential instead.
// Leave SIGNUP_DB_NAME as '' (empty) until you've set it — that's the only
// value signupDb() treats as "not configured yet".
define('SIGNUP_DB_NAME', 'mangonetcom_serviceorder');
define('SIGNUP_DB_USER', DB_USER);
define('SIGNUP_DB_PASS', DB_PASS);

/** Lazily connects to the signup app's database. Returns null if not configured or unreachable. */
function signupDb(): ?PDO {
    static $pdo = null;
    static $tried = false;
    if ($pdo !== null) return $pdo;
    if ($tried) return null;
    $tried = true;
    if (DB_TYPE !== 'mysql' || SIGNUP_DB_NAME === '') return null;
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, SIGNUP_DB_NAME),
            SIGNUP_DB_USER, SIGNUP_DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (\Throwable $e) {
        error_log('Signup DB connection error: ' . $e->getMessage());
        return null;
    }
    return $pdo;
}

/**
 * Pulls paid/approved signups (status IN ('paid','approved') — approved
 * implies paid, since that's the next stage after payment in the signup
 * app's workflow) from the serviceorder app's `submissions` table into
 * installation_profiles, skipping any already imported (tracked via
 * signup_submission_id). payment_confirmed_at is set from the signup app's
 * paid_at (the moment Paystack payment was verified there), which starts the
 * installation SLA clock automatically — no manual "Confirm Payment" step.
 *
 * Deliberately does NOT import nin/passport_photo/govt_id/proof_of_address —
 * those are KYC documents with no operational use in FieldPulse, and copying
 * them would duplicate sensitive data across two systems unnecessarily.
 *
 * Returns ['imported'=>int, 'skipped'=>int, 'errors'=>string[]].
 */
function syncInstallationsFromSignup(): array {
    $sdb = signupDb();
    if (!$sdb) {
        return ['imported' => 0, 'skipped' => 0, 'errors' => ['Signup database not configured — set SIGNUP_DB_NAME in config.php.']];
    }

    try {
        $rows = $sdb->query("SELECT * FROM submissions WHERE status IN ('paid','approved') AND paid_at IS NOT NULL ORDER BY paid_at ASC")
                    ->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return ['imported' => 0, 'skipped' => 0, 'errors' => ['Could not read submissions: ' . $e->getMessage()]];
    }

    $imported = 0; $skipped = 0; $errors = [];
    foreach ($rows as $row) {
        if (dbFetch("SELECT id FROM installation_profiles WHERE signup_submission_id = ?", [$row['id']])) {
            $skipped++;
            continue;
        }

        $name    = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $address = implode(', ', array_filter([$row['address'] ?? '', $row['city'] ?? '', $row['state'] ?? '']));
        $paidAt  = $row['paid_at'];
        $slaDue  = addWorkingDays($paidAt, INSTALLATION_SLA_WORKING_DAYS);

        try {
            dbRun(
                "INSERT INTO installation_profiles
                    (id,name,phone,email,address,plan,wifi_username,wifi_password,notes,status,
                     payment_confirmed_at,sla_due_at,signup_submission_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [newUuid(), $name, $row['phone'] ?? '', $row['email'] ?? '', $address, $row['plan'] ?? '',
                 $row['wifi_ssid'] ?? '', $row['wifi_password'] ?? '', $row['notes'] ?? '', 'pending',
                 $paidAt, $slaDue, $row['id']]
            );
            $imported++;
        } catch (\Throwable $e) {
            $errors[] = "Submission {$row['id']}: " . $e->getMessage();
        }
    }
    return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
}

/**
 * Execute an INSERT that silently skips on unique-constraint violation.
 * MySQL: INSERT IGNORE INTO …  |  PostgreSQL: INSERT INTO … ON CONFLICT DO NOTHING
 */
function dbInsertIgnore(string $sql, array $params = []): void {
    if (DB_TYPE === 'pgsql') {
        dbRun($sql . ' ON CONFLICT DO NOTHING', $params);
    } else {
        dbRun(preg_replace('/^\s*INSERT\s+INTO\s+/i', 'INSERT IGNORE INTO ', $sql), $params);
    }
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
function notifyUser(string $userId, string $title, string $message, string $link = ''): void {
    try {
        if (!$userId) return;
        dbRun(
            "INSERT INTO notifications (id, user_id, title, message, link) VALUES (?,?,?,?,?)",
            [newUuid(), $userId, $title, $message, $link]
        );
    } catch (\Throwable $e) {
        error_log('notifyUser error: ' . $e->getMessage());
    }
}

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
    // Fail fast so a slow/unreachable SMTP server never hangs a user-facing page
    $mail->Timeout    = 12;

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

// ─── Absolute site base URL (for QR codes & public links) ─────────────────────
function siteBaseUrl(): string {
    $cfg = getAppConfig();
    if (!empty($cfg['siteUrl'])) return rtrim($cfg['siteUrl'], '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

// ─── Inventory upload paths ───────────────────────────────────────────────────
define('INV_UPLOAD_DIR', __DIR__ . '/uploads/products/');
define('INV_QR_DIR',     __DIR__ . '/uploads/qrcodes/');
function invUploadUrl(): string { return siteBaseUrl() . '/uploads/products/'; }
function invQrUrl(): string     { return siteBaseUrl() . '/uploads/qrcodes/'; }

// ─── Payment request documents ─────────────────────────────────────────────────
// Not under a web-guessable /uploads path with direct static access like the
// inventory images above — these can be financial/receipt documents, so they're
// only ever served through api/payment-request-document.php, which checks the
// same ownership/permission rules as the module itself before streaming a file.
define('PR_DOC_DIR', __DIR__ . '/uploads/payment_requests/');
define('PR_DOC_MAX_FILES', 5);
define('PR_DOC_MAX_BYTES', 10 * 1024 * 1024); // 10MB per file

/**
 * Validate the backing documents submitted alongside a Payment Request,
 * WITHOUT touching disk or the database. Call this before creating the
 * parent record so a bad upload never leaves an orphan payment request.
 * $files is the raw $_FILES['documents'] sub-array (multi-file input).
 * Returns ['ok'=>true,'count'=>N] or ['ok'=>false,'error'=>string].
 */
function validatePaymentRequestDocuments(array $files, bool $required = false, int $existingCount = 0): array {
    $allowedExt  = ['pdf','jpg','jpeg','png'];
    $allowedMime = ['application/pdf','image/jpeg','image/png'];

    $names = $files['name'] ?? [];
    $count = 0;
    foreach ($names as $n) { if ($n !== '') $count++; }
    if ($count === 0) {
        if ($required && $existingCount === 0) {
            return ['ok' => false, 'error' => 'At least one backing document is required.'];
        }
        return ['ok' => true, 'count' => 0];
    }
    if ($count > PR_DOC_MAX_FILES) return ['ok' => false, 'error' => 'You can attach at most ' . PR_DOC_MAX_FILES . ' documents.'];

    for ($i = 0; $i < $count; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Upload failed for "' . ($files['name'][$i] ?? 'a file') . '".'];
        }
        if (($files['size'][$i] ?? 0) > PR_DOC_MAX_BYTES) {
            return ['ok' => false, 'error' => '"' . $files['name'][$i] . '" is larger than 10MB.'];
        }
        $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            return ['ok' => false, 'error' => '"' . $files['name'][$i] . '" — only PDF, JPG, or PNG files are accepted.'];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($files['tmp_name'][$i]);
        if (!in_array($mime, $allowedMime, true)) {
            return ['ok' => false, 'error' => '"' . $files['name'][$i] . '" does not look like a valid PDF/JPG/PNG file.'];
        }
    }
    return ['ok' => true, 'count' => $count];
}

/**
 * Move + record already-validated documents against an existing payment
 * request. Call validatePaymentRequestDocuments() first — this trusts its
 * result and does not re-validate.
 */
function savePaymentRequestDocuments(array $files, string $paymentRequestId, string $uploaderId): int {
    $names = $files['name'] ?? [];
    $count = 0;
    foreach ($names as $n) { if ($n !== '') $count++; }
    if ($count === 0) return 0;

    if (!is_dir(PR_DOC_DIR)) @mkdir(PR_DOC_DIR, 0755, true);
    $saved = 0;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    for ($i = 0; $i < $count; $i++) {
        $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
        $storedName = 'prdoc_' . newUuid() . '.' . $ext;
        // Store the server-verified mime (from finfo), not the client-declared
        // one — that's what gets echoed back as Content-Type on download.
        $verifiedMime = $finfo->file($files['tmp_name'][$i]);
        if (move_uploaded_file($files['tmp_name'][$i], PR_DOC_DIR . $storedName)) {
            dbRun("INSERT INTO payment_request_documents (id,payment_request_id,original_name,stored_name,mime_type,size_bytes,uploaded_by) VALUES (?,?,?,?,?,?,?)",
                [newUuid(), $paymentRequestId, $files['name'][$i], $storedName, $verifiedMime, $files['size'][$i] ?? null, $uploaderId]);
            $saved++;
        }
    }
    return $saved;
}

// ─── Ticket photos (proof of service) ──────────────────────────────────────────
// Same "never a web-guessable static path" pattern as payment request documents —
// served only through api/ticket-photo.php, which checks canAccessTicket() on
// the parent ticket before streaming a file.
define('TICKET_PHOTO_DIR', __DIR__ . '/uploads/ticket_photos/');
define('TICKET_PHOTO_MAX_FILES', 6);
define('TICKET_PHOTO_MAX_BYTES', 8 * 1024 * 1024); // 8MB per photo

function validateTicketPhotos(array $files): array {
    $allowedExt  = ['jpg','jpeg','png','webp'];
    $allowedMime = ['image/jpeg','image/png','image/webp'];

    $names = $files['name'] ?? [];
    $count = 0;
    foreach ($names as $n) { if ($n !== '') $count++; }
    if ($count === 0) return ['ok' => true, 'count' => 0];
    if ($count > TICKET_PHOTO_MAX_FILES) return ['ok' => false, 'error' => 'You can attach at most ' . TICKET_PHOTO_MAX_FILES . ' photos.'];

    for ($i = 0; $i < $count; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Upload failed for "' . ($files['name'][$i] ?? 'a photo') . '".'];
        }
        if (($files['size'][$i] ?? 0) > TICKET_PHOTO_MAX_BYTES) {
            return ['ok' => false, 'error' => '"' . $files['name'][$i] . '" is larger than 8MB.'];
        }
        $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            return ['ok' => false, 'error' => '"' . $files['name'][$i] . '" — only JPG, PNG, or WebP photos are accepted.'];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($files['tmp_name'][$i]);
        if (!in_array($mime, $allowedMime, true)) {
            return ['ok' => false, 'error' => '"' . $files['name'][$i] . '" does not look like a valid photo.'];
        }
    }
    return ['ok' => true, 'count' => $count];
}

function saveTicketPhotos(array $files, string $ticketId, string $uploaderId, string $uploaderName): int {
    $names = $files['name'] ?? [];
    $count = 0;
    foreach ($names as $n) { if ($n !== '') $count++; }
    if ($count === 0) return 0;

    if (!is_dir(TICKET_PHOTO_DIR)) @mkdir(TICKET_PHOTO_DIR, 0755, true);
    $saved = 0;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    for ($i = 0; $i < $count; $i++) {
        $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
        $storedName = 'ticketphoto_' . newUuid() . '.' . $ext;
        $verifiedMime = $finfo->file($files['tmp_name'][$i]);
        if (move_uploaded_file($files['tmp_name'][$i], TICKET_PHOTO_DIR . $storedName)) {
            dbRun("INSERT INTO ticket_photos (id,ticket_id,original_name,stored_name,mime_type,size_bytes,uploaded_by,uploaded_by_name) VALUES (?,?,?,?,?,?,?,?)",
                [newUuid(), $ticketId, $files['name'][$i], $storedName, $verifiedMime, $files['size'][$i] ?? null, $uploaderId, $uploaderName]);
            $saved++;
        }
    }
    return $saved;
}

// ─── Role constants ───────────────────────────────────────────────────────────
define('ROLES', ['admin','project_admin','supervisor-fiber','supervisor-noc','cx_supervisor','cx','engineer','noc_engineer','vendor','accountant','accounts_receivable','accounts_payable','coo_manager']);

// ─── Permission definitions ────────────────────────────────────────────────────
define('ALL_PERMISSIONS', [
    'tickets.view_all'        => 'View all tickets (not just own)',
    'tickets.view_department' => 'View own department tickets only',
    'tickets.create'       => 'Create tickets',
    'tickets.update'       => 'Update tickets',
    'tickets.assign'       => 'Assign tickets to team members',
    'tickets.resolve'      => 'Mark tickets resolved',
    'tickets.close'        => 'Close tickets',
    'tickets.delete'       => 'Delete tickets',
    'customers.view'       => 'View customers',
    'customers.create'     => 'Create customers',
    'customers.update'     => 'Edit customers',
    'customers.delete'     => 'Delete customers',
    'installations.view'   => 'View installations',
    'installations.create' => 'Create installation profiles',
    'installations.update' => 'Update installations',
    'installations.delete' => 'Delete installation records',
    'installations.financial' => 'Update payment fields only (Amount Paid, Cost, Payment Confirmed Date, Installation Paid) — no stage/vendor/plan',
    'schedule.view'        => 'View schedule',
    'map.view'             => 'View field map',
    'team.view'            => 'View team page',
    'team.manage'          => 'Manage team members & vendors',
    'analytics.view'       => 'View analytics',
    'reports.view'          => 'View drill-down reports',
    'admin.access'         => 'Access admin panel',
    // ── Inventory module ──
    'inventory.view'              => 'View inventory dashboard',
    'inventory.assets.view'       => 'View assets',
    'inventory.assets.manage'     => 'Add / edit / delete assets',
    'inventory.items.view'        => 'View stock items',
    'inventory.items.manage'      => 'Add / edit / delete stock items',
    'inventory.cabinets.view'     => 'View cabinets',
    'inventory.cabinets.manage'   => 'Add / edit / delete cabinets',
    'inventory.categories.view'   => 'View categories',
    'inventory.categories.manage' => 'Add / edit / delete categories',
    'inventory.requests.create'   => 'Submit stock requests',
    'inventory.requests.view'     => 'View stock requests',
    'inventory.requests.approve'  => 'Approve / reject stock requests',
    'inventory.movements.view'    => 'View stock movements',
    'inventory.refill'            => 'Refill / add stock',
    // ── Payment Requests module ──
    'payment_requests.create'      => 'Submit payment requests',
    'payment_requests.view'        => 'View all payment requests (not just own)',
    'payment_requests.authorize'   => 'Authorize payment requests (Line Manager / Supervisor stage)',
    'payment_requests.approve'     => 'Approve payment requests (COO / senior management stage)',
    'payment_requests.finance_check' => 'Finance check — disburse or return to requester',
    // ── Finance module ──
    'finance.view' => 'Access the Finance dashboard (aggregates + AI reports)',
]);

// ─── RBAC helpers ─────────────────────────────────────────────────────────────
function hasPermission(string $perm): bool {
    static $cache = null;
    $u = currentUser();
    if (!$u) return false;
    if ($u['role'] === 'admin') return true;
    if ($cache === null) {
        try {
            $rows  = dbFetchAll("SELECT permission FROM role_permissions WHERE role = ?", [$u['role']]);
            $cache = array_column($rows, 'permission');
        } catch (\Throwable $e) {
            $cache = [];
        }
    }
    return in_array($perm, $cache ?? []);
}

function requirePermission(string $perm): void {
    if (!hasPermission($perm)) {
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            jsonResponse(['error' => 'Access denied'], 403);
        }
        header('Location: /dashboard'); exit;
    }
}

function getPermissionsForRole(string $role): array {
    if ($role === 'admin') return array_keys(ALL_PERMISSIONS);
    try {
        $rows = dbFetchAll("SELECT permission FROM role_permissions WHERE role = ?", [$role]);
        return array_column($rows, 'permission');
    } catch (\Throwable $e) {
        return [];
    }
}

// ─── Role registry (DB-managed roles) ─────────────────────────────────────────
/** All roles from the `roles` table, keyed by name. Falls back to ROLES constant. */
function getRoles(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        foreach (dbFetchAll("SELECT name, label, department, is_system FROM roles ORDER BY is_system DESC, label") as $r) {
            $cache[$r['name']] = $r;
        }
    } catch (\Throwable $e) { /* table may not exist yet */ }
    if (!$cache) {
        // Fallback to the built-in constant if the roles table is unavailable
        foreach (ROLES as $r) {
            $cache[$r] = ['name'=>$r, 'label'=>ucwords(str_replace(['-','_'],' ',$r)), 'department'=>'', 'is_system'=>1];
        }
    }
    return $cache;
}

/** List of role name keys. */
function roleKeys(): array { return array_keys(getRoles()); }

/** Department of a given role name ('' if none). */
function roleDepartment(string $role): string {
    return (string)(getRoles()[$role]['department'] ?? '');
}

/** Department of the current logged-in user's role ('' if none). */
function userDepartment(): string {
    $u = currentUser();
    return $u ? roleDepartment($u['role']) : '';
}

// ─── Ticket visibility scoping ────────────────────────────────────────────────
/**
 * Returns [sqlFragment, params] to AND into a tickets query for the current user.
 *  - tickets.view_all        → no restriction ('' fragment)
 *  - tickets.view_department → tickets whose fault type routes to the user's
 *                              department, plus tickets they created or are assigned
 *  - otherwise               → only own (created_by or assigned_to)
 * $alias is the tickets table alias used in the query (e.g. 't' or '').
 */
function ticketScopeSql(string $alias = 't'): array {
    $u = currentUser();
    if (!$u) return ['1=0', []];
    $p = $alias ? $alias . '.' : '';
    // Vendors aren't part of the internal permission tiers below — they see only
    // tickets handed to their company for field work.
    if (($u['role'] ?? '') === 'vendor') {
        if (empty($u['vendor_id'])) return ['1=0', []];
        return ["{$p}vendor_id = ?", [$u['vendor_id']]];
    }
    if (hasPermission('tickets.view_all')) return ['', []];
    $uid = $u['id'];

    if (hasPermission('tickets.view_department') && ($dept = userDepartment()) !== '') {
        return [
            "({$p}created_by = ? OR {$p}assigned_to = ? OR {$p}fault_type_id IN (SELECT id FROM fault_types WHERE route_to = ?))",
            [$uid, $uid, $dept],
        ];
    }
    return ["({$p}created_by = ? OR {$p}assigned_to = ?)", [$uid, $uid]];
}

/** Can the current user view this specific ticket row? */
function canAccessTicket(array $ticket): bool {
    $u = currentUser();
    if (!$u) return false;
    if (($u['role'] ?? '') === 'vendor') {
        return !empty($u['vendor_id']) && ($ticket['vendor_id'] ?? null) === $u['vendor_id'];
    }
    if (hasPermission('tickets.view_all')) return true;
    if (($ticket['assigned_to'] ?? null) === $u['id'] || ($ticket['created_by'] ?? null) === $u['id']) return true;
    if (hasPermission('tickets.view_department') && ($dept = userDepartment()) !== '' && !empty($ticket['fault_type_id'])) {
        $ft = dbFetch("SELECT route_to FROM fault_types WHERE id = ?", [$ticket['fault_type_id']]);
        if ($ft && strtolower(trim((string)$ft['route_to'])) === strtolower($dept)) return true;
    }
    return false;
}

// ─── Auto-assign supervisor for ticket routing ────────────────────────────────
/**
 * Given a fault_type id, finds the most recently logged-in active supervisor
 * for the fault type's routed department. Falls back to any active supervisor.
 */
function getAutoAssignSupervisor(string $faultTypeId): ?array {
    $ft = dbFetch("SELECT route_to FROM fault_types WHERE id = ?", [$faultTypeId]);
    if (!$ft || empty($ft['route_to'])) return null;

    $roleMap = [
        'fiber'        => 'supervisor-fiber',
        'noc'          => 'supervisor-noc',
        'installation' => 'supervisor-fiber',
        'cx'           => 'cx_supervisor',
    ];
    $targetRole = $roleMap[strtolower(trim($ft['route_to']))] ?? null;
    if (!$targetRole) return null;

    // Most recently logged-in active supervisor; random fallback if no login record
    $sup = dbFetch(
        "SELECT u.id, u.name, u.email FROM users u
         LEFT JOIN (
             SELECT user_id, MAX(created_at) AS last_login
             FROM audit_logs WHERE action = 'login' GROUP BY user_id
         ) al ON al.user_id = u.id
         WHERE u.role = ? AND u.status = 'active'
         ORDER BY COALESCE(al.last_login, '2000-01-01') DESC
         LIMIT 1",
        [$targetRole]
    );
    return $sup ?: null;
}

// ─── Hub-city routing helpers ─────────────────────────────────────────────────
/**
 * Given a customer's mailing_city, look up the hub it belongs to via hub_city_mappings.
 */
function getHubIdForCity(string $city): ?string {
    if (empty(trim($city))) return null;
    $row = dbFetch(
        "SELECT hub_id FROM hub_city_mappings WHERE LOWER(TRIM(city_name)) = LOWER(TRIM(?))",
        [trim($city)]
    );
    return $row['hub_id'] ?? null;
}

/**
 * For fiber/installation tickets: find the least-loaded active engineer in the team
 * assigned to the given hub. Falls back to supervisor-fiber if none found.
 */
function getAutoAssignFiber(string $faultTypeId, ?string $hubId): ?array {
    if ($hubId) {
        $hub = dbFetch("SELECT team_id FROM hubs WHERE id = ?", [$hubId]);
        $teamId = $hub['team_id'] ?? null;
        if ($teamId) {
            $engineer = dbFetch(
                "SELECT u.id, u.name, u.email FROM users u
                 LEFT JOIN (
                     SELECT assigned_to, COUNT(*) AS open_count
                     FROM tickets WHERE status IN ('open','in_progress')
                     GROUP BY assigned_to
                 ) tc ON tc.assigned_to = u.id
                 WHERE u.team_id = ? AND u.status = 'active' AND u.role IN ('engineer','noc_engineer')
                 ORDER BY COALESCE(tc.open_count, 0) ASC, u.name ASC
                 LIMIT 1",
                [$teamId]
            );
            if ($engineer) return $engineer;
        }
    }
    return getAutoAssignSupervisor($faultTypeId);
}

// ─── Email helpers for ticket events ──────────────────────────────────────────
function emailTicketAssigned(array $ticket, array $assignee): void {
    try {
        if (empty($assignee['email'])) return;
        $tn  = htmlspecialchars($ticket['ticket_number'] ?? '');
        $desc = htmlspecialchars(substr($ticket['description'] ?? '', 0, 200));
        $prio = strtoupper($ticket['priority'] ?? '');
        $link = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/ticket/' . $ticket['id'];
        sendEmail(
            $assignee['email'], $assignee['name'],
            "Ticket Assigned to You — {$tn}",
            "<p>Hi {$assignee['name']},</p>
             <p>Ticket <strong>{$tn}</strong> (Priority: {$prio}) has been assigned to you.</p>
             <p><strong>Issue:</strong> {$desc}</p>
             <p><a href='{$link}'>View Ticket →</a></p>
             <p style='color:#64748b;font-size:.85rem'>FieldPulse · MangoNet</p>"
        );
    } catch (\Throwable $e) {
        error_log('emailTicketAssigned error: ' . $e->getMessage());
    }
}

function emailTicketUpdated(array $ticket, array $creator, string $changedBy, string $newStatus): void {
    try {
        if (empty($creator['email'])) return;
        $tn   = htmlspecialchars($ticket['ticket_number'] ?? '');
        $statusLabel = str_replace('_', ' ', ucfirst($newStatus));
        $link = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/ticket/' . $ticket['id'];
        sendEmail(
            $creator['email'], $creator['name'],
            "Ticket {$tn} Updated",
            "<p>Hi {$creator['name']},</p>
             <p>Ticket <strong>{$tn}</strong> that you created has been updated by <strong>{$changedBy}</strong>.</p>
             <p><strong>New Status:</strong> {$statusLabel}</p>
             <p><a href='{$link}'>View Ticket →</a></p>
             <p style='color:#64748b;font-size:.85rem'>FieldPulse · MangoNet</p>"
        );
    } catch (\Throwable $e) {
        error_log('emailTicketUpdated error: ' . $e->getMessage());
    }
}

function emailCustomerTicketCreated(array $ticket, array $customer): void {
    try {
        if (empty($customer['email'])) return;
        $tn   = htmlspecialchars($ticket['ticket_number'] ?? '');
        $desc = htmlspecialchars($ticket['description'] ?? '');
        $prio = ['p1'=>'Critical','p2'=>'High','p3'=>'Medium','p4'=>'Low'][$ticket['priority'] ?? 'p3'] ?? 'Medium';
        $cfg  = getAppConfig();
        $co   = htmlspecialchars($cfg['companyName'] ?? 'FieldPulse');
        sendEmail(
            $customer['email'], $customer['name'],
            "Your Service Ticket Has Been Raised — {$tn}",
            "<p>Dear {$customer['name']},</p>
             <p>Thank you for reaching out. We have successfully logged a ticket for your issue.</p>
             <table style='border-collapse:collapse;width:100%;max-width:480px;font-size:.9rem'>
               <tr><td style='padding:6px 12px;background:#f8fafc;font-weight:600;border:1px solid #e2e8f0'>Ticket #</td><td style='padding:6px 12px;border:1px solid #e2e8f0'>{$tn}</td></tr>
               <tr><td style='padding:6px 12px;background:#f8fafc;font-weight:600;border:1px solid #e2e8f0'>Issue</td><td style='padding:6px 12px;border:1px solid #e2e8f0'>{$desc}</td></tr>
               <tr><td style='padding:6px 12px;background:#f8fafc;font-weight:600;border:1px solid #e2e8f0'>Priority</td><td style='padding:6px 12px;border:1px solid #e2e8f0'>{$prio}</td></tr>
             </table>
             <p>Our team will be in touch shortly. Please keep your ticket number for reference.</p>
             <p style='color:#64748b;font-size:.85rem'>{$co} Support Team</p>"
        );
    } catch (\Throwable $e) {
        error_log('emailCustomerTicketCreated error: ' . $e->getMessage());
    }
}

/**
 * Sent to the customer the first time a ticket reaches a done-state (resolved/closed).
 * Call only on the first transition into resolved/closed to avoid duplicate emails.
 */
function emailCustomerTicketResolved(array $ticket, array $customer): void {
    try {
        if (empty($customer['email'])) return;
        $tn   = htmlspecialchars($ticket['ticket_number'] ?? '');
        $desc = htmlspecialchars($ticket['description'] ?? '');
        $cfg  = getAppConfig();
        $co   = htmlspecialchars($cfg['companyName'] ?? 'FieldPulse');
        sendEmail(
            $customer['email'], $customer['name'],
            "Your Ticket {$tn} Has Been Resolved",
            "<p>Dear {$customer['name']},</p>
             <p>We are pleased to inform you that your ticket has been <strong>resolved</strong>. Our team has completed work on your reported issue.</p>
             <table style='border-collapse:collapse;width:100%;max-width:480px;font-size:.9rem'>
               <tr><td style='padding:6px 12px;background:#f8fafc;font-weight:600;border:1px solid #e2e8f0'>Ticket #</td><td style='padding:6px 12px;border:1px solid #e2e8f0'>{$tn}</td></tr>
               <tr><td style='padding:6px 12px;background:#f8fafc;font-weight:600;border:1px solid #e2e8f0'>Issue</td><td style='padding:6px 12px;border:1px solid #e2e8f0'>{$desc}</td></tr>
               <tr><td style='padding:6px 12px;background:#f8fafc;font-weight:600;border:1px solid #e2e8f0'>Status</td><td style='padding:6px 12px;border:1px solid #e2e8f0;color:#15803d;font-weight:600'>Resolved</td></tr>
             </table>
             <p>If the issue persists or recurs, simply reply or contact us and we'll reopen your case right away.</p>
             <p style='color:#64748b;font-size:.85rem'>{$co} Support Team</p>"
        );
    } catch (\Throwable $e) {
        error_log('emailCustomerTicketResolved error: ' . $e->getMessage());
    }
}

// ─── Installation vendor emails ────────────────────────────────────────────────
function emailVendorInstallationAssigned(array $profile, array $vendor, bool $isReassignment = false): void {
    try {
        if (empty($vendor['email'])) return;
        $name = htmlspecialchars($profile['name'] ?? '');
        $addr = htmlspecialchars($profile['address'] ?? '—');
        $plan = htmlspecialchars($profile['plan'] ?? '—');
        $due  = !empty($profile['sla_due_at']) ? date('d M Y', strtotime($profile['sla_due_at'])) : null;
        $link = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/installations?detail=' . $profile['id'];
        $verb = $isReassignment ? 'reassigned to you' : 'assigned to you';
        sendEmail(
            $vendor['email'], $vendor['name'] ?? 'Vendor',
            ($isReassignment ? 'Installation Reassigned to You' : 'New Installation Assigned to You') . " — {$name}",
            "<p>Hi " . htmlspecialchars($vendor['name'] ?? 'there') . ",</p>
             <p>An installation job for <strong>{$name}</strong> has been {$verb}.</p>
             <table style='border-collapse:collapse;font-size:.9rem'>
               <tr><td style='padding:4px 10px;background:#f8fafc;font-weight:600'>Customer</td><td style='padding:4px 10px'>{$name}</td></tr>
               <tr><td style='padding:4px 10px;background:#f8fafc;font-weight:600'>Address</td><td style='padding:4px 10px'>{$addr}</td></tr>
               <tr><td style='padding:4px 10px;background:#f8fafc;font-weight:600'>Plan</td><td style='padding:4px 10px'>{$plan}</td></tr>
               " . ($due ? "<tr><td style='padding:4px 10px;background:#f8fafc;font-weight:600'>SLA Due</td><td style='padding:4px 10px'>{$due}</td></tr>" : '') . "
             </table>
             <p><a href='{$link}'>View Installation →</a></p>
             <p style='color:#64748b;font-size:.85rem'>FieldPulse · MangoNet</p>"
        );
    } catch (\Throwable $e) {
        error_log('emailVendorInstallationAssigned error: ' . $e->getMessage());
    }
}

function emailVendorInstallationSlaWarning(array $profile, array $vendor): void {
    try {
        if (empty($vendor['email'])) return;
        $name = htmlspecialchars($profile['name'] ?? '');
        $due  = date('d M Y', strtotime($profile['sla_due_at']));
        $link = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/installations?detail=' . $profile['id'];
        sendEmail(
            $vendor['email'], $vendor['name'] ?? 'Vendor',
            "⚠ Installation SLA Due Soon — {$name}",
            "<p style='color:#d97706;font-weight:700'>⚠ SLA Due Soon</p>
             <p>Hi " . htmlspecialchars($vendor['name'] ?? 'there') . ", the installation for <strong>{$name}</strong> is due by <strong>{$due}</strong> and hasn't been completed yet.</p>
             <p><a href='{$link}'>View Installation →</a></p>
             <p style='color:#64748b;font-size:.85rem'>FieldPulse · MangoNet</p>"
        );
    } catch (\Throwable $e) {
        error_log('emailVendorInstallationSlaWarning error: ' . $e->getMessage());
    }
}

function emailVendorInstallationSlaBreached(array $profile, array $vendor): void {
    try {
        if (empty($vendor['email'])) return;
        $name = htmlspecialchars($profile['name'] ?? '');
        $due  = date('d M Y', strtotime($profile['sla_due_at']));
        $link = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/installations?detail=' . $profile['id'];
        sendEmail(
            $vendor['email'], $vendor['name'] ?? 'Vendor',
            "🚨 Installation SLA Breached — {$name}",
            "<p style='color:#dc2626;font-weight:700'>🚨 SLA Breached</p>
             <p>Hi " . htmlspecialchars($vendor['name'] ?? 'there') . ", the installation for <strong>{$name}</strong> was due by <strong>{$due}</strong> and is now overdue. Please complete it or update its status as soon as possible.</p>
             <p><a href='{$link}'>View Installation →</a></p>
             <p style='color:#64748b;font-size:.85rem'>FieldPulse · MangoNet</p>"
        );
    } catch (\Throwable $e) {
        error_log('emailVendorInstallationSlaBreached error: ' . $e->getMessage());
    }
}

// ─── Ensure app_config.key has a UNIQUE constraint (required for ON CONFLICT) ─
if (DB_TYPE === 'pgsql') {
    try {
        db()->exec("ALTER TABLE app_config ADD CONSTRAINT app_config_key_uq UNIQUE (key)");
    } catch (\Throwable $e) { /* already exists — fine */ }
}

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

// ─── One-time RBAC table + seed migration ─────────────────────────────────────
$_k = dbKey();
$_rbacDone = dbFetch("SELECT value FROM app_config WHERE $_k = 'rbac_seeded_v1'");
if (!$_rbacDone) {
    try {
        // Create table
        if (DB_TYPE === 'pgsql') {
            db()->exec("CREATE TABLE IF NOT EXISTS role_permissions (
                id varchar(36) PRIMARY KEY,
                role varchar(50) NOT NULL,
                permission varchar(100) NOT NULL,
                UNIQUE (role, permission)
            )");
        } else {
            db()->exec("CREATE TABLE IF NOT EXISTS `role_permissions` (
                `id` varchar(36) NOT NULL,
                `role` varchar(50) NOT NULL,
                `permission` varchar(100) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_role_perm` (`role`,`permission`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // Add sla_warned_at column to tickets if missing
        if (DB_TYPE === 'mysql') {
            try { db()->exec("ALTER TABLE `tickets` ADD COLUMN `sla_warned_at` DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
        }

        // Seed defaults
        $_defaults = [
            'admin'            => array_keys(ALL_PERMISSIONS),
            'project_admin'    => array_keys(ALL_PERMISSIONS),
            'supervisor-fiber' => ['tickets.view_all','tickets.create','tickets.update','tickets.assign','tickets.resolve','tickets.close','customers.view','customers.create','customers.update','installations.view','installations.create','installations.update','schedule.view','map.view','team.view','analytics.view','payment_requests.create','payment_requests.authorize'],
            'supervisor-noc'   => ['tickets.view_all','tickets.create','tickets.update','tickets.assign','tickets.resolve','tickets.close','customers.view','customers.create','customers.update','schedule.view','map.view','team.view','analytics.view','payment_requests.create','payment_requests.authorize'],
            'cx_supervisor'    => ['tickets.create','tickets.update','tickets.resolve','tickets.close','customers.view','customers.create','customers.update','analytics.view','payment_requests.create','payment_requests.authorize'],
            'cx'               => ['tickets.create','customers.view','customers.create','payment_requests.create'],
            'engineer'         => ['tickets.update','tickets.resolve','tickets.close','schedule.view','map.view','installations.view','payment_requests.create'],
            'noc_engineer'     => ['tickets.update','tickets.resolve','tickets.close','schedule.view','map.view','payment_requests.create'],
            'vendor'           => ['installations.view','payment_requests.create'],
            'accountant'          => ['payment_requests.create','payment_requests.view','payment_requests.finance_check','installations.view','installations.financial','customers.view','reports.view','analytics.view','finance.view'],
            'accounts_receivable' => ['installations.view','installations.financial','customers.view','reports.view','analytics.view','finance.view'],
            'accounts_payable'    => ['payment_requests.create','payment_requests.view','payment_requests.finance_check','reports.view','analytics.view','finance.view'],
            'coo_manager'          => ['payment_requests.create','payment_requests.view','payment_requests.approve','installations.view','installations.financial','customers.view','reports.view','analytics.view','finance.view'],
        ];
        foreach ($_defaults as $_r => $_perms) {
            foreach ($_perms as $_p) {
                try { dbInsertIgnore("INSERT INTO role_permissions (id,role,permission) VALUES (?,?,?)", [newUuid(),$_r,$_p]); } catch (\Throwable $e) {}
            }
        }
        dbUpsertConfig('rbac_seeded_v1', 'true');
    } catch (\Throwable $e) {
        error_log('RBAC migration error: ' . $e->getMessage());
    }
}

// ─── One-time roles registry + department scoping migration ───────────────────
$_k = dbKey();
$_rolesDone = dbFetch("SELECT value FROM app_config WHERE $_k = 'roles_seeded_v1'");
if (!$_rolesDone) {
    try {
        if (DB_TYPE === 'pgsql') {
            db()->exec("CREATE TABLE IF NOT EXISTS roles (
                name varchar(50) PRIMARY KEY,
                label varchar(100) NOT NULL,
                department varchar(30) NOT NULL DEFAULT '',
                is_system smallint NOT NULL DEFAULT 0
            )");
        } else {
            db()->exec("CREATE TABLE IF NOT EXISTS `roles` (
                `name` varchar(50) NOT NULL,
                `label` varchar(100) NOT NULL,
                `department` varchar(30) NOT NULL DEFAULT '',
                `is_system` tinyint(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // Seed the built-in roles (label + department). is_system = cannot be deleted.
        $_seedRoles = [
            ['admin','Admin','',1],
            ['project_admin','Project Admin','',1],
            ['supervisor-fiber','Fiber Supervisor','fiber',1],
            ['supervisor-noc','NOC Supervisor','noc',1],
            ['cx_supervisor','CX Supervisor','cx',1],
            ['cx','CX Agent','',1],
            ['engineer','Engineer','fiber',1],
            ['noc_engineer','NOC Engineer','noc',1],
            ['vendor','Vendor','',1],
        ];
        foreach ($_seedRoles as [$_rn,$_rl,$_rd,$_rs]) {
            try { dbInsertIgnore("INSERT INTO roles (name,label,department,is_system) VALUES (?,?,?,?)", [$_rn,$_rl,$_rd,$_rs]); } catch (\Throwable $e) {}
        }

        // Switch fiber/noc supervisors from view_all → view_department (department-only)
        foreach (['supervisor-fiber','supervisor-noc'] as $_sr) {
            dbRun("DELETE FROM role_permissions WHERE role=? AND permission='tickets.view_all'", [$_sr]);
            try { dbInsertIgnore("INSERT INTO role_permissions (id,role,permission) VALUES (?,?,'tickets.view_department')", [newUuid(),$_sr]); } catch (\Throwable $e) {}
        }

        dbUpsertConfig('roles_seeded_v1', 'true');
    } catch (\Throwable $e) {
        error_log('Roles migration error: ' . $e->getMessage());
    }
}

// ─── Schema v3: noc_engineer role + permissions ────────────────────────────────
$_k = dbKey();
$_sv3 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v3_migrated'");
if (!$_sv3) {
    try {
        // Insert noc_engineer into roles table
        try { dbInsertIgnore("INSERT INTO roles (name,label,department,is_system) VALUES (?,?,?,?)", ['noc_engineer','NOC Engineer','noc',1]); } catch (\Throwable $e) {}
        // Seed permissions for noc_engineer
        $_nocPerms = ['tickets.update','tickets.resolve','tickets.close','schedule.view','map.view'];
        foreach ($_nocPerms as $_p) {
            try { dbInsertIgnore("INSERT INTO role_permissions (id,role,permission) VALUES (?,?,?)", [newUuid(),'noc_engineer',$_p]); } catch (\Throwable $e) {}
        }
        dbUpsertConfig('schema_v3_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v3 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v2: ticket_scope · hub_city_mappings · hubs.team_id ───────────────
// Runs on both MySQL (cPanel) and PostgreSQL (Replit) — safe to re-run (all ops
// are guarded by IF NOT EXISTS / try-catch on duplicate-column errors).
$_k = dbKey();
$_sv2 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v2_migrated'");
if (!$_sv2) {
    try {
        // 1. ticket_scope column on tickets
        if (DB_TYPE === 'pgsql') {
            try { db()->exec("ALTER TABLE tickets ADD COLUMN ticket_scope VARCHAR(20) NOT NULL DEFAULT 'customer'"); } catch (\Throwable $e) {}
        } else {
            try { db()->exec("ALTER TABLE `tickets` ADD COLUMN `ticket_scope` VARCHAR(20) NOT NULL DEFAULT 'customer'"); } catch (\Throwable $e) {}
        }

        // 2. hub_city_mappings table — must use utf8mb4_general_ci to match all
        //    other tables; mismatched collations cause error 1267 on JOINs
        if (DB_TYPE === 'pgsql') {
            db()->exec("CREATE TABLE IF NOT EXISTS hub_city_mappings (
                id          VARCHAR(36)  PRIMARY KEY,
                hub_id      VARCHAR(36)  NOT NULL,
                city_name   VARCHAR(255) NOT NULL,
                created_at  TIMESTAMP    DEFAULT NOW(),
                UNIQUE (hub_id, city_name)
            )");
        } else {
            db()->exec("CREATE TABLE IF NOT EXISTS `hub_city_mappings` (
                `id`         VARCHAR(36)  NOT NULL,
                `hub_id`     VARCHAR(36)  NOT NULL,
                `city_name`  VARCHAR(255) NOT NULL,
                `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_hub_city` (`hub_id`, `city_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            // Fix existing tables that were mistakenly created with utf8mb4_unicode_ci
            try { db()->exec("ALTER TABLE `hub_city_mappings` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"); } catch (\Throwable $e) {}
        }

        // 3. team_id column on hubs
        if (DB_TYPE === 'pgsql') {
            try { db()->exec("ALTER TABLE hubs ADD COLUMN team_id VARCHAR(36) DEFAULT NULL"); } catch (\Throwable $e) {}
        } else {
            try { db()->exec("ALTER TABLE `hubs` ADD COLUMN `team_id` VARCHAR(36) DEFAULT NULL"); } catch (\Throwable $e) {}
        }

        // 4. sla_warned_at on tickets (MySQL — pgsql schema already has it)
        if (DB_TYPE === 'pgsql') {
            try { db()->exec("ALTER TABLE tickets ADD COLUMN sla_warned_at TIMESTAMP DEFAULT NULL"); } catch (\Throwable $e) {}
        }

        dbUpsertConfig('schema_v2_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v2 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v4: escalated_at on tickets · reports.view permission ─────────────
$_k = dbKey();
$_sv4 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v4_migrated'");
if (!$_sv4) {
    try {
        // 1. escalated_at column on tickets — marks when a ticket was first
        //    escalated (used to measure "escalation → resolution" time in reports)
        if (DB_TYPE === 'pgsql') {
            try { db()->exec("ALTER TABLE tickets ADD COLUMN escalated_at TIMESTAMP DEFAULT NULL"); } catch (\Throwable $e) {}
        } else {
            try { db()->exec("ALTER TABLE `tickets` ADD COLUMN `escalated_at` DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
        }

        // 2. reports.view permission — grant to management/supervisor roles that
        //    already have analytics.view (mirrors the existing analytics grant)
        foreach (['project_admin','supervisor-fiber','supervisor-noc','cx_supervisor'] as $_rr) {
            try { dbInsertIgnore("INSERT INTO role_permissions (id,role,permission) VALUES (?,?,'reports.view')", [newUuid(),$_rr]); } catch (\Throwable $e) {}
        }

        dbUpsertConfig('schema_v4_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v4 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v5: installation SLA tracking + vendor reassignment history ───────
$_k = dbKey();
$_sv5 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v5_migrated'");
if (!$_sv5) {
    try {
        // 1. installation_profiles: payment_confirmed_at (SLA clock start),
        //    sla_due_at (payment_confirmed_at + 7 working days), completed_at
        $_invCols = [
            'payment_confirmed_at' => 'TIMESTAMP DEFAULT NULL',
            'sla_due_at'           => 'TIMESTAMP DEFAULT NULL',
            'completed_at'         => 'TIMESTAMP DEFAULT NULL',
        ];
        foreach ($_invCols as $_col => $_type) {
            if (DB_TYPE === 'pgsql') {
                try { db()->exec("ALTER TABLE installation_profiles ADD COLUMN {$_col} {$_type}"); } catch (\Throwable $e) {}
            } else {
                $_myType = str_replace('TIMESTAMP', 'DATETIME', $_type);
                try { db()->exec("ALTER TABLE `installation_profiles` ADD COLUMN `{$_col}` {$_myType}"); } catch (\Throwable $e) {}
            }
        }

        // 2. installation_vendor_history — audit trail for vendor reassignment
        if (DB_TYPE === 'pgsql') {
            db()->exec("CREATE TABLE IF NOT EXISTS installation_vendor_history (
                id               VARCHAR(36) PRIMARY KEY,
                profile_id       VARCHAR(36) NOT NULL,
                old_vendor_id    VARCHAR(36) DEFAULT NULL,
                new_vendor_id    VARCHAR(36) DEFAULT NULL,
                reason           TEXT,
                changed_by       VARCHAR(36) DEFAULT NULL,
                changed_by_name  TEXT,
                created_at       TIMESTAMP DEFAULT NOW()
            )");
        } else {
            db()->exec("CREATE TABLE IF NOT EXISTS `installation_vendor_history` (
                `id`              VARCHAR(36) NOT NULL,
                `profile_id`      VARCHAR(36) NOT NULL,
                `old_vendor_id`   VARCHAR(36) DEFAULT NULL,
                `new_vendor_id`   VARCHAR(36) DEFAULT NULL,
                `reason`          TEXT,
                `changed_by`      VARCHAR(36) DEFAULT NULL,
                `changed_by_name` TEXT,
                `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_ivh_profile` (`profile_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // 3. Backfill sla_due_at for any existing profiles that already have a
        //    payment_confirmed_at (none should yet, but safe to run once)
        foreach (dbFetchAll("SELECT id, payment_confirmed_at FROM installation_profiles WHERE payment_confirmed_at IS NOT NULL AND sla_due_at IS NULL") as $_p) {
            $_due = addWorkingDays($_p['payment_confirmed_at'], INSTALLATION_SLA_WORKING_DAYS);
            dbRun("UPDATE installation_profiles SET sla_due_at = ? WHERE id = ?", [$_due, $_p['id']]);
        }

        dbUpsertConfig('schema_v5_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v5 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v6: expanded installation profile fields ──────────────────────────
// Fields requested for the Installation Module (billing/ops detail captured
// alongside the SLA fields already added in schema v5).
$_k = dbKey();
$_sv6 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v6_migrated'");
if (!$_sv6) {
    try {
        $_invCols2 = [
            'amount_paid'        => 'DECIMAL(12,2) DEFAULT NULL',
            'network_user_id'    => 'VARCHAR(100) DEFAULT NULL',
            'router_type'        => 'VARCHAR(100) DEFAULT NULL',
            'estate'             => 'VARCHAR(255) DEFAULT NULL',
            'pop'                => 'VARCHAR(100) DEFAULT NULL',
            'connection_status'  => "VARCHAR(30) DEFAULT NULL",
            'connection_date'    => 'DATE DEFAULT NULL',
            'installer'          => 'VARCHAR(150) DEFAULT NULL',
            'installation_cost'  => 'DECIMAL(12,2) DEFAULT NULL',
            'field_marketer'     => 'VARCHAR(150) DEFAULT NULL',
        ];
        foreach ($_invCols2 as $_col => $_type) {
            if (DB_TYPE === 'pgsql') {
                try { db()->exec("ALTER TABLE installation_profiles ADD COLUMN {$_col} {$_type}"); } catch (\Throwable $e) {}
            } else {
                try { db()->exec("ALTER TABLE `installation_profiles` ADD COLUMN `{$_col}` {$_type}"); } catch (\Throwable $e) {}
            }
        }
        dbUpsertConfig('schema_v6_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v6 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v7: split tickets.close into tickets.resolve + tickets.close ──────
// tickets.close used to gate both "mark resolved" and "mark closed". Any role
// that already had tickets.close keeps working exactly as before by also
// getting tickets.resolve here — admins can then separate them per-role from
// Admin → Permissions.
$_k = dbKey();
$_sv7 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v7_migrated'");
if (!$_sv7) {
    try {
        $_closeRoles = dbFetchAll("SELECT DISTINCT role FROM role_permissions WHERE permission = 'tickets.close'");
        foreach ($_closeRoles as $_cr) {
            try { dbInsertIgnore("INSERT INTO role_permissions (id,role,permission) VALUES (?,?,'tickets.resolve')", [newUuid(),$_cr['role']]); } catch (\Throwable $e) {}
        }
        dbUpsertConfig('schema_v7_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v7 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v8: login brute-force lockout columns ──────────────────────────────
$_k = dbKey();
$_sv8 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v8_migrated'");
if (!$_sv8) {
    try {
        if (DB_TYPE === 'pgsql') {
            try { db()->exec("ALTER TABLE users ADD COLUMN failed_login_attempts INT NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE users ADD COLUMN locked_until TIMESTAMP DEFAULT NULL"); } catch (\Throwable $e) {}
        } else {
            try { db()->exec("ALTER TABLE `users` ADD COLUMN `failed_login_attempts` INT NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE `users` ADD COLUMN `locked_until` DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
        }
        dbUpsertConfig('schema_v8_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v8 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v9: signup-app import tracking ─────────────────────────────────────
$_k = dbKey();
$_sv9 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v9_migrated'");
if (!$_sv9) {
    try {
        if (DB_TYPE === 'pgsql') {
            try { db()->exec("ALTER TABLE installation_profiles ADD COLUMN signup_submission_id VARCHAR(36) DEFAULT NULL"); } catch (\Throwable $e) {}
            try { db()->exec("CREATE UNIQUE INDEX idx_install_signup_submission ON installation_profiles (signup_submission_id)"); } catch (\Throwable $e) {}
        } else {
            try { db()->exec("ALTER TABLE `installation_profiles` ADD COLUMN `signup_submission_id` VARCHAR(36) DEFAULT NULL"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE `installation_profiles` ADD UNIQUE KEY `idx_install_signup_submission` (`signup_submission_id`)"); } catch (\Throwable $e) {}
        }
        dbUpsertConfig('schema_v9_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v9 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v10: installation SLA email tracking (avoids duplicate warn/breach emails) ─
$_k = dbKey();
$_sv10 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v10_migrated'");
if (!$_sv10) {
    try {
        if (DB_TYPE === 'pgsql') {
            try { db()->exec("ALTER TABLE installation_profiles ADD COLUMN sla_warned_at TIMESTAMP DEFAULT NULL"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE installation_profiles ADD COLUMN sla_breached_notified_at TIMESTAMP DEFAULT NULL"); } catch (\Throwable $e) {}
        } else {
            try { db()->exec("ALTER TABLE `installation_profiles` ADD COLUMN `sla_warned_at` DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE `installation_profiles` ADD COLUMN `sla_breached_notified_at` DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
        }
        dbUpsertConfig('schema_v10_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v10 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v11: installation module rework ────────────────────────────────────
// New fields (on-hold/refund reasons, payment status, hub link) + a one-time
// data migration of existing stage values into the new stage list (Pending,
// In Progress, On Hold (Customer), On Hold (Deployment), Cable Laying,
// Termination Pending, Configured, Connected, Refunded). Only 'completed' has
// no direct match in the new list — it becomes 'connected', the new terminal
// stage. in_progress/configured keep their existing meaning unchanged.
// completed_at is left untouched — it already captured the moment each of
// those records was first marked done, which is still correct after the rename.
$_k = dbKey();
$_sv11 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v11_migrated'");
if (!$_sv11) {
    try {
        if (DB_TYPE === 'pgsql') {
            try { db()->exec("ALTER TABLE installation_profiles ADD COLUMN on_hold_reason TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE installation_profiles ADD COLUMN refund_reason TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE installation_profiles ADD COLUMN payment_status VARCHAR(20) DEFAULT NULL"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE installation_profiles ADD COLUMN hub_id VARCHAR(36) DEFAULT NULL"); } catch (\Throwable $e) {}
        } else {
            try { db()->exec("ALTER TABLE `installation_profiles` ADD COLUMN `on_hold_reason` TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE `installation_profiles` ADD COLUMN `refund_reason` TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE `installation_profiles` ADD COLUMN `payment_status` VARCHAR(20) DEFAULT NULL"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE `installation_profiles` ADD COLUMN `hub_id` VARCHAR(36) DEFAULT NULL"); } catch (\Throwable $e) {}
        }
        dbRun("UPDATE installation_profiles SET status = 'connected' WHERE status = 'completed'");
        dbUpsertConfig('schema_v11_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v11 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v12: installation_paid + refunded_at + shared locations list ──────
// installation_paid: separate Yes/No flag from payment_status (which is a
// finer-grained Paid/Partial/Unpaid). refunded_at: same role as completed_at but
// for the Refunded stage — captured once so the SLA clock stops there too.
// locations: a shared canonical location list (admin-managed) so Customers,
// Tickets, and Installations all offer the same set of location names.
$_k = dbKey();
$_sv12 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v12_migrated'");
if (!$_sv12) {
    try {
        if (DB_TYPE === 'pgsql') {
            try { db()->exec("ALTER TABLE installation_profiles ADD COLUMN installation_paid VARCHAR(10) DEFAULT NULL"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE installation_profiles ADD COLUMN refunded_at TIMESTAMP DEFAULT NULL"); } catch (\Throwable $e) {}
            db()->exec("CREATE TABLE IF NOT EXISTS locations (
                id   VARCHAR(36) PRIMARY KEY,
                name VARCHAR(150) NOT NULL UNIQUE
            )");
        } else {
            try { db()->exec("ALTER TABLE `installation_profiles` ADD COLUMN `installation_paid` VARCHAR(10) DEFAULT NULL"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE `installation_profiles` ADD COLUMN `refunded_at` DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
            db()->exec("CREATE TABLE IF NOT EXISTS `locations` (
                `id`   VARCHAR(36)  PRIMARY KEY,
                `name` VARCHAR(150) NOT NULL UNIQUE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        }
        // Seed from whatever location names are already in use, so the list isn't empty on day one.
        $_seedCities = dbFetchAll("SELECT DISTINCT TRIM(city_name) AS n FROM hub_city_mappings WHERE TRIM(city_name) <> ''");
        foreach ($_seedCities as $_c) {
            try { dbRun("INSERT INTO locations (id,name) VALUES (?,?)", [newUuid(), $_c['n']]); } catch (\Throwable $e) {}
        }
        dbUpsertConfig('schema_v12_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v12 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v13: remove the 'configured' stage ─────────────────────────────────
// 'Configured' sat between Termination Pending and Connected; it's been dropped
// from the stage list, so existing records in it move to 'connected' — setup was
// already effectively done at that point.
$_k = dbKey();
$_sv13 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v13_migrated'");
if (!$_sv13) {
    try {
        dbRun("UPDATE installation_profiles SET status = 'connected', completed_at = COALESCE(completed_at, NOW()) WHERE status = 'configured'");
        dbUpsertConfig('schema_v13_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v13 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v14: Payment Requests module ───────────────────────────────────────
// Vendors submit requests for completed job payment; staff submit for expenses.
// Optionally linked to an installation job or a ticket. Pending -> Approved ->
// Paid, or Rejected (with a reason). Also backfills payment_requests.create
// onto every non-viewer role — role_permissions was already seeded on existing
// installs, so editing $_defaults above only affects fresh installs.
$_k = dbKey();
$_sv14 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v14_migrated'");
if (!$_sv14) {
    try {
        if (DB_TYPE === 'pgsql') {
            db()->exec("CREATE TABLE IF NOT EXISTS payment_requests (
                id                VARCHAR(36) PRIMARY KEY,
                requester_id      VARCHAR(36) NOT NULL,
                requester_name    VARCHAR(150),
                vendor_id         VARCHAR(36) DEFAULT NULL,
                linked_type       VARCHAR(20) DEFAULT NULL,
                linked_id         VARCHAR(36) DEFAULT NULL,
                amount            DECIMAL(12,2) NOT NULL,
                description       TEXT,
                status            VARCHAR(20) NOT NULL DEFAULT 'pending',
                reviewed_by       VARCHAR(36) DEFAULT NULL,
                reviewed_by_name  VARCHAR(150),
                reviewed_at       TIMESTAMP DEFAULT NULL,
                review_notes      TEXT,
                paid_at           TIMESTAMP DEFAULT NULL,
                payment_reference VARCHAR(150) DEFAULT NULL,
                created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            db()->exec("CREATE TABLE IF NOT EXISTS `payment_requests` (
                `id`                VARCHAR(36) NOT NULL,
                `requester_id`      VARCHAR(36) NOT NULL,
                `requester_name`    VARCHAR(150) DEFAULT NULL,
                `vendor_id`         VARCHAR(36) DEFAULT NULL,
                `linked_type`       VARCHAR(20) DEFAULT NULL,
                `linked_id`         VARCHAR(36) DEFAULT NULL,
                `amount`            DECIMAL(12,2) NOT NULL,
                `description`       TEXT,
                `status`            VARCHAR(20) NOT NULL DEFAULT 'pending',
                `reviewed_by`       VARCHAR(36) DEFAULT NULL,
                `reviewed_by_name`  VARCHAR(150) DEFAULT NULL,
                `reviewed_at`       DATETIME DEFAULT NULL,
                `review_notes`      TEXT,
                `paid_at`           DATETIME DEFAULT NULL,
                `payment_reference` VARCHAR(150) DEFAULT NULL,
                `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at`        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        }
        $_prRoles = ['admin','project_admin','supervisor-fiber','supervisor-noc','cx_supervisor','cx','engineer','noc_engineer','vendor'];
        foreach ($_prRoles as $_pr) {
            try { dbInsertIgnore("INSERT INTO role_permissions (id,role,permission) VALUES (?,?,'payment_requests.create')", [newUuid(),$_pr]); } catch (\Throwable $e) {}
        }
        foreach (['admin','project_admin'] as $_pr) {
            try { dbInsertIgnore("INSERT INTO role_permissions (id,role,permission) VALUES (?,?,'payment_requests.view')", [newUuid(),$_pr]); } catch (\Throwable $e) {}
            try { dbInsertIgnore("INSERT INTO role_permissions (id,role,permission) VALUES (?,?,'payment_requests.approve')", [newUuid(),$_pr]); } catch (\Throwable $e) {}
        }
        dbUpsertConfig('schema_v14_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v14 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v15: Payment Request backing documents ─────────────────────────────
// Up to 5 optional attachments per request (pdf/jpg/png), served only through
// api/payment-request-document.php — never linked as a direct static path.
$_k = dbKey();
$_sv15 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v15_migrated'");
if (!$_sv15) {
    try {
        if (DB_TYPE === 'pgsql') {
            db()->exec("CREATE TABLE IF NOT EXISTS payment_request_documents (
                id                  VARCHAR(36) PRIMARY KEY,
                payment_request_id  VARCHAR(36) NOT NULL,
                original_name       VARCHAR(255),
                stored_name         VARCHAR(255) NOT NULL,
                mime_type           VARCHAR(100),
                size_bytes          INT,
                uploaded_by         VARCHAR(36),
                created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            db()->exec("CREATE TABLE IF NOT EXISTS `payment_request_documents` (
                `id`                  VARCHAR(36) NOT NULL,
                `payment_request_id`  VARCHAR(36) NOT NULL,
                `original_name`       VARCHAR(255) DEFAULT NULL,
                `stored_name`         VARCHAR(255) NOT NULL,
                `mime_type`           VARCHAR(100) DEFAULT NULL,
                `size_bytes`          INT DEFAULT NULL,
                `uploaded_by`         VARCHAR(36) DEFAULT NULL,
                `created_at`          DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_prd_request` (`payment_request_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        }
        if (!is_dir(PR_DOC_DIR)) @mkdir(PR_DOC_DIR, 0755, true);
        // Deny direct web access to the storage folder — files are only ever
        // served through the authenticated download endpoint.
        @file_put_contents(PR_DOC_DIR . '.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents(PR_DOC_DIR . 'index.php', "<?php http_response_code(403); exit;\n");
        dbUpsertConfig('schema_v15_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v15 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v16: Finance department roles ──────────────────────────────────────
// Accountant = full financial oversight (Payment Requests + installation
// financials + customer billing + reports). Accounts Receivable = money coming
// in (installation payments, customer billing). Accounts Payable = money going
// out (Payment Request approvals). Mirrors the existing cx_supervisor/cx split.
$_k = dbKey();
$_sv16 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v16_migrated'");
if (!$_sv16) {
    try {
        $_financeRoles = [
            ['accountant',           'Accountant'],
            ['accounts_receivable',  'Accounts Receivable'],
            ['accounts_payable',     'Accounts Payable'],
        ];
        foreach ($_financeRoles as [$_rn, $_rl]) {
            dbInsertIgnore("INSERT INTO roles (name,label,department,is_system) VALUES (?,?,?,1)", [$_rn, $_rl, 'finance']);
        }

        $_financePerms = [
            'accountant' => ['payment_requests.create','payment_requests.view','payment_requests.approve',
                              'installations.view','installations.financial','customers.view',
                              'reports.view','analytics.view','finance.view'],
            'accounts_receivable' => ['installations.view','installations.financial','customers.view',
                                       'reports.view','analytics.view','finance.view'],
            'accounts_payable' => ['payment_requests.create','payment_requests.view','payment_requests.approve',
                                    'reports.view','analytics.view','finance.view'],
        ];
        foreach ($_financePerms as $_r => $_perms) {
            foreach ($_perms as $_p) {
                try { dbInsertIgnore("INSERT INTO role_permissions (id,role,permission) VALUES (?,?,?)", [newUuid(),$_r,$_p]); } catch (\Throwable $e) {}
            }
        }
        dbUpsertConfig('schema_v16_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v16 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v17: Payment Request Voucher fields + line items ──────────────────
// Expands Payment Requests to match the company's paper "Request Voucher" —
// job/customer context, a line-item cost breakdown, and a 3-stage sign-off
// (Authorized -> Approved -> Disbursed) instead of the old 2-stage one.
$_k = dbKey();
$_sv17 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v17_migrated'");
if (!$_sv17) {
    try {
        $_prCols = [
            'date_of_request'    => 'DATE DEFAULT NULL',
            'department'         => 'VARCHAR(100) DEFAULT NULL',
            'customer_name'      => 'VARCHAR(150) DEFAULT NULL',
            'customer_user_id'   => 'VARCHAR(100) DEFAULT NULL',
            'location'           => 'VARCHAR(150) DEFAULT NULL',
            'hub_id'             => 'VARCHAR(36) DEFAULT NULL',
            'category'           => 'VARCHAR(50) DEFAULT NULL',
            'category_other'     => 'VARCHAR(150) DEFAULT NULL',
            'capex_opex'         => 'VARCHAR(10) DEFAULT NULL',
            'receiver'           => 'VARCHAR(150) DEFAULT NULL',
            'priority'           => 'VARCHAR(10) DEFAULT NULL',
            'authorized_by'      => 'VARCHAR(36) DEFAULT NULL',
            'authorized_by_name' => 'VARCHAR(150) DEFAULT NULL',
            'disbursed_by'       => 'VARCHAR(36) DEFAULT NULL',
            'disbursed_by_name'  => 'VARCHAR(150) DEFAULT NULL',
        ];
        foreach ($_prCols as $_col => $_def) {
            try {
                if (DB_TYPE === 'pgsql') { db()->exec("ALTER TABLE payment_requests ADD COLUMN {$_col} {$_def}"); }
                else { db()->exec("ALTER TABLE `payment_requests` ADD COLUMN `{$_col}` {$_def}"); }
            } catch (\Throwable $e) {}
        }
        try {
            if (DB_TYPE === 'pgsql') { db()->exec("ALTER TABLE payment_requests ADD COLUMN authorized_at TIMESTAMP DEFAULT NULL"); }
            else { db()->exec("ALTER TABLE `payment_requests` ADD COLUMN `authorized_at` DATETIME DEFAULT NULL"); }
        } catch (\Throwable $e) {}

        if (DB_TYPE === 'pgsql') {
            db()->exec("CREATE TABLE IF NOT EXISTS payment_request_items (
                id                  VARCHAR(36) PRIMARY KEY,
                payment_request_id  VARCHAR(36) NOT NULL,
                description         TEXT,
                qty                 DECIMAL(10,2) DEFAULT 1,
                unit_price          DECIMAL(12,2) DEFAULT 0,
                line_total          DECIMAL(12,2) DEFAULT 0,
                sort_order          INT DEFAULT 0
            )");
        } else {
            db()->exec("CREATE TABLE IF NOT EXISTS `payment_request_items` (
                `id`                  VARCHAR(36) NOT NULL,
                `payment_request_id`  VARCHAR(36) NOT NULL,
                `description`         TEXT,
                `qty`                 DECIMAL(10,2) DEFAULT 1,
                `unit_price`          DECIMAL(12,2) DEFAULT 0,
                `line_total`          DECIMAL(12,2) DEFAULT 0,
                `sort_order`          INT DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_pri_request` (`payment_request_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        }

        // Old 2-stage flow used 'paid' as the terminal state — carry those
        // records forward as 'disbursed' under the new 3-stage flow so
        // nothing already paid looks unpaid.
        dbRun("UPDATE payment_requests SET status = 'disbursed' WHERE status = 'paid'");

        dbUpsertConfig('schema_v17_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v17 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v18: re-assert Finance role permission grants ─────────────────────
// schema_v16 created the 'roles' rows for accountant/accounts_receivable/
// accounts_payable successfully, but the role_permissions grants for those
// roles came up empty on at least one live install — schema_v16 still marked
// itself complete (each grant insert is individually try/caught, so a run of
// failures there doesn't stop the migrated-flag from being set). This is a
// standalone re-run of just the grants, gated on its own flag, so it applies
// regardless of what schema_v16 believes already happened. Logs failures per
// grant this time instead of swallowing them silently.
$_k = dbKey();
$_sv18 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v18_migrated'");
if (!$_sv18) {
    try {
        $_financePerms = [
            'accountant' => ['payment_requests.create','payment_requests.view','payment_requests.approve',
                              'installations.view','installations.financial','customers.view',
                              'reports.view','analytics.view','finance.view'],
            'accounts_receivable' => ['installations.view','installations.financial','customers.view',
                                       'reports.view','analytics.view','finance.view'],
            'accounts_payable' => ['payment_requests.create','payment_requests.view','payment_requests.approve',
                                    'reports.view','analytics.view','finance.view'],
        ];
        foreach ($_financePerms as $_r => $_perms) {
            foreach ($_perms as $_p) {
                try {
                    dbInsertIgnore("INSERT INTO role_permissions (id,role,permission) VALUES (?,?,?)", [newUuid(),$_r,$_p]);
                } catch (\Throwable $e) {
                    error_log("Schema v18: failed granting {$_p} to {$_r}: " . $e->getMessage());
                }
            }
        }
        dbUpsertConfig('schema_v18_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v18 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v19: Payment Request 4-stage workflow (Authorize→Approve→Finance Check) ──
// Splits the old single "Approve" stage (done entirely by Finance) into three
// distinct stages: Authorize (Line Manager / Supervisor), Approve (COO /
// senior management), Finance Check (Accountant / Accounts Payable — either
// disburses or returns to the requester for edits). Adds a new non-terminal
// 'returned' status. reviewed_by/_name/_at/review_notes remain in use for the
// Reject action only; approved_by/_name/_at and returned_by/_name/_at/return_notes
// are new, distinct columns so Approve and Finance's Return don't collide with
// the existing Reject fields.
$_k = dbKey();
$_sv19 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v19_migrated'");
if (!$_sv19) {
    try {
        if (DB_TYPE === 'mysql') {
            $_v19Cols = [
                "ALTER TABLE `payment_requests` ADD COLUMN `approved_by` VARCHAR(64) DEFAULT NULL",
                "ALTER TABLE `payment_requests` ADD COLUMN `approved_by_name` VARCHAR(191) DEFAULT NULL",
                "ALTER TABLE `payment_requests` ADD COLUMN `approved_at` DATETIME DEFAULT NULL",
                "ALTER TABLE `payment_requests` ADD COLUMN `returned_by` VARCHAR(64) DEFAULT NULL",
                "ALTER TABLE `payment_requests` ADD COLUMN `returned_by_name` VARCHAR(191) DEFAULT NULL",
                "ALTER TABLE `payment_requests` ADD COLUMN `returned_at` DATETIME DEFAULT NULL",
                "ALTER TABLE `payment_requests` ADD COLUMN `return_notes` TEXT DEFAULT NULL",
            ];
        } else {
            $_v19Cols = [
                "ALTER TABLE payment_requests ADD COLUMN IF NOT EXISTS approved_by VARCHAR(64)",
                "ALTER TABLE payment_requests ADD COLUMN IF NOT EXISTS approved_by_name VARCHAR(191)",
                "ALTER TABLE payment_requests ADD COLUMN IF NOT EXISTS approved_at TIMESTAMP",
                "ALTER TABLE payment_requests ADD COLUMN IF NOT EXISTS returned_by VARCHAR(64)",
                "ALTER TABLE payment_requests ADD COLUMN IF NOT EXISTS returned_by_name VARCHAR(191)",
                "ALTER TABLE payment_requests ADD COLUMN IF NOT EXISTS returned_at TIMESTAMP",
                "ALTER TABLE payment_requests ADD COLUMN IF NOT EXISTS return_notes TEXT",
            ];
        }
        foreach ($_v19Cols as $_sql) {
            try { db()->exec($_sql); } catch (\Throwable $e) {}
        }

        // Revoke the stale finance-wide "approve" permission from Finance roles —
        // they now get "finance_check" instead. Approve belongs to COO/Manager tier.
        try {
            db()->exec("DELETE FROM role_permissions WHERE role IN ('accountant','accounts_payable') AND permission = 'payment_requests.approve'");
        } catch (\Throwable $e) {
            error_log('Schema v19: failed revoking stale approve grant: ' . $e->getMessage());
        }

        $_v19Grants = [
            'supervisor-fiber' => ['payment_requests.authorize'],
            'supervisor-noc'   => ['payment_requests.authorize'],
            'cx_supervisor'    => ['payment_requests.authorize'],
            'project_admin'    => ['payment_requests.authorize','payment_requests.approve','payment_requests.finance_check'],
            'admin'            => ['payment_requests.authorize','payment_requests.approve','payment_requests.finance_check'],
            'accountant'          => ['payment_requests.finance_check'],
            'accounts_payable'    => ['payment_requests.finance_check'],
        ];
        foreach ($_v19Grants as $_r => $_perms) {
            foreach ($_perms as $_p) {
                try {
                    dbInsertIgnore("INSERT INTO role_permissions (id,role,permission) VALUES (?,?,?)", [newUuid(),$_r,$_p]);
                } catch (\Throwable $e) {
                    error_log("Schema v19: failed granting {$_p} to {$_r}: " . $e->getMessage());
                }
            }
        }

        dbUpsertConfig('schema_v19_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v19 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v20: COO / Manager role, and Authorize-stage amount override ──────
// Dedicated Approve-stage role — previously Project Admin/Admin filled this
// gap since no "COO" role existed in the system. Also adds original_amount so
// the Authorizer (Line Manager / Supervisor) can revise the requested amount
// at the Authorize stage without losing the figure the requester originally
// submitted (kept for audit and shown on the printed voucher).
$_k = dbKey();
$_sv20 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v20_migrated'");
if (!$_sv20) {
    try {
        dbInsertIgnore("INSERT INTO roles (name,label,department,is_system) VALUES (?,?,?,1)", ['coo_manager', 'COO / Manager', 'executive']);

        $_v20Grants = ['payment_requests.create','payment_requests.view','payment_requests.approve',
                       'installations.view','installations.financial','customers.view',
                       'reports.view','analytics.view','finance.view'];
        foreach ($_v20Grants as $_p) {
            try {
                dbInsertIgnore("INSERT INTO role_permissions (id,role,permission) VALUES (?,?,?)", [newUuid(),'coo_manager',$_p]);
            } catch (\Throwable $e) {
                error_log("Schema v20: failed granting {$_p} to coo_manager: " . $e->getMessage());
            }
        }

        if (DB_TYPE === 'mysql') {
            try { db()->exec("ALTER TABLE `payment_requests` ADD COLUMN `original_amount` DECIMAL(14,2) DEFAULT NULL"); } catch (\Throwable $e) {}
        } else {
            try { db()->exec("ALTER TABLE payment_requests ADD COLUMN IF NOT EXISTS original_amount NUMERIC(14,2)"); } catch (\Throwable $e) {}
        }

        dbUpsertConfig('schema_v20_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v20 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v21: Partial disbursements (bill-style payment tracking) ──────────
// Finance can now record one or more payments against an approved request —
// like a Zoho Books bill — instead of a single all-or-nothing "Disburse".
// amount_paid is the running total; balance = amount - amount_paid. A new
// 'partially_disbursed' status covers the in-between state; 'disbursed' means
// amount_paid has reached the full amount. Existing 'disbursed' records are
// backfilled with amount_paid = amount and a single historical payment row
// built from their old paid_at/payment_reference/disbursed_by fields, so no
// payment history is lost.
$_k = dbKey();
$_sv21 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v21_migrated'");
if (!$_sv21) {
    try {
        if (DB_TYPE === 'mysql') {
            db()->exec("CREATE TABLE IF NOT EXISTS `payment_request_payments` (
                `id`                  VARCHAR(36) NOT NULL,
                `payment_request_id`  VARCHAR(36) NOT NULL,
                `amount`              DECIMAL(14,2) NOT NULL,
                `payment_reference`   VARCHAR(191) DEFAULT NULL,
                `note`                VARCHAR(255) DEFAULT NULL,
                `paid_by`             VARCHAR(36) DEFAULT NULL,
                `paid_by_name`        VARCHAR(191) DEFAULT NULL,
                `paid_at`             DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_prp_request` (`payment_request_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            try { db()->exec("ALTER TABLE `payment_requests` ADD COLUMN `amount_paid` DECIMAL(14,2) NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
        } else {
            db()->exec("CREATE TABLE IF NOT EXISTS payment_request_payments (
                id                  VARCHAR(36) PRIMARY KEY,
                payment_request_id  VARCHAR(36) NOT NULL,
                amount              NUMERIC(14,2) NOT NULL,
                payment_reference   VARCHAR(191),
                note                VARCHAR(255),
                paid_by             VARCHAR(36),
                paid_by_name        VARCHAR(191),
                paid_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            try { db()->exec("ALTER TABLE payment_requests ADD COLUMN IF NOT EXISTS amount_paid NUMERIC(14,2) NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
        }

        // Backfill: every already-disbursed request is fully paid.
        foreach (dbFetchAll("SELECT id, amount, paid_at, payment_reference, disbursed_by, disbursed_by_name FROM payment_requests WHERE status='disbursed'") as $_pr) {
            try {
                dbRun("UPDATE payment_requests SET amount_paid=? WHERE id=?", [$_pr['amount'], $_pr['id']]);
                dbInsertIgnore(
                    "INSERT INTO payment_request_payments (id,payment_request_id,amount,payment_reference,paid_by,paid_by_name,paid_at) VALUES (?,?,?,?,?,?,?)",
                    [newUuid(), $_pr['id'], $_pr['amount'], $_pr['payment_reference'], $_pr['disbursed_by'], $_pr['disbursed_by_name'], $_pr['paid_at'] ?: date('Y-m-d H:i:s')]
                );
            } catch (\Throwable $e) {
                error_log('Schema v21: failed backfilling payment history for ' . $_pr['id'] . ': ' . $e->getMessage());
            }
        }

        dbUpsertConfig('schema_v21_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v21 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v22: Cable Laid date ────────────────────────────────────────────
// The 'cable_laying' stage is relabeled "Cable Laid" (status value unchanged,
// existing records need no migration) and now requires a date — the day the
// cable was actually laid — the same way On Hold/Refunded require a reason.
$_k = dbKey();
$_sv22 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v22_migrated'");
if (!$_sv22) {
    try {
        if (DB_TYPE === 'mysql') {
            try { db()->exec("ALTER TABLE `installation_profiles` ADD COLUMN `cable_laid_date` DATE DEFAULT NULL"); } catch (\Throwable $e) {}
        } else {
            try { db()->exec("ALTER TABLE installation_profiles ADD COLUMN IF NOT EXISTS cable_laid_date DATE"); } catch (\Throwable $e) {}
        }
        dbUpsertConfig('schema_v22_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v22 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v23: installations.delete as its own toggleable permission ────────
// Deleting an installation record used to ride on installations.update/.create
// (the same "full edit" gate) — so any role granted edit access (including a
// vendor role an admin had opened up for stage/field updates) could also
// delete records outright, with no way to separate the two. This is a brand
// new permission key; only project_admin is explicitly granted it here since
// 'admin' bypasses permission checks entirely — every other role (vendor,
// supervisors, etc.) starts with none, and only gets it if explicitly toggled
// on in Admin → Roles & Permissions.
$_k = dbKey();
$_sv23 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v23_migrated'");
if (!$_sv23) {
    try {
        dbInsertIgnore("INSERT INTO role_permissions (id,role,permission) VALUES (?,?,?)", [newUuid(), 'project_admin', 'installations.delete']);
        dbUpsertConfig('schema_v23_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v23 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v24: Payment Request type (Customer / Deployment / Operational) ──
// Customer-tied requests must carry customer_name + customer_user_id (feeds
// the Customers module); Deployment requests aren't tied to a specific
// customer so those fields stay optional; Operational requests don't involve
// a customer at all. Existing records default to 'deployment' — the closest
// fit to the old behavior where customer fields were always optional.
$_k = dbKey();
$_sv24 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v24_migrated'");
if (!$_sv24) {
    try {
        if (DB_TYPE === 'mysql') {
            try { db()->exec("ALTER TABLE `payment_requests` ADD COLUMN `request_type` VARCHAR(20) DEFAULT NULL"); } catch (\Throwable $e) {}
        } else {
            try { db()->exec("ALTER TABLE payment_requests ADD COLUMN IF NOT EXISTS request_type VARCHAR(20)"); } catch (\Throwable $e) {}
        }
        try { dbRun("UPDATE payment_requests SET request_type='deployment' WHERE request_type IS NULL"); } catch (\Throwable $e) {}
        dbUpsertConfig('schema_v24_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v24 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v25: Payment Request type relabel + customer linking ──────────────
// Request Type is redefined from 3 values to 4: the old 'customer' (tied to a
// customer, required fields) becomes 'operational'; the old 'operational'
// (no customer at all) becomes 'admin'; 'deployment' is unchanged; a new
// 'expansion' type is added, splitting what used to be lumped under
// Category's "Deployment/Expansion". A single CASE UPDATE remaps existing
// rows using their pre-update value, so the 'customer'->'operational' and
// 'operational'->'admin' renames can't collide with each other.
// Also adds a proper many-to-many link from a Customer-type request to real
// records in the customers table (a request can now name more than one
// customer), replacing the old free-text customer_name/customer_user_id as
// the source of truth for that link — those columns remain for legacy data.
$_k = dbKey();
$_sv25 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v25_migrated'");
if (!$_sv25) {
    try {
        try {
            dbRun("UPDATE payment_requests SET request_type = CASE request_type
                    WHEN 'customer' THEN 'operational'
                    WHEN 'operational' THEN 'admin'
                    ELSE request_type END");
        } catch (\Throwable $e) {
            error_log('Schema v25: request_type relabel failed: ' . $e->getMessage());
        }

        if (DB_TYPE === 'mysql') {
            db()->exec("CREATE TABLE IF NOT EXISTS `payment_request_customers` (
                `id`                  VARCHAR(36) NOT NULL,
                `payment_request_id`  VARCHAR(36) NOT NULL,
                `customer_id`         VARCHAR(36) NOT NULL,
                `created_at`          DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_prc_request_customer` (`payment_request_id`,`customer_id`),
                KEY `idx_prc_request` (`payment_request_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } else {
            db()->exec("CREATE TABLE IF NOT EXISTS payment_request_customers (
                id                  VARCHAR(36) PRIMARY KEY,
                payment_request_id  VARCHAR(36) NOT NULL,
                customer_id         VARCHAR(36) NOT NULL,
                created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (payment_request_id, customer_id)
            )");
        }

        dbUpsertConfig('schema_v25_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v25 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v26: Integration API (keys, request log) ──────────────────────────
// Foundation for external systems to read/write FieldPulse data (customers,
// payment requests, and later others) via /api/v1/*, and for FieldPulse to
// push events out to them via webhooks. Auth is per-integration API keys
// (Authorization: Bearer <key>) rather than the session cookies used
// everywhere else — see includes/api-auth.php.
$_k = dbKey();
$_sv26 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v26_migrated'");
if (!$_sv26) {
    try {
        if (DB_TYPE === 'mysql') {
            db()->exec("CREATE TABLE IF NOT EXISTS `api_keys` (
                `id`                VARCHAR(36) NOT NULL,
                `name`              VARCHAR(191) NOT NULL,
                `key_prefix`        VARCHAR(16) NOT NULL,
                `key_hash`          VARCHAR(64) NOT NULL,
                `scopes`            TEXT NOT NULL,
                `webhook_url`       VARCHAR(500) DEFAULT NULL,
                `webhook_secret`    VARCHAR(64) DEFAULT NULL,
                `created_by`        VARCHAR(36) DEFAULT NULL,
                `created_by_name`   VARCHAR(191) DEFAULT NULL,
                `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
                `last_used_at`      DATETIME DEFAULT NULL,
                `revoked_at`        DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_key_hash` (`key_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            db()->exec("CREATE TABLE IF NOT EXISTS `api_request_log` (
                `id`            VARCHAR(36) NOT NULL,
                `api_key_id`    VARCHAR(36) NOT NULL,
                `method`        VARCHAR(10) NOT NULL,
                `path`          VARCHAR(255) NOT NULL,
                `status_code`   INT NOT NULL,
                `ip`            VARCHAR(64) DEFAULT NULL,
                `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_arl_key` (`api_key_id`),
                KEY `idx_arl_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } else {
            db()->exec("CREATE TABLE IF NOT EXISTS api_keys (
                id                VARCHAR(36) PRIMARY KEY,
                name              VARCHAR(191) NOT NULL,
                key_prefix        VARCHAR(16) NOT NULL,
                key_hash          VARCHAR(64) NOT NULL UNIQUE,
                scopes            TEXT NOT NULL,
                webhook_url       VARCHAR(500),
                webhook_secret    VARCHAR(64),
                created_by        VARCHAR(36),
                created_by_name   VARCHAR(191),
                created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_used_at      TIMESTAMP,
                revoked_at        TIMESTAMP
            )");
            db()->exec("CREATE TABLE IF NOT EXISTS api_request_log (
                id            VARCHAR(36) PRIMARY KEY,
                api_key_id    VARCHAR(36) NOT NULL,
                method        VARCHAR(10) NOT NULL,
                path          VARCHAR(255) NOT NULL,
                status_code   INT NOT NULL,
                ip            VARCHAR(64),
                created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        }
        dbUpsertConfig('schema_v26_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v26 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v27: Ticket photos (proof of service) ──────────────────────────────
// Optional photo evidence attached when a ticket is updated/resolved/closed —
// dispute resolution and install-quality QC, same reasoning as the payment
// request documents already in the app. Storage directory locked down the
// same way (deny-all .htaccess + a dummy index.php), served only through
// api/ticket-photo.php.
$_k = dbKey();
$_sv27 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v27_migrated'");
if (!$_sv27) {
    try {
        if (DB_TYPE === 'mysql') {
            db()->exec("CREATE TABLE IF NOT EXISTS `ticket_photos` (
                `id`                VARCHAR(36) NOT NULL,
                `ticket_id`         VARCHAR(36) NOT NULL,
                `original_name`     VARCHAR(255) DEFAULT NULL,
                `stored_name`       VARCHAR(255) NOT NULL,
                `mime_type`         VARCHAR(100) DEFAULT NULL,
                `size_bytes`        INT DEFAULT NULL,
                `uploaded_by`       VARCHAR(36) DEFAULT NULL,
                `uploaded_by_name`  VARCHAR(191) DEFAULT NULL,
                `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_tp_ticket` (`ticket_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } else {
            db()->exec("CREATE TABLE IF NOT EXISTS ticket_photos (
                id                VARCHAR(36) PRIMARY KEY,
                ticket_id         VARCHAR(36) NOT NULL,
                original_name     VARCHAR(255),
                stored_name       VARCHAR(255) NOT NULL,
                mime_type         VARCHAR(100),
                size_bytes        INT,
                uploaded_by       VARCHAR(36),
                uploaded_by_name  VARCHAR(191),
                created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        }
        if (!is_dir(TICKET_PHOTO_DIR)) @mkdir(TICKET_PHOTO_DIR, 0755, true);
        @file_put_contents(TICKET_PHOTO_DIR . '.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents(TICKET_PHOTO_DIR . 'index.php', "<?php http_response_code(403); exit;\n");
        dbUpsertConfig('schema_v27_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v27 migration error: ' . $e->getMessage());
    }
}

// ─── Integration API: scopes, key helpers, outbound webhooks ──────────────────
// Every scope an API key can be granted. Keep this in sync with the
// enforcement in each api/v1/*.php endpoint — a scope existing here doesn't
// grant anything by itself, each endpoint must explicitly requireApiScope() it.
define('API_SCOPES', [
    'customers.read'  => 'Read customer records',
    'customers.write' => 'Create / update customer records',
    'payments.read'   => 'Read payment requests',
    'payments.write'  => 'Record payments against approved payment requests',
]);

// Events FieldPulse can push to a key's webhook_url, if it has one configured.
define('WEBHOOK_EVENTS', ['customer.created', 'customer.updated', 'payment.disbursed']);

function generateApiKey(): array {
    $secret = bin2hex(random_bytes(24)); // 48 hex chars
    $full   = 'fp_live_' . $secret;
    return ['full' => $full, 'prefix' => substr($full, 0, 14), 'hash' => hash('sha256', $full)];
}

/**
 * Best-effort outbound webhook delivery: fires synchronously, logs failures,
 * never throws. No retry queue in this v1 — a down endpoint just misses the
 * event. Payload is signed with the key's webhook_secret (HMAC-SHA256) in the
 * X-FieldPulse-Signature header so receivers can verify authenticity.
 */
function fireWebhooks(string $event, array $payload): void {
    if (!in_array($event, WEBHOOK_EVENTS, true)) return;
    try {
        $keys = dbFetchAll("SELECT id, webhook_url, webhook_secret FROM api_keys WHERE revoked_at IS NULL AND webhook_url IS NOT NULL AND webhook_url != ''");
    } catch (\Throwable $e) { return; }
    if (!$keys) return;

    $body = json_encode(['event' => $event, 'data' => $payload, 'sent_at' => date('c')], JSON_UNESCAPED_SLASHES);
    foreach ($keys as $k) {
        try {
            $sig = hash_hmac('sha256', $body, $k['webhook_secret'] ?? '');
            $ch = curl_init($k['webhook_url']);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-FieldPulse-Event: ' . $event, 'X-FieldPulse-Signature: ' . $sig],
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_RETURNTRANSFER => true,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            error_log("Webhook delivery failed for key {$k['id']} ({$event}): " . $e->getMessage());
        }
    }
}

// ─── App version tracking ──────────────────────────────────────────────────────
// Unlike the schema_vN blocks above (each runs once, ever), this runs whenever
// the deployed APP_VERSION differs from what's recorded — i.e. once per release.
try {
    $_k = dbKey();
    $_recordedVersion = dbFetch("SELECT value FROM app_config WHERE $_k = 'app_version'");
    if (($_recordedVersion['value'] ?? null) !== APP_VERSION) {
        dbUpsertConfig('app_version', APP_VERSION);
        dbUpsertConfig('app_version_deployed_at', date('Y-m-d H:i:s'));
    }
} catch (\Throwable $e) {
    error_log('App version tracking error: ' . $e->getMessage());
}