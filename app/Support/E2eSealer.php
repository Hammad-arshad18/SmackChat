<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Client message format. The server checks the blob shape and never decrypts it.
 *
 * Wrap:   0x01 || ephemeral P-256 public (65) || nonce (12) || AES-256-GCM(channel key)
 * Message: 0x01 || nonce (12) || AES-256-GCM(utf-8 JSON document)
 * Wrap key: HKDF-SHA256(ECDH shared, salt = ephemeral public, info = smackchat.wrap.v1.{deviceId})
 * Message AAD: smackchat.message.v1.{channelId}
 */
class E2eSealer
{
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function rawPublic(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        $x = str_pad($details['ec']['x'] ?? '', 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'] ?? '', 32, "\0", STR_PAD_LEFT);

        return "\x04".$x.$y;
    }

    public static function wrap(string $channelKey, string $recipientPublic, int $deviceId, ?\OpenSSLAsymmetricKey $ephemeral = null): string
    {
        if (strlen($channelKey) !== 32 || strlen($recipientPublic) !== 65) {
            throw new RuntimeException('Channel key wrap is malformed.');
        }

        $ephemeral ??= self::generate();
        $ephemeralPublic = self::rawPublic($ephemeral);
        $shared = self::sharedSecret($ephemeral, $recipientPublic);
        $aes = hash_hkdf('sha256', $shared, 32, 'smackchat.wrap.v1.'.$deviceId, $ephemeralPublic);
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($channelKey, 'aes-256-gcm', $aes, OPENSSL_RAW_DATA, $nonce, $tag, '');

        if ($cipher === false || strlen($tag) !== 16) {
            throw new RuntimeException('Channel key wrap failed.');
        }

        return self::encode("\x01".$ephemeralPublic.$nonce.$cipher.$tag);
    }

    public static function unwrap(string $wrapped, \OpenSSLAsymmetricKey $recipientPrivate, int $deviceId): string
    {
        $binary = self::decode($wrapped);
        if ($binary === null || strlen($binary) !== 1 + 65 + 12 + 32 + 16 || $binary[0] !== "\x01") {
            throw new RuntimeException('Channel key wrap is malformed.');
        }

        $ephemeralPublic = substr($binary, 1, 65);
        $nonce = substr($binary, 66, 12);
        $cipher = substr($binary, 78, 32);
        $tag = substr($binary, 110, 16);
        $shared = self::sharedSecret($recipientPrivate, $ephemeralPublic);
        $aes = hash_hkdf('sha256', $shared, 32, 'smackchat.wrap.v1.'.$deviceId, $ephemeralPublic);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $aes, OPENSSL_RAW_DATA, $nonce, $tag, '');

        if (! is_string($plain) || strlen($plain) !== 32) {
            throw new RuntimeException('Channel key wrap could not be opened.');
        }

        return $plain;
    }

    public static function sealMessage(string $channelKey, int $channelId, array $document): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            json_encode($document, JSON_THROW_ON_ERROR),
            'aes-256-gcm',
            $channelKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'smackchat.message.v1.'.$channelId
        );

        if ($cipher === false || strlen($tag) !== 16) {
            throw new RuntimeException('Message seal failed.');
        }

        return self::encode("\x01".$nonce.$cipher.$tag);
    }

    public static function openMessage(string $channelKey, int $channelId, string $sealed): array
    {
        $binary = self::decode($sealed);
        if ($binary === null || strlen($binary) < 1 + 12 + 16 || $binary[0] !== "\x01") {
            throw new RuntimeException('Message seal is malformed.');
        }

        $nonce = substr($binary, 1, 12);
        $body = substr($binary, 13);
        $cipher = substr($body, 0, -16);
        $tag = substr($body, -16);
        $json = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $channelKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'smackchat.message.v1.'.$channelId
        );

        if (! is_string($json)) {
            throw new RuntimeException('Message seal could not be opened.');
        }

        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($document) ? $document : [];
    }

    public static function assertMessageBlob(string $sealed): void
    {
        $binary = self::decode($sealed);
        $valid = is_string($binary)
            && strlen($binary) >= 1 + 12 + 16
            && strlen($binary) <= 48000
            && $binary[0] === "\x01";

        if (! $valid) {
            throw ValidationException::withMessages([
                'sealed' => 'The encrypted message is not valid.',
            ]);
        }
    }

    public static function assertWrapBlob(string $wrapped): void
    {
        $binary = self::decode($wrapped);
        $valid = is_string($binary)
            && strlen($binary) === 1 + 65 + 12 + 32 + 16
            && $binary[0] === "\x01";

        if (! $valid) {
            throw ValidationException::withMessages([
                'wraps' => 'A conversation key is not valid.',
            ]);
        }
    }

    public static function encode(string $binary): string
    {
        return base64_encode($binary);
    }

    public static function decode(string $encoded): ?string
    {
        $binary = base64_decode($encoded, true);

        return is_string($binary) ? $binary : null;
    }

    private static function generate(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if (! $key instanceof \OpenSSLAsymmetricKey) {
            throw new RuntimeException('Could not generate an encryption key.');
        }

        return $key;
    }

    private static function sharedSecret(\OpenSSLAsymmetricKey $private, string $peerPublic): string
    {
        $secret = openssl_pkey_derive(self::publicKey($peerPublic), $private, 32);

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Key agreement failed.');
        }

        if (strlen($secret) > 32) {
            $secret = substr($secret, -32);
        }

        return str_pad($secret, 32, "\0", STR_PAD_LEFT);
    }

    private static function publicKey(string $raw): \OpenSSLAsymmetricKey
    {
        $spki = hex2bin(self::P256_SPKI_PREFIX).$raw;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n").'-----END PUBLIC KEY-----';
        $key = openssl_pkey_get_public($pem);

        if (! $key instanceof \OpenSSLAsymmetricKey) {
            throw new RuntimeException('Public key is not valid.');
        }

        return $key;
    }
}
