<?php
declare(strict_types=1);

namespace App\Core;

/** Paramètres configurables (table system_settings), avec surcharge par magasin. */
final class Settings
{
    private static array $cache = [];

    public static function get(string $key, mixed $default = null, ?int $storeId = null): mixed
    {
        $ck = ($storeId ?? 0) . '|' . $key;
        if (!array_key_exists($ck, self::$cache)) {
            $row = Database::fetch(
                'SELECT setting_value, value_type FROM system_settings WHERE setting_key = :k AND (store_id = :s OR store_id IS NULL)
                 ORDER BY store_id IS NULL ASC LIMIT 1',
                ['k' => $key, 's' => $storeId ?? 0]
            );
            self::$cache[$ck] = $row ? self::cast($row['setting_value'], $row['value_type']) : null;
        }
        return self::$cache[$ck] ?? $default;
    }

    public static function set(string $key, string $value, ?int $userId = null): void
    {
        Database::execute('UPDATE system_settings SET setting_value = :v, updated_by = :u WHERE setting_key = :k AND store_id IS NULL',
            ['v' => $value, 'u' => $userId, 'k' => $key]);
        self::$cache = [];
    }

    public static function all(): array
    {
        return Database::fetchAll('SELECT * FROM system_settings WHERE store_id IS NULL ORDER BY setting_key');
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    private static function cast(string $v, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $v,
            'float' => (float) $v,
            'bool' => in_array(strtolower($v), ['1', 'true', 'oui', 'yes'], true),
            'json' => json_decode($v, true),
            default => $v,
        };
    }
}
