<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Geo;
use App\Core\HttpException;
use App\Core\Settings;

final class ZoneService
{
    /** Zone active contenant le point (filtre bbox SQL puis test polygone exact). */
    public static function findZone(float $lat, float $lng): ?array
    {
        $candidates = Database::select(
            'SELECT * FROM service_zones WHERE is_active = 1 AND :lat BETWEEN min_lat AND max_lat AND :lng BETWEEN min_lng AND max_lng',
            ['lat' => $lat, 'lng' => $lng]
        );
        foreach ($candidates as $z) {
            $poly = json_decode($z['polygon'], true);
            if (Geo::pointInPolygon($lat, $lng, $poly['coordinates'][0] ?? [])) {
                return $z;
            }
        }
        return null;
    }

    /** Ville active la plus proche (rayon 60 km). */
    public static function nearestCity(float $lat, float $lng): ?array
    {
        $best = null;
        foreach (Database::select('SELECT * FROM cities WHERE is_active = 1') as $c) {
            $d = Geo::distance($lat, $lng, (float) $c['lat'], (float) $c['lng']);
            if ($d < 60000 && ($best === null || $d < $best['_d'])) {
                $best = $c + ['_d' => $d];
            }
        }
        return $best;
    }

    /**
     * Vérifie qu'un point est desservi. Si « zones.enforce » est actif, le point doit se trouver
     * dans une zone active autorisant le type de service.
     * @return array{city: ?array, zone: ?array}
     */
    public static function assertServiceable(float $lat, float $lng, string $kind, string $label = 'Ce lieu'): array
    {
        $city = self::nearestCity($lat, $lng);
        $zone = self::findZone($lat, $lng);
        if (!$city) {
            throw new HttpException(422, "$label n'est pas encore desservi par MARWA (aucune ville active à proximité).", 'not_serviceable');
        }
        if (Settings::get('zones.enforce', false)) {
            $flag = $kind === 'ride' ? 'allow_rides' : 'allow_delivery';
            if (!$zone || !(int) $zone[$flag]) {
                throw new HttpException(422, "$label est hors des zones de service actives pour ce type de commande.", 'not_serviceable');
            }
        }
        return ['city' => $city, 'zone' => $zone];
    }
}
