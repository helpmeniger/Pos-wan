<?php
declare(strict_types=1);

namespace App\Services\Routing;

interface RoutingProvider
{
    /** @throws \RuntimeException si l'itinéraire ne peut être calculé */
    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng, string $profile = 'car'): RouteResult;
}
