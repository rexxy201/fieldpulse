<?php
/**
 * PHPUnit bootstrap. Points config.php at a disposable local test database
 * via env vars (never at production/staging — see the guard below) and then
 * requires it, which runs every schema_vN migration against a fresh DB. That
 * alone is a real regression check: if a future migration only ever ran
 * successfully against an already-migrated production DB, this catches it
 * failing on a clean install before it ships.
 *
 * Set these before running phpunit (see phpunit.xml <php> block, or export
 * them yourself). Defaults assume a local MySQL/MariaDB with an empty root
 * password and a database already created from database/schema.sql — see
 * tests/README.md for the one-time setup.
 */

$dbName = getenv('FIELDPULSE_DB_NAME') ?: 'fieldpulse_test';

// Hard safety guard — this suite runs destructive-ish operations (creates
// records, may DELETE what it creates). Refuse to run against anything that
// doesn't look like a disposable test database.
if (!str_contains($dbName, 'test')) {
    fwrite(STDERR, "Refusing to run tests against a database not named like a test DB: {$dbName}\n");
    exit(1);
}

putenv('FIELDPULSE_DB_HOST=' . (getenv('FIELDPULSE_DB_HOST') !== false ? getenv('FIELDPULSE_DB_HOST') : '127.0.0.1'));
putenv('FIELDPULSE_DB_NAME=' . $dbName);
putenv('FIELDPULSE_DB_USER=' . (getenv('FIELDPULSE_DB_USER') !== false ? getenv('FIELDPULSE_DB_USER') : 'root'));
putenv('FIELDPULSE_DB_PASS=' . (getenv('FIELDPULSE_DB_PASS') !== false ? getenv('FIELDPULSE_DB_PASS') : ''));

// config.php sends security headers, starts a session, etc. — all fine under
// the CLI SAPI (header() is a no-op, sessions still work), but suppress the
// "headers already sent" class of notices some environments raise anyway.
error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/api-auth.php';

require_once __DIR__ . '/TestCase.php';
