<?php
/**
 * LicenseService — core license verification logic.
 * Orchestrates Models and Services; no HTTP concerns here.
 */

declare(strict_types=1);

namespace Services;

use Database;
use Models\AppModel;
use Models\AppAliasModel;
use Models\LicenseModel;

class LicenseService {

    // ── App ID normalisation ──────────────────────────────────────────────────

    public static function resolveAppId(string $appId, string $appVersion = ''): string {
        $id      = strtolower(trim($appId));
        $version = strtolower(trim($appVersion));

        // 1. Direct registered app
        if ($id !== '' && array_key_exists($id, AppModel::map(true))) return $id;

        // 2. Exact or partial match for 89tts
        if ($id === '89tts' || str_contains($id, '89tts') || str_contains($version, '89tts')) {
            return APP_ID_89TTS;
        }

        // 3. Alias lookup
        if ($id !== '') {
            $resolved = AppAliasModel::resolve($id);
            if ($resolved !== null) return $resolved;
        }

        // 4. Default to 89tts
        return APP_ID_89TTS;
    }

    // ── Device slot ID ────────────────────────────────────────────────────────

    public static function resolveDeviceSlotId(string $deviceId, string $machineId = ''): string {
        $machine = self::normalizeDeviceId($machineId);
        if ($machine !== '') return $machine;
        $legacy  = self::normalizeDeviceId($deviceId);
        if ($legacy  !== '') return $legacy;
        return strtoupper(substr(trim($deviceId), 0, DEVICE_ID_MAX_LENGTH));
    }

    private static function normalizeDeviceId(string $value): string {
        $raw = strtoupper(trim($value));
        if ($raw === '') return '';
        $normalized = preg_replace('/[^A-Z0-9]/', '', $raw) ?? '';
        $len        = strlen($normalized);
        return ($len >= 8 && $len <= DEVICE_ID_MAX_LENGTH) ? $normalized : '';
    }

    // ── Core verify ───────────────────────────────────────────────────────────

    /**
     * Verify a license.
     * Returns response array with 'valid' key.
     */
    public static function verify(
        string $licenseKey, string $deviceId, string $nonce,
        int $timestamp, string $appId = '', string $appVersion = '', string $machineId = ''
    ): array {
        $pdo         = Database::getInstance()->getPdo();
        $rawDeviceId = trim($deviceId);
        $deviceSlot  = self::resolveDeviceSlotId($rawDeviceId, $machineId);
        $serverTime  = time();

        // Clock skew
        if (abs($serverTime - $timestamp) > MAX_CLOCK_SKEW) {
            return self::invalid('Request expired or clock skew too large', $nonce, $serverTime);
        }

        $resolvedAppId = self::resolveAppId($appId, $appVersion);

        // Fetch license
        $stmt = $pdo->prepare('SELECT *, customer_name AS username FROM licenses WHERE license_key = ?');
        $stmt->execute([$licenseKey]);
        $license = $stmt->fetch();
        if (!$license) {
            self::log($licenseKey, $deviceSlot, 'verify_not_found', $appId);
            return self::invalid('License not found', $nonce, $serverTime);
        }

        // App scope
        $allowedApps = LicenseModel::getAllowedApps($license);
        if (!in_array($resolvedAppId, $allowedApps, true)) {
            self::log($licenseKey, $deviceSlot, 'verify_denied_app_scope', $resolvedAppId);
            return self::invalid('License is not valid for this application', $nonce, $serverTime);
        }

        // Revoked
        if ((int) $license['revoked']) {
            self::log($licenseKey, $deviceSlot, 'verify_revoked', $resolvedAppId);
            return self::invalid('License has been revoked', $nonce, $serverTime);
        }

        // Deferred validity start
        if (
            Database::hasColumn('licenses', 'start_on_first_activation') &&
            Database::hasColumn('licenses', 'first_activated_at') &&
            (int) ($license['start_on_first_activation'] ?? 0) === 1 &&
            empty($license['first_activated_at'])
        ) {
            $days = Database::hasColumn('licenses', 'validity_days')
                ? max(1, (int) ($license['validity_days'] ?? DEFAULT_LICENSE_VALIDITY))
                : DEFAULT_LICENSE_VALIDITY;
            $pdo->prepare(
                'UPDATE licenses SET first_activated_at = NOW(),
                 expiry_date = DATE_ADD(NOW(), INTERVAL ? DAY), updated_at = NOW()
                 WHERE id = ? AND first_activated_at IS NULL'
            )->execute([$days, (int) $license['id']]);
            $stmt = $pdo->prepare('SELECT *, customer_name AS username FROM licenses WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $license['id']]);
            $fresh = $stmt->fetch();
            if ($fresh) $license = $fresh;
        }

        // Expiry
        $expiry = (int) strtotime((string) $license['expiry_date']);
        if ($expiry < $serverTime) {
            self::log($licenseKey, $deviceSlot, 'verify_expired', $resolvedAppId);
            return self::invalid('License expired', $nonce, $serverTime);
        }

        // Device tracking check
        $tracking = AppModel::deviceTracking($resolvedAppId);
        if ($tracking !== 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM devices WHERE license_id = ?');
            $stmt->execute([(int) $license['id']]);
            $deviceCount = (int) $stmt->fetchColumn();

            // Lookup existing device
            $lookupIds = array_values(array_unique(array_filter([
                $deviceSlot,
                $rawDeviceId !== '' ? strtoupper($rawDeviceId) : '',
                $machineId   !== '' ? strtoupper(self::resolveDeviceSlotId('', $machineId)) : '',
            ], fn($v) => $v !== '')));

            if (count($lookupIds) === 1) {
                $stmt = $pdo->prepare('SELECT * FROM devices WHERE license_id = ? AND device_id = ? LIMIT 1');
                $stmt->execute([(int) $license['id'], $lookupIds[0]]);
            } else {
                $ph   = implode(', ', array_fill(0, count($lookupIds), '?'));
                $stmt = $pdo->prepare("SELECT * FROM devices WHERE license_id = ? AND device_id IN ({$ph}) ORDER BY id ASC LIMIT 1");
                $stmt->execute(array_merge([(int) $license['id']], $lookupIds));
            }
            $existingDevice = $stmt->fetch();

            if (!$existingDevice && $deviceCount >= (int) $license['max_devices']) {
                self::log($licenseKey, $deviceSlot, 'verify_device_limit', $resolvedAppId);
                return self::invalid('Maximum devices reached (' . $license['max_devices'] . ')', $nonce, $serverTime);
            }

            // Register / update device
            if ($existingDevice) {
                $existingId      = (int) $existingDevice['id'];
                $existingSlotId  = strtoupper((string) $existingDevice['device_id']);
                if ($existingSlotId !== strtoupper($deviceSlot)) {
                    $stmt = $pdo->prepare('SELECT * FROM devices WHERE license_id = ? AND device_id = ? LIMIT 1');
                    $stmt->execute([(int) $license['id'], $deviceSlot]);
                    $canonical = $stmt->fetch();
                    if ($canonical && (int) $canonical['id'] !== $existingId) {
                        $pdo->prepare('UPDATE devices SET last_seen = NOW() WHERE id = ?')->execute([(int) $canonical['id']]);
                        $pdo->prepare('DELETE FROM devices WHERE id = ?')->execute([$existingId]);
                    } else {
                        $pdo->prepare('UPDATE devices SET device_id = ?, last_seen = NOW() WHERE id = ?')->execute([$deviceSlot, $existingId]);
                    }
                } else {
                    $pdo->prepare('UPDATE devices SET last_seen = NOW() WHERE id = ?')->execute([$existingId]);
                }
            } else {
                $pdo->prepare('INSERT INTO devices (license_id, device_id, activated_at, last_seen) VALUES (?, ?, NOW(), NOW())')
                    ->execute([(int) $license['id'], $deviceSlot]);
            }
        }

        $daysLeft      = (int) ceil(($expiry - $serverTime) / 86400);
        $sessionVersion = LicenseModel::sessionVersion($license);
        $licenseProfile = LicenseModel::resolveProfile(LicenseModel::getAllowedApps($license));

        self::log($licenseKey, $deviceSlot, 'verify', $resolvedAppId);

        return [
            'valid'            => true,
            'license_key'      => $licenseKey,
            'username'         => $license['username'],
            'email'            => $license['email'],
            'expiry'           => $expiry,
            'days_left'        => $daysLeft,
            'max_devices'      => (int) $license['max_devices'],
            'device_slot'      => $deviceSlot,
            'app_id'           => $resolvedAppId,
            'app_profile'      => $licenseProfile,
            'allowed_apps'     => $allowedApps,
            'session_version'  => $sessionVersion,
            'nonce'            => $nonce,
            'server_time'      => $serverTime,
        ];
    }

    // ── API request logging ───────────────────────────────────────────────────

    public static function recordApiRequest(
        string $endpoint, string $licenseKey, string $machineId, string $deviceId,
        string $ip, string $appId, string $appVersion,
        int $httpStatus, string $outcome, string $errorCode = '', array $details = []
    ): void {
        try {
            self::ensureRequestMetricsSchema();
            $pdo    = Database::getInstance()->getPdo();
            $lk     = strtoupper(substr(trim($licenseKey), 0, 32));
            $action = match (true) {
                str_contains($endpoint, 'activate')     => 'activate',
                str_contains($endpoint, 'license/verify') => 'token_verify',
                default                                  => 'verify',
            };
            $detailsJson = $details ? json_encode($details) : null;

            // api_request_logs
            if (Database::hasColumn('api_request_logs', 'license_key')) {
                $pdo->prepare(
                    'INSERT INTO api_request_logs
                     (endpoint, action, license_key, machine_id, device_id, ip_address,
                      app_id, app_version, http_status, outcome, error_code, details_json, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
                )->execute([
                    substr($endpoint, 0, 60), $action, $lk,
                    substr(trim($machineId), 0, 128), substr(trim($deviceId), 0, 128), substr($ip, 0, 45),
                    substr(strtolower($appId), 0, 100), substr($appVersion, 0, 64),
                    $httpStatus, $outcome, $errorCode, $detailsJson,
                ]);
            }

            // license_request_counters
            if ($lk === '' || !Database::hasColumn('license_request_counters', 'license_key')) return;
            $isSuccess = in_array($outcome, ['valid', 'ok', 'active'], true) ? 1 : 0;
            $pdo->prepare(
                "INSERT INTO license_request_counters
                 (license_key, total_requests, {$action}_requests, success_requests, failed_requests,
                  last_request_at, last_ip, last_machine_id, last_app_id)
                 VALUES (?, 1, 1, ?, ?, NOW(), ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   total_requests = total_requests + 1,
                   {$action}_requests = {$action}_requests + 1,
                   success_requests = success_requests + ?,
                   failed_requests  = failed_requests + ?,
                   last_request_at  = NOW(),
                   last_ip          = ?,
                   last_machine_id  = ?,
                   last_app_id      = ?"
            )->execute([
                $lk, $isSuccess, 1 - $isSuccess,
                substr($ip, 0, 45), substr(trim($machineId), 0, 128), substr(strtolower($appId), 0, 100),
                $isSuccess, 1 - $isSuccess,
                substr($ip, 0, 45), substr(trim($machineId), 0, 128), substr(strtolower($appId), 0, 100),
            ]);
        } catch (\Exception $e) {
            error_log('recordApiRequest failed: ' . $e->getMessage());
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function invalid(string $message, string $nonce, int $serverTime): array {
        return ['valid' => false, 'message' => $message, 'nonce' => $nonce, 'server_time' => $serverTime];
    }

    private static function log(string $licenseKey, string $deviceId, string $action, string $appId = ''): void {
        try {
            $pdo    = Database::getInstance()->getPdo();
            $ip     = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $detail = $appId !== '' ? json_encode(['app_id' => $appId]) : null;
            if (Database::hasColumn('audit_log', 'details')) {
                $pdo->prepare('INSERT INTO audit_log (license_key, device_id, action, ip_address, details, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
                    ->execute([$licenseKey, $deviceId, $action, $ip, $detail]);
            } else {
                $pdo->prepare('INSERT INTO audit_log (license_key, device_id, action, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())')
                    ->execute([$licenseKey, $deviceId, $action, $ip]);
            }
        } catch (\Exception $e) {
            error_log('LicenseService::log failed: ' . $e->getMessage());
        }
    }

    private static function ensureRequestMetricsSchema(): void {
        static $done = false;
        if ($done) return;
        $pdo = Database::getInstance()->getPdo();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS license_request_counters (
                license_key VARCHAR(32) NOT NULL PRIMARY KEY,
                total_requests INT NOT NULL DEFAULT 0,
                verify_requests INT NOT NULL DEFAULT 0,
                activate_requests INT NOT NULL DEFAULT 0,
                token_verify_requests INT NOT NULL DEFAULT 0,
                success_requests INT NOT NULL DEFAULT 0,
                failed_requests INT NOT NULL DEFAULT 0,
                last_request_at DATETIME DEFAULT NULL,
                last_ip VARCHAR(45) DEFAULT NULL,
                last_machine_id VARCHAR(128) DEFAULT NULL,
                last_app_id VARCHAR(100) DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS api_request_logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                endpoint VARCHAR(60),
                action VARCHAR(20),
                license_key VARCHAR(32),
                machine_id VARCHAR(128),
                device_id VARCHAR(128),
                ip_address VARCHAR(45),
                app_id VARCHAR(100),
                app_version VARCHAR(64),
                http_status SMALLINT,
                outcome VARCHAR(20),
                error_code VARCHAR(50),
                details_json TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_lk (license_key),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $done = true;
    }
}
