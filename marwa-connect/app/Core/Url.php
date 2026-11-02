<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Génération d'URL compatible WampServer (sous-dossier, avec ou sans mod_rewrite).
 */
final class Url
{
    private static ?string $base = null;
    private static ?bool $rewrite = null;

    /** Chemin de base public (ex. "/marwa-connect"). */
    public static function basePath(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }
        $configured = (string) Env::get('APP_URL', '');
        if ($configured !== '') {
            $path = rtrim((string) parse_url($configured, PHP_URL_PATH), '/');
        } else {
            $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
            $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            // Accès via la réécriture du dossier racine : on retire "/public".
            if (str_ends_with($path, '/public') && !str_starts_with($uri, $path)) {
                $path = substr($path, 0, -7);
            }
        }
        return self::$base = $path;
    }

    /** Vrai si les URL "propres" sont disponibles (mod_rewrite actif). */
    public static function rewriteEnabled(): bool
    {
        if (self::$rewrite === null) {
            $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            self::$rewrite = PHP_SAPI === 'cli' || !str_contains($uri, '/index.php');
        }
        return self::$rewrite;
    }

    public static function to(string $path = '/', array $query = []): string
    {
        $path = '/' . ltrim($path, '/');
        $prefix = self::basePath();
        if (!self::rewriteEnabled()) {
            $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
            $prefix = rtrim(str_replace('\\', '/', $script), '/');
        }
        $url = $prefix . ($path === '/' ? '/' : $path);
        return $query ? $url . '?' . http_build_query($query) : $url;
    }

    public static function asset(string $path): string
    {
        $file = BASE_PATH . '/public/assets/' . ltrim($path, '/');
        $version = is_file($file) ? '?v=' . filemtime($file) : '';
        $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        // Les ressources statiques sont servies depuis le dossier du contrôleur frontal.
        $prefix = self::rewriteEnabled() ? self::basePath() : rtrim($script, '/');
        return $prefix . '/assets/' . ltrim($path, '/') . $version;
    }

    public static function reset(): void
    {
        self::$base = null;
        self::$rewrite = null;
    }
}
