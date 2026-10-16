<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Geo;
use App\Core\HttpException;
use App\Core\Settings;
use App\Services\Routing\RoutingService;

/**
 * Suivi GPS réel : les positions proviennent exclusivement de l'appareil du prestataire
 * (navigator.geolocation). Aucune position n'est simulée. Historique conservé uniquement
 * pendant une commande active, puis purgé selon tracking.history_retention_days.
 */
final class TrackingService
{
    public static function updateLocation(int $providerId, float $lat, float $lng, ?int $heading, ?float $speedKmh, ?int $accuracy): array
    {
        $p = Database::first('SELECT is_online, current_order_id FROM provider_profiles WHERE user_id = :id', ['id' => $providerId]);
        if (!$p) {
            throw HttpException::forbidden();
        }
        if (!(int) $p['is_online'] && !$p['current_order_id']) {
            throw HttpException::conflict('Passez « En ligne » pour partager votre position.', 'offline');
        }
        $last = Database::first('SELECT lat, lng, updated_at FROM provider_locations WHERE provider_id = :id', ['id' => $providerId]);
        $min = Settings::int('tracking.min_interval_seconds', 3);
        if ($last && strtotime((string) $last['updated_at']) > time() - $min) {
            return ['accepted' => false, 'reason' => 'too_frequent'];
        }
        if ($heading === null && $last) {
            $moved = Geo::distance((float) $last['lat'], (float) $last['lng'], $lat, $lng);
            if ($moved > 8) {
                $heading = (int) round(Geo::bearing((float) $last['lat'], (float) $last['lng'], $lat, $lng));
            }
        }
        $now = date('Y-m-d H:i:s');
        Database::execute(
            'INSERT INTO provider_locations (provider_id, lat, lng, heading, speed_kmh, accuracy_m, updated_at) VALUES (:p, :la, :ln, :h, :s, :a, :u)
             ON DUPLICATE KEY UPDATE lat = VALUES(lat), lng = VALUES(lng), heading = COALESCE(VALUES(heading), heading), speed_kmh = VALUES(speed_kmh), accuracy_m = VALUES(accuracy_m), updated_at = VALUES(updated_at)',
            ['p' => $providerId, 'la' => $lat, 'ln' => $lng, 'h' => $heading, 's' => $speedKmh, 'a' => $accuracy, 'u' => $now]
        );
        if ($p['current_order_id']) {
            $status = Database::value('SELECT status FROM orders WHERE id = :id', ['id' => $p['current_order_id']]);
            if (in_array($status, OrderStateMachine::TRACKABLE, true)) {
                Database::insert('driver_locations', ['order_id' => $p['current_order_id'], 'provider_id' => $providerId, 'lat' => $lat, 'lng' => $lng,
                    'heading' => $heading, 'speed_kmh' => $speedKmh, 'recorded_at' => $now]);
            }
        }
        return ['accepted' => true, 'at' => $now];
    }

    /** Vue de suivi pour une personne autorisée (client, prestataire affecté, commerce, admin). */
    public static function forOrder(array $order, string $viewer): array
    {
        $staleAfter = Settings::int('tracking.stale_after_seconds', 45);
        $out = [
            'order_id' => (int) $order['id'],
            'status' => $order['status'],
            'status_label' => OrderStateMachine::label($order['status']),
            'provider_stage' => $order['provider_stage'],
            'pickup' => ['lat' => (float) $order['pickup_lat'], 'lng' => (float) $order['pickup_lng'], 'text' => $order['pickup_text']],
            'dropoff' => ['lat' => (float) $order['dropoff_lat'], 'lng' => (float) $order['dropoff_lng'], 'text' => $order['dropoff_text']],
            'route' => json_decode((string) $order['route_geometry'], true) ?: [],
            'route_source' => $order['route_source'],
            'vehicle' => null,
            'position' => null,
            'eta' => null,
            'trail' => [],
        ];
        $shareDuringRide = $order['flow'] !== 'ride' || (int) $order['share_tracking'] === 1 || $order['status'] !== 'in_progress';
        if (!$order['provider_id'] || !in_array($order['status'], OrderStateMachine::TRACKABLE, true) || ($viewer === 'customer' && !$shareDuringRide)) {
            return $out;
        }
        $v = Database::first(
            'SELECT v.category, v.make, v.model, v.color, v.plate, u.full_name, p.provider_type
               FROM provider_profiles p JOIN users u ON u.id = p.user_id LEFT JOIN vehicles v ON v.provider_id = p.user_id AND v.is_active = 1
              WHERE p.user_id = :id ORDER BY v.id LIMIT 1',
            ['id' => $order['provider_id']]
        );
        $out['vehicle'] = [
            'icon' => self::iconFor($v['category'] ?? null, $v['provider_type'] ?? 'driver'),
            'category' => $v['category'] ?? null,
            'label' => trim(($v['make'] ?? '') . ' ' . ($v['model'] ?? '') . ' ' . ($v['color'] ?? '')),
            'plate' => $v['plate'] ?? null,
            'driver' => \App\Core\Str::firstName((string) ($v['full_name'] ?? '')),
        ];
        $loc = Database::first('SELECT lat, lng, heading, speed_kmh, accuracy_m, updated_at FROM provider_locations WHERE provider_id = :id', ['id' => $order['provider_id']]);
        if ($loc) {
            $age = time() - strtotime((string) $loc['updated_at']);
            $out['position'] = [
                'lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng'],
                'heading' => $loc['heading'] !== null ? (int) $loc['heading'] : null,
                'speed_kmh' => $loc['speed_kmh'] !== null ? (float) $loc['speed_kmh'] : null,
                'accuracy_m' => $loc['accuracy_m'] !== null ? (int) $loc['accuracy_m'] : null,
                'updated_at' => $loc['updated_at'],
                'age_seconds' => $age,
                'stale' => $age > $staleAfter,
            ];
            $toPickup = in_array($order['status'], ['assigned', 'en_route_pickup', 'accepted', 'preparing', 'ready'], true);
            $target = $toPickup ? $out['pickup'] : $out['dropoff'];
            $route = RoutingService::route((float) $loc['lat'], (float) $loc['lng'], $target['lat'], $target['lng'], RoutingService::profileFor($v['category'] ?? null));
            $out['eta'] = [
                'target' => $toPickup ? 'pickup' : 'dropoff',
                'remaining_m' => $route->distanceM,
                'remaining_s' => $route->durationS,
                'arrival_at' => date('Y-m-d H:i:s', time() + $route->durationS),
                'approximate' => $route->approximate,
            ];
            if ($route->source === 'osrm' && count($route->geometry) > 2) {
                $out['remaining_route'] = $route->geometry;
            }
        }
        if (in_array($viewer, ['customer', 'admin', 'provider'], true)) {
            $out['trail'] = array_map(static fn ($r) => [(float) $r['lat'], (float) $r['lng']], array_reverse(Database::select(
                'SELECT lat, lng FROM driver_locations WHERE order_id = :o ORDER BY id DESC LIMIT 200', ['o' => $order['id']]
            )));
        }
        return $out;
    }

    public static function iconFor(?string $category, string $providerType): string
    {
        return match ($category) {
            'moto', 'moto_taxi' => 'moto',
            'bicycle' => 'bicycle',
            'van' => 'van',
            null => $providerType === 'courier' ? 'moto' : 'car',
            default => 'car',
        };
    }

    public static function purgeHistory(): int
    {
        $days = Settings::int('tracking.history_retention_days', 30);
        return Database::execute('DELETE FROM driver_locations WHERE recorded_at < DATE_SUB(NOW(), INTERVAL :d DAY)', ['d' => $days]);
    }
}
