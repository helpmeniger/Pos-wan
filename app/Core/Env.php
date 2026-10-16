<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Chargeur minimal de fichier .env (KEY=VALUE, commentaires #, guillemets simples/doubles).
 * Les variables déjà définies dans l'environnement du serveur sont prioritaires.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }
            if (preg_match('/^"(.*)"$/s', $value, $m)) {
                $value = stripcslashes($m[1]);
            } elseif (preg_match("/^'(.*)'$/s", $value, $m)) {
                $value = $m[1];
            } else {
                $value = trim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
            }
            if (getenv($key) === false && !isset($_SERVER[$key])) {
                self::$values[$key] = $value;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $raw = getenv($key);
        if ($raw === false) {
            $raw = $_SERVER[$key] ?? self::$values[$key] ?? null;
        }
        if ($raw === null) {
            return $default;
        }
        return match (strtolower((string) $raw)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $raw,
        };
    }

    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }
}
