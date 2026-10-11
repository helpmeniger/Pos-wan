<?php
declare(strict_types=1);

namespace App\Core;

/** Paramètres modifiables par l'administrateur (table settings). */
final class Settings
{
    private static ?array $cache = null;

    public const DEFAULTS = [
        'institution_name'        => 'Service administratif (à configurer)',
        'session_idle_minutes'    => '15',
        'session_max_hours'       => '8',
        'login_max_attempts'      => '5',
        'lockout_minutes'         => '15',
        'account_inactivity_days' => '90',
        'password_min_length'     => '12',
    ];

    /** Bornes de validation des paramètres numériques. */
    public const BOUNDS = [
        'session_idle_minutes'    => [5, 60],
        'session_max_hours'       => [1, 12],
        'login_max_attempts'      => [3, 10],
        'lockout_minutes'         => [5, 1440],
        'account_inactivity_days' => [15, 365],
        'password_min_length'     => [10, 64],
    ];

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            try {
                foreach (Database::all('SELECT setting_key, setting_value FROM settings') as $row) {
                    self::$cache[$row['setting_key']] = $row['setting_value'];
                }
            } catch (\PDOException) {
                // base non initialisée : valeurs par défaut
            }
        }
        return self::$cache;
    }

    public static function get(string $key): string
    {
        return (string) (self::all()[$key] ?? self::DEFAULTS[$key] ?? '');
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function set(string $key, string $value, ?int $userId): void
    {
        Database::run(
            'INSERT INTO settings (setting_key, setting_value, updated_at, updated_by) VALUES (:k, :v, NOW(), :u)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW(), updated_by = VALUES(updated_by)',
            ['k' => $key, 'v' => $value, 'u' => $userId]
        );
        self::$cache = null;
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }
}
