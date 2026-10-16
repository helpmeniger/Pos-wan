<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

final class RequirePermission extends Middleware
{
    public function __construct(private array $permissions)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        Auth::requireUser();
        foreach ($this->permissions as $p) {
            if (!Auth::can($p)) {
                throw HttpException::forbidden('Permission requise : ' . $p);
            }
        }
        return $next($request);
    }
}
