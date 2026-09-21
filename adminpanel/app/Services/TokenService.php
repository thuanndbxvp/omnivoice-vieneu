<?php
/**
 * TokenService — Ed25519 signing / verification.
 */

declare(strict_types=1);

namespace Services;

class TokenService {

    private static function privateKey(): string {
        $raw = base64_decode(PRIVATE_KEY, true);
        if ($raw === false) throw new \Exception('Invalid PRIVATE_KEY encoding');
        // libsodium seed key is 64 bytes; some tools export 96-byte combined key
        if (strlen($raw) === 96) $raw = substr($raw, 0, 64);
        if (strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \Exception('Invalid PRIVATE_KEY length');
        }
        return $raw;
    }

    private static function publicKey(): string {
        $raw = base64_decode(ED25519_PUBLIC_KEY, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \Exception('Invalid ED25519_PUBLIC_KEY');
        }
        return $raw;
    }

    /**
     * Sign an array as a detached signature response.
     * Returns ['payload' => base64, 'signature' => base64].
     */
    public static function signResponse(array $data): array {
        $payload   = base64_encode((string) json_encode($data));
        $signature = base64_encode(sodium_crypto_sign_detached($payload, self::privateKey()));
        return ['payload' => $payload, 'signature' => $signature];
    }

    /**
     * Sign a compact token (sodium_crypto_sign — signature prepended).
     * Returns base64-encoded signed blob.
     */
    public static function signCompact(array $data): string {
        $payload = (string) json_encode($data);
        if ($payload === false) throw new \Exception('JSON encode failed');
        return base64_encode(sodium_crypto_sign($payload, self::privateKey()));
    }

    /**
     * Verify and decode a compact token. Throws on failure.
     */
    public static function verifyCompact(string $tokenB64): array {
        $signed = base64_decode($tokenB64, true);
        if ($signed === false || $signed === '') throw new \Exception('Invalid token encoding');
        $payload = sodium_crypto_sign_open($signed, self::publicKey());
        if ($payload === false) throw new \Exception('Invalid token signature');
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) throw new \Exception('Invalid token payload');
        return $decoded;
    }
}
