<?php
declare(strict_types=1);

namespace App\Core;

/** Cookies d'authentification (HttpOnly, SameSite=Lax, Secure en HTTPS) ; mémorisés en ligne de commande pour les tests. */
final class Cookie
{
    public static array $sent = [];

    public static function set(string $name, string $value, int $expires): void
    {
        self::$sent[$name] = $expires > time() ? $value : null;
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie($name, $value, ['expires' => $expires, 'path' => Paths::base() . '/', 'secure' => (bool) Config::get('security.cookie_secure'),
            'httponly' => true, 'samesite' => 'Lax']);
    }

    public static function forget(string $name): void
    {
        self::set($name, '', time() - 3600);
    }
}
