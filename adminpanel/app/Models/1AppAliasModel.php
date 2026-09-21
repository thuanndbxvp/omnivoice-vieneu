<?php
/**
 * AppAliasModel — quản lý bảng app_aliases.
 */

declare(strict_types=1);

namespace Models;

use Database;

class AppAliasModel {
    private static bool $bootstrapped = false;

    private static array $defaultAliases = [];

    public static function boot(): void {
        if (self::$bootstrapped) return;
        $pdo = Database::getInstance()->getPdo();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_aliases (
                id INT AUTO_INCREMENT PRIMARY KEY,
                alias VARCHAR(100) NOT NULL UNIQUE,
                app_id VARCHAR(100) NOT NULL,
                note VARCHAR(255) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_app_id (app_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::$bootstrapped = true;
    }

    // ── Queries ───────────────────────────────────────────────────────────────

    /** Resolve alias → canonical app_id, or null if not found. */
    public static function resolve(string $alias): ?string {
        self::boot();
        $alias = strtolower(trim($alias));
        if ($alias === '') return null;
        $stmt = Database::getInstance()->getPdo()
            ->prepare('SELECT app_id FROM app_aliases WHERE alias = ? LIMIT 1');
        $stmt->execute([$alias]);
        $row = $stmt->fetch();
        return $row ? strtolower(trim((string) $row['app_id'])) : null;
    }

    public static function all(): array {
        self::boot();
        return Database::getInstance()->getPdo()
            ->query('SELECT * FROM app_aliases ORDER BY app_id ASC, id ASC')
            ->fetchAll();
    }

    public static function find(int $id): ?array {
        self::boot();
        $stmt = Database::getInstance()->getPdo()
            ->prepare('SELECT * FROM app_aliases WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    // ── Mutations ─────────────────────────────────────────────────────────────

    public static function create(string $alias, string $appId, string $note = ''): void {
        self::boot();
        $alias = strtolower(trim($alias));
        $appId = strtolower(trim($appId));
        if (!preg_match('/^[a-z0-9._-]{2,100}$/', $alias)) throw new \Exception('Alias không hợp lệ');
        if ($appId === '') throw new \Exception('App ID là bắt buộc');
        Database::getInstance()->getPdo()->prepare(
            'INSERT INTO app_aliases (alias, app_id, note, created_at) VALUES (?, ?, ?, NOW())'
        )->execute([$alias, $appId, trim($note)]);
    }

    public static function delete(int $id): void {
        self::boot();
        Database::getInstance()->getPdo()
            ->prepare('DELETE FROM app_aliases WHERE id = ?')
            ->execute([$id]);
    }
}
