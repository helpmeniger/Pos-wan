<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/** En-têtes de sécurité HTTP appliqués à toutes les réponses. */
final class SecurityHeaders implements MiddlewareInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        return $this->apply($request, $next($request));
    }

    /** Appliqué aussi par le noyau aux pages d'erreur (exceptions qui ne repassent pas par ce middleware). */
    public function apply(Request $request, Response $response): Response
    {
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",           // attributs style ponctuels (barres de progression)
            "img-src 'self' data: https://*.tile.openstreetmap.org",
            "font-src 'self'",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
        $response
            ->withHeader('Content-Security-Policy', $csp)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'geolocation=(self), camera=(), microphone=(), payment=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin');
        if ($request->isSecure() && $this->config->get('security.hsts')) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        // Pages personnelles : pas de mise en cache par le navigateur ni les proxys
        if ($response->header('Cache-Control') === null) {
            $response->withHeader('Cache-Control', 'no-store, private');
        }
        return $response;
    }
}
