<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Clé secrète de l'application (signatures internes, jetons opaques).
 * APP_KEY du .env si défini ; sinon une clé aléatoire est générée une seule fois
 * dans storage/app.key (jamais servi par le Web).
 */
final class Secrets
{
    private static ?string $key = null;

    public static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $env = (string) Env::get('APP_KEY', '');
        if (strlen($env) >= 32) {
            return self::$key = $env;
        }
        $file = MARWA_ROOT . '/storage/app.key';
        $k = is_file($file) ? trim((string) @file_get_contents($file)) : '';
        if (strlen($k) < 32) {
            $k = bin2hex(random_bytes(32));
            if (@file_put_contents($file, $k, LOCK_EX) !== false) {
                @chmod($file, 0600);
            }
        }
        return self::$key = $k;
    }

    public static function hmac(string $purpose, string $data): string
    {
        return hash_hmac('sha256', $purpose . '|' . $data, self::key());
    }
}
