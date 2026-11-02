<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use Closure;

/** Bloque l'assistant d'installation une fois l'application installée. */
final class NotInstalledMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, ?string $argument = null): Response
    {
        if (App::installed()) {
            throw new HttpException(404, "L'application est déjà installée.");
        }
        return $next($request);
    }
}
