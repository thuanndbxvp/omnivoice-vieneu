<?php
/**
 * POST /api/license/activate
 * Activate a license key and return a signed compact token.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap/app.php';

use Services\SecurityService;
use Services\LicenseService;
use Services\TokenService;

SecurityService::emitCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$ip = SecurityService::clientIp();

// ── Rate limit ────────────────────────────────────────────────────────────────
[$globalOk, $globalRetry] = SecurityService::rateLimitAllow(
    'activate-ip|' . $ip,
    VERIFY_RATE_LIMIT_MAX * 2,
    VERIFY_RATE_LIMIT_WINDOW
);
if (!$globalOk) {
    http_response_code(429);
    header('Retry-After: ' . $globalRetry);
    echo json_encode(['ok' => false, 'error' => 'Rate limit exceeded']);
    exit;
}

// ── Parse body ─────────────────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
if (strlen((string) $raw) > 65536) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Request too large']);
    exit;
}
$body = json_decode((string) $raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$licenseKey = strtoupper(trim((string) ($body['licenseKey'] ?? $body['license_key'] ?? '')));
$machineId  = trim((string) (
    $body['machineId']
    ?? $body['machine_id']
    ?? $body['deviceId']
    ?? $body['device_id']
    ?? ''
));
$appId      = strtolower(trim((string) ($body['appId']     ?? $body['app_id']     ?? '')));
$appVersion = trim((string) ($body['appVersion'] ?? $body['app_version'] ?? ''));

// Validate
if (!preg_match('/^[A-Z0-9]{4}(?:-[A-Z0-9]{4}){3}$/', $licenseKey)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid license key format']);
    exit;
}
if ($machineId === '' || strlen($machineId) < 8 || strlen($machineId) > DEVICE_ID_MAX_LENGTH) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid machineId']);
    exit;
}
if ($appId !== '' && !preg_match('/^[a-z0-9._-]{2,64}$/', $appId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid appId']);
    exit;
}

$deviceSlot = LicenseService::resolveDeviceSlotId($machineId, $machineId);
$nonce      = bin2hex(random_bytes(16));
$timestamp  = time();

// Per-license rate limit
[$perOk, $perRetry] = SecurityService::rateLimitAllow(
    'activate|' . $ip . '|' . $licenseKey . '|' . $deviceSlot,
    VERIFY_RATE_LIMIT_MAX,
    VERIFY_RATE_LIMIT_WINDOW
);
if (!$perOk) {
    http_response_code(429);
    header('Retry-After: ' . $perRetry);
    echo json_encode(['ok' => false, 'error' => 'Rate limit exceeded']);
    exit;
}

// ── Verify ────────────────────────────────────────────────────────────────────
try {
    $result = LicenseService::verify($licenseKey, $machineId, $nonce, $timestamp, $appId, $appVersion, $machineId);
} catch (\Exception $e) {
    $errId = bin2hex(random_bytes(4));
    error_log("activate verify exception id={$errId}: " . $e->getMessage());
    LicenseService::recordApiRequest('/api/license/activate', $licenseKey, $machineId, $machineId, $ip, $appId, $appVersion, 500, 'server_error', 'exception_' . $errId);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error. Ref: ' . $errId]);
    exit;
}

if (!$result['valid']) {
    LicenseService::recordApiRequest('/api/license/activate', $licenseKey, $machineId, $machineId, $ip, $appId, $appVersion, 400, 'invalid', $result['message'] ?? '');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $result['message'] ?? 'Invalid']);
    exit;
}

// ── Build token ───────────────────────────────────────────────────────────────
try {
    $kid = substr(hash('sha256', $licenseKey), 0, 16);
    $payload = [
        'kid'             => $kid,
        'license_key'     => $licenseKey,
        'username'        => $result['username'],
        'email'           => $result['email'],
        'app_id'          => $result['app_id'],
        'device_code'     => $result['device_slot'],
        'session_version' => $result['session_version'],
        'iat'             => gmdate('c'),
        'exp_utc'         => gmdate('c', $result['expiry']),
        'last_verified'   => gmdate('c'),
        'offline_grace_days' => 7,
    ];
    $token = TokenService::signCompact($payload);

    LicenseService::recordApiRequest('/api/license/activate', $licenseKey, $machineId, $machineId, $ip, $result['app_id'], $appVersion, 200, 'ok');
    echo json_encode([
        'ok'             => true,
        'token'          => $token,
        'kid'            => $kid,
        'expiresAt'      => gmdate('c', $result['expiry']),
        'machineId'      => $result['device_slot'],
        'appId'          => $result['app_id'],
        'sessionVersion' => $result['session_version'],
        'daysLeft'       => $result['days_left'],
    ]);
} catch (\Exception $e) {
    $errId = bin2hex(random_bytes(4));
    error_log("activate sign exception id={$errId}: " . $e->getMessage());
    LicenseService::recordApiRequest('/api/license/activate', $licenseKey, $machineId, $machineId, $ip, $appId, $appVersion, 500, 'server_error', 'sign_' . $errId);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Token generation failed. Ref: ' . $errId]);
}
