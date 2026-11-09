<?php

namespace App\Support;

/**
 * Stable URL token for a message id. AES-256-GCM, keyed from the app key.
 * The same id always produces the same token, and the token does not reveal the id.
 */
class OpaqueId
{
    public static function encode(int $id): string
    {
        $key = self::key();
        $nonce = self::nonce($key, $id);
        $tag = '';
        $cipher = openssl_encrypt(pack('J', $id), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, self::aad());

        return 'v1.'.rtrim(strtr(base64_encode($nonce.$cipher.$tag), '+/', '-_'), '=');
    }

    public static function decode(?string $token): ?int
    {
        if (! is_string($token) || ! str_starts_with($token, 'v1.')) {
            return null;
        }

        $raw = base64_decode(strtr(substr($token, 3), '-_', '+/'), true);
        if ($raw === false || strlen($raw) !== 36) {
            return null;
        }

        $nonce = substr($raw, 0, 12);
        $cipher = substr($raw, 12, 8);
        $tag = substr($raw, 20, 16);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag, self::aad());
        if ($plain === false || strlen($plain) !== 8) {
            return null;
        }

        $id = unpack('J', $plain)[1];
        if ($id < 1 || ! hash_equals($nonce, self::nonce(self::key(), $id))) {
            return null;
        }

        return $id;
    }

    private static function nonce(string $key, int $id): string
    {
        return substr(hash_hmac('sha256', 'n:'.$id, $key, true), 0, 12);
    }

    private static function key(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = $decoded !== false ? $decoded : $key;
        }

        return hash('sha256', 'smackchat.url.v1|'.$key, true);
    }

    private static function aad(): string
    {
        return 'smackchat.url.v1';
    }
}
