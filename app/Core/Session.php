<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Sessions PHP durcies : cookie HttpOnly + SameSite, Secure en HTTPS,
 * expiration d'inactivité, durée absolue et rotation périodique de l'identifiant.
 * En CLI (tests), un tableau en mémoire remplace la session native.
 */
final class Session
{
    private static bool $started = false;
    private static array $memory = [];

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        self::$started = true;
        if (PHP_SAPI === 'cli') {
            return;
        }
        $secure = (bool) Config::get('security.cookie_secure');
        $dir = MARWA_ROOT . '/storage/sessions';
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        session_name((string) Config::get('security.session_name', 'MARWASESSID'));
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) ((int) Config::get('security.session_idle_minutes', 120) * 60));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => Paths::base() . '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        $idle = (int) Config::get('security.session_idle_minutes', 120) * 60;
        $absolute = (int) Config::get('security.session_absolute_hours', 12) * 3600;
        $created = $_SESSION['_created'] ?? $now;
        $last = $_SESSION['_last'] ?? $now;
        if (($now - $last) > $idle || ($now - $created) > $absolute) {
            self::destroy();
            session_start();
            $created = $now;
        }
        $_SESSION['_created'] = $created;
        $_SESSION['_last'] = $now;
        if (($now - ($_SESSION['_rotated'] ?? 0)) > 900) {
            session_regenerate_id(true);
            $_SESSION['_rotated'] = $now;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return PHP_SAPI === 'cli' ? (self::$memory[$key] ?? $default) : ($_SESSION[$key] ?? $default);
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        if (PHP_SAPI === 'cli') {
            self::$memory[$key] = $value;
        } else {
            $_SESSION[$key] = $value;
        }
    }

    public static function forget(string $key): void
    {
        self::start();
        if (PHP_SAPI === 'cli') {
            unset(self::$memory[$key]);
        } else {
            unset($_SESSION[$key]);
        }
    }

    public static function regenerate(): void
    {
        self::start();
        if (PHP_SAPI !== 'cli') {
            session_regenerate_id(true);
            $_SESSION['_rotated'] = time();
        }
    }

    public static function destroy(): void
    {
        if (PHP_SAPI === 'cli') {
            self::$memory = [];
            return;
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            session_destroy();
        }
    }

    public static function reset(): void
    {
        self::$memory = [];
    }
}
