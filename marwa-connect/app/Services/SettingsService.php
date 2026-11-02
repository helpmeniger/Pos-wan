<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Database;

/**
 * Paramètres applicatifs stockés en base (avec cache mémoire par requête).
 */
final class SettingsService
{
    private static ?array $cache = null;

    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        if (!App::installed()) {
            return self::$cache;
        }
        try {
            foreach (Database::fetchAll('SELECT `key`, `value`, `type` FROM settings') as $row) {
                self::$cache[$row['key']] = self::cast($row['value'], $row['type']);
            }
        } catch (\Throwable) {
            // Base indisponible : valeurs par défaut.
        }
        return self::$cache;
    }

    private static function cast(?string $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'bool' => $value === '1' || $value === 'true',
            'json' => json_decode((string) $value, true),
            'encrypted' => $value ? Crypto::decrypt($value) : '',
            default => $value,
        };
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::load()[$key] ?? $default;
    }

    public static function group(string $group): array
    {
        $rows = Database::fetchAll('SELECT `key`, `value`, `type` FROM settings WHERE `group` = :g ORDER BY `key`', ['g' => $group]);
        $out = [];
        foreach ($rows as $r) {
            $out[$r['key']] = self::cast($r['value'], $r['type']);
        }
        return $out;
    }

    public static function set(string $key, mixed $value, ?string $type = null, ?string $group = null, ?int $userId = null): void
    {
        $existing = Database::fetch('SELECT `type`, `group` FROM settings WHERE `key` = :k', ['k' => $key]);
        $type ??= $existing['type'] ?? 'string';
        $group ??= $existing['group'] ?? explode('.', $key)[0];
        $stored = match ($type) {
            'bool' => $value ? '1' : '0',
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE),
            'encrypted' => $value === '' || $value === null ? '' : Crypto::encrypt((string) $value),
            default => (string) $value,
        };
        Database::query(
            'INSERT INTO settings (`group`, `key`, `value`, `type`, updated_by) VALUES (:g, :k, :v, :t, :u)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`), updated_by = VALUES(updated_by)',
            ['g' => $group, 'k' => $key, 'v' => $stored, 't' => $type, 'u' => $userId]
        );
        self::$cache = null;
    }
}
