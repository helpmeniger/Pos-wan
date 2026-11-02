<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Chargeur de fichier .env (format CLE=valeur).
 */
final class Env
{
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(string $file): void
    {
        self::$loaded = is_file($file);
        if (!self::$loaded) {
            return;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $quote = $value[0];
                $value = substr($value, 1, -1);
                if ($quote === '"') {
                    $value = str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
                }
            }
            self::$values[$key] = $value;
        }
    }

    public static function exists(): bool
    {
        return self::$loaded;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$values[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }
        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }

    /** Écrit un fichier .env à partir d'un tableau associatif. */
    public static function write(string $file, array $values): bool
    {
        $out = "# MARWA CONNECT — fichier généré le " . date('Y-m-d H:i:s') . "\n# Ne jamais partager ce fichier : il contient des secrets.\n\n";
        foreach ($values as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $value = (string) $value;
            if ($value !== '' && preg_match('/[\s#"\'=]/', $value)) {
                $value = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
            }
            $out .= $key . '=' . $value . "\n";
        }
        return file_put_contents($file, $out, LOCK_EX) !== false;
    }
}
