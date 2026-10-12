<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

final class RequireActive extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $u = Auth::requireUser();
        if ($u['status'] !== 'active') {
            throw HttpException::forbidden('Votre compte est en attente de validation.');
        }
        return $next($request);
    }
}
