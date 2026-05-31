<?php
// =================================================================
// config.local.php — REAL SECRETS (gitignored)
//
// Copy this file to `config.local.php` on the server and fill in
// the real values. Do NOT commit config.local.php to git.
//
// Generate AUTH_PASSWORD_HASH with:
//   php -r "echo password_hash('your_password', PASSWORD_DEFAULT);"
// =================================================================

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'REPLACE_ME');
define('DB_USER', 'REPLACE_ME');
define('DB_PASS', 'REPLACE_ME');

define('API_KEY',             'REPLACE_ME_some_long_random_string');
define('AUTH_PASSWORD_HASH',  '$2y$10$REPLACE_ME_generated_with_password_hash');
