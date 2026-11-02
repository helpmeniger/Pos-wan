<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use Closure;

/** Vérifie le jeton CSRF sur toute requête modifiant l'état. */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, ?string $argument = null): Response
    {
        if (!in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $token = $request->input('_token') ?? $request->header('X-CSRF-Token');
            if (!Csrf::verify(is_string($token) ? $token : null)) {
                throw new HttpException(419, 'Jeton de sécurité expiré ou invalide. Rechargez la page et réessayez.');
            }
        }
        return $next($request);
    }
}
