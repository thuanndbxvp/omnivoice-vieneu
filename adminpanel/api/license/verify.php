<?php
/**
 * POST /api/license/verify
 * Verify a previously issued compact token (offline/online check).
 * Returns {ok, active, reason?, expiresAt?}
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap/app.php';

use Services\SecurityService;
use Services\LicenseService;
use Services\TokenService;
use Models\LicenseModel;

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

// ── Rate limit ─────────────────────────────────────────────────────────────────
$warmupBody = json_decode((string) file_get_contents('php://input'), true);
$machineRaw = trim((string) (($warmupBody['machineId'] ?? $warmupBody['machine_id'] ?? $warmupBody['deviceId'] ?? $warmupBody['device_id'] ?? '')));
$slotRaw    = LicenseService::resolveDeviceSlotId($machineRaw, $machineRaw);

[$globalOk, $globalRetry] = SecurityService::rateLimitAllow(
    'token-verify-ip|' . $ip,
    VERIFY_RATE_LIMIT_MAX * 4,
    VERIFY_RATE_LIMIT_WINDOW
);
if (!$globalOk) {
    http_response_code(429);
    header('Retry-After: ' . $globalRetry);
    echo json_encode(['ok' => false, 'error' => 'Rate limit exceeded']);
    exit;
}

[$perOk, $perRetry] = SecurityService::rateLimitAllow(
    'token-verify|' . $ip . '|' . $slotRaw,
    VERIFY_RATE_LIMIT_MAX * 2,
    VERIFY_RATE_LIMIT_WINDOW
);
if (!$perOk) {
    http_response_code(429);
    header('Retry-After: ' . $perRetry);
    echo json_encode(['ok' => false, 'error' => 'Rate limit exceeded']);
    exit;
}

// ── Parse body ─────────────────────────────────────────────────────────────────
// Re-read since we decoded above for rate limit key
$raw  = file_get_contents('php://input');
$body = json_decode((string) $raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$token      = trim((string) ($body['token']      ?? ''));
$machineId  = trim((string) ($body['machineId']  ?? $body['machine_id'] ?? $body['deviceId'] ?? $body['device_id'] ?? ''));
$appId      = strtolower(trim((string) ($body['appId']     ?? $body['app_id'] ?? '')));
$appVersion = trim((string) ($body['appVersion'] ?? $body['app_version'] ?? ''));

if ($token === '' || strlen($token) > 32768) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid token']);
    exit;
}
if ($machineId === '' || strlen($machineId) < 8 || strlen($machineId) > DEVICE_ID_MAX_LENGTH) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid machineId']);
    exit;
}

$deviceSlot = LicenseService::resolveDeviceSlotId($machineId, $machineId);

// ── Verify token signature ─────────────────────────────────────────────────────
try {
    $payload = TokenService::verifyCompact($token);
} catch (\Exception $e) {
    LicenseService::recordApiRequest('/api/license/verify', '', $machineId, $machineId, $ip, $appId, $appVersion, 400, 'invalid_token', 'bad_signature');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid token']);
    exit;
}

$licenseKey = strtoupper(trim((string) ($payload['license_key'] ?? '')));
if (!preg_match('/^[A-Z0-9]{4}(?:-[A-Z0-9]{4}){3}$/', $licenseKey)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid token payload']);
    exit;
}

// ── Check DB state ─────────────────────────────────────────────────────────────
try {
    $license = LicenseModel::findByKey($licenseKey);
    if (!$license) {
        LicenseService::recordApiRequest('/api/license/verify', $licenseKey, $machineId, $machineId, $ip, $appId, $appVersion, 200, 'inactive', 'license_not_found');
        echo json_encode(['ok' => true, 'active' => false, 'reason' => 'license_not_found']);
        exit;
    }

    // Session version check
    $tokenSessionVersion = (int) ($payload['session_version'] ?? 1);
    $dbSessionVersion    = LicenseModel::sessionVersion($license);
    if ($tokenSessionVersion !== $dbSessionVersion) {
        LicenseService::recordApiRequest('/api/license/verify', $licenseKey, $machineId, $machineId, $ip, $appId, $appVersion, 200, 'inactive', 'session_reset');
        echo json_encode(['ok' => true, 'active' => false, 'reason' => 'session_reset_required']);
        exit;
    }

    // Device match
    $tokenDevice = strtoupper(trim((string) ($payload['device_code'] ?? '')));
    if ($tokenDevice !== strtoupper($deviceSlot)) {
        LicenseService::recordApiRequest('/api/license/verify', $licenseKey, $machineId, $machineId, $ip, $appId, $appVersion, 200, 'inactive', 'device_mismatch');
        echo json_encode(['ok' => true, 'active' => false, 'reason' => 'device_mismatch']);
        exit;
    }

    // Full verify
    $nonce  = bin2hex(random_bytes(16));
    $result = LicenseService::verify($licenseKey, $machineId, $nonce, time(), $appId, $appVersion, $machineId);

    if ($result['valid']) {
        LicenseService::recordApiRequest('/api/license/verify', $licenseKey, $machineId, $machineId, $ip, $result['app_id'], $appVersion, 200, 'active');
        echo json_encode([
            'ok'        => true,
            'active'    => true,
            'expiresAt' => gmdate('c', $result['expiry']),
            'daysLeft'  => $result['days_left'],
        ]);
    } else {
        LicenseService::recordApiRequest('/api/license/verify', $licenseKey, $machineId, $machineId, $ip, $appId, $appVersion, 200, 'inactive', $result['message'] ?? '');
        echo json_encode(['ok' => true, 'active' => false, 'reason' => $result['message'] ?? 'invalid']);
    }
} catch (\Exception $e) {
    $errId = bin2hex(random_bytes(4));
    error_log("token-verify exception id={$errId}: " . $e->getMessage());
    LicenseService::recordApiRequest('/api/license/verify', $licenseKey ?? '', $machineId, $machineId, $ip, $appId, $appVersion, 500, 'server_error', 'exception_' . $errId);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error. Ref: ' . $errId]);
}
