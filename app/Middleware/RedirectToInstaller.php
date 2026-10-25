<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\Install\InstallState;

/** Tant que l'application n'est pas installée, toute page mène à l'assistant d'installation. */
final class RedirectToInstaller implements MiddlewareInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        $path = $request->path();
        if (!InstallState::isInstalled((string) $this->config->get('app.root'))
            && $path !== '/installation' && !str_starts_with($path, '/assets/')) {
            return Response::redirect('/installation');
        }
        return $next($request);
    }
}
