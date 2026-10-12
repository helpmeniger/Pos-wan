<?php
declare(strict_types=1);

namespace Verifia;

/** Accès en lecture à config/config.php (notation pointée : "db.dsn"). */
final class Config
{
    private static ?array $data = null;

    public static function load(?string $file = null): void
    {
        $file ??= getenv('VERIFIA_CONFIG') ?: dirname(__DIR__) . '/config/config.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Fichier de configuration introuvable : $file. Lancez scripts\\windows\\install.bat.");
        }
        self::$data = require $file;
    }

    public static function set(array $data): void
    {
        self::$data = $data;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$data === null) {
            self::load();
        }
        $node = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public static function storagePath(string $sub = ''): string
    {
        $base = rtrim((string) self::get('storage.path', dirname(__DIR__) . '/storage'), '/\\');
        return $sub === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($sub, '/\\');
    }
}
