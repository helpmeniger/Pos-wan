<?php
declare(strict_types=1);

namespace App\Core;

final class Geo
{
    /** Distance orthodromique en mètres. */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /** Cap initial en degrés (0 = nord). */
    public static function bearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $y = sin(deg2rad($lng2 - $lng1)) * cos(deg2rad($lat2));
        $x = cos(deg2rad($lat1)) * sin(deg2rad($lat2)) - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($lng2 - $lng1));
        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }

    /**
     * Point dans un polygone GeoJSON (anneau extérieur, coordonnées [lng, lat]).
     * Algorithme du lancer de rayon.
     */
    public static function pointInPolygon(float $lat, float $lng, array $ring): bool
    {
        $inside = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = [(float) $ring[$i][0], (float) $ring[$i][1]];
            [$xj, $yj] = [(float) $ring[$j][0], (float) $ring[$j][1]];
            if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    /** Valide et normalise un polygone GeoJSON ; renvoie l'anneau extérieur fermé. */
    public static function parsePolygon(mixed $geojson): array
    {
        if (is_string($geojson)) {
            $geojson = json_decode($geojson, true);
        }
        $ring = $geojson['coordinates'][0] ?? null;
        if (($geojson['type'] ?? '') !== 'Polygon' || !is_array($ring) || count($ring) < 3) {
            throw ValidationException::field('polygon', 'Le polygone doit comporter au moins 3 sommets.');
        }
        $clean = [];
        foreach ($ring as $pt) {
            if (!is_array($pt) || count($pt) < 2 || !is_numeric($pt[0]) || !is_numeric($pt[1])
                || abs((float) $pt[0]) > 180 || abs((float) $pt[1]) > 90) {
                throw ValidationException::field('polygon', 'Coordonnées de polygone invalides.');
            }
            $clean[] = [round((float) $pt[0], 6), round((float) $pt[1], 6)];
        }
        if ($clean[0] !== $clean[count($clean) - 1]) {
            $clean[] = $clean[0];
        }
        return $clean;
    }

    public static function bbox(array $ring): array
    {
        $lngs = array_column($ring, 0);
        $lats = array_column($ring, 1);
        return ['min_lat' => min($lats), 'max_lat' => max($lats), 'min_lng' => min($lngs), 'max_lng' => max($lngs)];
    }

    /** Arrondit une position pour l'afficher à un tiers non affecté (~110 m). */
    public static function blur(float $v): float
    {
        return round($v, 3);
    }
}
