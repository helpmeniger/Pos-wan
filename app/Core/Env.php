<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Lecteur minimal de fichier .env (KEY=VALUE). Le fichier .env est situé
 * HORS du dossier public et n'est jamais versionné.
 */
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
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                $v = substr($v, 1, -1);
            }
            // Les variables d'environnement réelles sont prioritaires (tests, hébergement)
            if (getenv($k) === false) {
                self::$vars[$k] = $v;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $v = self::$vars[$key] ?? getenv($key);
        if ($v === false || $v === null) {
            return $default;
        }
        return match (strtolower((string) $v)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $v,
        };
    }

    public static function set(string $key, string $value): void
    {
        self::$vars[$key] = $value;
    }
}
