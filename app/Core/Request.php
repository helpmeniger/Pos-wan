<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    private static ?array $json = null;

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** Chemin de la route, sans le préfixe d'installation (ex. /pos-wan/public). */
    public static function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = base_path_prefix();
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        } elseif ($base !== '' && str_ends_with($base, '/public') && ($parent = substr($base, 0, -7)) !== '' && str_starts_with($uri, $parent . '/')) {
            // Accès via la racine du projet réécrite vers public/ (ex. /pos-wan/login)
            $uri = substr($uri, strlen($parent));
        }
        if (str_starts_with($uri, '/index.php')) {
            $uri = substr($uri, 10);
        }
        $uri = '/' . trim(rawurldecode($uri), '/');
        return $uri;
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        $json = self::json();
        return $_POST[$key] ?? $json[$key] ?? $_GET[$key] ?? $default;
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::input($key);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function num(string $key, float $default = 0.0): float
    {
        $v = self::input($key);
        if (is_string($v)) {
            $v = str_replace([' ', "\u{202F}", "\u{00A0}", ','], ['', '', '', '.'], $v);
        }
        return is_numeric($v) ? (float) $v : $default;
    }

    public static function json(): array
    {
        if (self::$json === null) {
            self::$json = [];
            if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
                $raw = file_get_contents('php://input') ?: '';
                $d = json_decode($raw, true);
                self::$json = is_array($d) ? $d : [];
            }
        }
        return self::$json;
    }

    public static function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
            || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    public static function ip(): string
    {
        // On ne fait pas confiance à X-Forwarded-For (falsifiable) : application locale.
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
