<?php
/**
 * Database connection — singleton PDO wrapper.
 */

declare(strict_types=1);

class Database {
    private static ?Database $instance = null;
    private static array $columnCache = [];
    private PDO $pdo;

    private function __construct() {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, defined('DB_PORT') ? DB_PORT : 3306, DB_NAME, DB_CHARSET);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        
        // Cấu hình SSL cho TiDB Serverless
        if (defined('DB_SSL') && DB_SSL) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = APP_ROOT . '/cacert.pem';
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }

    public static function getInstance(): static {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    public function getPdo(): PDO {
        return $this->pdo;
    }

    /** Check if a column exists in a table (cached). */
    public static function hasColumn(string $table, string $column): bool {
        $key = "{$table}.{$column}";
        if (array_key_exists($key, self::$columnCache)) {
            return self::$columnCache[$key];
        }
        try {
            $stmt = self::getInstance()->getPdo()->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$table, $column]);
            self::$columnCache[$key] = ((int) $stmt->fetchColumn()) > 0;
        } catch (Exception) {
            self::$columnCache[$key] = false;
        }
        return self::$columnCache[$key];
    }
}
