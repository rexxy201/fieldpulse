<?php
// Copy this file to secrets.php (same folder) on the LIVE SERVER ONLY, and fill
// in the real value. secrets.php is gitignored — never commit it, never let it
// pass through cPanel Git Version Control deploy.
define('DB_PASS_FROM_SECRETS', 'YOUR_DATABASE_PASSWORD');

// Required for NOC device credentials (SNMP communities, RouterOS passwords).
// They are encrypted with this key; without it the NOC page refuses to save
// new credentials. Generate one ONCE on the server and never change it (a new
// key makes already-stored credentials unreadable) or commit it:
//   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
// then paste the output below. Existing credentials are re-encrypted with it
// automatically on the next page load. If the NOC poller scripts run from a
// different folder (e.g. the git clone under ~/repositories), that folder's
// secrets.php needs the same line.
define('NOC_CRED_KEY', base64_decode('PASTE_THE_GENERATED_VALUE_HERE'));

// Optional — only needed on staging (or any environment whose database isn't
// the production mangonetcom_fieldpulse one). Leave these out on production;
// config.php falls back to the production DB_NAME/DB_USER when undefined.
// define('DB_HOST_FROM_SECRETS', 'localhost');
// define('DB_NAME_FROM_SECRETS', 'mangonetcom_fieldpulse_staging');
// define('DB_USER_FROM_SECRETS', 'mangonetcom_fieldpulse_staging');
