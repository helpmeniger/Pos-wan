<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use Closure;

/** Usage : 'can:users.manage' ou 'can:sales.view|sales.create' (l'une des permissions). */
final class PermissionMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, ?string $argument = null): Response
    {
        Auth::authorize(...explode('|', (string) $argument));
        return $next($request);
    }
}
