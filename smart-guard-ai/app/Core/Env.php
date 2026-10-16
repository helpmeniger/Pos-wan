<?php
declare(strict_types=1);

namespace App\Core;

/** Chargeur minimal de fichier .env (aucune dépendance externe). */
final class Env
{
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
                $value = substr($value, 1, -1);
            }
            self::$vars[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $v = self::$vars[$key] ?? getenv($key);
        return ($v === false || $v === null || $v === '') ? $default : $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        return $v === null ? $default : in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on', 'oui'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function list(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) self::get($key, '')))));
    }

    /** Pour les tests uniquement. */
    public static function set(string $key, string $value): void
    {
        self::$vars[$key] = $value;
    }
}
