<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Settings;
use App\Services\Routing\RoutingService;
use DateTimeImmutable;

/**
 * Calcul des tarifs indicatifs à partir des règles paramétrées (pricing_rules).
 * Aucune valeur tarifaire n'est codée en dur.
 */
final class PricingService
{
    public const RIDE_SERVICES = ['taxi', 'pro'];
    public const COURIER_SERVICES = ['parcel', 'document', 'errand', 'urgent'];
    public const MERCHANT_SERVICES = ['food', 'shop'];
    public const RIDE_VEHICLES = ['taxi', 'sedan', 'family', 'van', 'moto_taxi'];
    public const COURIER_VEHICLES = ['moto', 'bicycle', 'car', 'van'];

    public static function flowFor(string $serviceType): string
    {
        return match (true) {
            in_array($serviceType, self::RIDE_SERVICES, true) => 'ride',
            in_array($serviceType, self::MERCHANT_SERVICES, true) => 'merchant',
            in_array($serviceType, self::COURIER_SERVICES, true) => 'courier',
            default => throw HttpException::badRequest('Type de service inconnu.'),
        };
    }

    public static function findRule(string $serviceType, ?string $vehicleCategory, ?int $cityId, ?int $zoneId, ?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable();
        $rule = Database::first(
            'SELECT * FROM pricing_rules
              WHERE is_active = 1 AND service_type = :s
                AND (vehicle_category IS NULL OR vehicle_category = :v)
                AND (city_id IS NULL OR city_id = :c)
                AND (zone_id IS NULL OR zone_id = :z)
                AND (valid_from IS NULL OR valid_from <= :at1)
                AND (valid_to IS NULL OR valid_to >= :at2)
              ORDER BY (zone_id IS NOT NULL) DESC, (city_id IS NOT NULL) DESC, (vehicle_category IS NOT NULL) DESC, priority DESC, id DESC
              LIMIT 1',
            ['s' => $serviceType, 'v' => $vehicleCategory ?? '', 'c' => $cityId ?? 0, 'z' => $zoneId ?? 0,
             'at1' => $at->format('Y-m-d H:i:s'), 'at2' => $at->format('Y-m-d H:i:s')]
        );
        if (!$rule) {
            throw new HttpException(422, "Aucune règle tarifaire active pour ce service. L'administration doit la configurer.", 'no_pricing_rule');
        }
        return $rule;
    }

    /** Tarif course/livraison (hors frais) selon la règle. Fonction pure, testée unitairement. */
    public static function computeFare(array $rule, int $distanceM, int $durationS, DateTimeImmutable $at, ?int $roundingStep = null): int
    {
        $km = $distanceM / 1000;
        $min = $durationS / 60;
        $raw = (int) $rule['base_fare'] + (int) $rule['per_km'] * $km + (int) $rule['per_min'] * $min;
        $raw = max($raw, (float) $rule['min_fare']);
        if (self::isNight($rule, $at)) {
            $raw = $raw * ((int) $rule['night_multiplier_pct']) / 100;
        }
        return self::round($raw, $roundingStep ?? Settings::int('pricing.rounding_step', 50));
    }

    public static function isNight(array $rule, DateTimeImmutable $at): bool
    {
        if (empty($rule['night_start']) || empty($rule['night_end']) || (int) $rule['night_multiplier_pct'] === 100) {
            return false;
        }
        $t = $at->format('H:i:s');
        $s = $rule['night_start'];
        $e = $rule['night_end'];
        return $s <= $e ? ($t >= $s && $t < $e) : ($t >= $s || $t < $e);
    }

    /** Arrondi au multiple supérieur du pas (ex. 50 FCFA). */
    public static function round(float $amount, int $step): int
    {
        $step = max(1, $step);
        return (int) (ceil(round($amount, 2) / $step) * $step);
    }

    /**
     * Estimation complète pour l'interface client.
     * @return array<string, mixed>
     */
    public static function estimate(string $serviceType, ?string $vehicleCategory, float $pLat, float $pLng, float $dLat, float $dLng, ?DateTimeImmutable $at = null): array
    {
        $flow = self::flowFor($serviceType);
        if ($flow === 'ride' && $vehicleCategory !== null && !in_array($vehicleCategory, self::RIDE_VEHICLES, true)) {
            throw HttpException::badRequest('Catégorie de véhicule invalide pour une course.');
        }
        if ($flow !== 'ride' && $vehicleCategory !== null && !in_array($vehicleCategory, self::COURIER_VEHICLES, true)) {
            throw HttpException::badRequest('Moyen de livraison invalide.');
        }
        $at ??= new DateTimeImmutable();
        $where = ZoneService::assertServiceable($pLat, $pLng, $flow === 'ride' ? 'ride' : 'delivery', 'Le point de départ');
        ZoneService::assertServiceable($dLat, $dLng, $flow === 'ride' ? 'ride' : 'delivery', 'La destination');
        $route = RoutingService::route($pLat, $pLng, $dLat, $dLng, RoutingService::profileFor($vehicleCategory ?? ($flow === 'ride' ? 'taxi' : 'moto')));
        $cityId = $where['city'] ? (int) $where['city']['id'] : null;
        $zoneId = $where['zone'] ? (int) $where['zone']['id'] : null;
        $rule = self::findRule($serviceType, $vehicleCategory, $cityId, $zoneId, $at);
        $fare = self::computeFare($rule, $route->distanceM, $route->durationS, $at);
        $booking = (int) $rule['booking_fee'];
        $service = (int) $rule['service_fee'];
        return [
            'service_type' => $serviceType,
            'vehicle_category' => $vehicleCategory,
            'city_id' => $cityId,
            'zone_id' => $zoneId,
            'rule_id' => (int) $rule['id'],
            'distance_m' => $route->distanceM,
            'duration_s' => $route->durationS,
            'route_source' => $route->source,
            'approximate' => $route->approximate,
            'geometry' => $route->geometry,
            'fare' => $fare,
            'booking_fee' => $booking,
            'service_fee' => $service,
            'total' => $fare + $booking + $service,
            'night' => self::isNight($rule, $at),
            'min_offer' => self::round($fare * (int) $rule['min_offer_pct'] / 100, 50),
            'max_offer' => self::round($fare * (int) $rule['max_offer_pct'] / 100, 50),
            'disclaimer' => $route->approximate
                ? 'Estimation approximative (distance calculée sans service d\'itinéraire). Le prix final est celui accepté avant le départ.'
                : 'Tarif indicatif basé sur l\'itinéraire routier estimé. Le prix final est celui accepté avant le départ.',
        ];
    }
}
