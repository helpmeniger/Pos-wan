<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth\RateLimiter;

/** 'throttle:nom' : limite par IP selon security.rate_limits.nom (requêtes non sûres uniquement). */
final class Throttle implements MiddlewareInterface
{
    public function __construct(private readonly RateLimiter $limiter)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        $name = $args[0] ?? 'default';
        if (!$request->isMethodSafe()) {
            $key = $name . ':ip:' . $request->ip();
            if (!$this->limiter->attempt($key, $name)) {
                throw new HttpException(429, '', ['Retry-After' => (string) $this->limiter->retryAfter($key)]);
            }
        }
        return $next($request);
    }
}
