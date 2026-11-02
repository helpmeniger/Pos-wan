<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\RateLimiter;
use Closure;

/**
 * Limitation de débit générique. Usage : 'throttle:60,1' (60 requêtes par minute et par IP+route).
 */
final class ThrottleMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, ?string $argument = null): Response
    {
        [$max, $minutes] = array_map('intval', array_pad(explode(',', (string) ($argument ?: '60,1')), 2, 1));
        $key = 'route:' . $request->path . ':' . $request->ip();
        if (!RateLimiter::hit($key, $max, $minutes * 60)) {
            throw new HttpException(429, 'Trop de requêtes. Patientez quelques instants.', ['Retry-After' => (string) ($minutes * 60)]);
        }
        return $next($request);
    }
}
