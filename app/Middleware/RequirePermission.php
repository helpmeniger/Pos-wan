<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Audit\SecurityLogger;
use App\Services\Auth\AuthService;
use App\Services\Auth\PermissionService;

/** 'permission:a,b' : l'utilisateur doit détenir TOUTES les permissions listées. */
final class RequirePermission implements MiddlewareInterface
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
        foreach ($args as $permission) {
            if (!$this->permissions->allows((int) $user['id'], $permission)) {
                $this->security->log('access.denied', (int) $user['id'], $request, ['permission' => $permission, 'path' => $request->path()], 'AVERTISSEMENT');
                throw new HttpException(403);
            }
        }
        return $next($request);
    }
}
