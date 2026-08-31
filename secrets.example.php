<?php
// Copy this file to secrets.php (same folder) on the LIVE SERVER ONLY, and fill
// in the real value. secrets.php is gitignored — never commit it, never let it
// pass through cPanel Git Version Control deploy.
define('DB_PASS_FROM_SECRETS', 'YOUR_DATABASE_PASSWORD');

// Optional — only needed on staging (or any environment whose database isn't
// the production mangonetcom_fieldpulse one). Leave these out on production;
// config.php falls back to the production DB_NAME/DB_USER when undefined.
// define('DB_HOST_FROM_SECRETS', 'localhost');
// define('DB_NAME_FROM_SECRETS', 'mangonetcom_fieldpulse_staging');
// define('DB_USER_FROM_SECRETS', 'mangonetcom_fieldpulse_staging');
