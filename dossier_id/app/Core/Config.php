<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static array $data = [];

    public static function load(?string $file = null): void
    {
        $file ??= getenv('DOSSIER_ID_CONFIG') ?: BASE_PATH . '/config/config.php';
        if (!is_file($file)) {
            throw new \RuntimeException(
                'Fichier de configuration introuvable. Copiez config/config.example.php en config/config.php.'
            );
        }
        self::$data = require $file;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $ref = &self::$data;
        foreach (explode('.', $key) as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
    }

    public static function isDemo(): bool
    {
        return self::get('app.env') !== 'production';
    }
}
