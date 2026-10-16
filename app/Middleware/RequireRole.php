<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Audit\SecurityLogger;
use App\Services\Auth\AuthService;
use App\Services\Auth\PermissionService;

/** 'role:WORKER,CLIENT' : l'utilisateur doit détenir AU MOINS un des rôles listés. */
final class RequireRole implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly SecurityLogger $security,
    ) {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            throw new HttpException(401);
        }
        foreach ($args as $role) {
            if ($this->permissions->hasRole((int) $user['id'], $role)) {
                return $next($request);
            }
        }
        $this->security->log('access.denied', (int) $user['id'], $request, ['roles' => $args, 'path' => $request->path()], 'AVERTISSEMENT');
        throw new HttpException(403);
    }
}
