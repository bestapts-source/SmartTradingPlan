<?php
if (!defined('TRADING_BOOT')) { http_response_code(403); exit('Forbidden'); }
// ============================================================
// Trading Analytics — Configuration
//
// Secrets live in api/config.local.php (gitignored).
// This file ONLY holds non-sensitive defaults and helpers.
//
// On the server, create api/config.local.php with:
//   define('DB_USER', 'real_user');
//   define('DB_PASS', 'real_pass');
//   define('API_KEY', 'real_key');
//   define('AUTH_PASSWORD_HASH', 'real_bcrypt_hash');
// Optionally override DB_HOST or DB_NAME the same way.
// ============================================================

// ---- Load secrets if present --------------------------------
$localConfig = __DIR__ . '/config.local.php';
if (file_exists($localConfig)) {
    require $localConfig;
}

// ---- Database defaults --------------------------------------
// May be overridden in config.local.php
if (!defined('DB_HOST'))    define('DB_HOST', '127.0.0.1');
if (!defined('DB_NAME'))    define('DB_NAME', 'trading_analytics');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
if (!defined('DB_USER'))    define('DB_USER', '');
if (!defined('DB_PASS'))    define('DB_PASS', '');

// ---- Secrets fallbacks (will refuse to run if not overridden)
if (!defined('API_KEY')) define('API_KEY', '');
if (!defined('AUTH_PASSWORD_HASH')) define('AUTH_PASSWORD_HASH', '');

// ---- Timezones ---------------------------------------------
define('TZ_IBKR',    'America/New_York');
define('TZ_STORAGE', 'UTC');
define('TZ_DISPLAY', 'Asia/Jerusalem');
date_default_timezone_set(TZ_STORAGE);

// ---- File storage ------------------------------------------
define('RAW_HTML_DIR', __DIR__ . '/raw');

// ---- Session settings --------------------------------------
define('SESSION_COOKIE_NAME', 'tplan_sess');
define('SESSION_LIFETIME', 60 * 60 * 24 * 30);  // 30 days

// ---- CORS allowed origin -----------------------------------
if (!defined('CORS_ORIGIN')) {
    define('CORS_ORIGIN', 'https://phpstack-1428625-6458295.cloudwaysapps.com');
}

// ---- Error display -----------------------------------------
// TODO: turn off in production once stable
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// ============================================================
// Helpers
// ============================================================

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function jsonError(string $msg, int $code = 400, array $extra = []): void {
    jsonResponse(array_merge(['success' => false, 'error' => $msg], $extra), $code);
}

function handleCorsPreflight(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        jsonResponse(['ok' => true]);
    }
}

function getApiKey(): ?string {
    if (!empty($_GET['api_key']))            return $_GET['api_key'];
    if (!empty($_SERVER['HTTP_X_API_KEY']))  return $_SERVER['HTTP_X_API_KEY'];
    return null;
}
