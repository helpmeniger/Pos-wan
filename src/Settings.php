<?php
declare(strict_types=1);

namespace Verifia;

/** Paramètres modifiables depuis l'interface (table application_settings). */
final class Settings
{
    private static array $cache = [];

    public static function get(string $key, string $default = ''): string
    {
        if (!array_key_exists($key, self::$cache)) {
            $v = Database::value('SELECT setting_value FROM application_settings WHERE setting_key = ?', [$key]);
            self::$cache[$key] = $v === null ? null : (string) $v;
        }
        return self::$cache[$key] ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) self::get($key, (string) $default);
    }

    public static function set(string $key, string $value): void
    {
        Database::run(
            'INSERT INTO application_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, $value]
        );
        self::$cache[$key] = $value;
    }

    public static function all(): array
    {
        return Database::all('SELECT setting_key, setting_value, description, updated_at FROM application_settings ORDER BY setting_key');
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
