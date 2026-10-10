<?php
declare(strict_types=1);

namespace App\Core;

/** Jeton CSRF par session, vérifié sur toute requête modifiant des données. */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function verify(): void
    {
        $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? (Request::json()['_csrf'] ?? '');
        $token = $_SESSION['_csrf'] ?? '';
        if (!is_string($sent) || $token === '' || !hash_equals($token, $sent)) {
            throw new HttpException(419);
        }
    }
}
