<?php
/**
 * Configuration loader
 * Reads .env / .env.local and defines application constants.
 */

declare(strict_types=1);

function _cfg_load_env(string $path): void {
    if (!is_file($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $key   = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        if ($value !== '' && $value[0] === '"' && $value[-1] === '"') {
            $value = stripslashes(substr($value, 1, -1));
        }
        if ($key !== '' && !isset($_ENV[$key])) {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}

function _cfg_env(string $key, string $default = ''): string {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

// Load env files (local overrides base)
$_cfgRoot = dirname(__DIR__);
_cfg_load_env($_cfgRoot . '/.env');
_cfg_load_env($_cfgRoot . '/.env.local');
unset($_cfgRoot);

// ── Database ────────────────────────────────────────────────────────────────
define('DB_HOST',    _cfg_env('DB_HOST',    'localhost'));
define('DB_PORT',    _cfg_env('DB_PORT',    '3306'));
define('DB_NAME',    _cfg_env('DB_NAME',    ''));
define('DB_USER',    _cfg_env('DB_USER',    ''));
define('DB_PASS',    _cfg_env('DB_PASS',    ''));
define('DB_CHARSET', _cfg_env('DB_CHARSET', 'utf8mb4'));
$dbSslEnv = strtolower(_cfg_env('DB_SSL', 'false'));
define('DB_SSL', $dbSslEnv === 'true' || $dbSslEnv === '1' || strpos(DB_HOST, 'tidbcloud.com') !== false);

// ── Session / Auth ───────────────────────────────────────────────────────────
define('SESSION_TIMEOUT',    (int)_cfg_env('SESSION_TIMEOUT',    '3600'));
define('MAX_LOGIN_ATTEMPTS', (int)_cfg_env('MAX_LOGIN_ATTEMPTS', '5'));
define('LOGIN_COOLDOWN',     (int)_cfg_env('LOGIN_COOLDOWN',     '300'));
define('CSRF_TOKEN_TTL',     (int)_cfg_env('CSRF_TOKEN_TTL',     '7200'));
define('ADMIN_USERNAME',     _cfg_env('ADMIN_USERNAME', 'admin'));
define('ADMIN_PASSWORD_HASH',_cfg_env('ADMIN_PASSWORD_HASH', ''));

// ── API / License ────────────────────────────────────────────────────────────
define('MAX_CLOCK_SKEW',           (int)_cfg_env('MAX_CLOCK_SKEW',           '120'));
define('DEFAULT_MAX_DEVICES',      (int)_cfg_env('DEFAULT_MAX_DEVICES',      '2'));
define('DEFAULT_LICENSE_VALIDITY', (int)_cfg_env('DEFAULT_LICENSE_VALIDITY', '365'));
define('DEVICE_ID_MAX_LENGTH',     (int)_cfg_env('DEVICE_ID_MAX_LENGTH',     '128'));
define('NONCE_MAX_LENGTH',         (int)_cfg_env('NONCE_MAX_LENGTH',         '64'));
define('VERIFY_RATE_LIMIT_MAX',    (int)_cfg_env('VERIFY_RATE_LIMIT_MAX',    '30'));
define('VERIFY_RATE_LIMIT_WINDOW', (int)_cfg_env('VERIFY_RATE_LIMIT_WINDOW', '60'));
define('TRUST_PROXY',              _cfg_env('TRUST_PROXY', '0') === '1');
define('API_ALLOWED_ORIGINS',      _cfg_env('API_ALLOWED_ORIGINS', '*'));

// ── Crypto keys ──────────────────────────────────────────────────────────────
define('PRIVATE_KEY',       _cfg_env('PRIVATE_KEY', ''));
define('ED25519_PUBLIC_KEY', _cfg_env('ED25519_PUBLIC_KEY', ''));

// ── App IDs ──────────────────────────────────────────────────────────────────
define('APP_ID_89TTS',                     '89tts');

// ── License profiles ─────────────────────────────────────────────────────────
define('DEFAULT_LICENSE_PROFILE',          'standard');

// ── Paths ────────────────────────────────────────────────────────────────────
define('APP_ROOT',  dirname(__DIR__));
define('LOGS_DIR',  APP_ROOT . '/logs');

// ── Realtime Sync ────────────────────────────────────────────────────────────
define('SYNC_SECRET',     _cfg_env('SYNC_SECRET', '89globalmedia_sync_secret_2026'));
define('VERCEL_SYNC_URL', _cfg_env('VERCEL_SYNC_URL', ''));
