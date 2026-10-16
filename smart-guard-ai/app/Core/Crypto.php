<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Chiffrement symétrique authentifié (libsodium secretbox) pour les secrets
 * stockés en base (identifiants des caméras). La clé APP_KEY vit dans .env.
 */
final class Crypto
{
    private static function key(): string
    {
        $hex = (string) Env::get('APP_KEY', '');
        if (!preg_match('/^[0-9a-f]{64}$/i', $hex)) {
            throw new \RuntimeException('APP_KEY absente ou invalide dans .env (64 caractères hexadécimaux attendus). Exécutez php scripts/generate_keys.php');
        }
        return hex2bin($hex);
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($plain, $nonce, self::key());
    }

    public static function decrypt(?string $cipher): ?string
    {
        if ($cipher === null || $cipher === '' || strlen($cipher) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($cipher, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($cipher, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        return $plain === false ? null : $plain;
    }
}
