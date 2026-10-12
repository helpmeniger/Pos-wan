<?php
declare(strict_types=1);

namespace App\Core;

/** Paramètres métier modifiables par l'administration (table settings, valeurs JSON). */
final class Settings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Database::select('SELECT k, v FROM settings') as $row) {
                self::$cache[$row['k']] = json_decode($row['v'], true);
            }
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function int(string $key, int $default): int
    {
        return (int) self::get($key, $default);
    }

    public static function set(string $key, mixed $value, ?int $userId = null): void
    {
        Database::execute(
            'INSERT INTO settings (k, v, updated_by) VALUES (:k, :v, :u) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_by = VALUES(updated_by)',
            ['k' => $key, 'v' => json_encode($value, JSON_UNESCAPED_UNICODE), 'u' => $userId]
        );
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
