<?php
declare(strict_types=1);

namespace App\Core;

/** Chiffrement symétrique authentifié (AES-256-GCM) des secrets stockés en base. */
final class Crypto
{
    private static function key(): string
    {
        return hash('sha256', 'marwa-crypto|' . Secrets::key(), true);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $c = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($c === false) {
            throw new \RuntimeException('Chiffrement impossible.');
        }
        return 'v1.' . base64_encode($iv . $tag . $c);
    }

    public static function decrypt(string $data): ?string
    {
        if (!str_starts_with($data, 'v1.')) {
            return null;
        }
        $raw = base64_decode(substr($data, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $p === false ? null : $p;
    }
}
