<?php
/**
 * AgencyModel — quản lý bảng agencies.
 */

declare(strict_types=1);

namespace Models;

use Database;

class AgencyModel {
    private static bool $bootstrapped = false;

    public static function boot(): void {
        if (self::$bootstrapped) return;
        Database::getInstance()->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS agencies (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(80) NOT NULL UNIQUE,
                name VARCHAR(150) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::$bootstrapped = true;
    }

    // ── Queries ───────────────────────────────────────────────────────────────

    public static function all(bool $includeInactive = true): array {
        self::boot();
        $sql  = 'SELECT * FROM agencies';
        $sql .= $includeInactive ? '' : ' WHERE is_active = 1';
        $sql .= ' ORDER BY name ASC, id ASC';
        return Database::getInstance()->getPdo()->query($sql)->fetchAll();
    }

    /** Returns [id => name] map. */
    public static function map(bool $includeInactive = true): array {
        $map = [];
        foreach (self::all($includeInactive) as $row) {
            $map[(int) $row['id']] = trim((string) $row['name']);
        }
        return $map;
    }

    public static function find(int $id): ?array {
        self::boot();
        $stmt = Database::getInstance()->getPdo()
            ->prepare('SELECT * FROM agencies WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    // ── Mutations ─────────────────────────────────────────────────────────────

    public static function create(string $code, string $name, int $isActive = 1): int {
        self::boot();
        $code = strtolower(trim($code));
        $name = trim($name);
        if (!preg_match('/^[a-z0-9._-]{2,80}$/', $code)) throw new \Exception('Mã đại lý không hợp lệ');
        if ($name === '') throw new \Exception('Tên đại lý là bắt buộc');
        $pdo = Database::getInstance()->getPdo();
        $pdo->prepare(
            'INSERT INTO agencies (code, name, is_active, created_at) VALUES (?, ?, ?, NOW())'
        )->execute([$code, $name, $isActive === 1 ? 1 : 0]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, string $code, string $name, int $isActive = 1): void {
        self::boot();
        if ($id <= 0) throw new \Exception('ID không hợp lệ');
        $code = strtolower(trim($code));
        $name = trim($name);
        if (!preg_match('/^[a-z0-9._-]{2,80}$/', $code)) throw new \Exception('Mã đại lý không hợp lệ');
        if ($name === '') throw new \Exception('Tên đại lý là bắt buộc');
        $stmt = Database::getInstance()->getPdo()->prepare(
            'UPDATE agencies SET code=?, name=?, is_active=?, updated_at=NOW() WHERE id=?'
        );
        $stmt->execute([$code, $name, $isActive === 1 ? 1 : 0, $id]);
        if ($stmt->rowCount() <= 0 && !self::find($id)) throw new \Exception('Không tìm thấy đại lý');
    }

    public static function setActive(int $id, int $active): void {
        self::boot();
        $stmt = Database::getInstance()->getPdo()->prepare(
            'UPDATE agencies SET is_active=?, updated_at=NOW() WHERE id=?'
        );
        $stmt->execute([$active === 1 ? 1 : 0, $id]);
        if ($stmt->rowCount() <= 0 && !self::find($id)) throw new \Exception('Không tìm thấy đại lý');
    }
}
