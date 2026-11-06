<?php
/**
 * Contrôle qualité topologique des limites communes.
 *
 * Deux parcelles voisines tracées séparément laissent souvent un petit vide
 * (« écart ») ou un léger empiètement le long de leur limite commune. Le
 * service repère les sommets situés à moins de `quality_tolerance_m` (60 cm
 * par défaut) de la limite voisine sans être dessus, et propose de les
 * recoller exactement (nouvelle version des limites, historisée).
 */
declare(strict_types=1);

final class QualityService
{
    public const MIN_GAP_M = 0.02; // en deçà : considéré comme confondu (2 cm)

    /**
     * Analyse les paires de parcelles voisines.
     * @return array<int, array> anomalies triées par gravité
     */
    public static function scan(?int $areaId = null, int $limit = 300): array
    {
        $tol = (float) setting('quality_tolerance_m', 0.60);
        $d = $tol / 100000; // marge en degrés (> tolérance à la latitude du Niger)
        $where = 'a.is_archived = 0 AND b.is_archived = 0';
        $params = [$d, $d, $d, $d];
        if ($areaId) {
            $ids = admin_area_descendants($areaId);
            $where .= ' AND a.administrative_area_id IN (' . implode(',', array_map('intval', $ids)) . ')';
        }
        $pairs = db_all("SELECT a.id AS a_id, b.id AS b_id FROM parcels a JOIN parcels b ON b.id > a.id
                         AND b.min_lat <= a.max_lat + ? AND b.max_lat >= a.min_lat - ? AND b.min_lng <= a.max_lng + ? AND b.max_lng >= a.min_lng - ?
                         WHERE $where LIMIT 20000", $params);
        $dismissed = [];
        foreach (db_all('SELECT parcel_a_id, parcel_b_id FROM quality_dismissals') as $r) {
            $dismissed[$r['parcel_a_id'] . '-' . $r['parcel_b_id']] = true;
        }
        $geoms = [];
        $get = static function (int $id) use (&$geoms): ?array {
            if (!array_key_exists($id, $geoms)) {
                $g = ParcelService::currentGeometry($id);
                $geoms[$id] = $g['coordinates'][0] ?? null;
            }
            return $geoms[$id];
        };
        $issues = [];
        foreach ($pairs as $pr) {
            $a = (int) $pr['a_id'];
            $b = (int) $pr['b_id'];
            if (isset($dismissed["$a-$b"])) {
                continue;
            }
            $ra = $get($a);
            $rb = $get($b);
            if (!$ra || !$rb) {
                continue;
            }
            $va = self::nearVertices($ra, $rb, $tol);
            $vb = self::nearVertices($rb, $ra, $tol);
            if (!$va && !$vb) {
                continue;
            }
            $all = array_merge($va, $vb);
            $max = max(array_column($all, 'dist'));
            $kind = count(array_filter($all, static fn ($v) => $v['inside'])) > 0 ? 'empietement' : 'ecart';
            $issues[] = ['a' => $a, 'b' => $b, 'kind' => $kind, 'max_m' => $max, 'count' => count($all),
                         'vertices_a' => $va, 'vertices_b' => $vb, 'point' => $all[0]['point']];
            if (count($issues) >= $limit) {
                break;
            }
        }
        usort($issues, static fn ($x, $y) => $y['max_m'] <=> $x['max_m']);
        if ($issues) {
            $ids = array_unique(array_merge(array_column($issues, 'a'), array_column($issues, 'b')));
            $info = [];
            foreach (db_all('SELECT id, parcel_reference, verification_status FROM parcels WHERE id IN (' . implode(',', $ids) . ')') as $r) {
                $info[(int) $r['id']] = $r;
            }
            foreach ($issues as &$i) {
                $i['ref_a'] = $info[$i['a']]['parcel_reference'];
                $i['ref_b'] = $info[$i['b']]['parcel_reference'];
                $i['status_a'] = $info[$i['a']]['verification_status'];
                $i['status_b'] = $info[$i['b']]['verification_status'];
            }
            unset($i);
        }
        return $issues;
    }

    /** Sommets de $ring situés à moins de $tol mètres de la limite de $other sans être dessus. */
    public static function nearVertices(array $ring, array $other, float $tol): array
    {
        $origin = $ring[0];
        $O = array_map(static fn ($p) => Geo::toLocal($p, $origin), Geo::closeRing($other));
        $out = [];
        $n = count($ring) - 1;
        for ($i = 0; $i < $n; $i++) {
            $p = Geo::toLocal($ring[$i], $origin);
            [$q, $dist] = self::nearestOnRing($p, $O);
            if ($dist > self::MIN_GAP_M && $dist <= $tol) {
                $out[] = ['index' => $i, 'dist' => round($dist, 3), 'inside' => Geo::pointInRing($p, $O),
                          'point' => $ring[$i], 'target' => Geo::fromLocal($q, $origin)];
            }
        }
        return $out;
    }

    /** Point le plus proche sur un anneau (coordonnées locales) : [point, distance]. */
    private static function nearestOnRing(array $p, array $ring): array
    {
        $best = [$ring[0], INF];
        for ($i = 0, $n = count($ring) - 1; $i < $n; $i++) {
            [$ax, $ay] = $ring[$i];
            [$bx, $by] = $ring[$i + 1];
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $l2 = $dx * $dx + $dy * $dy;
            $t = $l2 > 0 ? max(0.0, min(1.0, (($p[0] - $ax) * $dx + ($p[1] - $ay) * $dy) / $l2)) : 0.0;
            $q = [$ax + $t * $dx, $ay + $t * $dy];
            // préférence pour un sommet existant tout proche (limite commune exacte)
            foreach ([[$ax, $ay], [$bx, $by]] as $v) {
                if (hypot($v[0] - $q[0], $v[1] - $q[1]) < 0.15) {
                    $q = $v;
                }
            }
            $dist = hypot($p[0] - $q[0], $p[1] - $q[1]);
            if ($dist < $best[1]) {
                $best = [$q, $dist];
            }
        }
        return $best;
    }

    /**
     * Recolle la parcelle $moveId sur la limite de $refId : chaque sommet proche
     * est déplacé sur la limite voisine. Crée une nouvelle version des limites.
     */
    public static function snap(int $moveId, int $refId, int $userId): array
    {
        $tol = (float) setting('quality_tolerance_m', 0.60);
        $move = ParcelService::getOrFail($moveId);
        $ref = ParcelService::getOrFail($refId);
        $gm = ParcelService::currentGeometry($moveId);
        $gr = ParcelService::currentGeometry($refId);
        $ring = $gm['coordinates'][0];
        $near = self::nearVertices($ring, $gr['coordinates'][0], $tol);
        if (!$near) {
            throw new UserFacingException('Aucun sommet à recoller : les limites sont déjà cohérentes.');
        }
        $maxShift = 0.0;
        foreach ($near as $v) {
            $ring[$v['index']] = [round($v['target'][0], Geo::PRECISION), round($v['target'][1], Geo::PRECISION)];
            $maxShift = max($maxShift, $v['dist']);
        }
        $ring[count($ring) - 1] = $ring[0];
        $coords = $gm['coordinates'];
        $coords[0] = $ring;
        $reason = sprintf('Recollage de %d sommet(s) sur la limite de %s (déplacement max. %s m)', count($near), $ref['parcel_reference'],
            number_format($maxShift, 2, ',', ''));
        $res = ParcelService::updateGeometry($moveId, ['type' => 'Polygon', 'coordinates' => $coords], $userId, $reason, true);
        ParcelService::history($moveId, $userId, 'limite_recollee', null, ['reference_voisine' => $ref['parcel_reference'],
            'sommets' => count($near), 'deplacement_max_m' => $maxShift]);
        audit_log('qualite_recollage', 'parcel', $moveId, ['voisine' => $refId, 'sommets' => count($near)]);
        return $res + ['moved' => count($near), 'max_shift' => $maxShift, 'reference' => $move['parcel_reference']];
    }

    public static function dismiss(int $a, int $b, string $reason, int $userId): void
    {
        [$a, $b] = [min($a, $b), max($a, $b)];
        db_query('INSERT INTO quality_dismissals (parcel_a_id, parcel_b_id, reason, dismissed_by) VALUES (?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE reason = VALUES(reason), dismissed_by = VALUES(dismissed_by), dismissed_at = NOW()',
            [$a, $b, mb_substr($reason, 0, 255), $userId]);
        audit_log('qualite_ecart_accepte', 'parcel', $a, ['voisine' => $b, 'motif' => $reason]);
    }
}
