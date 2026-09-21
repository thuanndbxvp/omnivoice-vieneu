<?php
declare(strict_types=1);

/**
 * Custom Session Handler for TiDB (or MySQL).
 * Fixes session persistence issues on Serverless environments like Vercel.
 */
class DbSessionHandler implements SessionHandlerInterface {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function open(string $path, string $name): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read(string $id): string|false {
        $stmt = $this->db->prepare("SELECT data FROM sessions WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Return session data or empty string if not found
        return $row ? $row['data'] : '';
    }

    public function write(string $id, string $data): bool {
        $time = time();
        $stmt = $this->db->prepare("REPLACE INTO sessions (id, data, last_accessed) VALUES (:id, :data, :time)");
        return $stmt->execute([
            ':id' => $id,
            ':data' => $data,
            ':time' => $time
        ]);
    }

    public function destroy(string $id): bool {
        $stmt = $this->db->prepare("DELETE FROM sessions WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function gc(int $max_lifetime): int|false {
        $stmt = $this->db->prepare("DELETE FROM sessions WHERE last_accessed < :time");
        $stmt->execute([':time' => time() - $max_lifetime]);
        return $stmt->rowCount();
    }
}
