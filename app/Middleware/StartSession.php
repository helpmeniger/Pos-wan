<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

final class StartSession implements MiddlewareInterface
{
    public function __construct(private readonly Session $session, private readonly View $view)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        // Pas de session pour les webhooks serveur-à-serveur
        if (!str_starts_with($request->path(), '/webhooks/')) {
            $this->session->start($request->isSecure());
            $this->view->share('currentPath', $request->path());
        }
        return $next($request);
    }
}
