<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Chiffrement symétrique authentifié des secrets (mots de passe API des routeurs,
 * clés des prestataires de paiement…). Utilise libsodium (XSalsa20-Poly1305) ou,
 * à défaut, OpenSSL AES-256-GCM. La clé maître est APP_KEY dans .env.
 */
final class Crypto
{
    private const PREFIX_SODIUM = 'v1s:';
    private const PREFIX_OPENSSL = 'v1o:';

    private static function key(): string
    {
        $raw = (string) Env::get('APP_KEY', '');
        if (str_starts_with($raw, 'base64:')) {
            $raw = base64_decode(substr($raw, 7), true) ?: '';
        }
        if (strlen($raw) !== 32) {
            throw new RuntimeException('APP_KEY absente ou invalide (32 octets requis).');
        }
        return $raw;
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public static function encrypt(string $plain): string
    {
        $key = self::key();
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return self::PREFIX_SODIUM . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Échec du chiffrement.');
        }
        return self::PREFIX_OPENSSL . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): string
    {
        $key = self::key();
        if (str_starts_with($payload, self::PREFIX_SODIUM)) {
            $bin = base64_decode(substr($payload, 4), true);
            if ($bin === false || strlen($bin) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                throw new RuntimeException('Donnée chiffrée corrompue.');
            }
            $plain = sodium_crypto_secretbox_open(
                substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $key
            );
            if ($plain === false) {
                throw new RuntimeException('Déchiffrement impossible (clé incorrecte ?).');
            }
            return $plain;
        }
        if (str_starts_with($payload, self::PREFIX_OPENSSL)) {
            $bin = base64_decode(substr($payload, 4), true);
            if ($bin === false || strlen($bin) < 28) {
                throw new RuntimeException('Donnée chiffrée corrompue.');
            }
            $plain = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
            if ($plain === false) {
                throw new RuntimeException('Déchiffrement impossible (clé incorrecte ?).');
            }
            return $plain;
        }
        throw new RuntimeException('Format de donnée chiffrée inconnu.');
    }

    /** Jeton aléatoire URL-safe. */
    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
