<?php
declare(strict_types=1);

namespace App\Services\Routing;

final class RouteResult
{
    /**
     * @param array<int, array{0: float, 1: float}> $geometry points [lat, lng]
     */
    public function __construct(
        public readonly int $distanceM,
        public readonly int $durationS,
        public readonly array $geometry,
        public readonly string $source,      // osrm | estimate
        public readonly bool $approximate,
        /** Étapes de navigation (manœuvres), vides si le fournisseur ne les donne pas. */
        public readonly array $steps = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'distance_m' => $this->distanceM,
            'duration_s' => $this->durationS,
            'geometry' => $this->geometry,
            'source' => $this->source,
            'approximate' => $this->approximate,
            'steps' => $this->steps,
        ];
    }
}
