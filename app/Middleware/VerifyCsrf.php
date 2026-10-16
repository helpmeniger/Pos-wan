<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Audit\SecurityLogger;

/**
 * Exige un jeton CSRF valide sur toute requête modifiante, sauf :
 *  - webhooks (authentifiés par signature),
 *  - API appelée avec un jeton Bearer (pas de cookie de session → pas de CSRF possible).
 */
final class VerifyCsrf implements MiddlewareInterface
{
    public function __construct(private readonly Csrf $csrf, private readonly SecurityLogger $security)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        if ($request->isMethodSafe() || str_starts_with($request->path(), '/webhooks/')) {
            return $next($request);
        }
        if (str_starts_with($request->path(), '/api/') && str_starts_with((string) $request->header('Authorization'), 'Bearer ')) {
            return $next($request);
        }
        $token = $request->input('_token');
        $token = is_string($token) ? $token : $request->header('X-CSRF-Token');
        if (!$this->csrf->validate($token)) {
            $this->security->log('csrf.invalid', null, $request, ['path' => $request->path()], 'AVERTISSEMENT');
            throw new HttpException(419);
        }
        return $next($request);
    }
}
