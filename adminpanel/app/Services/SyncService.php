<?php
/**
 * SyncService — Xử lý gửi đồng bộ dữ liệu License sang Vercel (TiDB) tức thì qua HTTPS.
 */

declare(strict_types=1);

namespace Services;

use Database;
use Throwable;

class SyncService {

    /**
     * Gửi toàn bộ danh sách licenses từ Database hiện tại sang Vercel.
     */
    public static function syncAll(): array {
        $pdo = Database::getInstance()->getPdo();
        $stmt = $pdo->query("SELECT * FROM licenses");
        $licenses = $stmt->fetchAll();

        return self::sendRequest([
            'action'   => 'upsert_batch',
            'licenses' => $licenses
        ]);
    }

    /**
     * Đồng bộ tức thì 1 license vừa tạo / sửa sang Vercel.
     */
    public static function syncOne(array $license): array {
        return self::sendRequest([
            'action'  => 'upsert_one',
            'license' => $license
        ]);
    }

    /**
     * Xoá 1 license trên Vercel khi bị xoá trên local.
     */
    public static function deleteOne(string $licenseKey): array {
        return self::sendRequest([
            'action'      => 'delete_one',
            'license_key' => $licenseKey
        ]);
    }

    /**
     * Đồng bộ tức thì 1 app vừa tạo / sửa sang Vercel.
     */
    public static function syncApp(array $app): array {
        return self::sendRequest([
            'action' => 'upsert_app',
            'app'    => $app
        ]);
    }

    /**
     * Gửi toàn bộ platform_apps sang Vercel.
     */
    public static function syncAllApps(): array {
        $pdo = Database::getInstance()->getPdo();
        $stmt = $pdo->query("SELECT * FROM platform_apps");
        $apps = $stmt->fetchAll();

        return self::sendRequest([
            'action' => 'upsert_apps_batch',
            'apps'   => $apps
        ]);
    }

    /**
     * Đồng bộ 1 alias sang Vercel.
     */
    public static function syncAlias(array $alias): array {
        return self::sendRequest([
            'action' => 'upsert_alias',
            'alias'  => $alias
        ]);
    }

    /**
     * Xoá 1 alias trên Vercel.
     */
    public static function deleteAlias(int $aliasId, string $alias = ''): array {
        return self::sendRequest([
            'action'   => 'delete_alias',
            'alias_id' => $aliasId,
            'alias'    => $alias
        ]);
    }

    /**
     * Gửi request HTTPS POST sang Vercel endpoint.
     */
    private static function sendRequest(array $payload): array {
        // Tạm thời disable tính năng đồng bộ sang TiDB theo yêu cầu
        return [
            'success'  => true,
            'disabled' => true,
            'message'  => 'TiDB sync is temporarily disabled.'
        ];

        $url = defined('VERCEL_SYNC_URL') ? VERCEL_SYNC_URL : '';
        $secret = defined('SYNC_SECRET') ? SYNC_SECRET : '';

        $body = json_encode($payload);

        // 1. Thử qua cURL (chuẩn nhất cho cPanel hosting)
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-Sync-Token: ' . $secret,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4, // Bắt buộc dùng IPv4 để tránh treo IPv6 trên cPanel
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (vHost-SyncService)',
            ]);

            $response = curl_exec($ch);
            $errNo = curl_errno($ch);
            $errMsg = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errNo !== 0) {
                return ['success' => false, 'error' => "Lỗi kết nối cURL ({$errNo}): {$errMsg}"];
            }

            if (!empty($response)) {
                $data = json_decode($response, true);
                if (is_array($data)) {
                    return $data;
                }
                return ['success' => false, 'error' => "Phản hồi không phải JSON (HTTP {$httpCode}): " . substr(strip_tags($response), 0, 100)];
            }
        }

        // 2. Fallback: stream context (file_get_contents)
        $options = [
            'http' => [
                'header'  => "Content-Type: application/json\r\n" .
                             "X-Sync-Token: " . $secret . "\r\n",
                'method'  => 'POST',
                'content' => $body,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ]
        ];

        $context = stream_context_create($options);

        try {
            $result = @file_get_contents($url, false, $context);
            if ($result === false) {
                return ['success' => false, 'error' => 'Không thể kết nối tới máy chủ Vercel.'];
            }
            $data = json_decode($result, true);
            return is_array($data) ? $data : ['success' => false, 'error' => 'Phản hồi không hợp lệ: ' . substr(strip_tags($result), 0, 100)];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
