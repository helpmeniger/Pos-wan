<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\Auth\AuthService;

final class RedirectIfAuthenticated implements MiddlewareInterface
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        if ($this->auth->user() !== null) {
            return Response::redirect('/tableau-de-bord');
        }
        return $next($request);
    }
}
