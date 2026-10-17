<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Chiffrement symétrique authentifié des secrets stockés en base (identifiants des caméras).
 * La clé APP_KEY vit dans .env.
 *  - libsodium (secretbox) si l'extension « sodium » est active ;
 *  - sinon OpenSSL AES-256-GCM (format préfixé « SGv2 »), disponible par défaut sous WampServer.
 * Les deux formats sont toujours lisibles quand l'extension correspondante est présente.
 */
final class Crypto
{
    private const GCM_PREFIX = 'SGv2';

    private static function key(): string
    {
        $hex = (string) Env::get('APP_KEY', '');
        if (!preg_match('/^[0-9a-f]{64}$/i', $hex)) {
            throw new \RuntimeException('APP_KEY absente ou invalide dans .env (64 caractères hexadécimaux attendus). Exécutez php scripts/generate_keys.php');
        }
        return hex2bin($hex);
    }

    public static function hasSodium(): bool
    {
        return function_exists('sodium_crypto_secretbox');
    }

    public static function encrypt(string $plain): string
    {
        if (self::hasSodium()) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return $nonce . sodium_crypto_secretbox($plain, $nonce, self::key());
        }
        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('Aucune extension de chiffrement disponible : activez « sodium » ou « openssl » dans PHP.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, self::GCM_PREFIX, 16);
        if ($cipher === false) {
            throw new \RuntimeException('Échec du chiffrement OpenSSL.');
        }
        return self::GCM_PREFIX . $iv . $tag . $cipher;
    }

    public static function decrypt(?string $cipher): ?string
    {
        if ($cipher === null || $cipher === '') {
            return null;
        }
        if (str_starts_with($cipher, self::GCM_PREFIX) && strlen($cipher) > 4 + 12 + 16 && function_exists('openssl_decrypt')) {
            $plain = openssl_decrypt(substr($cipher, 32), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($cipher, 4, 12), substr($cipher, 16, 16), self::GCM_PREFIX);
            if ($plain !== false) {
                return $plain;
            }
        }
        if (self::hasSodium() && strlen($cipher) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            $nonce = substr($cipher, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open(substr($cipher, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
            return $plain === false ? null : $plain;
        }
        return null;
    }
}
