<?php
/**
 * ReportService — thống kê và báo cáo dựa trên dữ liệu DB hiện có.
 */

declare(strict_types=1);

namespace Services;

use Database;

class ReportService {

    /**
     * Tổng quan hệ thống: số license theo trạng thái, device, app.
     */
    public static function overview(): array {
        $pdo = Database::getInstance()->getPdo();
        $now = date('Y-m-d H:i:s');

        $row = $pdo->query("
            SELECT
                COUNT(*) AS total,
                SUM(revoked = 0 AND expiry_date > '{$now}') AS active,
                SUM(revoked = 0 AND expiry_date <= '{$now}') AS expired,
                SUM(revoked = 1) AS revoked,
                SUM(max_devices) AS total_device_slots
            FROM licenses
        ")->fetch();

        $deviceCount = (int) $pdo->query('SELECT COUNT(*) FROM devices')->fetchColumn();

        return [
            'total'              => (int) ($row['total']              ?? 0),
            'active'             => (int) ($row['active']             ?? 0),
            'expired'            => (int) ($row['expired']            ?? 0),
            'revoked'            => (int) ($row['revoked']            ?? 0),
            'total_device_slots' => (int) ($row['total_device_slots'] ?? 0),
            'used_device_slots'  => $deviceCount,
        ];
    }

    /**
     * Thống kê theo đại lý.
     */
    public static function byAgency(): array {
        $pdo = Database::getInstance()->getPdo();
        if (!Database::hasColumn('licenses', 'agency_id')) return [];
        $now = date('Y-m-d H:i:s');

        $rows = $pdo->query("
            SELECT
                l.agency_id,
                COALESCE(a.name, 'Không có đại lý') AS agency_name,
                COALESCE(a.code, '') AS agency_code,
                COUNT(l.id) AS total,
                SUM(l.revoked = 0 AND l.expiry_date > '{$now}') AS active,
                SUM(l.revoked = 0 AND l.expiry_date <= '{$now}') AS expired,
                SUM(l.revoked = 1) AS revoked
            FROM licenses l
            LEFT JOIN agencies a ON a.id = l.agency_id
            GROUP BY l.agency_id
            ORDER BY total DESC
        ")->fetchAll();

        return $rows;
    }

    /**
     * Thống kê theo app (dựa trên api_request_logs).
     */
    public static function byApp(): array {
        $pdo = Database::getInstance()->getPdo();
        if (!Database::hasColumn('api_request_logs', 'app_id')) return [];

        return $pdo->query("
            SELECT
                COALESCE(NULLIF(app_id,''), 'unknown') AS app_id,
                COUNT(*) AS total_requests,
                SUM(outcome IN ('valid','ok','active')) AS success_requests,
                SUM(outcome NOT IN ('valid','ok','active')) AS failed_requests
            FROM api_request_logs
            GROUP BY app_id
            ORDER BY total_requests DESC
        ")->fetchAll();
    }

    /**
     * Lịch sử kích hoạt license theo ngày (30 ngày gần nhất).
     */
    public static function activationsByDay(int $days = 30): array {
        $pdo  = Database::getInstance()->getPdo();
        $from = date('Y-m-d', strtotime("-{$days} days"));

        return $pdo->query("
            SELECT DATE(created_at) AS day, COUNT(*) AS count
            FROM licenses
            WHERE created_at >= '{$from}'
            GROUP BY DATE(created_at)
            ORDER BY day ASC
        ")->fetchAll();
    }

    /**
     * Thống kê lượt gọi API theo ngày (30 ngày).
     */
    public static function apiCallsByDay(int $days = 30): array {
        $pdo = Database::getInstance()->getPdo();
        if (!Database::hasColumn('api_request_logs', 'created_at')) return [];
        $from = date('Y-m-d', strtotime("-{$days} days"));

        return $pdo->query("
            SELECT
                DATE(created_at) AS day,
                COUNT(*) AS total,
                SUM(outcome IN ('valid','ok','active')) AS success,
                SUM(outcome NOT IN ('valid','ok','active')) AS failed
            FROM api_request_logs
            WHERE created_at >= '{$from}'
            GROUP BY DATE(created_at)
            ORDER BY day ASC
        ")->fetchAll();
    }

    /**
     * Top license dùng nhiều nhất (theo tổng request).
     */
    public static function topLicensesByRequests(int $limit = 20): array {
        $pdo = Database::getInstance()->getPdo();
        if (!Database::hasColumn('license_request_counters', 'license_key')) return [];

        return $pdo->query("
            SELECT
                c.license_key, c.total_requests, c.success_requests, c.failed_requests,
                c.last_request_at, c.last_app_id,
                l.customer_name, l.email, l.expiry_date, l.revoked
            FROM license_request_counters c
            LEFT JOIN licenses l ON l.license_key = c.license_key
            ORDER BY c.total_requests DESC
            LIMIT {$limit}
        ")->fetchAll();
    }

    /**
     * Licenses sắp hết hạn trong N ngày tới.
     */
    public static function expiringLicenses(int $days = 30): array {
        $pdo  = Database::getInstance()->getPdo();
        $now  = date('Y-m-d H:i:s');
        $future = date('Y-m-d H:i:s', strtotime("+{$days} days"));

        $sql = "
            SELECT l.*, d.device_count
            FROM licenses l
            LEFT JOIN (
                SELECT license_id, COUNT(*) AS device_count FROM devices GROUP BY license_id
            ) d ON d.license_id = l.id
        ";
        if (Database::hasColumn('licenses', 'agency_id')) {
            $sql = "
                SELECT l.*, a.name AS agency_name, d.device_count
                FROM licenses l
                LEFT JOIN agencies a ON a.id = l.agency_id
                LEFT JOIN (
                    SELECT license_id, COUNT(*) AS device_count FROM devices GROUP BY license_id
                ) d ON d.license_id = l.id
            ";
        }
        $sql .= " WHERE l.revoked = 0 AND l.expiry_date > '{$now}' AND l.expiry_date <= '{$future}'
                  ORDER BY l.expiry_date ASC";

        return $pdo->query($sql)->fetchAll();
    }

    /**
     * Lỗi phổ biến nhất trong 7 ngày (từ api_request_logs).
     */
    public static function topErrors(int $days = 7): array {
        $pdo = Database::getInstance()->getPdo();
        if (!Database::hasColumn('api_request_logs', 'error_code')) return [];
        $from = date('Y-m-d', strtotime("-{$days} days"));

        return $pdo->query("
            SELECT error_code, COUNT(*) AS count
            FROM api_request_logs
            WHERE error_code IS NOT NULL AND error_code != ''
              AND created_at >= '{$from}'
            GROUP BY error_code
            ORDER BY count DESC
            LIMIT 15
        ")->fetchAll();
    }

    /**
     * Thống kê device đã đăng ký theo app (từ audit_log).
     */
    public static function devicesByApp(): array {
        $pdo = Database::getInstance()->getPdo();
        if (!Database::hasColumn('api_request_logs', 'app_id')) return [];

        return $pdo->query("
            SELECT app_id, COUNT(DISTINCT device_id) AS unique_devices, COUNT(*) AS total_activations
            FROM api_request_logs
            WHERE action = 'activate' AND outcome IN ('valid','ok')
            GROUP BY app_id
            ORDER BY unique_devices DESC
        ")->fetchAll();
    }
}
