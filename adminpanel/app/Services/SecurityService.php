<?php
/**
 * SecurityService — rate limiting, nonce replay, CORS, IP blocking.
 */

declare(strict_types=1);

namespace Services;

class SecurityService {

    // ── Client IP ─────────────────────────────────────────────────────────────

    public static function clientIp(): string {
        if (TRUST_PROXY) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $h) {
                $v = $_SERVER[$h] ?? '';
                if ($v === '') continue;
                $first = trim(explode(',', $v)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    // ── Rate Limiting (filesystem sliding window) ─────────────────────────────

    public static function rateLimitAllow(string $bucket, int $max, int $windowSec): array {
        $dir  = LOGS_DIR . '/ratelimit';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = $dir . '/' . hash('sha256', $bucket) . '.json';

        $now        = microtime(true);
        $windowStart = $now - $windowSec;
        $timestamps  = [];

        if (is_file($file)) {
            $data = @json_decode((string) @file_get_contents($file), true);
            if (is_array($data['ts'] ?? null)) {
                $timestamps = array_values(array_filter($data['ts'], fn($t) => $t > $windowStart));
            }
        }

        if (count($timestamps) >= $max) {
            $oldest     = min($timestamps);
            $retryAfter = (int) ceil($oldest + $windowSec - $now);
            return [false, max(1, $retryAfter)];
        }

        $timestamps[] = $now;
        @file_put_contents($file, json_encode(['ts' => $timestamps]), LOCK_EX);
        return [true, 0];
    }

    public static function rateLimitReset(string $bucket): void {
        $file = LOGS_DIR . '/ratelimit/' . hash('sha256', $bucket) . '.json';
        if (is_file($file)) @unlink($file);
    }

    // ── Nonce (replay prevention) ─────────────────────────────────────────────

    public static function acceptNonce(string $licenseKey, string $deviceId, string $nonce): bool {
        $dir = LOGS_DIR . '/nonces';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $key  = hash('sha256', "{$licenseKey}|{$deviceId}|{$nonce}");
        $file = "{$dir}/{$key}.nonce";
        $ttl  = MAX_CLOCK_SKEW * 2;

        // Probabilistic cleanup (1 in 30 requests)
        if (mt_rand(1, 30) === 1) self::cleanNonces();

        if (is_file($file) && (time() - (int) filemtime($file)) < $ttl) return false;
        @file_put_contents($file, '1', LOCK_EX);
        return true;
    }

    private static function cleanNonces(): void {
        $dir = LOGS_DIR . '/nonces';
        if (!is_dir($dir)) return;
        $ttl = MAX_CLOCK_SKEW * 2 + 30;
        foreach (glob("{$dir}/*.nonce") ?: [] as $f) {
            if ((time() - (int) filemtime($f)) > $ttl) @unlink($f);
        }
    }

    // ── IP Blocking ───────────────────────────────────────────────────────────

    public static function blockIp(string $ip, int $seconds): void {
        $file = LOGS_DIR . '/blocked_ips.json';
        $data = [];
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true) ?? [];
        }
        $data[$ip] = time() + $seconds;
        file_put_contents($file, json_encode($data), LOCK_EX);
    }

    public static function isIpBlocked(string $ip): bool {
        $file = LOGS_DIR . '/blocked_ips.json';
        if (!is_file($file)) return false;
        $data = json_decode((string) file_get_contents($file), true) ?? [];
        if (!isset($data[$ip])) return false;
        if (time() > (int) $data[$ip]) {
            unset($data[$ip]);
            file_put_contents($file, json_encode($data), LOCK_EX);
            return false;
        }
        return true;
    }

    // ── CORS ──────────────────────────────────────────────────────────────────

    public static function emitCorsHeaders(): void {
        $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = self::allowedOrigins();

        if ($allowed === ['*']) {
            header('Access-Control-Allow-Origin: *');
        } elseif ($origin !== '' && in_array($origin, $allowed, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Vary: Origin');
        } else {
            return;
        }
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400');
    }

    private static function allowedOrigins(): array {
        $raw = trim(API_ALLOWED_ORIGINS);
        if ($raw === '' || $raw === '*') return ['*'];
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
