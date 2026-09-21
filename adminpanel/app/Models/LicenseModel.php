<?php
/**
 * LicenseModel — quản lý bảng licenses + devices.
 */

declare(strict_types=1);

namespace Models;

use Database;

class LicenseModel {

    // ── Queries ───────────────────────────────────────────────────────────────

    /**
     * Fetch all licenses with optional search + status filter.
     * Returns array of license rows, each with allowed_apps_list attached.
     */
    public static function all(string $search = '', string $status = ''): array {
        $pdo  = Database::getInstance()->getPdo();
        $sql  = 'SELECT l.*, (SELECT COUNT(*) FROM devices d WHERE d.license_id = l.id) AS device_count';
        $params = [];

        if (Database::hasColumn('licenses', 'agency_id')) {
            $sql .= ', a.name AS agency_name, a.code AS agency_code';
        }
        $sql .= ' FROM licenses l';
        if (Database::hasColumn('licenses', 'agency_id')) {
            $sql .= ' LEFT JOIN agencies a ON a.id = l.agency_id';
        }

        $where = [];
        if ($search !== '') {
            $like = '%' . $search . '%';
            $searchCols = ['l.license_key', 'l.customer_name', 'l.email'];
            if (Database::hasColumn('licenses', 'admin_note'))  $searchCols[] = 'l.admin_note';
            if (Database::hasColumn('licenses', 'agency_id'))   $searchCols[] = 'a.name';
            $orParts = [];
            foreach ($searchCols as $col) {
                $orParts[] = "{$col} LIKE ?";
                $params[]  = $like;
            }
            $where[] = '(' . implode(' OR ', $orParts) . ')';
        }
        $now = date('Y-m-d H:i:s');
        switch ($status) {
            case 'active':
                $where[] = 'l.revoked = 0';
                $where[] = "l.expiry_date > '{$now}'";
                break;
            case 'expired':
                $where[] = "l.expiry_date <= '{$now}'";
                break;
            case 'revoked':
                $where[] = 'l.revoked = 1';
                break;
        }
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY l.created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map([self::class, 'attachAllowedApps'], $rows);
    }

    /** Fetch single license by internal id (with devices). */
    public static function find(int $id): ?array {
        $pdo  = Database::getInstance()->getPdo();
        $sql  = 'SELECT l.*';
        if (Database::hasColumn('licenses', 'agency_id')) {
            $sql .= ', a.name AS agency_name';
        }
        $sql .= ' FROM licenses l';
        if (Database::hasColumn('licenses', 'agency_id')) {
            $sql .= ' LEFT JOIN agencies a ON a.id = l.agency_id';
        }
        $sql .= ' WHERE l.id = ? LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        $row  = $stmt->fetch();
        if (!$row) return null;

        $row = self::attachAllowedApps($row);

        // Attach devices
        $stmt = $pdo->prepare('SELECT * FROM devices WHERE license_id = ? ORDER BY activated_at ASC');
        $stmt->execute([$id]);
        $row['devices'] = $stmt->fetchAll();

        return $row;
    }

    /**
     * Fetch multiple licenses by IDs in a single query (avoids N+1).
     * Returns array of license rows with allowed_apps_list attached.
     */
    public static function findByIds(array $ids): array {
        if (empty($ids)) return [];
        $ids  = array_values(array_unique(array_map('intval', $ids)));
        $ids  = array_filter($ids, fn($id) => $id > 0);
        if (empty($ids)) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo = Database::getInstance()->getPdo();
        $sql = 'SELECT l.*';
        if (Database::hasColumn('licenses', 'agency_id')) {
            $sql .= ', a.name AS agency_name, a.code AS agency_code';
        }
        $sql .= ' FROM licenses l';
        if (Database::hasColumn('licenses', 'agency_id')) {
            $sql .= ' LEFT JOIN agencies a ON a.id = l.agency_id';
        }
        $sql .= " WHERE l.id IN ({$placeholders}) ORDER BY l.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($ids));
        return array_map([self::class, 'attachAllowedApps'], $stmt->fetchAll());
    }

    /**
     * Return all license IDs matching search + status filter.
     * Used for "select all in DB" cross-page bulk selection.
     */
    public static function allIds(string $search = '', string $status = ''): array {
        $pdo    = Database::getInstance()->getPdo();
        $sql    = 'SELECT l.id FROM licenses l';
        $params = [];

        if (Database::hasColumn('licenses', 'agency_id')) {
            $sql .= ' LEFT JOIN agencies a ON a.id = l.agency_id';
        }

        $where = [];
        if ($search !== '') {
            $like = '%' . $search . '%';
            $searchCols = ['l.license_key', 'l.customer_name', 'l.email'];
            if (Database::hasColumn('licenses', 'admin_note')) $searchCols[] = 'l.admin_note';
            if (Database::hasColumn('licenses', 'agency_id'))  $searchCols[] = 'a.name';
            $orParts = [];
            foreach ($searchCols as $col) {
                $orParts[] = "{$col} LIKE ?";
                $params[]  = $like;
            }
            $where[] = '(' . implode(' OR ', $orParts) . ')';
        }
        $now = date('Y-m-d H:i:s');
        switch ($status) {
            case 'active':
                $where[] = 'l.revoked = 0';
                $where[] = "l.expiry_date > '{$now}'";
                break;
            case 'expired':
                $where[] = "l.expiry_date <= '{$now}'";
                break;
            case 'revoked':
                $where[] = 'l.revoked = 1';
                break;
        }
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY l.created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id');
    }

    /** Fetch single license by license_key. */
    public static function findByKey(string $key): ?array {
        $key = strtoupper(trim($key));
        if ($key === '') return null;
        $stmt = Database::getInstance()->getPdo()
            ->prepare('SELECT * FROM licenses WHERE license_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? self::attachAllowedApps($row) : null;
    }

    // ── Mutations ─────────────────────────────────────────────────────────────

    public static function create(
        string $licenseKey, string $username, string $email = '',
        int $validityDays = DEFAULT_LICENSE_VALIDITY, int $maxDevices = DEFAULT_MAX_DEVICES,
        ?array $allowedApps = null, string $adminNote = '',
        ?int $agencyId = null, bool $startNow = true
    ): int {
        $pdo = Database::getInstance()->getPdo();

        $validityDays = max(1, min(3650, $validityDays));
        $maxDevices   = max(1, $maxDevices);
        $email        = trim($email);
        $adminNote    = mb_substr(trim($adminNote), 0, 2000);
        $allowedApps  = self::normalizeAllowedApps($allowedApps ?? []);
        $appProfile   = self::resolveProfile($allowedApps);

        if ($startNow) {
            $expiry           = date('Y-m-d H:i:s', strtotime("+{$validityDays} days"));
            $firstActivatedAt = date('Y-m-d H:i:s');
        } else {
            $expiry           = '2099-12-31 23:59:59';
            $firstActivatedAt = null;
        }

        // Build dynamic column list
        $cols   = ['license_key', 'customer_name', 'email', 'expiry_date', 'max_devices', 'created_at'];
        $vals   = [$licenseKey, $username, $email, $expiry, $maxDevices, date('Y-m-d H:i:s')];
        $places = ['?', '?', '?', '?', '?', '?'];

        self::appendIfExists($pdo, 'app_profile',            $appProfile,   $cols, $vals, $places);
        self::appendIfExists($pdo, 'allowed_apps',           json_encode($allowedApps), $cols, $vals, $places);
        self::appendIfExists($pdo, 'admin_note',             $adminNote,    $cols, $vals, $places);
        self::appendIfExists($pdo, 'session_version',        1,             $cols, $vals, $places);
        self::appendIfExists($pdo, 'validity_days',          $validityDays, $cols, $vals, $places);
        self::appendIfExists($pdo, 'start_on_first_activation', $startNow ? 0 : 1, $cols, $vals, $places);
        self::appendIfExists($pdo, 'first_activated_at',     $firstActivatedAt, $cols, $vals, $places);

        if ($agencyId !== null && $agencyId > 0 && Database::hasColumn('licenses', 'agency_id')) {
            $cols[]   = 'agency_id';
            $vals[]   = $agencyId;
            $places[] = '?';
        }

        $sql  = 'INSERT INTO licenses (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $places) . ')';
        $pdo->prepare($sql)->execute($vals);
        $newId = (int) $pdo->lastInsertId();

        // Tự động đồng bộ sang Vercel (TiDB)
        if (class_exists('\\Services\\SyncService')) {
            $created = self::find($newId);
            if ($created) \Services\SyncService::syncOne($created);
        }

        return $newId;
    }

    public static function update(int $id, array $fields): void {
        if (empty($fields)) return;
        $sets   = [];
        $params = [];
        $allowed = [
            'customer_name', 'email', 'expiry_date', 'max_devices',
            'revoked', 'admin_note', 'allowed_apps', 'agency_id',
        ];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $fields)) continue;
            if (!Database::hasColumn('licenses', $col)) continue;
            $sets[]   = "{$col} = ?";
            $params[] = $fields[$col];
        }
        if (empty($sets)) return;
        $sets[]   = 'updated_at = NOW()';
        $params[] = $id;
        Database::getInstance()->getPdo()
            ->prepare('UPDATE licenses SET ' . implode(', ', $sets) . ' WHERE id = ?')
            ->execute($params);

        // Tự động đồng bộ sang Vercel (TiDB)
        if (class_exists('\\Services\\SyncService')) {
            $updated = self::find($id);
            if ($updated) \Services\SyncService::syncOne($updated);
        }
    }

    public static function revoke(int $id): bool {
        $stmt = Database::getInstance()->getPdo()
            ->prepare('UPDATE licenses SET revoked = 1 WHERE id = ?');
        $stmt->execute([$id]);
        $ok = $stmt->rowCount() > 0;
        if ($ok && class_exists('\\Services\\SyncService')) {
            $lic = self::find($id);
            if ($lic) \Services\SyncService::syncOne($lic);
        }
        return $ok;
    }

    public static function unrevoke(int $id): bool {
        $stmt = Database::getInstance()->getPdo()
            ->prepare('UPDATE licenses SET revoked = 0 WHERE id = ?');
        $stmt->execute([$id]);
        $ok = $stmt->rowCount() > 0;
        if ($ok && class_exists('\\Services\\SyncService')) {
            $lic = self::find($id);
            if ($lic) \Services\SyncService::syncOne($lic);
        }
        return $ok;
    }

    public static function delete(int $id, bool $requireRevoked = true): array {
        $pdo     = Database::getInstance()->getPdo();
        $license = self::find($id);
        if (!$license) return ['success' => false, 'reason' => 'not_found'];
        if ($requireRevoked && !(int) $license['revoked']) {
            return ['success' => false, 'reason' => 'not_revoked'];
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM licenses WHERE id = ?')->execute([$id]);
            $pdo->commit();

            // Xoá trên Vercel (TiDB)
            if (class_exists('\\Services\\SyncService')) {
                \Services\SyncService::deleteOne((string) $license['license_key']);
            }
        } catch (\Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'reason' => 'db_error'];
        }
        return ['success' => true, 'reason' => 'deleted'];
    }

    // ── Device management ─────────────────────────────────────────────────────

    public static function resetDevices(int $licenseId, bool $invalidateSessions = true): array {
        $pdo = Database::getInstance()->getPdo();
        $license = self::find($licenseId);
        if (!$license) return ['success' => false, 'reason' => 'not_found'];
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM devices WHERE license_id = ?');
            $stmt->execute([$licenseId]);
            $deleted = (int) $stmt->fetchColumn();
            $pdo->prepare('DELETE FROM devices WHERE license_id = ?')->execute([$licenseId]);
            if ($invalidateSessions && Database::hasColumn('licenses', 'session_version')) {
                $pdo->prepare(
                    'UPDATE licenses SET session_version = GREATEST(COALESCE(session_version,1),1) + 1 WHERE id = ?'
                )->execute([$licenseId]);
            }
            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'reason' => 'db_error'];
        }
        return ['success' => true, 'reason' => 'devices_reset', 'deleted' => $deleted];
    }

    public static function resetDevicesForCustomer(
        int $licenseId,
        string $email,
        string $ip,
        string $userAgent,
        int $cooldownSec = 86400
    ): array {
        self::ensureCustomerResetSchema();
        $pdo = Database::getInstance()->getPdo();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT id FROM licenses WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$licenseId]);
            if (!$stmt->fetchColumn()) {
                $pdo->rollBack();
                return ['success' => false, 'reason' => 'not_found'];
            }

            $stmt = $pdo->prepare(
                'SELECT created_at FROM license_device_resets WHERE license_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$licenseId]);
            $last = $stmt->fetchColumn();
            if ($last) {
                $lastTs = strtotime((string) $last);
                if ($lastTs !== false) {
                    $retryAfter = $cooldownSec - (time() - $lastTs);
                    if ($retryAfter > 0) {
                        $pdo->rollBack();
                        return ['success' => false, 'reason' => 'cooldown', 'retry_after' => $retryAfter];
                    }
                }
            }

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM devices WHERE license_id = ?');
            $stmt->execute([$licenseId]);
            $deleted = (int) $stmt->fetchColumn();

            $pdo->prepare('DELETE FROM devices WHERE license_id = ?')->execute([$licenseId]);
            if (Database::hasColumn('licenses', 'session_version')) {
                $pdo->prepare(
                    'UPDATE licenses SET session_version = GREATEST(COALESCE(session_version,1),1) + 1 WHERE id = ?'
                )->execute([$licenseId]);
            }

            $pdo->prepare(
                'INSERT INTO license_device_resets (license_id, email_snapshot, ip_address, user_agent, created_at)
                 VALUES (?, ?, ?, ?, NOW())'
            )->execute([
                $licenseId,
                substr(trim($email), 0, 255),
                substr(trim($ip), 0, 45),
                substr(trim($userAgent), 0, 255),
            ]);

            $pdo->commit();
            return ['success' => true, 'reason' => 'devices_reset', 'deleted' => $deleted];
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['success' => false, 'reason' => 'db_error'];
        }
    }

    // ── Counters / Logs ───────────────────────────────────────────────────────

    public static function getRequestCounter(string $licenseKey): ?array {
        if (!Database::hasColumn('license_request_counters', 'license_key')) return null;
        $stmt = Database::getInstance()->getPdo()
            ->prepare('SELECT * FROM license_request_counters WHERE license_key = ? LIMIT 1');
        $stmt->execute([strtoupper($licenseKey)]);
        return $stmt->fetch() ?: null;
    }

    public static function getRequestLogs(string $licenseKey, int $limit = 100): array {
        $limit = max(10, min(300, $limit));
        if (!Database::hasColumn('api_request_logs', 'license_key')) return [];
        $stmt = Database::getInstance()->getPdo()->prepare(
            'SELECT * FROM api_request_logs WHERE license_key = ? ORDER BY id DESC LIMIT ?'
        );
        $stmt->execute([strtoupper($licenseKey), $limit]);
        return $stmt->fetchAll();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function normalizeAllowedApps(array|string $apps): array {
        if (is_string($apps)) {
            $decoded = json_decode($apps, true);
            $apps = is_array($decoded) ? $decoded : preg_split('/[\s,;]+/', $apps, -1, PREG_SPLIT_NO_EMPTY);
        }
        $result = [];
        foreach ((array) $apps as $id) {
            $id = strtolower(trim((string) $id));
            if ($id !== '' && preg_match('/^[a-z0-9._-]{2,100}$/', $id)) $result[] = $id;
        }
        return $result ?: self::defaultAllowedApps();
    }

    public static function resolveProfile(array $allowedApps): string {
        return DEFAULT_LICENSE_PROFILE;
    }

    public static function sessionVersion(array $licenseRow): int {
        $v = (int) ($licenseRow['session_version'] ?? 0);
        return $v > 0 ? $v : 1;
    }

    public static function getAllowedApps(array $licenseRow): array {
        if (Database::hasColumn('licenses', 'allowed_apps') && !empty($licenseRow['allowed_apps'])) {
            $decoded = json_decode((string) $licenseRow['allowed_apps'], true);
            if (is_array($decoded) && $decoded) return $decoded;
        }
        return self::defaultAllowedApps();
    }

    private static function defaultAllowedApps(): array {
        return [
            APP_ID_89TTS,
        ];
    }

    private static function attachAllowedApps(array $row): array {
        $row['allowed_apps_list'] = self::getAllowedApps($row);
        return $row;
    }

    private static function ensureCustomerResetSchema(): void {
        static $done = false;
        if ($done) return;
        Database::getInstance()->getPdo()->exec(
            "CREATE TABLE IF NOT EXISTS license_device_resets (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                license_id BIGINT NOT NULL,
                email_snapshot VARCHAR(255) NOT NULL DEFAULT '',
                ip_address VARCHAR(45) NOT NULL DEFAULT '',
                user_agent VARCHAR(255) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                INDEX idx_ldr_license_created (license_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $done = true;
    }

    private static function appendIfExists(
        \PDO $pdo, string $col, mixed $val,
        array &$cols, array &$vals, array &$places
    ): void {
        if (Database::hasColumn('licenses', $col)) {
            $cols[]   = $col;
            $vals[]   = $val;
            $places[] = '?';
        }
    }
}
