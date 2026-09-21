<?php
/**
 * AppModel — quản lý bảng platform_apps.
 */

declare(strict_types=1);

namespace Models;

use Database;

class AppModel {
    private static bool $bootstrapped = false;

    // ── Bootstrap ─────────────────────────────────────────────────────────────

    public static function boot(): void {
        if (self::$bootstrapped) return;
        $pdo = Database::getInstance()->getPdo();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS platform_apps (
                id INT AUTO_INCREMENT PRIMARY KEY,
                app_id VARCHAR(100) NOT NULL UNIQUE,
                app_name VARCHAR(150) NOT NULL,
                verify_mode VARCHAR(40) NOT NULL DEFAULT 'standard',
                default_max_devices INT NOT NULL DEFAULT 2,
                default_years INT NOT NULL DEFAULT 1,
                device_tracking TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=co dem thiet bi, 0=khong dem',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        if (!\Database::hasColumn('platform_apps', 'device_tracking')) {
            $pdo->exec("ALTER TABLE platform_apps
                ADD COLUMN device_tracking TINYINT(1) NOT NULL DEFAULT 1
                COMMENT '1=co dem thiet bi, 0=khong dem' AFTER default_years");
        }
        self::$bootstrapped = true;
    }

    // ── Queries ───────────────────────────────────────────────────────────────

    public static function all(bool $includeInactive = true): array {
        self::boot();
        $sql  = 'SELECT * FROM platform_apps';
        $sql .= $includeInactive ? '' : ' WHERE is_active = 1';
        $sql .= ' ORDER BY id DESC';
        return Database::getInstance()->getPdo()->query($sql)->fetchAll();
    }

    public static function find(int $id): ?array {
        self::boot();
        $stmt = Database::getInstance()->getPdo()
            ->prepare('SELECT * FROM platform_apps WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Returns [app_id => app_name] map. */
    public static function map(bool $includeInactive = false): array {
        self::boot();
        $sql  = 'SELECT app_id, app_name, is_active FROM platform_apps';
        $sql .= $includeInactive ? '' : ' WHERE is_active = 1';
        $sql .= ' ORDER BY id ASC';
        $rows = Database::getInstance()->getPdo()->query($sql)->fetchAll();
        $map  = [];
        foreach ($rows as $row) {
            $id = strtolower(trim((string) $row['app_id']));
            if ($id === '') continue;
            $label = trim((string) $row['app_name']) ?: $id;
            if ($includeInactive && (int) $row['is_active'] !== 1) $label .= ' (inactive)';
            $map[$id] = $label;
        }
        return $map;
    }

    /** Returns device_tracking flag for a given app_id (1 = track, 0 = no-track). */
    public static function deviceTracking(string $appId): int {
        self::boot();
        $appId = strtolower(trim($appId));
        if ($appId === '') return 1;
        $stmt = Database::getInstance()->getPdo()
            ->prepare('SELECT device_tracking FROM platform_apps WHERE app_id = ? LIMIT 1');
        $stmt->execute([$appId]);
        $row = $stmt->fetch();
        return $row ? ((int) $row['device_tracking'] === 0 ? 0 : 1) : 1;
    }

    // ── Mutations ─────────────────────────────────────────────────────────────

    public static function create(
        string $appId, string $appName, string $verifyMode = 'standard',
        int $defaultMaxDevices = 2, int $defaultYears = 1,
        int $isActive = 1, int $deviceTracking = 1
    ): int {
        self::boot();
        $appId  = strtolower(trim($appId));
        $appName = trim($appName);
        $verifyMode = self::normalizeVerifyMode($verifyMode);
        $defaultMaxDevices = max(1, min(999, $defaultMaxDevices));
        $defaultYears      = max(1, min(100, $defaultYears));
        $isActive          = $isActive === 1 ? 1 : 0;
        $deviceTracking    = $deviceTracking === 0 ? 0 : 1;

        if (!preg_match('/^[a-z0-9._-]{3,100}$/', $appId)) throw new \Exception('App ID không hợp lệ');
        if ($appName === '') throw new \Exception('Tên app là bắt buộc');

        $pdo = Database::getInstance()->getPdo();
        $pdo->prepare(
            "INSERT INTO platform_apps
             (app_id, app_name, verify_mode, default_max_devices, default_years, device_tracking, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([$appId, $appName, $verifyMode, $defaultMaxDevices, $defaultYears, $deviceTracking, $isActive]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(
        int $id, string $appName, string $verifyMode = 'standard',
        int $defaultMaxDevices = 2, int $defaultYears = 1,
        int $isActive = 1, int $deviceTracking = 1
    ): void {
        self::boot();
        if ($id <= 0) throw new \Exception('ID không hợp lệ');
        $appName  = trim($appName);
        $verifyMode = self::normalizeVerifyMode($verifyMode);
        $defaultMaxDevices = max(1, min(999, $defaultMaxDevices));
        $defaultYears      = max(1, min(100, $defaultYears));
        $isActive          = $isActive === 1 ? 1 : 0;
        $deviceTracking    = $deviceTracking === 0 ? 0 : 1;
        if ($appName === '') throw new \Exception('Tên app là bắt buộc');

        $stmt = Database::getInstance()->getPdo()->prepare(
            "UPDATE platform_apps
             SET app_name=?, verify_mode=?, default_max_devices=?, default_years=?,
                 device_tracking=?, is_active=?, updated_at=NOW()
             WHERE id=?"
        );
        $stmt->execute([$appName, $verifyMode, $defaultMaxDevices, $defaultYears, $deviceTracking, $isActive, $id]);
        if ($stmt->rowCount() <= 0 && !self::find($id)) throw new \Exception('Không tìm thấy app');
    }

    public static function setActive(int $id, int $active): void {
        self::boot();
        if ($id <= 0) throw new \Exception('ID không hợp lệ');
        $stmt = Database::getInstance()->getPdo()->prepare(
            'UPDATE platform_apps SET is_active=?, updated_at=NOW() WHERE id=?'
        );
        $stmt->execute([$active === 1 ? 1 : 0, $id]);
        if ($stmt->rowCount() <= 0 && !self::find($id)) throw new \Exception('Không tìm thấy app');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function normalizeVerifyMode(string $v): string {
        $v = strtolower(trim($v));
        return ($v !== '' && preg_match('/^[a-z0-9._-]{2,40}$/', $v)) ? $v : 'standard';
    }
}
