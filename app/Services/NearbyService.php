<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Geo;
use App\Core\Secrets;
use App\Core\Settings;

/**
 * Véhicules disponibles autour d'un client (carte « taxis proches »).
 *
 * Protection de la vie privée des prestataires : aucune identité, identifiant opaque
 * changeant chaque jour, position décalée de façon stable d'environ 50 à 100 m et arrondie,
 * seulement les prestataires en ligne, libres et dont la position date de moins de 2 minutes.
 * Les positions sont réelles (GPS des prestataires) : rien n'est simulé.
 */
final class NearbyService
{
    public static function around(float $lat, float $lng, string $service = 'taxi'): array
    {
        $radiusKm = (float) Settings::get('nearby.radius_km', 5);
        $maxAge = Settings::int('nearby.location_max_age_s', 120);
        $limit = Settings::int('nearby.max_vehicles', 20);
        $type = in_array($service, ['taxi', 'pro'], true) ? 'driver' : 'courier';
        $categories = $type === 'driver' ? PricingService::RIDE_VEHICLES : PricingService::COURIER_VEHICLES;
        [$inCat, $catParams] = Database::in($categories, 'cat');
        $dLat = $radiusKm / 111.0;
        $dLng = $radiusKm / (111.0 * max(0.2, cos(deg2rad($lat))));

        $rows = Database::select(
            "SELECT p.user_id, l.lat, l.lng, l.heading, l.updated_at, MIN(v.category) AS category
               FROM provider_profiles p
               JOIN users u ON u.id = p.user_id AND u.status = 'active'
               JOIN provider_locations l ON l.provider_id = p.user_id
               JOIN vehicles v ON v.provider_id = p.user_id AND v.is_active = 1
              WHERE p.provider_type = :ptype AND p.verification_status = 'verified'
                AND p.is_online = 1 AND p.current_order_id IS NULL AND p.merchant_id IS NULL
                AND l.updated_at >= DATE_SUB(NOW(), INTERVAL :age SECOND)
                AND l.lat BETWEEN :lat1 AND :lat2 AND l.lng BETWEEN :lng1 AND :lng2
                AND v.category IN ($inCat)
              GROUP BY p.user_id, l.lat, l.lng, l.heading, l.updated_at
              LIMIT 200",
            ['ptype' => $type, 'age' => $maxAge, 'lat1' => $lat - $dLat, 'lat2' => $lat + $dLat, 'lng1' => $lng - $dLng, 'lng2' => $lng + $dLng] + $catParams
        );

        $day = date('Ymd');
        $out = [];
        foreach ($rows as $r) {
            $dist = Geo::distance($lat, $lng, (float) $r['lat'], (float) $r['lng']);
            if ($dist > $radiusKm * 1000) {
                continue;
            }
            [$jLat, $jLng] = self::jitter((int) $r['user_id'], $day, (float) $r['lat']);
            $out[] = [
                'k' => substr(Secrets::hmac('nearby', $r['user_id'] . '|' . $day), 0, 12),
                'lat' => round((float) $r['lat'] + $jLat, 4),
                'lng' => round((float) $r['lng'] + $jLng, 4),
                'heading' => $r['heading'] !== null ? (int) $r['heading'] : null,
                'icon' => TrackingService::iconFor((string) $r['category'], $type),
                'category' => $r['category'],
                'distance_m' => (int) round($dist),
            ];
        }
        usort($out, static fn ($a, $b) => $a['distance_m'] <=> $b['distance_m']);
        $out = array_slice($out, 0, $limit);

        $eta = null;
        if ($out) {
            $speed = $type === 'driver' ? 24 : 28;
            $eta = max(1, (int) ceil(($out[0]['distance_m'] * 1.35 / 1000) / $speed * 60));
        }
        return [
            'count' => count($out),
            'nearest_eta_min' => $eta,
            'radius_km' => $radiusKm,
            'vehicles' => array_map(static function ($v) {
                unset($v['distance_m']);
                return $v;
            }, $out),
            'approximate' => true,
        ];
    }

    /** Décalage stable (par prestataire et par jour) de 50 à 100 m dans une direction pseudo-aléatoire. */
    private static function jitter(int $providerId, string $day, float $lat): array
    {
        $h = hexdec(substr(Secrets::hmac('nearby-jitter', $providerId . '|' . $day), 0, 8));
        $angle = ($h % 360) * M_PI / 180;
        $meters = 50 + ($h >> 9) % 51;
        return [($meters * cos($angle)) / 111000, ($meters * sin($angle)) / (111000 * max(0.2, cos(deg2rad($lat))))];
    }
}
