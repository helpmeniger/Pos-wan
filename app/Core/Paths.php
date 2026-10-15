<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Détection automatique des chemins d'URL de l'application, à partir de la requête réelle.
 *
 * L'application fonctionne ainsi quel que soit le dossier d'installation
 * (http://localhost/marwa, http://localhost/autre-nom/marwa, VirtualHost…), sans dépendre
 * de la valeur de APP_URL, et même si le module Apache mod_rewrite est désactivé :
 *  - avec réécriture : liens « /marwa/connexion », fichiers statiques « /marwa/assets/… » ;
 *  - sans réécriture  : liens « /marwa/index.php/connexion », fichiers « /marwa/public/assets/… ».
 *
 * En ligne de commande (worker, scripts), le chemin de APP_URL est utilisé.
 */
final class Paths
{
    /** Préfixe des routes (ex. « /marwa » ou « /marwa/index.php »). */
    private static ?string $routes = null;
    /** Dossier d'installation vu depuis le navigateur (ex. « /marwa »). */
    private static ?string $base = null;
    /** Préfixe des fichiers statiques du dossier public/ (ex. « /marwa » ou « /marwa/public »). */
    private static ?string $public = null;
    private static bool $rewrite = true;

    /**
     * @param string $entry « public » (public/index.php) ou « root » (index.php à la racine du projet)
     */
    public static function detect(array $server, string $entry = 'public'): void
    {
        if (PHP_SAPI === 'cli-server') {
            // Serveur de développement PHP (bin/dev-router.php) : toujours servi à la racine.
            self::set('', '', '', true);
            return;
        }
        $script = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? '/index.php'));
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/.');

        if ($entry === 'root') {
            // index.php racine : utilisé uniquement quand la réécriture d'URL n'est pas disponible.
            self::set($dir, $dir . '/index.php', $dir . '/public', false);
            return;
        }

        $rewrite = self::rewriteAvailable($server);
        if ($rewrite) {
            // Accès par le .htaccess racine (« /marwa/public/index.php ») ou par un VirtualHost sur public/.
            $base = str_ends_with($dir, '/public') ? substr($dir, 0, -7) : $dir;
            self::set($base, $base, $base, true);
        } else {
            self::set($dir, $dir . '/index.php', $dir, false);
        }
    }

    private static function rewriteAvailable(array $server): bool
    {
        $forced = Env::get('APP_URL_REWRITE');
        if (is_bool($forced)) {
            return $forced;
        }
        foreach (['MARWA_REWRITE', 'REDIRECT_MARWA_REWRITE', 'REDIRECT_REDIRECT_MARWA_REWRITE'] as $k) {
            if (!empty($server[$k])) {
                return true;
            }
        }
        // Nginx : la configuration fournie renvoie toutes les URL vers index.php (try_files).
        return str_contains(strtolower((string) ($server['SERVER_SOFTWARE'] ?? '')), 'nginx');
    }

    private static function set(string $base, string $routes, string $public, bool $rewrite): void
    {
        self::$base = $base;
        self::$routes = $routes;
        self::$public = $public;
        self::$rewrite = $rewrite;
    }

    public static function reset(): void
    {
        self::$base = self::$routes = self::$public = null;
        self::$rewrite = true;
    }

    private static function configured(): string
    {
        return rtrim((string) parse_url((string) Config::get('app.url', ''), PHP_URL_PATH), '/');
    }

    public static function base(): string
    {
        return self::$base ?? self::configured();
    }

    public static function routes(): string
    {
        return self::$routes ?? self::configured();
    }

    public static function publicBase(): string
    {
        return self::$public ?? self::configured();
    }

    public static function rewrite(): bool
    {
        return self::$rewrite;
    }

    /** Adresse absolue de l'application telle qu'elle est utilisée par le navigateur. */
    public static function absolute(array $server): string
    {
        $https = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') || (int) ($server['SERVER_PORT'] ?? 80) === 443;
        $host = (string) ($server['HTTP_HOST'] ?? 'localhost');
        if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$|^\[[0-9a-fA-F:]+\](:\d{1,5})?$/', $host)) {
            $host = 'localhost';
        }
        return ($https ? 'https' : 'http') . '://' . $host . self::base();
    }
}
