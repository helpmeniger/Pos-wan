<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;

final class Throttle extends Middleware
{
    public function __construct(private string $name, private int $max, private int $seconds)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $who = Auth::id() !== null ? 'u' . Auth::id() : 'ip' . $request->ip();
        RateLimiter::enforce("throttle:{$this->name}:{$who}", $this->max, $this->seconds);
        return $next($request);
    }
}
