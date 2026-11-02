<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use Closure;

/** Réservé aux visiteurs non connectés (page de connexion). */
final class GuestMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, ?string $argument = null): Response
    {
        return Auth::check() ? Response::redirect('/') : $next($request);
    }
}
