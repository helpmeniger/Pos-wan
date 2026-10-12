<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

final class RequireRole extends Middleware
{
    public function __construct(private array $roles)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        Auth::requireUser();
        if (!Auth::hasRole(...$this->roles)) {
            throw HttpException::forbidden("Cette fonctionnalité n'est pas accessible à votre profil.");
        }
        return $next($request);
    }
}
