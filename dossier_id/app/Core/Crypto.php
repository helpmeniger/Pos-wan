<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Chiffrement authentifié (libsodium XSalsa20-Poly1305) des fichiers stockés.
 * Deux clés distinctes : « storage » (photos, documents) et « backup » (sauvegardes).
 * Les clés sont stockées dans storage.key_dir, de préférence hors de l'arborescence web.
 */
final class Crypto
{
    private const MAGIC = "DID1";
    private static array $keys = [];

    public static function keyPath(string $name): string
    {
        if (!in_array($name, ['storage', 'backup'], true)) {
            throw new \InvalidArgumentException('Nom de clé inconnu.');
        }
        return rtrim((string) Config::get('storage.key_dir'), '/\\') . DIRECTORY_SEPARATOR . $name . '.key';
    }

    public static function key(string $name): string
    {
        if (!isset(self::$keys[$name])) {
            $path = self::keyPath($name);
            if (!is_file($path)) {
                throw new \RuntimeException("Clé de chiffrement « $name » introuvable. Exécutez scripts/install.php.");
            }
            $key = base64_decode(trim((string) file_get_contents($path)), true);
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new \RuntimeException("Clé de chiffrement « $name » invalide.");
            }
            self::$keys[$name] = $key;
        }
        return self::$keys[$name];
    }

    /** Crée la clé si elle n'existe pas. Retourne true si une clé a été créée. */
    public static function ensureKey(string $name): bool
    {
        $path = self::keyPath($name);
        if (is_file($path)) {
            return false;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new \RuntimeException("Impossible de créer le répertoire des clés : $dir");
        }
        file_put_contents($path, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)) . PHP_EOL, LOCK_EX);
        @chmod($path, 0600);
        return true;
    }

    public static function encrypt(string $plain, string $keyName = 'storage'): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::MAGIC . $nonce . sodium_crypto_secretbox($plain, $nonce, self::key($keyName));
    }

    public static function decrypt(string $blob, string $keyName = 'storage'): string
    {
        $nlen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($blob) < 4 + $nlen + SODIUM_CRYPTO_SECRETBOX_MACBYTES || !str_starts_with($blob, self::MAGIC)) {
            throw new \RuntimeException('Fichier chiffré invalide.');
        }
        $plain = sodium_crypto_secretbox_open(substr($blob, 4 + $nlen), substr($blob, 4, $nlen), self::key($keyName));
        if ($plain === false) {
            throw new \RuntimeException('Échec du déchiffrement : fichier altéré ou clé incorrecte.');
        }
        return $plain;
    }

    /** Chiffrement en flux d'un fichier (sauvegardes volumineuses). */
    public static function encryptFile(string $src, string $dest, string $keyName = 'backup'): void
    {
        $in = fopen($src, 'rb');
        $out = fopen($dest, 'wb');
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push(self::key($keyName));
        fwrite($out, "DIDB1" . $header);
        $chunk = 1 << 20;
        while (true) {
            $data = fread($in, $chunk);
            $eof = feof($in);
            $tag = $eof ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            $c = sodium_crypto_secretstream_xchacha20poly1305_push($state, (string) $data, '', $tag);
            fwrite($out, pack('N', strlen($c)) . $c);
            if ($eof) {
                break;
            }
        }
        fclose($in);
        fclose($out);
    }

    public static function decryptFile(string $src, string $dest, string $keyName = 'backup'): void
    {
        $in = fopen($src, 'rb');
        $magic = fread($in, 5);
        $header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        if ($magic !== 'DIDB1' || strlen((string) $header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            fclose($in);
            throw new \RuntimeException("Ce fichier n'est pas une sauvegarde DOSSIER ID valide.");
        }
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, self::key($keyName));
        $out = fopen($dest, 'wb');
        $final = false;
        try {
            while (!$final && ($lenBin = fread($in, 4)) !== false && strlen($lenBin) === 4) {
                $len = unpack('N', $lenBin)[1];
                if ($len <= 0 || $len > (1 << 21)) {
                    throw new \RuntimeException('Sauvegarde corrompue.');
                }
                $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, (string) fread($in, $len));
                if ($res === false) {
                    throw new \RuntimeException('Échec du déchiffrement de la sauvegarde : fichier altéré ou clé incorrecte.');
                }
                [$plain, $tag] = $res;
                fwrite($out, $plain);
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            if (!$final) {
                throw new \RuntimeException('Sauvegarde tronquée.');
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public static function resetCache(): void
    {
        self::$keys = [];
    }
}
