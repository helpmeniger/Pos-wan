<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Geo;
use App\Services\Routing\RoutingService;

/**
 * Navigation intégrée pour le prestataire affecté : itinéraire depuis sa position GPS
 * réelle vers le point à atteindre (départ / commerce, puis destination), avec des
 * consignes en français générées à partir des manœuvres OSRM.
 */
final class NavigationService
{
    public static function forProvider(array $order, float $lat, float $lng): array
    {
        $toPickup = in_array($order['status'], ['assigned', 'en_route_pickup', 'arrived_pickup', 'accepted', 'preparing', 'ready'], true)
            && !in_array($order['provider_stage'] ?? null, ['picked_up'], true);
        $target = $toPickup
            ? ['kind' => $order['flow'] === 'merchant' ? 'merchant' : 'pickup', 'text' => $order['pickup_text'], 'lat' => (float) $order['pickup_lat'], 'lng' => (float) $order['pickup_lng']]
            : ['kind' => 'dropoff', 'text' => $order['dropoff_text'], 'lat' => (float) $order['dropoff_lat'], 'lng' => (float) $order['dropoff_lng']];
        $category = Database::value('SELECT category FROM vehicles WHERE provider_id = :p AND is_active = 1 ORDER BY id LIMIT 1', ['p' => $order['provider_id']]);
        $route = RoutingService::navigate($lat, $lng, $target['lat'], $target['lng'], RoutingService::profileFor($category ? (string) $category : null));

        $steps = array_map(static fn ($s) => $s + ['instruction' => self::instruction($s)], $route->steps);
        return [
            'target' => $target,
            'distance_m' => $route->distanceM,
            'duration_s' => $route->durationS,
            'arrival_at' => date('Y-m-d H:i:s', time() + $route->durationS),
            'geometry' => $route->geometry,
            'steps' => $steps,
            'source' => $route->source,
            'approximate' => $route->approximate,
            'bearing' => (int) round(Geo::bearing($lat, $lng, $target['lat'], $target['lng'])),
            'computed_at' => date('Y-m-d H:i:s'),
        ];
    }

    private const DIRECTIONS = [
        'uturn' => 'faites demi-tour', 'sharp right' => 'tournez franchement à droite', 'right' => 'tournez à droite',
        'slight right' => 'serrez à droite', 'straight' => 'continuez tout droit', 'slight left' => 'serrez à gauche',
        'left' => 'tournez à gauche', 'sharp left' => 'tournez franchement à gauche',
    ];

    /** Consigne lisible pour une manœuvre OSRM (types documentés par le projet OSRM). */
    public static function instruction(array $s): string
    {
        $type = (string) ($s['type'] ?? '');
        $mod = (string) ($s['modifier'] ?? '');
        $name = trim((string) ($s['name'] ?? ''));
        $on = $name !== '' ? ' sur ' . $name : '';
        $dir = self::DIRECTIONS[$mod] ?? 'continuez';
        $text = match ($type) {
            'depart' => 'Démarrez' . ($name !== '' ? ' sur ' . $name : '') . ($mod && $mod !== 'straight' ? ' puis ' . $dir : ''),
            'arrive' => 'Vous êtes arrivé' . ($mod === 'left' ? ' (sur votre gauche)' : ($mod === 'right' ? ' (sur votre droite)' : '')),
            'roundabout', 'rotary' => 'Au rond-point, prenez la ' . self::ordinal((int) ($s['exit'] ?? 1)) . ' sortie' . $on,
            'exit roundabout', 'exit rotary' => 'Sortez du rond-point' . $on,
            'roundabout turn' => 'Au rond-point, ' . $dir . $on,
            'merge' => 'Insérez-vous' . $on,
            'on ramp' => 'Prenez la bretelle' . $on,
            'off ramp' => 'Sortez' . $on,
            'fork' => ($mod !== '' && str_contains($mod, 'left') ? 'À l\'embranchement, restez à gauche' : (str_contains($mod, 'right') ? 'À l\'embranchement, restez à droite' : 'À l\'embranchement, continuez')) . $on,
            'end of road' => 'Au bout de la route, ' . $dir . $on,
            'new name', 'continue', 'notification' => ($mod === '' || $mod === 'straight' ? 'Continuez tout droit' : ucfirst($dir)) . $on,
            default => ucfirst($dir) . $on,
        };
        return ucfirst($text);
    }

    private static function ordinal(int $n): string
    {
        return match (true) {
            $n <= 1 => '1re',
            default => $n . 'e',
        };
    }
}
