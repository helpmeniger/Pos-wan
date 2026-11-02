<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Registre des alias de middlewares.
 */
final class Middleware
{
    private const ALIASES = [
        'auth' => \App\Middleware\AuthMiddleware::class,
        'guest' => \App\Middleware\GuestMiddleware::class,
        'csrf' => \App\Middleware\CsrfMiddleware::class,
        'can' => \App\Middleware\PermissionMiddleware::class,
        'installed' => \App\Middleware\InstalledMiddleware::class,
        'not_installed' => \App\Middleware\NotInstalledMiddleware::class,
        'throttle' => \App\Middleware\ThrottleMiddleware::class,
    ];

    public static function resolve(string $alias): string
    {
        return self::ALIASES[$alias] ?? throw new \InvalidArgumentException("Middleware inconnu : {$alias}");
    }
}
