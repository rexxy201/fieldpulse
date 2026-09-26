<?php
declare(strict_types=1);

// ─── Error handling hardening ──────────────────────────────────────────────
// Never let PHP's default handler print an exception/stack trace (which can
// include SQL text and file paths) to the browser — log it and show a
// generic message instead. Pinned explicitly here rather than relying on the
// hosting php.ini's display_errors default, which could silently regress on
// a PHP version bump or hosting migration.
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_exception_handler(function (\Throwable $e): void {
    error_log('Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);
    $isApi = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');
    if ($isApi) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Something went wrong. Please try again.']);
    } else {
        echo 'Something went wrong. Please try again, or contact support if this persists.';
    }
});

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
// ─── Shared Date Range filter (presets + bounds + filter-bar markup) ──────────
require_once __DIR__ . '/includes/date-range.php';

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

// Env-var overrides below are for the automated test suite only (see
// tests/bootstrap.php) — unset in production, so DB_HOST/NAME/USER/PASS
// resolve to the exact same values they always have on the live server.
// Deliberately `!== false` rather than `?:` — an intentionally empty value
// (e.g. a local test user with no password) must not be treated as unset.
// secrets.php may optionally override host/name/user too (not just the
// password) — this is what lets the same config.php serve staging: staging's
// own secrets.php (a separate file that lives only on that server, gitignored,
// never touched by deploy) points DB_NAME/DB_USER at a dedicated staging
// database instead of production's, with no branch-specific config.php diff.
define('DB_HOST', getenv('FIELDPULSE_DB_HOST') !== false ? getenv('FIELDPULSE_DB_HOST') : (defined('DB_HOST_FROM_SECRETS') ? DB_HOST_FROM_SECRETS : 'localhost'));
define('DB_NAME', getenv('FIELDPULSE_DB_NAME') !== false ? getenv('FIELDPULSE_DB_NAME') : (defined('DB_NAME_FROM_SECRETS') ? DB_NAME_FROM_SECRETS : 'mangonetcom_fieldpulse'));
define('DB_USER', getenv('FIELDPULSE_DB_USER') !== false ? getenv('FIELDPULSE_DB_USER') : (defined('DB_USER_FROM_SECRETS') ? DB_USER_FROM_SECRETS : 'mangonetcom_fieldpulse'));
define('DB_PASS', getenv('FIELDPULSE_DB_PASS') !== false ? getenv('FIELDPULSE_DB_PASS') : (defined('DB_PASS_FROM_SECRETS') ? DB_PASS_FROM_SECRETS : 'YOUR_DATABASE_PASSWORD'));

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
// db()'s `global $pdo;` only ever resolves against $GLOBALS — identical to
// this assignment when config.php is required from a page's true top level
// (always true in production), but explicit here so it also works when the
// PHPUnit test bootstrap includes this file from inside a method scope.
$GLOBALS['pdo'] = $pdo;

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
    // geolocation=(self), not (): GPS check-in (my-jobs, ticket-detail) calls
    // navigator.geolocation on our own origin. An empty allowlist disables it
    // everywhere, including for us, so every check-in failed.
    header('Permissions-Policy: geolocation=(self), microphone=(), camera=()');
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

/**
 * Random one-time password for a new member or an admin reset. Shown once to
 * the admin; the member must replace it at first sign-in (must_change_password).
 * Replaces the old fixed "admin123", which let anyone who knew a new member's
 * username sign in as them. Ambiguous characters (0/O, 1/l/I) are left out
 * so it can be read aloud or copied by hand.
 */
function generateTempPassword(int $length = 12): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $out;
}

// ─── Ticket field values ─────────────────────────────────────────────────────
// Every value the UI offers. Anything else is rejected on write: status and
// priority are rendered unescaped in several templates (badge classes etc.),
// so an arbitrary string here was a stored-XSS vector.
const TICKET_STATUSES   = ['new', 'open', 'assigned', 'in_progress', 'pending_confirmation', 'resolved', 'closed'];
const TICKET_PRIORITIES = ['p1', 'p2', 'p3', 'p4'];

/**
 * Validates status/priority in a ticket write payload. Keys that are absent
 * are fine; present ones must be a known value. Returns an error or null.
 */
function ticketFieldError(array $b): ?string {
    if (array_key_exists('status', $b) && !in_array($b['status'], TICKET_STATUSES, true)) {
        return 'Invalid status. Allowed: ' . implode(', ', TICKET_STATUSES);
    }
    if (array_key_exists('priority', $b) && !in_array($b['priority'], TICKET_PRIORITIES, true)) {
        return 'Invalid priority. Allowed: ' . implode(', ', TICKET_PRIORITIES);
    }
    return null;
}

/** How long an admin-issued temporary password can be used to sign in. */
define('TEMP_PASSWORD_HOURS', 72);

/**
 * Sets an admin-issued temporary password: the member must replace it at next
 * sign-in, and it stops working after TEMP_PASSWORD_HOURS so one that was
 * never passed on (or was intercepted) doesn't stay valid indefinitely.
 */
function setTemporaryPassword(string $userId, string $plain): void {
    dbRun("UPDATE users SET password = ?, must_change_password = 1, temp_password_expires_at = ? WHERE id = ?",
        [hashPassword($plain), date('Y-m-d H:i:s', time() + TEMP_PASSWORD_HOURS * 3600), $userId]);
}

/**
 * Self-service password change (My Account). Returns null on success or a
 * user-facing error message. Clears must_change_password on success.
 */
function changeOwnPassword(string $userId, string $current, string $new, string $confirm): ?string {
    $u = dbFetch("SELECT password FROM users WHERE id = ?", [$userId]);
    if (!$u || !verifyPassword($current, $u['password'] ?? '')) return 'Your current password is incorrect.';
    if (strlen($new) < 8) return 'Your new password must be at least 8 characters.';
    if ($new !== $confirm) return 'The new passwords do not match.';
    if ($new === $current) return 'Your new password must be different from the current one.';
    dbRun("UPDATE users SET password = ?, must_change_password = 0, temp_password_expires_at = NULL WHERE id = ?", [hashPassword($new), $userId]);
    return null;
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
define('LOGIN_IP_MAX_FAILURES', 30);

/**
 * Shared by pages/login.php and api/auth.php so both entry points get the same
 * lockout behavior. Returns ['ok'=>bool, 'user'=>array|null, 'error'=>?string].
 *
 * Failed sign-ins are throttled in two ways, both counting failures only
 * (successful sign-ins never count, so a whole office behind one IP is fine):
 *  - per username + IP: LOGIN_MAX_ATTEMPTS failures lock that username out
 *    from that IP only. The old lock was per account, from any IP, so anyone
 *    could keep e.g. "admin" locked out indefinitely by guessing wrong.
 *  - per IP: LOGIN_IP_MAX_FAILURES failures across any usernames block that
 *    IP, which stops password-spraying many accounts from one address.
 * Unknown usernames are counted the same way as real ones, so the lock
 * message doesn't reveal which usernames exist.
 */
function attemptLogin(string $username, string $password, ?string $ip = null): array {
    $ip      ??= clientIp();
    $window  = LOGIN_LOCKOUT_MINUTES;
    $pairKey = hash('sha256', strtolower(trim($username)) . '|' . $ip);

    $ipState = rateLimitState('login_fail_ip', $ip, $window);
    if ($ipState['count'] >= LOGIN_IP_MAX_FAILURES) {
        return ['ok' => false, 'user' => null, 'error' => 'Too many failed sign-in attempts from your network. Try again in ' . loginMinutesText($ipState['resetsIn']) . '.'];
    }
    $pairState = rateLimitState('login_fail_user_ip', $pairKey, $window);
    if ($pairState['count'] >= LOGIN_MAX_ATTEMPTS) {
        return ['ok' => false, 'user' => null, 'error' => 'Too many failed attempts. Try again in ' . loginMinutesText($pairState['resetsIn']) . '.'];
    }

    $user = dbFetch("SELECT * FROM users WHERE username = ?", [$username]);

    if ($user && verifyPassword($password, $user['password'])) {
        // Checked only after the password verifies, so it reveals nothing to
        // someone guessing. Not counted as a failed attempt.
        if (!isActiveUserRow($user)) {
            return ['ok' => false, 'user' => null, 'error' => 'This account has been deactivated. Contact an admin.'];
        }
        if (!empty($user['must_change_password']) && !empty($user['temp_password_expires_at'])
            && strtotime($user['temp_password_expires_at']) < time()) {
            return ['ok' => false, 'user' => null, 'error' => 'Your temporary password has expired. Ask an admin to reset it.'];
        }
        // Clears this username+IP's failures; the legacy per-account columns
        // are no longer set but are reset here so old locks don't linger.
        rateLimitClear('login_fail_user_ip', $pairKey);
        if ((int)($user['failed_login_attempts'] ?? 0) !== 0 || !empty($user['locked_until'])) {
            dbRun("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?", [$user['id']]);
        }
        return ['ok' => true, 'user' => $user, 'error' => null];
    }

    rateLimitRecord('login_fail_ip', $ip, $window);
    rateLimitRecord('login_fail_user_ip', $pairKey, $window);
    return ['ok' => false, 'user' => null, 'error' => 'Invalid username or password.'];
}

function loginMinutesText(int $seconds): string {
    $mins = max(1, (int)ceil($seconds / 60));
    return $mins . ' minute' . ($mins === 1 ? '' : 's');
}

// ─── Auth helpers ─────────────────────────────────────────────────────────────
function isLoggedIn(): bool { return !empty($_SESSION['user_id']); }

/**
 * Whether a users row may sign in / keep a session. Only an explicit
 * non-"active" status blocks (e.g. "inactive" from the Team page); an empty
 * status is treated as active so rows from before the column had a value
 * aren't locked out.
 */
function isActiveUserRow(array $user): bool {
    $status = strtolower(trim((string)($user['status'] ?? '')));
    return $status === '' || $status === 'active';
}

/**
 * Re-reads the signed-in user from the database so the session reflects the
 * account as it is now, not as it was at sign-in: a deleted or deactivated
 * user is signed out on their next request, and a role change (e.g. an admin
 * demoted) takes effect immediately instead of when the session expires.
 * Returns false if the session was ended.
 */
function refreshSessionUser(): bool {
    $row = dbFetch("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);
    if (!$row || !isActiveUserRow($row)) {
        $_SESSION = [];
        session_destroy();
        return false;
    }
    $_SESSION['user'] = sanitizeUser($row);
    return true;
}

function requireAuth(): void {
    if (!isLoggedIn() || !refreshSessionUser()) {
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            jsonResponse(['error' => 'Not authenticated'], 401);
        }
        header('Location: /login'); exit;
    }
    // Signed in with a temporary password: everything except My Account (where
    // it gets changed) and logout is blocked until it's replaced.
    if (!empty($_SESSION['user']['must_change_password'])) {
        $path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        if ($path !== 'account' && $path !== 'logout') {
            if (str_starts_with($path, 'api/')) {
                jsonResponse(['error' => 'Password change required'], 403);
            }
            header('Location: /account'); exit;
        }
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
// Configurable from Admin → SLA & Timers (stored in app_config as
// installationSlaWorkingDays); falls back to the agreed 10-day target if
// never set. Re-read from getAppConfig() on every request, so a change in
// Admin takes effect immediately without a deploy.
define('INSTALLATION_SLA_WORKING_DAYS', (int)(getAppConfig()['installationSlaWorkingDays'] ?? 10));
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
    // ORDER BY ticket_number DESC (a plain string sort) breaks the instant the
    // sequence crosses a digit boundary — e.g. "999" sorts ABOVE "1000"
    // lexically ('9' > '1'), so once a -1000 ticket exists this always picked
    // -999 as "last" again and regenerated -1000 forever. Fetching every
    // number for this prefix/year and taking the numeric max in PHP sorts
    // correctly regardless of digit count, and works the same on MySQL or
    // Postgres (no vendor-specific numeric-cast SQL needed).
    $rows = dbFetchAll(
        "SELECT ticket_number FROM tickets WHERE ticket_number LIKE ?",
        ["$prefix-$year-%"]
    );
    $seq = 1;
    foreach ($rows as $row) {
        $parts = explode('-', $row['ticket_number'] ?? '');
        $n = (int)end($parts);
        if ($n >= $seq) $seq = $n + 1;
    }
    return sprintf('%s-%s-%03d', $prefix, $year, $seq);
}

/**
 * Generates a ticket number and runs $insertFn($ticketNumber), retrying with
 * a freshly-regenerated number if a UNIQUE constraint collision occurs (two
 * concurrent ticket creations racing for the same next number — rare, but
 * the read-then-increment in generateTicketNumber() isn't atomic, so this is
 * the hard backstop behind it rather than the primary defense).
 */
function withUniqueTicketNumber(string $prefix, callable $insertFn, int $maxAttempts = 3): string {
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ticketNum = generateTicketNumber($prefix);
        try {
            $insertFn($ticketNum);
            return $ticketNum;
        } catch (\PDOException $e) {
            $isDuplicateKey = ($e->errorInfo[1] ?? null) === 1062; // MySQL duplicate-entry code
            if ($isDuplicateKey && $attempt < $maxAttempts) continue;
            throw $e;
        }
    }
    throw new \RuntimeException('Could not generate a unique ticket number after ' . $maxAttempts . ' attempts.');
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

/**
 * Notify (in-app + email) every active user holding a given permission —
 * used to route Payment Request stage-change alerts to whichever roles
 * currently hold payment_requests.authorize / .approve / .finance_check,
 * without hardcoding role names (role_permissions is the source of truth
 * for who holds a permission, and it's admin-configurable).
 */
function notifyPermissionHolders(string $permission, string $title, string $inAppMessage, string $link, string $emailSubject, string $emailBodyHtml): void {
    try {
        $roles = array_column(dbFetchAll("SELECT DISTINCT role FROM role_permissions WHERE permission = ?", [$permission]), 'role');
        if (!$roles) return;
        $ph = implode(',', array_fill(0, count($roles), '?'));
        $users = dbFetchAll("SELECT id, name, email FROM users WHERE role IN ($ph) AND status = 'active'", $roles);
        foreach ($users as $u) {
            notifyUser($u['id'], $title, $inAppMessage, $link);
            if (!empty($u['email'])) {
                try {
                    sendEmail($u['email'], $u['name'], $emailSubject, $emailBodyHtml);
                } catch (\Throwable $e) {
                    error_log("notifyPermissionHolders email error ({$u['email']}): " . $e->getMessage());
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('notifyPermissionHolders error: ' . $e->getMessage());
    }
}

/** Shared HTML body for Payment Request stage-change emails/notifications. */
function paymentRequestEmailBody(array $pr, string $headline, string $extraNote = ''): string {
    $link = siteBaseUrl() . '/payment-requests?status=' . urlencode($pr['status'] ?? 'all');
    $amt  = number_format((float)($pr['amount'] ?? 0), 2);
    $desc = htmlspecialchars(substr($pr['description'] ?? '', 0, 200));
    $req  = htmlspecialchars($pr['requester_name'] ?? '—');
    $no   = htmlspecialchars($pr['request_no'] ?? '—');
    return "<p>{$headline}</p>
        <table style='border-collapse:collapse;width:100%;max-width:480px;font-size:.9rem'>
          <tr><td style='padding:6px 12px;background:#f8fafc;font-weight:600;border:1px solid #e2e8f0'>Request No</td><td style='padding:6px 12px;border:1px solid #e2e8f0'>{$no}</td></tr>
          <tr><td style='padding:6px 12px;background:#f8fafc;font-weight:600;border:1px solid #e2e8f0'>Requested By</td><td style='padding:6px 12px;border:1px solid #e2e8f0'>{$req}</td></tr>
          <tr><td style='padding:6px 12px;background:#f8fafc;font-weight:600;border:1px solid #e2e8f0'>Description</td><td style='padding:6px 12px;border:1px solid #e2e8f0'>{$desc}</td></tr>
          <tr><td style='padding:6px 12px;background:#f8fafc;font-weight:600;border:1px solid #e2e8f0'>Amount</td><td style='padding:6px 12px;border:1px solid #e2e8f0'>₦{$amt}</td></tr>
        </table>"
        . ($extraNote ? "<p>{$extraNote}</p>" : '') .
        "<p><a href='{$link}'>View Payment Request →</a></p>
        <p style='color:#64748b;font-size:.85rem'>FieldPulse · MangoNet</p>";
}

/** Fetch a payment_requests row plus the requester's email, for the stage-change notify helpers below. */
function fetchPaymentRequestForNotify(string $id): ?array {
    return dbFetch("SELECT pr.*, u.email AS requester_email FROM payment_requests pr LEFT JOIN users u ON u.id = pr.requester_id WHERE pr.id = ?", [$id]) ?: null;
}

/** Notify the requester (in-app + email) of a Payment Request stage change. */
function notifyPaymentRequestOriginator(array $pr, string $title, string $headline, string $extraNote = ''): void {
    try {
        if (empty($pr['requester_id'])) return;
        $link = '/payment-requests?status=' . urlencode($pr['status'] ?? 'all');
        notifyUser($pr['requester_id'], $title, $headline, $link);
        if (!empty($pr['requester_email'])) {
            sendEmail($pr['requester_email'], $pr['requester_name'] ?? 'there', $title, paymentRequestEmailBody($pr, $headline, $extraNote));
        }
    } catch (\Throwable $e) {
        error_log('notifyPaymentRequestOriginator error: ' . $e->getMessage());
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

// ─── NOC credential encryption ───────────────────────────────────────────────
// Device credentials (SNMP communities, RouterOS passwords) are encrypted at
// rest with NOC_CRED_KEY from secrets.php (at least 32 bytes; see
// secrets.example.php). There is deliberately no fallback key for new values:
// the old code silently used a key hard-coded in this public repo whenever
// NOC_CRED_KEY was missing, which made the encryption meaningless.
//
// Format "v2:" + base64(iv|tag|ciphertext), AES-256-GCM (authenticated) with
// a key derived from NOC_CRED_KEY. Values without the prefix are the old
// AES-256-CBC format; they still decrypt (with NOC_CRED_KEY as-is, then the
// old repo key) and are re-encrypted by nocReencryptLegacyCredentials().
const NOC_LEGACY_DEV_KEY_SEED = 'fieldpulse-noc-dev-key';

/** NOC_CRED_KEY if it is set and long enough to use, else null. */
function nocCredKey(): ?string {
    return (defined('NOC_CRED_KEY') && is_string(NOC_CRED_KEY) && strlen(NOC_CRED_KEY) >= 32) ? NOC_CRED_KEY : null;
}

/** @throws RuntimeException when no usable key is configured. */
function nocEncrypt(string $plain, ?string $key = null): string {
    $key ??= nocCredKey();
    if ($key === null) {
        throw new RuntimeException('NOC_CRED_KEY is not configured in secrets.php (see secrets.example.php).');
    }
    $encKey = hash_hkdf('sha256', $key, 32, 'fieldpulse-noc-v2');
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $encKey, OPENSSL_RAW_DATA, $iv, $tag);
    return 'v2:' . base64_encode($iv . $tag . $ct);
}

/** Returns '' when the value is empty or can't be decrypted. */
function nocDecrypt(string $encrypted, ?string $key = null): string {
    if ($encrypted === '') return '';
    $key ??= nocCredKey();
    if (str_starts_with($encrypted, 'v2:')) {
        if ($key === null) return '';
        $raw = base64_decode(substr($encrypted, 3), true);
        if ($raw === false || strlen($raw) < 28) return '';
        $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash_hkdf('sha256', $key, 32, 'fieldpulse-noc-v2'),
            OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $pt === false ? '' : $pt;
    }
    // Old CBC format: unauthenticated, so a wrong key usually fails the
    // padding check but can occasionally "succeed" with garbage. Only accept
    // output that looks like a credential (valid UTF-8, no control chars).
    $raw = base64_decode($encrypted, true);
    if ($raw === false || strlen($raw) < 32) return '';
    $candidates = [];
    if (defined('NOC_CRED_KEY') && is_string(NOC_CRED_KEY) && NOC_CRED_KEY !== '') $candidates[] = NOC_CRED_KEY;
    if ($key !== null) $candidates[] = $key;
    $candidates[] = hash('sha256', NOC_LEGACY_DEV_KEY_SEED, true);
    foreach (array_unique($candidates) as $k) {
        $pt = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $k, OPENSSL_RAW_DATA, substr($raw, 0, 16));
        if ($pt !== false && mb_check_encoding($pt, 'UTF-8') && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $pt)) {
            return $pt;
        }
    }
    return '';
}

/**
 * Re-encrypts every old-format device credential with the configured key.
 * Runs once per key (tracked in app_config by a fingerprint of the key).
 * Values that can't be decrypted are left untouched and logged. Returns the
 * number of values re-encrypted.
 */
function nocReencryptLegacyCredentials(?string $key = null): int {
    $key ??= nocCredKey();
    if ($key === null) return 0;
    $flag = 'noc_creds_v2_' . substr(hash('sha256', $key), 0, 12);
    $k = dbKey();
    if (dbFetch("SELECT value FROM app_config WHERE $k = ?", [$flag])) return 0;
    try {
        $devices = dbFetchAll("SELECT id, snmp_community, api_credentials FROM network_devices");
    } catch (\Throwable $e) {
        // NOC tables not installed: nothing stored in the old format. Record
        // the run so this isn't retried (and logged) on every request.
        dbUpsertConfig($flag, 'true');
        return 0;
    }
    $count = 0;
    foreach ($devices as $d) {
        foreach (['snmp_community', 'api_credentials'] as $col) {
            $v = (string)($d[$col] ?? '');
            if ($v === '' || str_starts_with($v, 'v2:')) continue;
            $pt = nocDecrypt($v, $key);
            if ($pt === '') { error_log("NOC credential re-encryption: could not decrypt $col for device {$d['id']}"); continue; }
            dbRun("UPDATE network_devices SET $col = ? WHERE id = ?", [nocEncrypt($pt, $key), $d['id']]);
            $count++;
        }
    }
    dbUpsertConfig($flag, 'true');
    return $count;
}

// ─── Absolute site base URL (emailed links, QR codes, public links) ───────────
// Never built from the request: the Host header is client-controlled, so a
// password-reset email built from it could carry a link to an attacker's
// domain with a valid reset token in it (reset-link poisoning). Cron-called
// endpoints also have no meaningful Host. Order: SITE_URL from secrets.php
// (e.g. staging), then an app_config 'siteUrl', then the production URL —
// the same "production is the default" convention as DB_NAME above.
const SITE_URL_DEFAULT = 'https://fieldpulse.mangonetonline.com';

function siteBaseUrl(): string {
    $candidates = [defined('SITE_URL') ? SITE_URL : null, getAppConfig()['siteUrl'] ?? null];
    foreach ($candidates as $url) {
        if (is_string($url) && preg_match('#^https?://[a-z0-9.-]+(:\d+)?/?$#i', trim($url))) {
            return rtrim(trim($url), '/');
        }
    }
    return SITE_URL_DEFAULT;
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
    'tickets.checkin'      => 'GPS check-in / check-out on tickets (field technicians)',
    'customers.view'       => 'View customers',
    'customers.create'     => 'Create customers',
    'customers.update'     => 'Edit customers',
    'customers.delete'     => 'Delete customers',
    'customers.bulk'       => 'Bulk status change / mass SMS customers',
    'customers.export'     => 'Export customers to CSV',
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
    'admin.audit.view'     => 'View system audit log',
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
    'inventory.po.view'           => 'View purchase orders',
    'inventory.po.manage'         => 'Create / edit / cancel purchase orders',
    'inventory.po.receive'        => 'Receive goods against a purchase order',
    'inventory.zoho.export'       => 'Export inventory to Zoho CSV format',
    'inventory.serials.view'      => 'View serial numbers',
    'inventory.serials.manage'    => 'Add / dispatch / retire serial numbers',
    // ── Payment Requests module ──
    'payment_requests.create'      => 'Submit payment requests',
    'payment_requests.view'        => 'View all payment requests (not just own)',
    'payment_requests.authorize'   => 'Authorize payment requests (Line Manager / Supervisor stage)',
    'payment_requests.approve'     => 'Approve payment requests (COO / senior management stage)',
    'payment_requests.finance_check'  => 'Finance check — disburse or return to requester',
    'payment_requests.finance_recall' => 'Recall finance action — revert disbursed / partially disbursed / finance review back to Approved',
    // ── Finance module ──
    'finance.view' => 'Access the Finance dashboard (aggregates + AI reports)',
    // ── NOC module ──
    'noc.view'            => 'View NOC dashboard, ONU status board, and device list',
    'noc.devices.manage'  => 'Add / edit / delete network devices (OLTs, routers)',
    'map.coverage'        => 'Upload / manage KMZ/KML network coverage map layers',
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

/**
 * A user's assigned hub ids, flattened from both hub_id (single) and hub_ids
 * (a JSON array, e.g. ["id1","id2"], set via Team management's multi-hub
 * checkboxes — users.hub_ids is a real JSON column in the live database
 * with MySQL's own json_valid() check, not tracked in any migration here)
 * into one array. Empty array means "no hub restriction" — used by
 * ticketScopeSql()/canAccessTicket() to distinguish a maintenance-vendor
 * team member scoped to specific hub(s) from their team's supervisor, who
 * has none assigned and sees everything.
 */
function userHubIdList(array $user): array {
    $ids = [];
    if (!empty($user['hub_id'])) $ids[] = $user['hub_id'];
    $raw = $user['hub_ids'] ?? null;
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            // Legacy Postgres-array-literal format ("{id1,id2}"), from
            // before this was corrected to write real JSON — tolerate it
            // on read so any such row already in the database still works.
            $clean = trim($raw, '{}');
            $decoded = $clean !== '' ? explode(',', $clean) : [];
        }
        foreach ($decoded as $id) {
            $id = trim((string)$id, " \"'");
            if ($id !== '') $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
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
    $role = $u['role'] ?? '';
    // Vendors aren't part of the internal permission tiers below — they see only
    // tickets handed to their company for field work, whether as the
    // installation vendor (vendor_id, role 'vendor') or the hub-routed
    // maintenance/fiber vendor (maintenance_vendor_id, role 'vendor-mtce').
    if ($role === 'vendor' || $role === 'vendor-mtce') {
        if (empty($u['vendor_id'])) return ['1=0', []];
        // A maintenance-vendor team member assigned to specific hub(s) (via
        // Team management) only sees that hub's tickets — an engineer in
        // Lekki shouldn't see Yaba's queue. No hub assigned at all means
        // this is the team's supervisor, who sees every ticket for the
        // company, same as the plain 'vendor' role always has.
        if ($role === 'vendor-mtce') {
            $hubIds = userHubIdList($u);
            if ($hubIds) {
                $ph = implode(',', array_fill(0, count($hubIds), '?'));
                return ["{$p}maintenance_vendor_id = ? AND {$p}hub_id IN ($ph)", array_merge([$u['vendor_id']], $hubIds)];
            }
        }
        return ["({$p}vendor_id = ? OR {$p}maintenance_vendor_id = ?)", [$u['vendor_id'], $u['vendor_id']]];
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
    $role = $u['role'] ?? '';
    if ($role === 'vendor' || $role === 'vendor-mtce') {
        if (empty($u['vendor_id'])) return false;
        if ($role === 'vendor-mtce') {
            $hubIds = userHubIdList($u);
            if ($hubIds) {
                return ($ticket['maintenance_vendor_id'] ?? null) === $u['vendor_id']
                    && in_array($ticket['hub_id'] ?? null, $hubIds, true);
            }
        }
        return ($ticket['vendor_id'] ?? null) === $u['vendor_id']
            || ($ticket['maintenance_vendor_id'] ?? null) === $u['vendor_id'];
    }
    if (hasPermission('tickets.view_all')) return true;
    if (($ticket['assigned_to'] ?? null) === $u['id'] || ($ticket['created_by'] ?? null) === $u['id']) return true;
    if (hasPermission('tickets.view_department') && ($dept = userDepartment()) !== '' && !empty($ticket['fault_type_id'])) {
        $ft = dbFetch("SELECT route_to FROM fault_types WHERE id = ?", [$ticket['fault_type_id']]);
        if ($ft && strtolower(trim((string)$ft['route_to'])) === strtolower($dept)) return true;
    }
    return false;
}

/**
 * Returns [sqlFragment, params] to AND into an installation_profiles query
 * for the current user — mirrors ticketScopeSql()'s vendor model: a
 * vendor-type user (role 'vendor' or 'vendor-mtce') only ever sees their
 * own company's jobs, further hub-scoped for a 'vendor-mtce' team member
 * with hub(s) assigned via Team management (their supervisor, with none
 * assigned, sees the whole company's jobs). Non-vendor roles get no
 * restriction here — installations.view (required to reach this page/
 * endpoint at all) is all-or-nothing for internal staff, same as before.
 * $alias is the installation_profiles table alias used in the query (e.g.
 * 'p' or '' for an unaliased query).
 */
function installationScopeSql(string $alias = 'p'): array {
    $u = currentUser();
    if (!$u) return ['1=0', []];
    $p = $alias ? $alias . '.' : '';
    $role = $u['role'] ?? '';
    if ($role === 'vendor' || $role === 'vendor-mtce') {
        if (empty($u['vendor_id'])) return ['1=0', []];
        if ($role === 'vendor-mtce') {
            $hubIds = userHubIdList($u);
            if ($hubIds) {
                $ph = implode(',', array_fill(0, count($hubIds), '?'));
                return ["{$p}vendor_id = ? AND {$p}hub_id IN ($ph)", array_merge([$u['vendor_id']], $hubIds)];
            }
        }
        return ["{$p}vendor_id = ?", [$u['vendor_id']]];
    }
    return ['', []];
}

/** Can the current user view/act on this specific installation profile? */
function canAccessInstallation(array $profile): bool {
    $u = currentUser();
    if (!$u) return false;
    $role = $u['role'] ?? '';
    if ($role === 'vendor' || $role === 'vendor-mtce') {
        if (empty($u['vendor_id']) || ($profile['vendor_id'] ?? null) !== $u['vendor_id']) return false;
        if ($role === 'vendor-mtce') {
            $hubIds = userHubIdList($u);
            if ($hubIds) return in_array($profile['hub_id'] ?? null, $hubIds, true);
        }
        return true;
    }
    // Non-vendor roles are already gated by requirePermission('installations.view')
    // at the page/endpoint level — no further per-row restriction for staff.
    return true;
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
        $link = siteBaseUrl() . '/ticket/' . $ticket['id'];
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
        $link = siteBaseUrl() . '/ticket/' . $ticket['id'];
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

        // CSAT token — generated once per ticket, reused if this email is ever
        // re-triggered, so a re-send doesn't invalidate a link already sent.
        $csatToken = $ticket['csat_token'] ?? null;
        if (!$csatToken) {
            $csatToken = bin2hex(random_bytes(16));
            try { dbRun("UPDATE tickets SET csat_token = ? WHERE id = ?", [$csatToken, $ticket['id']]); } catch (\Throwable $e) {}
        }
        $base = siteBaseUrl() . "/csat?ticket={$ticket['id']}&token={$csatToken}&score=";
        $stars = '';
        for ($i = 1; $i <= 5; $i++) {
            $stars .= "<a href='{$base}{$i}' style='display:inline-block;width:38px;height:38px;line-height:38px;text-align:center;margin-right:6px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;color:#0ea5e9;font-weight:700;text-decoration:none'>{$i}</a>";
        }

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
             <p style='margin-top:1.5rem'>How did we do? <span style='color:#64748b;font-size:.85rem'>(1 = poor, 5 = excellent)</span></p>
             <p>{$stars}</p>
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
        $link = siteBaseUrl() . '/installations?detail=' . $profile['id'];
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
        $link = siteBaseUrl() . '/installations?detail=' . $profile['id'];
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
        $link = siteBaseUrl() . '/installations?detail=' . $profile['id'];
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
            'engineer'         => ['tickets.update','tickets.resolve','tickets.close','tickets.checkin','schedule.view','map.view','installations.view','payment_requests.create'],
            'noc_engineer'     => ['tickets.update','tickets.resolve','tickets.close','tickets.checkin','schedule.view','map.view','payment_requests.create'],
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

// ─── Schema v28: Self-service password reset ───────────────────────────────────
// Only the SHA-256 hash of the reset token is stored — a database leak alone
// can't be used to reset anyone's password, same reasoning as never storing
// plaintext passwords.
$_k = dbKey();
$_sv28 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v28_migrated'");
if (!$_sv28) {
    try {
        if (DB_TYPE === 'mysql') {
            try { db()->exec("ALTER TABLE `users` ADD COLUMN `reset_token_hash` VARCHAR(64) DEFAULT NULL"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE `users` ADD COLUMN `reset_token_expires_at` DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
        } else {
            try { db()->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token_hash VARCHAR(64)"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token_expires_at TIMESTAMP"); } catch (\Throwable $e) {}
        }
        dbUpsertConfig('schema_v28_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v28 migration error: ' . $e->getMessage());
    }
}

// ─── Password reset helpers ─────────────────────────────────────────────────────
define('PASSWORD_RESET_MINUTES', 60);

/**
 * Issue a reset token for the given email if — and only if — an active
 * account with that email exists. Always returns silently either way; the
 * caller must show the same "if that email exists…" message regardless, to
 * avoid leaking which emails have accounts (user enumeration).
 */
function issuePasswordReset(string $email): void {
    $email = trim($email);
    if ($email === '') return;
    try {
        $u = dbFetch("SELECT id,name,email FROM users WHERE email = ? AND status = 'active'", [$email]);
        if (!$u) return;
        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', time() + PASSWORD_RESET_MINUTES * 60);
        dbRun("UPDATE users SET reset_token_hash=?, reset_token_expires_at=? WHERE id=?", [$hash, $expires, $u['id']]);
        $link = siteBaseUrl() . '/reset-password?token=' . $token;
        sendEmail(
            $u['email'], $u['name'],
            'Reset your FieldPulse password',
            "<p>Hi {$u['name']},</p>
             <p>We received a request to reset your FieldPulse password. This link is valid for " . PASSWORD_RESET_MINUTES . " minutes.</p>
             <p><a href='{$link}'>Reset your password →</a></p>
             <p style='color:#64748b;font-size:.85rem'>If you didn't request this, you can safely ignore this email — your password won't change.</p>
             <p style='color:#64748b;font-size:.85rem'>FieldPulse · MangoNet</p>"
        );
    } catch (\Throwable $e) {
        error_log('issuePasswordReset error: ' . $e->getMessage());
    }
}

/** Looks up a still-valid (unexpired, unused) reset token. Returns the user row or null. */
function findUserByResetToken(string $token): ?array {
    if ($token === '') return null;
    $hash = hash('sha256', $token);
    $u = dbFetch("SELECT * FROM users WHERE reset_token_hash = ?", [$hash]);
    if (!$u) return null;
    if (empty($u['reset_token_expires_at']) || strtotime($u['reset_token_expires_at']) < time()) return null;
    return $u;
}

/** Sets a new password and invalidates the token — single use. */
function completePasswordReset(string $userId, string $newPassword): void {
    dbRun("UPDATE users SET password=?, must_change_password=0, temp_password_expires_at=NULL, reset_token_hash=NULL, reset_token_expires_at=NULL, failed_login_attempts=0, locked_until=NULL WHERE id=?",
        [hashPassword($newPassword), $userId]);
}

// ─── Schema v29: per-item inventory reorder threshold ──────────────────────────
// "Low stock" used to be a single hardcoded quantity<=5 for every item — a
// router and a cable tie don't deserve the same alert threshold. Existing
// items default to 5 so today's alerts don't change until someone tunes them.
$_k = dbKey();
$_sv29 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v29_migrated'");
if (!$_sv29) {
    try {
        if (DB_TYPE === 'mysql') {
            try { db()->exec("ALTER TABLE `inv_items` ADD COLUMN `reorder_threshold` INT NOT NULL DEFAULT 5"); } catch (\Throwable $e) {}
        } else {
            try { db()->exec("ALTER TABLE inv_items ADD COLUMN IF NOT EXISTS reorder_threshold INT NOT NULL DEFAULT 5"); } catch (\Throwable $e) {}
        }
        dbUpsertConfig('schema_v29_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v29 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v30: Payment Request budget ceilings ───────────────────────────────
// Soft warnings, not hard blocks — the approval chain already controls who can
// spend; this adds visibility into whether a department/spend-type is running
// over its monthly allowance before the next request gets approved on top of it.
$_k = dbKey();
$_sv30 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v30_migrated'");
if (!$_sv30) {
    try {
        if (DB_TYPE === 'mysql') {
            db()->exec("CREATE TABLE IF NOT EXISTS `payment_budgets` (
                `id`              VARCHAR(36) NOT NULL,
                `department`      VARCHAR(191) DEFAULT NULL,
                `capex_opex`      VARCHAR(10) DEFAULT NULL,
                `monthly_amount`  DECIMAL(14,2) NOT NULL,
                `created_by`      VARCHAR(36) DEFAULT NULL,
                `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } else {
            db()->exec("CREATE TABLE IF NOT EXISTS payment_budgets (
                id              VARCHAR(36) PRIMARY KEY,
                department      VARCHAR(191),
                capex_opex      VARCHAR(10),
                monthly_amount  NUMERIC(14,2) NOT NULL,
                created_by      VARCHAR(36),
                created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        }
        dbUpsertConfig('schema_v30_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v30 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v31: Report subscriptions ───────────────────────────────────────────
// Generalizes the ops-digest/finance-digest pattern (both already work) to any
// report on the Reports page — pick a report, a cadence, a recipient list,
// instead of having to already know a report exists and open the app to see it.
$_k = dbKey();
$_sv31 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v31_migrated'");
if (!$_sv31) {
    try {
        if (DB_TYPE === 'mysql') {
            db()->exec("CREATE TABLE IF NOT EXISTS `report_subscriptions` (
                `id`                VARCHAR(36) NOT NULL,
                `report_type`       VARCHAR(30) NOT NULL,
                `cadence`           VARCHAR(10) NOT NULL,
                `recipients`        TEXT NOT NULL,
                `created_by`        VARCHAR(36) DEFAULT NULL,
                `created_by_name`   VARCHAR(191) DEFAULT NULL,
                `last_sent_at`      DATETIME DEFAULT NULL,
                `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
                `enabled`           TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } else {
            db()->exec("CREATE TABLE IF NOT EXISTS report_subscriptions (
                id                VARCHAR(36) PRIMARY KEY,
                report_type       VARCHAR(30) NOT NULL,
                cadence           VARCHAR(10) NOT NULL,
                recipients        TEXT NOT NULL,
                created_by        VARCHAR(36),
                created_by_name   VARCHAR(191),
                last_sent_at      TIMESTAMP,
                created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                enabled           SMALLINT NOT NULL DEFAULT 1
            )");
        }
        dbUpsertConfig('schema_v31_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v31 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v32: API rate limiting + webhook retry queue ───────────────────────
// Both flagged as known v1 limits when the Integration API shipped — fine for
// a single internal integration, risky once a second/third external partner
// is calling in.
$_k = dbKey();
$_sv32 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v32_migrated'");
if (!$_sv32) {
    try {
        if (DB_TYPE === 'mysql') {
            try { db()->exec("ALTER TABLE `api_keys` ADD COLUMN `rate_limit_per_min` INT NOT NULL DEFAULT 60"); } catch (\Throwable $e) {}
            db()->exec("CREATE TABLE IF NOT EXISTS `webhook_deliveries` (
                `id`              VARCHAR(36) NOT NULL,
                `api_key_id`      VARCHAR(36) NOT NULL,
                `event`           VARCHAR(50) NOT NULL,
                `payload`         TEXT NOT NULL,
                `attempts`        INT NOT NULL DEFAULT 1,
                `status`          VARCHAR(20) NOT NULL DEFAULT 'pending',
                `next_retry_at`   DATETIME DEFAULT NULL,
                `last_error`      VARCHAR(500) DEFAULT NULL,
                `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
                `delivered_at`    DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_wd_status_retry` (`status`,`next_retry_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } else {
            try { db()->exec("ALTER TABLE api_keys ADD COLUMN IF NOT EXISTS rate_limit_per_min INT NOT NULL DEFAULT 60"); } catch (\Throwable $e) {}
            db()->exec("CREATE TABLE IF NOT EXISTS webhook_deliveries (
                id              VARCHAR(36) PRIMARY KEY,
                api_key_id      VARCHAR(36) NOT NULL,
                event           VARCHAR(50) NOT NULL,
                payload         TEXT NOT NULL,
                attempts        INT NOT NULL DEFAULT 1,
                status          VARCHAR(20) NOT NULL DEFAULT 'pending',
                next_retry_at   TIMESTAMP,
                last_error      VARCHAR(500),
                created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                delivered_at    TIMESTAMP
            )");
        }
        dbUpsertConfig('schema_v32_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v32 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v33: CSAT (customer satisfaction) micro-survey ─────────────────────
// Closes the loop the audit flagged as missing — SLA compliance measures
// speed, nothing measured whether the customer was actually happy. Email-only
// for now (a 1-5 rating link in the resolution email); SMS is deliberately
// deferred until that workflow is decided.
$_k = dbKey();
$_sv33 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v33_migrated'");
if (!$_sv33) {
    try {
        if (DB_TYPE === 'mysql') {
            try { db()->exec("ALTER TABLE `tickets` ADD COLUMN `csat_token` VARCHAR(64) DEFAULT NULL"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE `tickets` ADD COLUMN `csat_score` TINYINT DEFAULT NULL"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE `tickets` ADD COLUMN `csat_comment` TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE `tickets` ADD COLUMN `csat_submitted_at` DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
        } else {
            try { db()->exec("ALTER TABLE tickets ADD COLUMN IF NOT EXISTS csat_token VARCHAR(64)"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE tickets ADD COLUMN IF NOT EXISTS csat_score SMALLINT"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE tickets ADD COLUMN IF NOT EXISTS csat_comment TEXT"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE tickets ADD COLUMN IF NOT EXISTS csat_submitted_at TIMESTAMP"); } catch (\Throwable $e) {}
        }
        dbUpsertConfig('schema_v33_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v33 migration error: ' . $e->getMessage());
    }
}

define('WEBHOOK_MAX_ATTEMPTS', 6);
/** Exponential-ish backoff in minutes, indexed by attempt number (1-based). */
define('WEBHOOK_RETRY_BACKOFF_MIN', [1, 5, 15, 60, 180, 720]);

// ─── Schema v34: Two-factor authentication (email one-time code) ───────────────
// Opt-in per user (not forced on the whole org at once — enabling requires a
// live confirmation round-trip through the user's own inbox first, so nobody
// can lock themselves out by flipping the toggle with a typo'd/dead email).
// SMS is deliberately not an option here — pegged per explicit instruction
// until that workflow is decided; email reuses the SMTP already wired for
// password reset and CSAT.
$_k = dbKey();
$_sv34 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v34_migrated'");
if (!$_sv34) {
    try {
        if (DB_TYPE === 'mysql') {
            try { db()->exec("ALTER TABLE `users` ADD COLUMN `twofa_enabled` TINYINT(1) NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE `users` ADD COLUMN `twofa_code_hash` VARCHAR(64) DEFAULT NULL"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE `users` ADD COLUMN `twofa_code_expires_at` DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
        } else {
            try { db()->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS twofa_enabled SMALLINT NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS twofa_code_hash VARCHAR(64)"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS twofa_code_expires_at TIMESTAMP"); } catch (\Throwable $e) {}
        }
        dbUpsertConfig('schema_v34_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v34 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v35: fix collation mismatch on migration-created tables ────────────
// Found by the new automated test suite, not by hand: every table created by
// a schema_vN block used utf8mb4_general_ci, while database/schema.sql's
// original tables (users, tickets, vendors, hubs, customers, ...) use
// utf8mb4_unicode_ci. A JOIN comparing a VARCHAR column across the two groups
// — e.g. payment_requests.vendor_id against vendors.id, exactly what the
// Payment Requests list / Finance / vendor scorecard queries do — can throw
// "Illegal mix of collations". CONVERT TO doesn't change the data (same
// utf8mb4 charset, just sort/compare rules), so this is safe to run against
// tables that already have rows. Each table converts independently so one
// failure can't block the rest.
$_k = dbKey();
$_sv35 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v35_migrated'");
if (!$_sv35) {
    try {
        if (DB_TYPE === 'mysql') {
            $_mismatchedTables = [
                'api_keys', 'api_request_log', 'hub_city_mappings', 'locations', 'payment_budgets',
                'payment_requests', 'payment_request_customers', 'payment_request_documents',
                'payment_request_items', 'payment_request_payments', 'report_subscriptions',
                'ticket_photos', 'webhook_deliveries',
            ];
            foreach ($_mismatchedTables as $_t) {
                try {
                    db()->exec("ALTER TABLE `{$_t}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                } catch (\Throwable $e) {
                    error_log("Schema v35: failed converting collation for {$_t}: " . $e->getMessage());
                }
            }
        }
        dbUpsertConfig('schema_v35_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v35 migration error: ' . $e->getMessage());
    }
}

// schema_v36: schema_v35 converted a hand-picked table list, but each table's
// ALTER ran in its own try/catch — a single failure (lock contention, a
// transient error) logs and moves on without blocking the rest, and either
// way the v35 flag gets marked done, so it would never retry. This pass is
// dynamic instead of a fixed list: it asks information_schema for every
// table in THIS database still on utf8mb4_general_ci and converts whatever
// it finds, so it self-heals regardless of what schema_v35 missed or
// whether it ran at all yet.
$_sv36 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v36_migrated'");
if (!$_sv36) {
    try {
        if (DB_TYPE === 'mysql') {
            $_staleTables = dbFetchAll(
                "SELECT DISTINCT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ? AND TABLE_COLLATION = 'utf8mb4_general_ci'",
                [DB_NAME]
            );
            foreach ($_staleTables as $_row) {
                $_t = $_row['TABLE_NAME'];
                try {
                    db()->exec("ALTER TABLE `{$_t}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                } catch (\Throwable $e) {
                    error_log("Schema v36: failed converting collation for {$_t}: " . $e->getMessage());
                }
            }
        }
        dbUpsertConfig('schema_v36_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v36 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v37: fix duplicate ticket numbers, then enforce uniqueness ────────
// generateTicketNumber() used to pick "last" via ORDER BY ticket_number DESC
// — a plain string sort, which breaks the moment the sequence crosses a
// digit boundary ("999" sorts above "1000" lexically). Once a -1000 ticket
// existed, every ticket after it got handed -1000 again, forever — the exact
// bug reported live. Fixed in generateTicketNumber() itself; this migration
// cleans up the duplicates that bug already created and adds a UNIQUE
// constraint so a future collision fails loudly (caught and retried by
// withUniqueTicketNumber()) instead of silently duplicating again.
$_sv37 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v37_migrated'");
if (!$_sv37) {
    try {
        if (DB_TYPE === 'mysql') {
            $_dupGroups = dbFetchAll(
                "SELECT ticket_number FROM tickets WHERE ticket_number IS NOT NULL
                 GROUP BY ticket_number HAVING COUNT(*) > 1"
            );
            foreach ($_dupGroups as $_dg) {
                $_num = $_dg['ticket_number'];
                // Keep the oldest row exactly as-is; renumber every later
                // duplicate so no ticket's URL/reference changes except the
                // ones that were never uniquely identifiable to begin with.
                $_dupRows = dbFetchAll(
                    "SELECT id FROM tickets WHERE ticket_number = ? ORDER BY created_at ASC, id ASC",
                    [$_num]
                );
                array_shift($_dupRows);
                $_prefix = explode('-', $_num)[0] ?: 'INC';
                foreach ($_dupRows as $_dr) {
                    try {
                        $_newNum = generateTicketNumber($_prefix);
                        dbRun("UPDATE tickets SET ticket_number = ? WHERE id = ?", [$_newNum, $_dr['id']]);
                    } catch (\Throwable $e) {
                        error_log("Schema v37: failed renumbering ticket {$_dr['id']}: " . $e->getMessage());
                    }
                }
            }
            try {
                db()->exec("ALTER TABLE `tickets` ADD UNIQUE KEY `uniq_ticket_number` (`ticket_number`)");
            } catch (\Throwable $e) {
                error_log('Schema v37: failed adding unique index on ticket_number: ' . $e->getMessage());
            }
        }
        dbUpsertConfig('schema_v37_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v37 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v38: installation handoff — who laid the cable, who terminated ───
// installation_profiles.vendor_id is a single mutable field: whoever is
// "currently assigned" — needed so termination can be handed to a different
// person (e.g. Lekki: Ejike lays cable, Kingsley terminates) and have it show
// up in Kingsley's own vendor-scoped view. But once vendor_id moves on to
// Kingsley, Ejike's part of the job would otherwise vanish from the record.
// These two columns snapshot vendor_id at the moment each stage is first
// reached, so both contributions stay visible permanently — see
// captureInstallationHandoff() below, called from every place stage changes.
$_sv38 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v38_migrated'");
if (!$_sv38) {
    try {
        if (DB_TYPE === 'mysql') {
            try {
                db()->exec("ALTER TABLE `installation_profiles` ADD COLUMN `cable_laid_by_vendor_id` VARCHAR(36) DEFAULT NULL");
            } catch (\Throwable $e) { error_log('Schema v38: cable_laid_by_vendor_id: ' . $e->getMessage()); }
            try {
                db()->exec("ALTER TABLE `installation_profiles` ADD COLUMN `terminated_by_vendor_id` VARCHAR(36) DEFAULT NULL");
            } catch (\Throwable $e) { error_log('Schema v38: terminated_by_vendor_id: ' . $e->getMessage()); }
            // Backfill from history for jobs already past these stages, on a
            // best-effort basis: whoever is currently assigned gets credited
            // for both if there's nothing more specific to go on — accurate
            // for the common single-vendor case (e.g. Yaba's Samson), and no
            // worse than blank for the rest.
            try {
                db()->exec("UPDATE installation_profiles SET cable_laid_by_vendor_id = vendor_id
                             WHERE cable_laid_by_vendor_id IS NULL AND vendor_id IS NOT NULL
                               AND status IN ('cable_laying','termination_pending','connected')");
                db()->exec("UPDATE installation_profiles SET terminated_by_vendor_id = vendor_id
                             WHERE terminated_by_vendor_id IS NULL AND vendor_id IS NOT NULL AND status = 'connected'");
            } catch (\Throwable $e) { error_log('Schema v38 backfill: ' . $e->getMessage()); }
        } else {
            try { db()->exec("ALTER TABLE installation_profiles ADD COLUMN IF NOT EXISTS cable_laid_by_vendor_id VARCHAR(36)"); } catch (\Throwable $e) {}
            try { db()->exec("ALTER TABLE installation_profiles ADD COLUMN IF NOT EXISTS terminated_by_vendor_id VARCHAR(36)"); } catch (\Throwable $e) {}
        }
        dbUpsertConfig('schema_v38_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v38 migration error: ' . $e->getMessage());
    }
}

/**
 * Call after writing a new status to installation_profiles, passing the
 * vendor_id the row has AFTER any reassignment in the same request. Snapshots
 * that vendor into cable_laid_by_vendor_id / terminated_by_vendor_id the
 * first time each relevant stage is reached — a no-op once already set, so
 * it never overwrites an earlier contributor's credit.
 */
function captureInstallationHandoff(string $profileId, string $newStatus, ?string $currentVendorId): void {
    if (!$currentVendorId) return;
    if ($newStatus === 'cable_laying') {
        dbRun("UPDATE installation_profiles SET cable_laid_by_vendor_id = ? WHERE id = ? AND cable_laid_by_vendor_id IS NULL",
            [$currentVendorId, $profileId]);
    }
    if ($newStatus === 'connected') {
        dbRun("UPDATE installation_profiles SET terminated_by_vendor_id = ? WHERE id = ? AND terminated_by_vendor_id IS NULL",
            [$currentVendorId, $profileId]);
    }
}

/**
 * Next unique Payment Request number for the given year, e.g. "PR-2026-0001".
 * Fetches every request_no for that year and takes the numeric max in PHP
 * (not ORDER BY ... DESC, a plain string sort that breaks past 4 digits —
 * see schema_v37's ticket_number fix) so it scales past 9999/year cleanly.
 */
function generatePaymentRequestNumber(?string $year = null): string {
    $year = $year ?: date('Y');
    // Include legacy PR- records so the sequence never resets after the prefix change
    $rows = dbFetchAll(
        "SELECT request_no FROM payment_requests WHERE request_no LIKE ? OR request_no LIKE ?",
        ["REQ-$year-%", "PR-$year-%"]
    );
    $seq = 1;
    foreach ($rows as $row) {
        $parts = explode('-', $row['request_no'] ?? '');
        $n = (int)end($parts);
        if ($n >= $seq) $seq = $n + 1;
    }
    return sprintf('REQ-%s-%04d', $year, $seq);
}

/**
 * Generates a Payment Request number and runs $insertFn($requestNo), retrying
 * with a freshly-regenerated number if a UNIQUE constraint collision occurs
 * (two concurrent submissions racing for the same next number) — same
 * backstop pattern as withUniqueTicketNumber().
 */
function withUniquePaymentRequestNumber(callable $insertFn, int $maxAttempts = 3): string {
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $num = generatePaymentRequestNumber();
        try {
            $insertFn($num);
            return $num;
        } catch (\PDOException $e) {
            $isDuplicateKey = ($e->errorInfo[1] ?? null) === 1062;
            if ($isDuplicateKey && $attempt < $maxAttempts) continue;
            throw $e;
        }
    }
    throw new \RuntimeException('Could not generate a unique payment request number after ' . $maxAttempts . ' attempts.');
}

// ─── Schema v39: unique, uncapped Payment Request numbers ─────────────────────
// Payment requests only ever had their UUID id — no human-readable reference.
// Adds request_no (format PR-<year>-<seq>, e.g. PR-2026-0001), backfills
// existing rows in creation order via generatePaymentRequestNumber() above,
// and enforces uniqueness with a UNIQUE key — same pattern, and same lesson,
// as schema_v37's ticket_number fix. The %d format has no digit cap, so this
// scales past 10,000 requests in a year without collisions or truncation.
$_sv39 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v39_migrated'");
if (!$_sv39) {
    try {
        if (DB_TYPE === 'mysql') {
            try { db()->exec("ALTER TABLE `payment_requests` ADD COLUMN `request_no` VARCHAR(30) DEFAULT NULL"); }
            catch (\Throwable $e) { error_log('Schema v39: add request_no: ' . $e->getMessage()); }
        } else {
            try { db()->exec("ALTER TABLE payment_requests ADD COLUMN IF NOT EXISTS request_no VARCHAR(30)"); } catch (\Throwable $e) {}
        }
        // Backfill in creation order, one at a time (not hot-path, so the
        // extra per-row query round trip is fine) via the same numeric-max
        // helper the app uses going forward — keeps this migration and
        // generatePaymentRequestNumber() from ever disagreeing on format.
        $_unNumbered = dbFetchAll("SELECT id, created_at FROM payment_requests WHERE request_no IS NULL ORDER BY created_at ASC, id ASC");
        foreach ($_unNumbered as $_pr) {
            $_num = generatePaymentRequestNumber(date('Y', strtotime($_pr['created_at'] ?? 'now')));
            try {
                dbRun("UPDATE payment_requests SET request_no = ? WHERE id = ?", [$_num, $_pr['id']]);
            } catch (\Throwable $e) {
                error_log("Schema v39: failed backfilling request_no for {$_pr['id']}: " . $e->getMessage());
            }
        }
        if (DB_TYPE === 'mysql') {
            try {
                db()->exec("ALTER TABLE `payment_requests` ADD UNIQUE KEY `uniq_request_no` (`request_no`)");
            } catch (\Throwable $e) {
                error_log('Schema v39: failed adding unique index on request_no: ' . $e->getMessage());
            }
        }
        dbUpsertConfig('schema_v39_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v39 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v40: indexes on hot-path filter/join columns ──────────────────────
// Found by a comprehensive review: these columns are hit on every request of
// their respective pages/queries (ticketScopeSql()'s created_by/vendor_id
// filter for every non-privileged user's ticket list, the customer portal's
// account_number lookup, each vendor's own installation list, Payment
// Requests' status/vendor/requester filters) but had no index beyond the
// primary key. Invisible on a small table, but a full table scan once these
// grow into the tens of thousands of rows. Purely additive — safe to run
// against a live, populated database; each ADD INDEX is independent so one
// failure (e.g. already exists) can't block the rest.
$_sv40 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v40_migrated'");
if (!$_sv40) {
    try {
        if (DB_TYPE === 'mysql') {
            $_indexes = [
                ['tickets', 'idx_tickets_created_by', '(`created_by`)'],
                ['tickets', 'idx_tickets_vendor', '(`vendor_id`)'],
                ['tickets', 'idx_tickets_fault_type', '(`fault_type_id`)'],
                ['tickets', 'idx_tickets_created_at', '(`created_at`)'],
                // account_number is TEXT (no fixed length), so MySQL needs an
                // explicit prefix length to index it.
                ['customers', 'idx_customers_account_number', '(`account_number`(50))'],
                ['customers', 'idx_customers_status', '(`status`)'],
                ['customers', 'idx_customers_hub', '(`hub_id`)'],
                ['installation_profiles', 'idx_installations_vendor', '(`vendor_id`)'],
                ['installation_profiles', 'idx_installations_status', '(`status`)'],
                ['installation_profiles', 'idx_installations_created_at', '(`created_at`)'],
                ['payment_requests', 'idx_payment_requests_status', '(`status`)'],
                ['payment_requests', 'idx_payment_requests_vendor', '(`vendor_id`)'],
                ['payment_requests', 'idx_payment_requests_requester', '(`requester_id`)'],
            ];
            foreach ($_indexes as [$_t, $_idxName, $_cols]) {
                try {
                    db()->exec("ALTER TABLE `{$_t}` ADD INDEX `{$_idxName}` {$_cols}");
                } catch (\Throwable $e) {
                    error_log("Schema v40: failed adding index {$_idxName} on {$_t}: " . $e->getMessage());
                }
            }
        }
        dbUpsertConfig('schema_v40_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v40 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v41: hub-based maintenance vendor routing ─────────────────────────
// A maintenance vendor (e.g. a contractor company with its own team of field
// engineers) needs every ticket in their assigned location visible to their
// whole team, not just whoever one auto-assign pass happened to pick. Adds
// hubs.maintenance_vendor_id — when set, ticket creation routes the ticket's
// vendor_id there instead of an individual engineer, and ticketScopeSql()
// already grants every user sharing that vendor_id full visibility (same
// mechanism vendor-scoped installations already use), no further schema
// change needed for the "whole team sees it" half of this.
$_sv41 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v41_migrated'");
if (!$_sv41) {
    try {
        if (DB_TYPE === 'mysql') {
            try { db()->exec("ALTER TABLE `hubs` ADD COLUMN `maintenance_vendor_id` VARCHAR(36) DEFAULT NULL"); }
            catch (\Throwable $e) { error_log('Schema v41: add maintenance_vendor_id: ' . $e->getMessage()); }
        } else {
            try { db()->exec("ALTER TABLE hubs ADD COLUMN IF NOT EXISTS maintenance_vendor_id VARCHAR(36)"); } catch (\Throwable $e) {}
        }
        dbUpsertConfig('schema_v41_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v41 migration error: ' . $e->getMessage());
    }
}

// ─── Schema v42: keep installation vendor and maintenance vendor separate ─────
// tickets.vendor_id was already in use as the "installation vendor" field —
// the manual Vendor picker on the create-ticket form and the Assign to
// Vendor action on the ticket detail page both write to it. Reusing that
// same column for hub-based maintenance/fiber vendor routing (as the first
// version of this feature did) conflates two distinct concepts on the same
// ticket. Adds tickets.maintenance_vendor_id as its own column, so a ticket
// can carry both independently: an installation vendor (vendor_id) picked
// manually, and a maintenance/fiber vendor (maintenance_vendor_id) resolved
// automatically from the ticket's hub. ticketScopeSql()/canAccessTicket()
// below now check both columns for vendor-role visibility.
$_sv42 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v42_migrated'");
if (!$_sv42) {
    try {
        if (DB_TYPE === 'mysql') {
            try { db()->exec("ALTER TABLE `tickets` ADD COLUMN `maintenance_vendor_id` VARCHAR(36) DEFAULT NULL"); }
            catch (\Throwable $e) { error_log('Schema v42: add maintenance_vendor_id: ' . $e->getMessage()); }
        } else {
            try { db()->exec("ALTER TABLE tickets ADD COLUMN IF NOT EXISTS maintenance_vendor_id VARCHAR(36)"); } catch (\Throwable $e) {}
        }
        dbUpsertConfig('schema_v42_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v42 migration error: ' . $e->getMessage());
    }
}

// schema_v43: lock_version on tickets — an integer bumped on every update, so
// concurrent edits to the same ticket (e.g. two dispatchers) can be detected
// instead of silently last-write-wins. See the optimistic-locking guard in
// pages/ticket-detail.php and api/tickets.php's PATCH handler.
$_sv43 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v43_migrated'");
if (!$_sv43) {
    try {
        if (DB_TYPE === 'mysql') {
            try { db()->exec("ALTER TABLE `tickets` ADD COLUMN `lock_version` INT NOT NULL DEFAULT 0"); }
            catch (\Throwable $e) { error_log('Schema v43: add lock_version: ' . $e->getMessage()); }
        } else {
            try { db()->exec("ALTER TABLE tickets ADD COLUMN IF NOT EXISTS lock_version INT NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
        }
        dbUpsertConfig('schema_v43_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v43 migration error: ' . $e->getMessage());
    }
}

// schema_v44: generic rate_limit_hits table — a single reusable fixed-window
// counter (bucket + key -> count/window_start) backing rateLimitCheck()
// below. Used for the public customer-portal account lookup (previously
// fully unthrottled, letting account numbers be enumerated), 2FA code
// verification (previously no attempt cap, making the second factor
// brute-forceable within its 10-minute validity window), and
// password-reset requests (previously unlimited, a mail-bombing vector).
$_sv44 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v44_migrated'");
if (!$_sv44) {
    try {
        if (DB_TYPE === 'mysql') {
            try {
                db()->exec("CREATE TABLE IF NOT EXISTS `rate_limit_hits` (
                    `id`            VARCHAR(36) NOT NULL,
                    `bucket`        VARCHAR(50) NOT NULL,
                    `rate_key`      VARCHAR(191) NOT NULL,
                    `attempt_count` INT NOT NULL DEFAULT 1,
                    `window_start`  DATETIME NOT NULL,
                    `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_bucket_key` (`bucket`,`rate_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } catch (\Throwable $e) { error_log('Schema v44: create rate_limit_hits: ' . $e->getMessage()); }
        } else {
            try {
                db()->exec("CREATE TABLE IF NOT EXISTS rate_limit_hits (
                    id            VARCHAR(36) PRIMARY KEY,
                    bucket        VARCHAR(50) NOT NULL,
                    rate_key      VARCHAR(191) NOT NULL,
                    attempt_count INT NOT NULL DEFAULT 1,
                    window_start  TIMESTAMP NOT NULL,
                    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (bucket, rate_key)
                )");
            } catch (\Throwable $e) {}
        }
        dbUpsertConfig('schema_v44_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v44 migration error: ' . $e->getMessage());
    }
}

// schema_v45: finance_review stage columns on payment_requests + signature_data
// on users. finance_review is an internal audit stage inserted between 'approved'
// and disbursement — the accountant can edit line items and resubmit for
// re-approval. signature_data stores the user's on-file signature as a base64
// PNG data URL, auto-embedded in the print voucher.
$_k = dbKey();
$_sv45 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v45_migrated'");
if (!$_sv45) {
    try {
        try { db()->exec("ALTER TABLE `users` ADD COLUMN `signature_data` MEDIUMTEXT DEFAULT NULL"); } catch (\Throwable $e) {}
        try { db()->exec("ALTER TABLE users ADD COLUMN signature_data MEDIUMTEXT DEFAULT NULL"); } catch (\Throwable $e) {}
        try { db()->exec("ALTER TABLE `payment_requests` ADD COLUMN `finance_reviewed_by` VARCHAR(36) DEFAULT NULL"); } catch (\Throwable $e) {}
        try { db()->exec("ALTER TABLE payment_requests ADD COLUMN finance_reviewed_by VARCHAR(36) DEFAULT NULL"); } catch (\Throwable $e) {}
        try { db()->exec("ALTER TABLE `payment_requests` ADD COLUMN `finance_reviewed_by_name` VARCHAR(191) DEFAULT NULL"); } catch (\Throwable $e) {}
        try { db()->exec("ALTER TABLE payment_requests ADD COLUMN finance_reviewed_by_name VARCHAR(191) DEFAULT NULL"); } catch (\Throwable $e) {}
        try { db()->exec("ALTER TABLE `payment_requests` ADD COLUMN `finance_reviewed_at` DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
        try { db()->exec("ALTER TABLE payment_requests ADD COLUMN finance_reviewed_at DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
        try { db()->exec("ALTER TABLE `payment_requests` ADD COLUMN `finance_review_notes` TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
        try { db()->exec("ALTER TABLE payment_requests ADD COLUMN finance_review_notes TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
        dbUpsertConfig('schema_v45_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v45 migration error: ' . $e->getMessage());
    }
}

// schema_v46: must_change_password on users. Set when an admin creates a member
// or sets their password (both now issue a random temporary password); cleared
// when the member changes it on My Account or via a reset link. requireAuth()
// blocks everything else while it's set. SMALLINT (not TINYINT) so the same
// statement also works on PostgreSQL.
$_k = dbKey();
$_sv46 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v46_migrated'");
if (!$_sv46) {
    try {
        try { db()->exec("ALTER TABLE `users` ADD COLUMN `must_change_password` SMALLINT NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
        try { db()->exec("ALTER TABLE users ADD COLUMN must_change_password SMALLINT NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
        dbUpsertConfig('schema_v46_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v46 migration error: ' . $e->getMessage());
    }
}

// schema_v47: temp_password_expires_at on users (see setTemporaryPassword()).
// Members already waiting on a temporary password get the full window from now
// rather than being cut off by the deploy. The unquoted variant uses TIMESTAMP
// because PostgreSQL has no DATETIME.
$_k = dbKey();
$_sv47 = dbFetch("SELECT value FROM app_config WHERE $_k = 'schema_v47_migrated'");
if (!$_sv47) {
    try {
        try { db()->exec("ALTER TABLE `users` ADD COLUMN `temp_password_expires_at` DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
        try { db()->exec("ALTER TABLE users ADD COLUMN temp_password_expires_at TIMESTAMP NULL DEFAULT NULL"); } catch (\Throwable $e) {}
        dbRun("UPDATE users SET temp_password_expires_at = ? WHERE must_change_password = 1 AND temp_password_expires_at IS NULL",
            [date('Y-m-d H:i:s', time() + TEMP_PASSWORD_HOURS * 3600)]);
        dbUpsertConfig('schema_v47_migrated', 'true');
    } catch (\Throwable $e) {
        error_log('Schema v47 migration error: ' . $e->getMessage());
    }
}

// Once NOC_CRED_KEY is set, move stored device credentials off the old format
// (and the old public repo key). A no-op after the first run for each key.
try { nocReencryptLegacyCredentials(); }
catch (\Throwable $e) { error_log('NOC credential re-encryption error: ' . $e->getMessage()); }


/**
 * Generic fixed-window rate limiter. Returns true (and records the attempt)
 * if the caller is still within $maxAttempts for this (bucket, key) pair
 * inside the current $windowMinutes window; returns false once the limit is
 * hit, without recording further attempts (so retrying doesn't reset the
 * window early). The window resets naturally once $windowMinutes has
 * elapsed since the first attempt in it.
 */
function rateLimitCheck(string $bucket, string $key, int $maxAttempts, int $windowMinutes): bool {
    $row = dbFetch("SELECT * FROM rate_limit_hits WHERE bucket=? AND rate_key=?", [$bucket, $key]);
    if (!$row) {
        try {
            dbRun("INSERT INTO rate_limit_hits (id,bucket,rate_key,attempt_count,window_start) VALUES (?,?,?,1,NOW())",
                [newUuid(), $bucket, $key]);
        } catch (\Throwable $e) {
            // Unique-key race — another request just inserted the same
            // (bucket,key) row a moment ago; treat as allowed rather than
            // erroring the caller's whole request over a rate-limit bookkeeping race.
        }
        return true;
    }
    if (time() - strtotime($row['window_start']) > $windowMinutes * 60) {
        dbRun("UPDATE rate_limit_hits SET attempt_count=1, window_start=NOW() WHERE id=?", [$row['id']]);
        return true;
    }
    if ((int)$row['attempt_count'] >= $maxAttempts) return false;
    dbRun("UPDATE rate_limit_hits SET attempt_count=attempt_count+1 WHERE id=?", [$row['id']]);
    return true;
}

/**
 * Read-only view of a rate_limit_hits counter: the count in the current
 * window (0 if none or expired) and seconds until that window resets.
 * Unlike rateLimitCheck(), it records nothing — used with rateLimitRecord()
 * where only some outcomes (e.g. failed sign-ins) should count.
 */
function rateLimitState(string $bucket, string $key, int $windowMinutes): array {
    $row = dbFetch("SELECT attempt_count, window_start FROM rate_limit_hits WHERE bucket=? AND rate_key=?", [$bucket, $key]);
    if (!$row) return ['count' => 0, 'resetsIn' => 0];
    $resetsIn = strtotime($row['window_start']) + $windowMinutes * 60 - time();
    if ($resetsIn <= 0) return ['count' => 0, 'resetsIn' => 0];
    return ['count' => (int)$row['attempt_count'], 'resetsIn' => $resetsIn];
}

/** Adds one to a counter, starting a fresh window if the old one expired. */
function rateLimitRecord(string $bucket, string $key, int $windowMinutes): void {
    $row = dbFetch("SELECT id, window_start FROM rate_limit_hits WHERE bucket=? AND rate_key=?", [$bucket, $key]);
    if (!$row) {
        try {
            dbRun("INSERT INTO rate_limit_hits (id,bucket,rate_key,attempt_count,window_start) VALUES (?,?,?,1,NOW())",
                [newUuid(), $bucket, $key]);
            return;
        } catch (\Throwable $e) {
            // Unique-key race: another request inserted it; fall through to increment.
            $row = dbFetch("SELECT id, window_start FROM rate_limit_hits WHERE bucket=? AND rate_key=?", [$bucket, $key]);
            if (!$row) return;
        }
    }
    if (time() - strtotime($row['window_start']) > $windowMinutes * 60) {
        dbRun("UPDATE rate_limit_hits SET attempt_count=1, window_start=NOW() WHERE id=?", [$row['id']]);
    } else {
        dbRun("UPDATE rate_limit_hits SET attempt_count=attempt_count+1 WHERE id=?", [$row['id']]);
    }
}

function rateLimitClear(string $bucket, string $key): void {
    dbRun("DELETE FROM rate_limit_hits WHERE bucket=? AND rate_key=?", [$bucket, $key]);
}

/** Best-effort client IP for rate-limit keys — not identity, just a throttle key. */
function clientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/** Vendor company responsible for maintenance tickets in a hub's location, if configured. */
function getMaintenanceVendorForHub(?string $hubId): ?string {
    if (!$hubId) return null;
    $row = dbFetch("SELECT maintenance_vendor_id FROM hubs WHERE id = ?", [$hubId]);
    return $row['maintenance_vendor_id'] ?? null;
}

/**
 * Notify (in-app + email) every active engineer on a vendor company's team
 * that a ticket has been routed to them — used instead of notifyUser() for
 * hub-based vendor routing, where there's no single assignee, the whole
 * team needs to know (ticketScopeSql() already makes it visible to all of
 * them; this just makes sure they're actually told).
 */
function notifyVendorTeamTicketAssigned(array $ticket, string $vendorId): void {
    try {
        $members = dbFetchAll("SELECT id, name, email, role, hub_id, hub_ids FROM users WHERE vendor_id = ? AND status = 'active'", [$vendorId]);
        if (!$members) return;
        $ticketHubId = $ticket['hub_id'] ?? null;
        $tn   = htmlspecialchars($ticket['ticket_number'] ?? '');
        $desc = htmlspecialchars(substr($ticket['description'] ?? '', 0, 200));
        $prio = strtoupper($ticket['priority'] ?? '');
        $link = '/ticket/' . $ticket['id'];
        $fullLink = siteBaseUrl() . $link;
        foreach ($members as $m) {
            // Same rule as ticketScopeSql(): a hub-restricted maintenance-vendor
            // team member only gets notified for their own hub's tickets — the
            // team's supervisor (no hub restriction) gets every one.
            if ($m['role'] === 'vendor-mtce') {
                $hubIds = userHubIdList($m);
                if ($hubIds && !in_array($ticketHubId, $hubIds, true)) continue;
            }
            notifyUser($m['id'], "Ticket Assigned to Your Team — {$tn}",
                ($ticket['customer_name'] ?? '') . ': ' . $prio . ' — ' . substr($ticket['description'] ?? '', 0, 80), $link);
            if (!empty($m['email'])) {
                try {
                    sendEmail($m['email'], $m['name'], "Ticket Assigned to Your Team — {$tn}",
                        "<p>Hi " . htmlspecialchars($m['name']) . ",</p>
                         <p>A ticket for your team's service area has come in.</p>
                         <p><strong>Ticket:</strong> {$tn} (Priority: {$prio})</p>
                         <p><strong>Issue:</strong> {$desc}</p>
                         <p><a href='{$fullLink}'>View Ticket →</a></p>
                         <p style='color:#64748b;font-size:.85rem'>FieldPulse · MangoNet</p>");
                } catch (\Throwable $e) {
                    error_log("notifyVendorTeamTicketAssigned email error ({$m['email']}): " . $e->getMessage());
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('notifyVendorTeamTicketAssigned error: ' . $e->getMessage());
    }
}

define('TWOFA_CODE_MINUTES', 10);

/** Generates, stores (hashed), and emails a fresh 6-digit code for this user. */
function issueTwoFactorCode(string $userId, string $email, string $name): void {
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = hash('sha256', $code);
    $expires = date('Y-m-d H:i:s', time() + TWOFA_CODE_MINUTES * 60);
    dbRun("UPDATE users SET twofa_code_hash=?, twofa_code_expires_at=? WHERE id=?", [$hash, $expires, $userId]);
    try {
        sendEmail($email, $name, 'Your FieldPulse verification code',
            "<p>Hi {$name},</p>
             <p>Your verification code is:</p>
             <p style='font-size:2rem;font-weight:700;letter-spacing:.2em;color:#0ea5e9'>{$code}</p>
             <p style='color:#64748b;font-size:.85rem'>This code expires in " . TWOFA_CODE_MINUTES . " minutes. If you didn't request this, you can ignore it — your account is still safe.</p>"
        );
    } catch (\Throwable $e) {
        error_log('issueTwoFactorCode email error: ' . $e->getMessage());
    }
}

/** Verifies a submitted code; clears it (single-use) on success. */
function verifyTwoFactorCode(string $userId, string $code): bool {
    $u = dbFetch("SELECT twofa_code_hash, twofa_code_expires_at FROM users WHERE id=?", [$userId]);
    if (!$u || empty($u['twofa_code_hash'])) return false;
    if (empty($u['twofa_code_expires_at']) || strtotime($u['twofa_code_expires_at']) < time()) return false;
    if (!hash_equals($u['twofa_code_hash'], hash('sha256', trim($code)))) return false;
    dbRun("UPDATE users SET twofa_code_hash=NULL, twofa_code_expires_at=NULL WHERE id=?", [$userId]);
    return true;
}

define('REPORT_SUBSCRIPTION_TYPES', [
    'department' => 'By Department', 'engineer' => 'By Engineer', 'olt' => 'By OLT / Equipment',
    'vendor' => 'By Vendor', 'issue' => 'By Issue Type', 'recurring' => 'Recurring Customers',
    'resolution' => 'Resolution Time',
]);

/** Is this subscription due to send, given its cadence and when it last sent? */
function reportSubscriptionIsDue(array $sub): bool {
    if (empty($sub['last_sent_at'])) return true;
    $last = strtotime($sub['last_sent_at']);
    return match ($sub['cadence']) {
        'daily'   => date('Y-m-d', $last) < date('Y-m-d'),
        'weekly'  => $last <= strtotime('-7 days'),
        'monthly' => date('Y-m', $last) !== date('Y-m'),
        default   => false,
    };
}

/** Rolling lookback window (days) matching a cadence — used for the report's own date filter. */
function reportSubscriptionWindowDays(string $cadence): int {
    return match ($cadence) { 'daily' => 1, 'weekly' => 7, 'monthly' => 30, default => 7 };
}

/**
 * This calendar month's committed spend (authorized/approved/partially
 * disbursed/disbursed — i.e. money that's moving or moved, not just
 * requested-and-not-yet-reviewed) against each budget rule, keyed by
 * budget id. A rule with a null department/capex_opex applies broadly
 * (matches everything for that dimension).
 */
function getBudgetStatuses(): array {
    $budgets = dbFetchAll("SELECT * FROM payment_budgets ORDER BY department, capex_opex");
    if (!$budgets) return [];
    $monthStart = date('Y-m-01 00:00:00');
    $out = [];
    foreach ($budgets as $b) {
        $where = ["status IN ('authorized','approved','partially_disbursed','disbursed')", "date_of_request >= ?"];
        $params = [$monthStart];
        if ($b['department'] !== null) { $where[] = "department = ?"; $params[] = $b['department']; }
        if ($b['capex_opex'] !== null) { $where[] = "capex_opex = ?"; $params[] = $b['capex_opex']; }
        $spent = (float)(dbFetch("SELECT SUM(amount) s FROM payment_requests WHERE " . implode(' AND ', $where), $params)['s'] ?? 0);
        $pct = $b['monthly_amount'] > 0 ? round($spent / $b['monthly_amount'] * 100) : 0;
        $out[] = [
            'id' => $b['id'], 'department' => $b['department'], 'capex_opex' => $b['capex_opex'],
            'monthly_amount' => (float)$b['monthly_amount'], 'spent' => $spent, 'pct' => $pct,
        ];
    }
    return $out;
}

// ─── Integration API: scopes, key helpers, outbound webhooks ──────────────────
// Every scope an API key can be granted. Keep this in sync with the
// enforcement in each api/v1/*.php endpoint — a scope existing here doesn't
// grant anything by itself, each endpoint must explicitly requireApiScope() it.
define('API_SCOPES', [
    'customers.read'      => 'Read customer records',
    'customers.write'     => 'Create / update customer records',
    'payments.read'       => 'Read payment requests',
    'payments.write'      => 'Record payments against approved payment requests',
    'tickets.read'        => 'Read tickets (list, detail)',
    'tickets.write'       => 'Update ticket status/priority/assignment/RCA, upload photos',
    'installations.read'  => 'Read installation jobs',
    'installations.write' => 'Update installation stage/fields',
]);

// Events FieldPulse can push to a key's webhook_url, if it has one configured.
define('WEBHOOK_EVENTS', [
    'customer.created', 'customer.updated', 'payment.disbursed',
    'ticket.updated', 'installation.updated',
]);

function generateApiKey(): array {
    $secret = bin2hex(random_bytes(24)); // 48 hex chars
    $full   = 'fp_live_' . $secret;
    return ['full' => $full, 'prefix' => substr($full, 0, 14), 'hash' => hash('sha256', $full)];
}

/**
 * Best-effort outbound webhook delivery: fires synchronously, never throws.
 * A non-2xx response or a transport error queues the payload into
 * webhook_deliveries for retryFailedWebhooks() to pick up on a backoff
 * schedule (see WEBHOOK_RETRY_BACKOFF_MIN), instead of just being dropped.
 * Payload is signed with the key's webhook_secret (HMAC-SHA256) in the
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
        [$ok, $error] = deliverWebhook($k['webhook_url'], $k['webhook_secret'] ?? '', $event, $body);
        if (!$ok) {
            error_log("Webhook delivery failed for key {$k['id']} ({$event}): {$error}");
            try {
                dbRun("INSERT INTO webhook_deliveries (id,api_key_id,event,payload,attempts,status,next_retry_at,last_error) VALUES (?,?,?,?,1,'pending',?,?)",
                    [newUuid(), $k['id'], $event, $body, date('Y-m-d H:i:s', time() + WEBHOOK_RETRY_BACKOFF_MIN[0] * 60), substr($error, 0, 500)]);
            } catch (\Throwable $e) {}
        }
    }
}

/** Single delivery attempt. Returns [success bool, error string]. */
function deliverWebhook(string $url, string $secret, string $event, string $body): array {
    try {
        $sig = hash_hmac('sha256', $body, $secret);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-FieldPulse-Event: ' . $event, 'X-FieldPulse-Signature: ' . $sig],
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($curlErr) return [false, $curlErr];
        if ($httpCode < 200 || $httpCode >= 300) return [false, "HTTP {$httpCode}"];
        return [true, ''];
    } catch (\Throwable $e) {
        return [false, $e->getMessage()];
    }
}

/**
 * Processes due retries — call from a cron hitting api/webhook-retry.php.
 * Gives up (status='failed') after WEBHOOK_MAX_ATTEMPTS, otherwise
 * reschedules with the next backoff step.
 */
function retryFailedWebhooks(): array {
    $due = dbFetchAll("SELECT wd.*, ak.webhook_url, ak.webhook_secret FROM webhook_deliveries wd
                        JOIN api_keys ak ON ak.id = wd.api_key_id
                        WHERE wd.status='pending' AND wd.next_retry_at <= NOW() AND ak.revoked_at IS NULL");
    $results = ['attempted' => 0, 'delivered' => 0, 'gave_up' => 0, 'rescheduled' => 0];
    foreach ($due as $d) {
        $results['attempted']++;
        [$ok, $error] = deliverWebhook($d['webhook_url'], $d['webhook_secret'] ?? '', $d['event'], $d['payload']);
        if ($ok) {
            dbRun("UPDATE webhook_deliveries SET status='delivered', delivered_at=NOW() WHERE id=?", [$d['id']]);
            $results['delivered']++;
            continue;
        }
        $attempts = (int)$d['attempts'] + 1;
        if ($attempts >= WEBHOOK_MAX_ATTEMPTS) {
            dbRun("UPDATE webhook_deliveries SET status='failed', attempts=?, last_error=? WHERE id=?", [$attempts, substr($error, 0, 500), $d['id']]);
            $results['gave_up']++;
        } else {
            $backoffMin = WEBHOOK_RETRY_BACKOFF_MIN[$attempts - 1] ?? end(WEBHOOK_RETRY_BACKOFF_MIN);
            dbRun("UPDATE webhook_deliveries SET attempts=?, next_retry_at=?, last_error=? WHERE id=?",
                [$attempts, date('Y-m-d H:i:s', time() + $backoffMin * 60), substr($error, 0, 500), $d['id']]);
            $results['rescheduled']++;
        }
    }
    return $results;
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

// ─── SMS / WhatsApp helper ────────────────────────────────────────────────────
/**
 * Send an SMS or WhatsApp message via the configured provider.
 * Providers supported: africas_talking, twilio
 * Returns ['ok'=>bool, 'ref'=>string|null, 'error'=>string|null]
 */
function sendSms(string $phone, string $message, string $channel = 'sms'): array {
    $cfg      = getAppConfig();
    $enabled  = ($cfg['smsEnabled'] ?? '0') === '1';
    $provider = $cfg['smsProvider'] ?? '';

    if (!$enabled || !$provider || !$phone) {
        return ['ok' => false, 'ref' => null, 'error' => 'SMS not configured or disabled'];
    }

    // Normalise to international format — prepend country code if starts with 0
    $phone = preg_replace('/\s+/', '', $phone);
    if (str_starts_with($phone, '0')) {
        $cc    = $cfg['smsCountryCode'] ?? '234'; // default Nigeria
        $phone = '+' . ltrim($cc, '+') . substr($phone, 1);
    }

    $ref = null; $error = null; $ok = false;

    try {
        if ($provider === 'africas_talking') {
            $apiKey   = $cfg['smsApiKey']    ?? '';
            $username = $cfg['smsUsername']  ?? 'sandbox';
            $sender   = $cfg['smsSenderId']  ?? '';
            $url = $channel === 'whatsapp'
                ? 'https://voice.africastalking.com/chat/send'
                : 'https://api.africastalking.com/version1/messaging';

            if ($channel === 'whatsapp') {
                $body = json_encode(['username' => $username, 'productName' => $sender ?: 'default',
                                     'channel'  => 'Whatsapp', 'to' => $phone, 'message' => $message]);
                $ct = 'application/json';
            } else {
                $body = http_build_query(array_filter([
                    'username' => $username, 'to' => $phone,
                    'message'  => $message,  'from' => $sender ?: null,
                ]));
                $ct = 'application/x-www-form-urlencoded';
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS     => $body, CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER     => ["apiKey: $apiKey", "Accept: application/json", "Content-Type: $ct"],
            ]);
            $resp  = curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            if (!$errno) {
                $data = json_decode($resp, true);
                $recipient = $data['SMSMessageData']['Recipients'][0] ?? $data['data'] ?? [];
                $status    = is_array($recipient) ? ($recipient['status'] ?? '') : '';
                $ok  = in_array($status, ['Success', 'MessageSent'], true) || ($data['status'] ?? '') === '200';
                $ref = is_array($recipient) ? ($recipient['messageId'] ?? null) : null;
                if (!$ok) $error = $resp;
            } else {
                $error = curl_strerror($errno);
            }

        } elseif ($provider === 'twilio') {
            $sid    = $cfg['smsApiKey']    ?? '';
            $token  = $cfg['smsApiSecret'] ?? '';
            $from   = $cfg['smsSenderId']  ?? '';
            $url    = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
            $body   = http_build_query(['To' => $phone, 'From' => $from, 'Body' => $message]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,    CURLOPT_TIMEOUT => 10,
                CURLOPT_USERPWD    => "$sid:$token",
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $resp  = curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            if (!$errno) {
                $data = json_decode($resp, true);
                $ok   = isset($data['sid']);
                $ref  = $data['sid'] ?? null;
                if (!$ok) $error = $data['message'] ?? $resp;
            } else {
                $error = curl_strerror($errno);
            }
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }

    // Log every attempt
    try {
        dbRun("INSERT INTO sms_log (id,recipient,message,channel,provider,status,provider_ref,error)
               VALUES (?,?,?,?,?,?,?,?)",
              [newUuid(), $phone, $message, $channel, $provider,
               $ok ? 'sent' : 'failed', $ref, $error]);
    } catch (\Throwable $e) { /* table may not exist yet on first boot */ }

    return ['ok' => $ok, 'ref' => $ref, 'error' => $error];
}

