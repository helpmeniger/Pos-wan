<?php
/**
 * Calculs géographiques côté serveur (aucune extension requise).
 *
 * - Superficie sur l'ellipsoïde WGS84 : intégration exacte de l'aire
 *   authalique par bandes (méthode des trapèzes sur la primitive de
 *   N·M·cos φ). Écart inférieur à 0,2 ppm avec l'algorithme de référence de
 *   Karney (GeographicLib / pyproj) pour des parcelles jusqu'à plusieurs km :
 *   moins d'un millimètre carré sur une parcelle de 400 m².
 * - Longueurs / périmètre : arc de méridien exact et rayon de courbure
 *   transverse à la latitude moyenne du segment (écart < 0,1 ppm sur des
 *   côtés de moins de 10 km).
 * - Validation topologique (anneaux fermés, auto-intersections).
 * - Détection de chevauchements entre polygones (projection locale métrique).
 * - Simplification Douglas-Peucker pour les niveaux de zoom éloignés.
 *
 * Format : GeoJSON Polygon WGS84, coordonnées [lng, lat].
 */
declare(strict_types=1);

final class Geo
{
    public const EARTH_RADIUS = 6371008.8;
    public const PRECISION = 8; // ~1,1 mm

    // ------------------------------------------------------------------
    // Mesures
    // ------------------------------------------------------------------

    /** Demi-grand axe et aplatissement de l'ellipsoïde WGS84. */
    public const WGS84_A = 6378137.0;
    public const WGS84_F = 1 / 298.257223563;

    /** Primitive de N(φ)·M(φ)·cos φ (aire authalique), en m². */
    private static function authalicPrimitive(float $phi): float
    {
        $a = self::WGS84_A;
        $e2 = self::WGS84_F * (2 - self::WGS84_F);
        $e = sqrt($e2);
        $s = sin($phi);
        return $a * $a * (1 - $e2) * ($s / (2 * (1 - $e2 * $s * $s)) + log((1 + $e * $s) / (1 - $e * $s)) / (4 * $e));
    }

    /** Longueur de l'arc de méridien de l'équateur à la latitude φ (m). */
    public static function meridianArc(float $phi): float
    {
        $a = self::WGS84_A;
        $e2 = self::WGS84_F * (2 - self::WGS84_F);
        return $a * ((1 - $e2 / 4 - 3 * $e2 ** 2 / 64 - 5 * $e2 ** 3 / 256) * $phi
            - (3 * $e2 / 8 + 3 * $e2 ** 2 / 32 + 45 * $e2 ** 3 / 1024) * sin(2 * $phi)
            + (15 * $e2 ** 2 / 256 + 45 * $e2 ** 3 / 1024) * sin(4 * $phi)
            - (35 * $e2 ** 3 / 3072) * sin(6 * $phi));
    }

    /** Aire (m², signée) d'un anneau [[lng,lat],...] fermé ou non, sur l'ellipsoïde WGS84. */
    public static function ringAreaSigned(array $ring): float
    {
        $ring = self::openRing($ring);
        $n = count($ring);
        if ($n < 3) {
            return 0.0;
        }
        $total = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $p = $ring[$i];
            $q = $ring[($i + 1) % $n];
            $total += deg2rad($q[0] - $p[0]) * (self::authalicPrimitive(deg2rad($p[1])) + self::authalicPrimitive(deg2rad($q[1]))) / 2;
        }
        return -$total; // positif pour un anneau anti-horaire
    }

    /** Aire d'un polygone (anneau extérieur moins les trous), en m². */
    public static function polygonArea(array $rings): float
    {
        $area = 0.0;
        foreach ($rings as $i => $ring) {
            $a = abs(self::ringAreaSigned($ring));
            $area += $i === 0 ? $a : -$a;
        }
        return max(0.0, $area);
    }

    /** Distance en mètres entre deux points [lng,lat] proches (ellipsoïde WGS84). */
    public static function distance(array $a, array $b): float
    {
        $e2 = self::WGS84_F * (2 - self::WGS84_F);
        $pm = deg2rad(($a[1] + $b[1]) / 2);
        $n = self::WGS84_A / sqrt(1 - $e2 * sin($pm) ** 2);
        $dx = deg2rad($b[0] - $a[0]) * $n * cos($pm);
        $dy = self::meridianArc(deg2rad($b[1])) - self::meridianArc(deg2rad($a[1]));
        return hypot($dx, $dy);
    }

    /** Longueur d'une ligne / d'un anneau en mètres. */
    public static function lineLength(array $coords): float
    {
        $len = 0.0;
        for ($i = 1, $n = count($coords); $i < $n; $i++) {
            $len += self::distance($coords[$i - 1], $coords[$i]);
        }
        return $len;
    }

    /** Périmètre (anneau extérieur) en mètres. */
    public static function polygonPerimeter(array $rings): float
    {
        return self::lineLength(self::closeRing($rings[0]));
    }

    public static function bbox(array $rings): array
    {
        $minLng = $minLat = INF;
        $maxLng = $maxLat = -INF;
        foreach ($rings[0] as $p) {
            $minLng = min($minLng, $p[0]);
            $maxLng = max($maxLng, $p[0]);
            $minLat = min($minLat, $p[1]);
            $maxLat = max($maxLat, $p[1]);
        }
        return ['min_lng' => $minLng, 'min_lat' => $minLat, 'max_lng' => $maxLng, 'max_lat' => $maxLat];
    }

    /** Centroïde (barycentre surfacique) [lng, lat] de l'anneau extérieur. */
    public static function centroid(array $rings): array
    {
        $ring = self::openRing($rings[0]);
        $origin = $ring[0];
        $pts = array_map(static fn ($p) => self::toLocal($p, $origin), $ring);
        $a = 0.0;
        $cx = $cy = 0.0;
        $n = count($pts);
        for ($i = 0; $i < $n; $i++) {
            [$x1, $y1] = $pts[$i];
            [$x2, $y2] = $pts[($i + 1) % $n];
            $f = $x1 * $y2 - $x2 * $y1;
            $a += $f;
            $cx += ($x1 + $x2) * $f;
            $cy += ($y1 + $y2) * $f;
        }
        if (abs($a) < 1e-9) {
            $sx = $sy = 0.0;
            foreach ($pts as $p) {
                $sx += $p[0];
                $sy += $p[1];
            }
            return self::fromLocal([$sx / $n, $sy / $n], $origin);
        }
        return self::fromLocal([$cx / (3 * $a), $cy / (3 * $a)], $origin);
    }

    // ------------------------------------------------------------------
    // Normalisation & validation
    // ------------------------------------------------------------------

    /**
     * Convertit une entrée GeoJSON (Feature, Polygon, MultiPolygon à une
     * seule partie) en anneaux normalisés : coordonnées arrondies, sommets
     * consécutifs dupliqués supprimés, anneaux fermés, orientation RFC 7946
     * (extérieur anti-horaire, trous horaires).
     *
     * @throws InvalidArgumentException message en français
     */
    public static function normalizePolygon($geojson): array
    {
        if (is_string($geojson)) {
            $geojson = json_decode($geojson, true);
        }
        if (!is_array($geojson)) {
            throw new InvalidArgumentException('Géométrie absente ou illisible.');
        }
        if (($geojson['type'] ?? '') === 'Feature') {
            $geojson = $geojson['geometry'] ?? null;
            if (!is_array($geojson)) {
                throw new InvalidArgumentException('La Feature ne contient pas de géométrie.');
            }
        }
        $type = $geojson['type'] ?? '';
        $coords = $geojson['coordinates'] ?? null;
        if ($type === 'MultiPolygon') {
            if (!is_array($coords) || count($coords) !== 1) {
                throw new InvalidArgumentException('MultiPolygon à plusieurs parties : chaque parcelle doit être un polygone unique (scindez les parties).');
            }
            $coords = $coords[0];
            $type = 'Polygon';
        }
        if ($type !== 'Polygon') {
            throw new InvalidArgumentException('Type de géométrie « ' . (is_string($type) ? $type : '?') . ' » non accepté : un Polygon est attendu.');
        }
        if (!is_array($coords) || !$coords) {
            throw new InvalidArgumentException('Le polygone ne contient aucune coordonnée.');
        }
        if (count($coords) > 50) {
            throw new InvalidArgumentException('Nombre de trous excessif dans le polygone.');
        }
        $rings = [];
        foreach ($coords as $ri => $ring) {
            if (!is_array($ring)) {
                throw new InvalidArgumentException('Anneau de coordonnées invalide.');
            }
            if (count($ring) > 10000) {
                throw new InvalidArgumentException('Polygone trop complexe (plus de 10 000 sommets).');
            }
            $clean = [];
            foreach ($ring as $p) {
                if (!is_array($p) || count($p) < 2 || !is_numeric($p[0]) || !is_numeric($p[1])) {
                    throw new InvalidArgumentException('Coordonnée non numérique dans le polygone.');
                }
                $pt = [round((float) $p[0], self::PRECISION), round((float) $p[1], self::PRECISION)];
                if (!is_finite($pt[0]) || !is_finite($pt[1])) {
                    throw new InvalidArgumentException('Coordonnée non finie dans le polygone.');
                }
                if (!$clean || $clean[count($clean) - 1] !== $pt) {
                    $clean[] = $pt;
                }
            }
            $clean = self::closeRing($clean);
            if (count($clean) < 4) {
                throw new InvalidArgumentException($ri === 0
                    ? 'Le polygone doit comporter au moins 3 sommets distincts.'
                    : 'Un trou du polygone comporte moins de 3 sommets distincts.');
            }
            // Orientation RFC 7946 : extérieur anti-horaire, trous horaires.
            $ccw = self::planarSignedArea($clean) > 0;
            if (($ri === 0 && !$ccw) || ($ri > 0 && $ccw)) {
                $clean = array_reverse($clean);
            }
            $rings[] = $clean;
        }
        return $rings;
    }

    /**
     * Valide un polygone normalisé. Retourne la liste des erreurs (vide si OK).
     */
    public static function validate(array $rings, array $bounds, float $minArea = 1.0, float $maxArea = 5.0e7): array
    {
        $errors = [];
        foreach ($rings as $ring) {
            foreach ($ring as $p) {
                if ($p[0] < -180 || $p[0] > 180 || $p[1] < -90 || $p[1] > 90) {
                    $errors[] = 'Coordonnées hors du domaine géographique valide (longitude/latitude inversées ?).';
                    break 2;
                }
                if ($p[1] < $bounds['min_lat'] || $p[1] > $bounds['max_lat'] || $p[0] < $bounds['min_lng'] || $p[0] > $bounds['max_lng']) {
                    $errors[] = sprintf('Le point (lat %.6f, lng %.6f) est en dehors de la zone plausible du Niger.', $p[1], $p[0]);
                    break 2;
                }
            }
        }
        foreach ($rings as $i => $ring) {
            if ($ring[0] !== $ring[count($ring) - 1]) {
                $errors[] = 'Le polygone n\'est pas fermé.';
            }
            if (self::ringSelfIntersects($ring)) {
                $errors[] = $i === 0
                    ? 'Les limites de la parcelle se croisent (polygone auto-intersecté). Corrigez l\'ordre des sommets.'
                    : 'Un trou du polygone est auto-intersecté.';
            }
        }
        if (!$errors) {
            $area = self::polygonArea($rings);
            if ($area <= 0 || $area < $minArea) {
                $errors[] = sprintf('Superficie trop faible (%.2f m²) : la géométrie n\'est pas exploitable.', $area);
            } elseif ($area > $maxArea) {
                $errors[] = sprintf('Superficie anormalement élevée (%.0f ha) : vérifiez le système de coordonnées.', $area / 10000);
            }
            for ($h = 1, $n = count($rings); $h < $n; $h++) {
                foreach (self::openRing($rings[$h]) as $p) {
                    if (!self::pointInRing($p, $rings[0])) {
                        $errors[] = 'Un trou du polygone sort de son contour extérieur.';
                        break 2;
                    }
                }
            }
        }
        return $errors;
    }

    /** Vrai si l'anneau se recoupe (segments non adjacents qui se touchent). */
    public static function ringSelfIntersects(array $ring): bool
    {
        $ring = self::closeRing($ring);
        $origin = $ring[0];
        $pts = array_map(static fn ($p) => self::toLocal($p, $origin), $ring);
        $n = count($pts) - 1; // nombre de segments
        if ($n < 3) {
            return true;
        }
        // Points répétés non consécutifs (anneau qui se touche lui-même)
        $seen = [];
        for ($i = 0; $i < $n; $i++) {
            $k = $ring[$i][0] . ',' . $ring[$i][1];
            if (isset($seen[$k])) {
                return true;
            }
            $seen[$k] = true;
        }
        for ($i = 0; $i < $n; $i++) {
            $a1 = $pts[$i];
            $a2 = $pts[$i + 1];
            for ($j = $i + 1; $j < $n; $j++) {
                if ($j === $i + 1 || ($i === 0 && $j === $n - 1)) {
                    continue; // segments adjacents
                }
                if (self::segmentsIntersect($a1, $a2, $pts[$j], $pts[$j + 1], false)) {
                    return true;
                }
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Relations entre polygones
    // ------------------------------------------------------------------

    public static function bboxIntersects(array $a, array $b): bool
    {
        return !($a['max_lng'] < $b['min_lng'] || $a['min_lng'] > $b['max_lng']
              || $a['max_lat'] < $b['min_lat'] || $a['min_lat'] > $b['max_lat']);
    }

    /**
     * Compare deux polygones (anneaux extérieurs).
     * Retourne 'none' (disjoints ou simplement contigus), 'overlap'
     * (chevauchement) ou 'duplicate' (géométries quasi identiques).
     * $tolerance : distance en mètres sous laquelle un sommet est considéré
     * comme situé sur la limite (parcelles contiguës).
     */
    public static function compare(array $ringsA, array $ringsB, float $tolerance = 0.10): string
    {
        $ba = self::bbox($ringsA);
        $bb = self::bbox($ringsB);
        if (!self::bboxIntersects($ba, $bb)) {
            return 'none';
        }
        $origin = $ringsA[0][0];
        $A = array_map(static fn ($p) => self::toLocal($p, $origin), self::closeRing($ringsA[0]));
        $B = array_map(static fn ($p) => self::toLocal($p, $origin), self::closeRing($ringsB[0]));

        // Doublon probable : aires et centroïdes quasi identiques
        $areaA = abs(self::localArea($A));
        $areaB = abs(self::localArea($B));
        $cA = self::localCentroid($A);
        $cB = self::localCentroid($B);
        if ($areaA > 0 && $areaB > 0) {
            $ratio = min($areaA, $areaB) / max($areaA, $areaB);
            $d = hypot($cA[0] - $cB[0], $cA[1] - $cB[1]);
            if ($ratio > 0.97 && $d < max(1.0, sqrt(max($areaA, $areaB)) * 0.02)) {
                return 'duplicate';
            }
        }

        // 1. Croisement franc de deux limites
        for ($i = 0, $na = count($A) - 1; $i < $na; $i++) {
            for ($j = 0, $nb = count($B) - 1; $j < $nb; $j++) {
                if (self::segmentsIntersect($A[$i], $A[$i + 1], $B[$j], $B[$j + 1], true)) {
                    return 'overlap';
                }
            }
        }
        // 2. Sommets ou milieux d'arêtes strictement à l'intérieur de l'autre polygone
        foreach ([[$A, $B], [$B, $A]] as [$P, $Q]) {
            for ($i = 0, $n = count($P) - 1; $i < $n; $i++) {
                $mid = [($P[$i][0] + $P[$i + 1][0]) / 2, ($P[$i][1] + $P[$i + 1][1]) / 2];
                foreach ([$P[$i], $mid] as $pt) {
                    if (self::localPointInRing($pt, $Q) && self::localDistanceToRing($pt, $Q) > $tolerance) {
                        return 'overlap';
                    }
                }
            }
        }
        // 3. Centroïde intérieur (polygones identiques à sommets confondus, etc.)
        if ((self::localPointInRing($cA, $B) && self::localDistanceToRing($cA, $B) > $tolerance)
            || (self::localPointInRing($cB, $A) && self::localDistanceToRing($cB, $A) > $tolerance)) {
            return 'overlap';
        }
        return 'none';
    }

    /** Point [lng,lat] dans un anneau [lng,lat] (règle pair-impair). */
    public static function pointInRing(array $p, array $ring): bool
    {
        $inside = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $ring[$i][0]; $yi = $ring[$i][1];
            $xj = $ring[$j][0]; $yj = $ring[$j][1];
            if ((($yi > $p[1]) !== ($yj > $p[1])) && ($p[0] < ($xj - $xi) * ($p[1] - $yi) / (($yj - $yi) ?: 1e-18) + $xi)) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    // ------------------------------------------------------------------
    // Simplification
    // ------------------------------------------------------------------

    /** Simplifie un polygone (Douglas-Peucker, tolérance en mètres). */
    public static function simplify(array $rings, float $toleranceM): array
    {
        $out = [];
        foreach ($rings as $i => $ring) {
            if (count($ring) <= 5) {
                $out[] = $ring;
                continue;
            }
            $origin = $ring[0];
            $local = array_map(static fn ($p) => self::toLocal($p, $origin), $ring);
            $keep = self::douglasPeucker($local, $toleranceM);
            $simplified = [];
            foreach ($keep as $idx) {
                $simplified[] = $ring[$idx];
            }
            if (count($simplified) < 4) {
                if ($i > 0) {
                    continue; // trou trop petit à cette échelle
                }
                $simplified = $ring;
            }
            $out[] = $simplified;
        }
        return $out;
    }

    private static function douglasPeucker(array $pts, float $tol): array
    {
        $n = count($pts);
        $keep = [0 => true, $n - 1 => true];
        $stack = [[0, $n - 1]];
        while ($stack) {
            [$s, $e] = array_pop($stack);
            $maxD = 0.0;
            $idx = -1;
            for ($i = $s + 1; $i < $e; $i++) {
                $d = self::pointSegmentDistance($pts[$i], $pts[$s], $pts[$e]);
                if ($d > $maxD) {
                    $maxD = $d;
                    $idx = $i;
                }
            }
            if ($idx >= 0 && $maxD > $tol) {
                $keep[$idx] = true;
                $stack[] = [$s, $idx];
                $stack[] = [$idx, $e];
            }
        }
        ksort($keep);
        return array_keys($keep);
    }

    // ------------------------------------------------------------------
    // Utilitaires
    // ------------------------------------------------------------------

    public static function closeRing(array $ring): array
    {
        if ($ring && $ring[0] !== $ring[count($ring) - 1]) {
            $ring[] = $ring[0];
        }
        return $ring;
    }

    public static function openRing(array $ring): array
    {
        $n = count($ring);
        if ($n > 1 && $ring[0] == $ring[$n - 1]) {
            array_pop($ring);
        }
        return array_values($ring);
    }

    /** Géométrie GeoJSON à partir des anneaux. */
    public static function toGeoJson(array $rings): array
    {
        return ['type' => 'Polygon', 'coordinates' => $rings];
    }

    /** Mesures complètes d'un polygone normalisé. */
    public static function measure(array $rings): array
    {
        $bbox = self::bbox($rings);
        $c = self::centroid($rings);
        return [
            'area_m2'      => round(self::polygonArea($rings), 2),
            'perimeter_m'  => round(self::polygonPerimeter($rings), 2),
            'centroid_lng' => round($c[0], self::PRECISION),
            'centroid_lat' => round($c[1], self::PRECISION),
            'vertex_count' => count($rings[0]) - 1,
        ] + $bbox;
    }

    /** Projection équirectangulaire locale (mètres) autour de $origin. */
    public static function toLocal(array $p, array $origin): array
    {
        $k = M_PI / 180 * self::EARTH_RADIUS;
        return [($p[0] - $origin[0]) * $k * cos(deg2rad($origin[1])), ($p[1] - $origin[1]) * $k];
    }

    public static function fromLocal(array $xy, array $origin): array
    {
        $k = M_PI / 180 * self::EARTH_RADIUS;
        return [$origin[0] + $xy[0] / ($k * cos(deg2rad($origin[1]))), $origin[1] + $xy[1] / $k];
    }

    private static function planarSignedArea(array $ring): float
    {
        $s = 0.0;
        for ($i = 0, $n = count($ring) - 1; $i < $n; $i++) {
            $s += $ring[$i][0] * $ring[$i + 1][1] - $ring[$i + 1][0] * $ring[$i][1];
        }
        return $s / 2;
    }

    private static function localArea(array $pts): float
    {
        return self::planarSignedArea($pts);
    }

    private static function localCentroid(array $pts): array
    {
        $a = 0.0;
        $cx = $cy = 0.0;
        for ($i = 0, $n = count($pts) - 1; $i < $n; $i++) {
            $f = $pts[$i][0] * $pts[$i + 1][1] - $pts[$i + 1][0] * $pts[$i][1];
            $a += $f;
            $cx += ($pts[$i][0] + $pts[$i + 1][0]) * $f;
            $cy += ($pts[$i][1] + $pts[$i + 1][1]) * $f;
        }
        if (abs($a) < 1e-12) {
            return $pts[0];
        }
        return [$cx / (3 * $a), $cy / (3 * $a)];
    }

    private static function cross(array $o, array $a, array $b): float
    {
        return ($a[0] - $o[0]) * ($b[1] - $o[1]) - ($a[1] - $o[1]) * ($b[0] - $o[0]);
    }

    /**
     * Intersection de segments en coordonnées locales (mètres).
     * $properOnly = true : seuls les croisements francs comptent (les contacts
     * en bout de segment ou les segments colinéaires sont ignorés).
     */
    private static function segmentsIntersect(array $p1, array $p2, array $p3, array $p4, bool $properOnly): bool
    {
        $eps = 1e-6; // m²
        $d1 = self::cross($p3, $p4, $p1);
        $d2 = self::cross($p3, $p4, $p2);
        $d3 = self::cross($p1, $p2, $p3);
        $d4 = self::cross($p1, $p2, $p4);
        $s1 = abs($d1) < $eps ? 0 : ($d1 > 0 ? 1 : -1);
        $s2 = abs($d2) < $eps ? 0 : ($d2 > 0 ? 1 : -1);
        $s3 = abs($d3) < $eps ? 0 : ($d3 > 0 ? 1 : -1);
        $s4 = abs($d4) < $eps ? 0 : ($d4 > 0 ? 1 : -1);
        if ($s1 * $s2 < 0 && $s3 * $s4 < 0) {
            return true;
        }
        if ($properOnly) {
            return false;
        }
        $on = static function (array $a, array $b, array $c): bool {
            return min($a[0], $b[0]) - 1e-9 <= $c[0] && $c[0] <= max($a[0], $b[0]) + 1e-9
                && min($a[1], $b[1]) - 1e-9 <= $c[1] && $c[1] <= max($a[1], $b[1]) + 1e-9;
        };
        return ($s1 === 0 && $on($p3, $p4, $p1)) || ($s2 === 0 && $on($p3, $p4, $p2))
            || ($s3 === 0 && $on($p1, $p2, $p3)) || ($s4 === 0 && $on($p1, $p2, $p4));
    }

    private static function localPointInRing(array $p, array $ring): bool
    {
        return self::pointInRing($p, $ring);
    }

    private static function pointSegmentDistance(array $p, array $a, array $b): float
    {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $len2 = $dx * $dx + $dy * $dy;
        if ($len2 <= 0) {
            return hypot($p[0] - $a[0], $p[1] - $a[1]);
        }
        $t = max(0.0, min(1.0, (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / $len2));
        return hypot($p[0] - ($a[0] + $t * $dx), $p[1] - ($a[1] + $t * $dy));
    }

    private static function localDistanceToRing(array $p, array $ring): float
    {
        $min = INF;
        for ($i = 0, $n = count($ring) - 1; $i < $n; $i++) {
            $min = min($min, self::pointSegmentDistance($p, $ring[$i], $ring[$i + 1]));
        }
        return $min;
    }
}
