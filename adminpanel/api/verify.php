<?php
/**
 * POST /api/verify
 * Direct license verification (no compact token).
 * Returns signed JSON: {valid, message?, license_key?, username?, expiry?, ...}
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';

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
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['valid' => false, 'message' => 'Method not allowed']);
    exit;
}

$ip = SecurityService::clientIp();

// ── Logging state ───────────────────────────────────────────────────────────
$logLicenseKey = '';
$logMachineId  = '';
$logDeviceId   = '';
$logAppId      = '';
$logAppVersion = '';

function respondVerify(array $payload, int $status, string $outcome, string $errorCode = ''): never {
    global $ip, $logLicenseKey, $logMachineId, $logDeviceId, $logAppId, $logAppVersion;

    LicenseService::recordApiRequest(
        '/api/verify',
        $logLicenseKey, $logMachineId, $logDeviceId,
        $ip, $logAppId, $logAppVersion,
        $status, $outcome, $errorCode
    );

    http_response_code($status);
    echo json_encode(TokenService::signResponse($payload));
    exit;
}

function verifyError(string $message, string $nonce = '', string $errorCode = 'validation_error'): never {
    respondVerify([
        'valid'       => false,
        'message'     => $message,
        'nonce'       => $nonce,
        'server_time' => time(),
    ], 200, 'invalid', $errorCode);
}

// ── IP-level rate limit ─────────────────────────────────────────────────────
[$ipOk, $ipRetry] = SecurityService::rateLimitAllow(
    'verify-ip|' . $ip,
    VERIFY_RATE_LIMIT_MAX * 4,
    VERIFY_RATE_LIMIT_WINDOW
);
if (!$ipOk) {
    http_response_code(429);
    header('Retry-After: ' . $ipRetry);
    echo json_encode(TokenService::signResponse([
        'valid'       => false,
        'message'     => 'Too many requests. Retry in ' . $ipRetry . 's.',
        'nonce'       => '',
        'server_time' => time(),
    ]));
    exit;
}

// ── Parse body ──────────────────────────────────────────────────────────────
$rawBody = file_get_contents('php://input');
if ($rawBody === false || strlen($rawBody) > 65536) {
    verifyError('Invalid request body', '', 'invalid_request_body');
}
$input = json_decode((string) $rawBody, true);
if (!is_array($input)) {
    verifyError('Invalid JSON', '', 'invalid_json');
}

// ── Extract fields ──────────────────────────────────────────────────────────
$licenseKey = strtoupper(trim((string) ($input['license_key'] ?? $input['licenseKey'] ?? '')));
$deviceId   = trim((string) ($input['device_id']   ?? $input['deviceId'] ?? ''));
$machineId  = trim((string) ($input['machine_id']  ?? $input['machineId'] ?? $deviceId));
$nonce      = trim((string) ($input['nonce']       ?? ''));
$timestamp  = (int) ($input['timestamp'] ?? 0);
$appId      = trim((string) ($input['app_id']      ?? $input['appId'] ?? ''));
$appVersion = trim((string) ($input['app_version'] ?? $input['appVersion'] ?? ''));

$deviceSlot = LicenseService::resolveDeviceSlotId($deviceId, $machineId);

$logLicenseKey = $licenseKey;
$logMachineId  = $machineId;
$logDeviceId   = $deviceId;
$logAppId      = $appId;
$logAppVersion = $appVersion;

// ── Validate ────────────────────────────────────────────────────────────────
if (!preg_match('/^[A-Z0-9]{4}(?:-[A-Z0-9]{4}){3}$/', $licenseKey)) {
    verifyError('Invalid license key format', $nonce, 'invalid_license_key');
}
if (strlen($deviceId) < 8 || strlen($deviceId) > DEVICE_ID_MAX_LENGTH) {
    verifyError('Invalid device_id', $nonce, 'invalid_device_id');
}
if ($machineId !== '' && (strlen($machineId) < 8 || strlen($machineId) > DEVICE_ID_MAX_LENGTH)) {
    verifyError('Invalid machine_id', $nonce, 'invalid_machine_id');
}
if (!preg_match('/^[A-Za-z0-9_-]{8,' . NONCE_MAX_LENGTH . '}$/', $nonce)) {
    verifyError('Invalid nonce', $nonce, 'invalid_nonce');
}
if ($appId !== '' && !preg_match('/^[A-Za-z0-9._-]{2,64}$/', $appId)) {
    verifyError('Invalid app_id', $nonce, 'invalid_app_id');
}
if ($appVersion !== '' && strlen($appVersion) > 64) {
    verifyError('Invalid app_version', $nonce, 'invalid_app_version');
}
if ($timestamp <= 0) {
    verifyError('Invalid timestamp', $nonce, 'invalid_timestamp');
}

// ── Per-license/device rate limit ───────────────────────────────────────────
[$perOk, $perRetry] = SecurityService::rateLimitAllow(
    'verify|' . $ip . '|' . $licenseKey . '|' . $deviceSlot,
    VERIFY_RATE_LIMIT_MAX,
    VERIFY_RATE_LIMIT_WINDOW
);
if (!$perOk) {
    verifyError('Too many attempts. Retry in ' . $perRetry . 's.', $nonce, 'rate_limit_license_device');
}

// ── Nonce replay prevention ─────────────────────────────────────────────────
if (!SecurityService::acceptNonce($licenseKey, $deviceSlot, $nonce)) {
    verifyError('Replay detected', $nonce, 'replay_detected');
}

// ── Verify ──────────────────────────────────────────────────────────────────
try {
    $result = LicenseService::verify(
        $licenseKey, $deviceId, $nonce, $timestamp,
        $appId, $appVersion, $machineId
    );

    // Strip internal-only field
    unset($result['session_version']);

    $outcome   = !empty($result['valid']) ? 'valid' : 'invalid';
    $errorCode = !empty($result['valid']) ? '' : (string) ($result['message'] ?? 'license_invalid');

    respondVerify($result, 200, $outcome, $errorCode);
} catch (\Exception $e) {
    $errId = bin2hex(random_bytes(8));
    error_log("api/verify exception id={$errId}: " . $e->getMessage());
    respondVerify([
        'valid'       => false,
        'message'     => 'Server error',
        'error_id'    => $errId,
        'server_time' => time(),
    ], 200, 'error', 'server_error_' . $errId);
}
