<?php
declare(strict_types=1);

namespace App\Services\Routing;

use App\Core\Geo;

/**
 * Estimation de secours sans service externe : distance à vol d'oiseau multipliée
 * par un facteur routier et vitesse moyenne configurable. Résultat marqué « approximatif ».
 */
final class EstimateRouter implements RoutingProvider
{
    public function __construct(private float $roadFactor = 1.35, private array $speeds = ['car' => 24, 'moto' => 28, 'bicycle' => 14])
    {
    }

    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng, string $profile = 'car'): RouteResult
    {
        $straight = Geo::distance($fromLat, $fromLng, $toLat, $toLng);
        $distance = (int) round($straight * $this->roadFactor);
        $speed = (float) ($this->speeds[$profile] ?? $this->speeds['car']);
        $duration = (int) round(($distance / 1000) / max($speed, 1) * 3600);
        return new RouteResult($distance, max($duration, $distance > 0 ? 60 : 0), [[$fromLat, $fromLng], [$toLat, $toLng]], 'estimate', true);
    }
}
