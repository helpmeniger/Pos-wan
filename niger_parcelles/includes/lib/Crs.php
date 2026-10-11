<?php
/**
 * Transformations de systèmes de coordonnées vers WGS84 (EPSG:4326).
 *
 * Systèmes pris en charge (couvrant le Niger) :
 *   EPSG:4326  WGS84 géographique (lng, lat)
 *   EPSG:3857  Web Mercator (pseudo-Mercator)
 *   EPSG:32631 WGS84 / UTM zone 31N (ouest du Niger, Niamey, Tillabéri, Dosso)
 *   EPSG:32632 WGS84 / UTM zone 32N (centre : Tahoua, Maradi, Agadez)
 *   EPSG:32633 WGS84 / UTM zone 33N (est : Zinder, Diffa)
 *
 * Les systèmes sur d'autres datums (ex. Clarke 1880) nécessitent un changement
 * de datum non pris en charge ici : convertissez-les au préalable (QGIS).
 */
declare(strict_types=1);

final class Crs
{
    public const SUPPORTED = [
        'EPSG:4326'  => 'WGS84 géographique (longitude/latitude en degrés)',
        'EPSG:32631' => 'WGS84 / UTM zone 31N (mètres)',
        'EPSG:32632' => 'WGS84 / UTM zone 32N (mètres)',
        'EPSG:32633' => 'WGS84 / UTM zone 33N (mètres)',
        'EPSG:3857'  => 'Web Mercator / Pseudo-Mercator (mètres)',
    ];

    /** Transforme [x, y] du système $crs en [lng, lat] WGS84. */
    public static function toWgs84(float $x, float $y, string $crs): array
    {
        switch ($crs) {
            case 'EPSG:4326':
                return [$x, $y];
            case 'EPSG:3857':
                $lng = $x / 6378137.0 * 180 / M_PI;
                $lat = (2 * atan(exp($y / 6378137.0)) - M_PI / 2) * 180 / M_PI;
                return [$lng, $lat];
            case 'EPSG:32631':
            case 'EPSG:32632':
            case 'EPSG:32633':
                return self::utmToWgs84($x, $y, (int) substr($crs, -2), true);
        }
        throw new InvalidArgumentException("Système de coordonnées non pris en charge : $crs");
    }

    /** Conversion inverse WGS84 -> UTM (utilisée par les tests et l'export). */
    public static function wgs84ToUtm(float $lng, float $lat, int $zone): array
    {
        $a = 6378137.0;
        $f = 1 / 298.257223563;
        $k0 = 0.9996;
        $e2 = $f * (2 - $f);
        $ep2 = $e2 / (1 - $e2);
        $lon0 = deg2rad(($zone - 1) * 6 - 180 + 3);
        $phi = deg2rad($lat);
        $lam = deg2rad($lng);
        $N = $a / sqrt(1 - $e2 * sin($phi) ** 2);
        $T = tan($phi) ** 2;
        $C = $ep2 * cos($phi) ** 2;
        $A = cos($phi) * ($lam - $lon0);
        $M = $a * ((1 - $e2 / 4 - 3 * $e2 ** 2 / 64 - 5 * $e2 ** 3 / 256) * $phi
            - (3 * $e2 / 8 + 3 * $e2 ** 2 / 32 + 45 * $e2 ** 3 / 1024) * sin(2 * $phi)
            + (15 * $e2 ** 2 / 256 + 45 * $e2 ** 3 / 1024) * sin(4 * $phi)
            - (35 * $e2 ** 3 / 3072) * sin(6 * $phi));
        $x = $k0 * $N * ($A + (1 - $T + $C) * $A ** 3 / 6 + (5 - 18 * $T + $T ** 2 + 72 * $C - 58 * $ep2) * $A ** 5 / 120) + 500000.0;
        $y = $k0 * ($M + $N * tan($phi) * ($A ** 2 / 2 + (5 - $T + 9 * $C + 4 * $C ** 2) * $A ** 4 / 24
            + (61 - 58 * $T + $T ** 2 + 600 * $C - 330 * $ep2) * $A ** 6 / 720));
        if ($lat < 0) {
            $y += 10000000.0;
        }
        return [$x, $y];
    }

    /** UTM (WGS84) -> [lng, lat]. Formules de Snyder (USGS PP 1395), précision < 1 mm. */
    public static function utmToWgs84(float $easting, float $northing, int $zone, bool $north = true): array
    {
        $a = 6378137.0;
        $f = 1 / 298.257223563;
        $k0 = 0.9996;
        $e2 = $f * (2 - $f);
        $ep2 = $e2 / (1 - $e2);
        $x = $easting - 500000.0;
        $y = $north ? $northing : $northing - 10000000.0;
        $lon0 = deg2rad(($zone - 1) * 6 - 180 + 3);
        $M = $y / $k0;
        $mu = $M / ($a * (1 - $e2 / 4 - 3 * $e2 ** 2 / 64 - 5 * $e2 ** 3 / 256));
        $e1 = (1 - sqrt(1 - $e2)) / (1 + sqrt(1 - $e2));
        $phi1 = $mu + (3 * $e1 / 2 - 27 * $e1 ** 3 / 32) * sin(2 * $mu)
            + (21 * $e1 ** 2 / 16 - 55 * $e1 ** 4 / 32) * sin(4 * $mu)
            + (151 * $e1 ** 3 / 96) * sin(6 * $mu)
            + (1097 * $e1 ** 4 / 512) * sin(8 * $mu);
        $N1 = $a / sqrt(1 - $e2 * sin($phi1) ** 2);
        $T1 = tan($phi1) ** 2;
        $C1 = $ep2 * cos($phi1) ** 2;
        $R1 = $a * (1 - $e2) / (1 - $e2 * sin($phi1) ** 2) ** 1.5;
        $D = $x / ($N1 * $k0);
        $lat = $phi1 - ($N1 * tan($phi1) / $R1) * ($D ** 2 / 2
            - (5 + 3 * $T1 + 10 * $C1 - 4 * $C1 ** 2 - 9 * $ep2) * $D ** 4 / 24
            + (61 + 90 * $T1 + 298 * $C1 + 45 * $T1 ** 2 - 252 * $ep2 - 3 * $C1 ** 2) * $D ** 6 / 720);
        $lng = $lon0 + ($D - (1 + 2 * $T1 + $C1) * $D ** 3 / 6
            + (5 - 2 * $C1 + 28 * $T1 - 3 * $C1 ** 2 + 8 * $ep2 + 24 * $T1 ** 2) * $D ** 5 / 120) / cos($phi1);
        return [rad2deg($lng), rad2deg($lat)];
    }

    /** Transforme récursivement les coordonnées d'une géométrie GeoJSON. */
    public static function transformGeometry(array $geometry, string $crs): array
    {
        if ($crs === 'EPSG:4326') {
            return $geometry;
        }
        $walk = static function ($c) use (&$walk, $crs) {
            if (is_array($c) && isset($c[0]) && is_numeric($c[0]) && isset($c[1]) && is_numeric($c[1])) {
                return self::toWgs84((float) $c[0], (float) $c[1], $crs);
            }
            return is_array($c) ? array_map($walk, $c) : $c;
        };
        $geometry['coordinates'] = $walk($geometry['coordinates'] ?? []);
        return $geometry;
    }

    /** Identifie un CRS à partir d'un texte (WKT .prj, URN GeoJSON, code). */
    public static function detect(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $t = strtoupper($text);
        if (preg_match('/EPSG[:\s]*:*\s*(4326|3857|900913|3395|32631|32632|32633)\b/', $t, $m)) {
            return $m[1] === '900913' ? 'EPSG:3857' : ($m[1] === '3395' ? null : 'EPSG:' . $m[1]);
        }
        if (strpos($t, 'CRS84') !== false) {
            return 'EPSG:4326';
        }
        if (preg_match('/UTM[_\s]*ZONE[_\s]*(3[123])\s*N/', $t, $m) && strpos($t, 'WGS') !== false) {
            return 'EPSG:326' . $m[1];
        }
        if (strpos($t, 'PSEUDO') !== false || strpos($t, 'WEB_MERCATOR') !== false || strpos($t, 'MERCATOR_AUXILIARY_SPHERE') !== false) {
            return 'EPSG:3857';
        }
        if (strpos($t, 'GEOGCS') === 0 && strpos($t, 'WGS') !== false && strpos($t, 'PROJCS') === false) {
            return 'EPSG:4326';
        }
        return null;
    }

    /** Devine un CRS d'après l'amplitude des coordonnées d'un point. */
    public static function guessFromCoordinate(float $x, float $y): ?string
    {
        if (abs($x) <= 180 && abs($y) <= 90) {
            return 'EPSG:4326';
        }
        if ($x > 100000 && $x < 900000 && $y > 1000000 && $y < 2800000) {
            return null; // UTM probable mais zone indéterminée : l'utilisateur doit choisir
        }
        return null;
    }
}
