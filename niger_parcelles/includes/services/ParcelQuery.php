<?php
/**
 * Construction des filtres de recherche de parcelles (liste, carte, exports,
 * rapports). Toutes les valeurs passent par des paramètres préparés.
 */
declare(strict_types=1);

final class ParcelQuery
{
    public const SORTS = [
        'reference' => 'p.parcel_reference', 'created' => 'p.created_at', 'area' => 'p.area_m2',
        'status' => 'p.verification_status', 'category' => 'p.category', 'updated' => 'p.updated_at',
    ];

    /** Lit les filtres depuis un tableau (GET). */
    public static function filtersFrom(array $src): array
    {
        $f = [];
        $str = static fn ($k, $n = 100) => isset($src[$k]) && !is_array($src[$k]) && trim((string) $src[$k]) !== '' ? mb_substr(trim((string) $src[$k]), 0, $n) : null;
        $f['q'] = $str('q');
        $f['area_id'] = isset($src['area_id']) && ctype_digit((string) $src['area_id']) ? (int) $src['area_id'] : null;
        $f['category'] = in_array($src['category'] ?? null, array_keys(labels('category')), true) ? $src['category'] : null;
        $f['status'] = in_array($src['status'] ?? null, array_keys(labels('verification_status')), true) ? $src['status'] : null;
        $f['survey_level'] = in_array($src['survey_level'] ?? null, array_keys(labels('survey_level')), true) ? $src['survey_level'] : null;
        $f['declared_status'] = in_array($src['declared_status'] ?? null, array_keys(labels('declared_status')), true) ? $src['declared_status'] : null;
        foreach (['min_area', 'max_area', 'lat', 'lng'] as $k) {
            $v = isset($src[$k]) && !is_array($src[$k]) ? filter_var(str_replace(',', '.', (string) $src[$k]), FILTER_VALIDATE_FLOAT) : false;
            $f[$k] = $v === false ? null : (float) $v;
        }
        foreach (['date_from', 'date_to'] as $k) {
            $v = $str($k, 10);
            $f[$k] = ($v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
        }
        $f['overlap'] = !empty($src['overlap']);
        $f['no_owner'] = !empty($src['no_owner']);
        $f['archived'] = !empty($src['archived']);
        $f['owner_id'] = isset($src['owner_id']) && ctype_digit((string) $src['owner_id']) ? (int) $src['owner_id'] : null;
        return $f;
    }

    /** Retourne [clause WHERE, paramètres]. */
    public static function where(array $f): array
    {
        $w = [];
        $p = [];
        $w[] = $f['archived'] ? 'p.is_archived = 1' : 'p.is_archived = 0';
        if (!empty($f['q'])) {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $parts = ['p.parcel_reference LIKE ?', 'p.cadastral_reference LIKE ?', 'p.title_reference LIKE ?', 'p.locality_description LIKE ?'];
            array_push($p, $like, $like, $like, $like);
            // Recherche par propriétaire : uniquement pour les rôles autorisés
            if (can('owner.view_personal')) {
                $parts[] = 'EXISTS (SELECT 1 FROM parcel_owners po JOIN owners o ON o.id = po.owner_id
                           WHERE po.parcel_id = p.id AND po.valid_to IS NULL
                           AND (CONCAT_WS(\' \', o.last_name, o.first_name) LIKE ? OR CONCAT_WS(\' \', o.first_name, o.last_name) LIKE ?
                                OR o.organization_name LIKE ? OR o.file_reference LIKE ?))';
                array_push($p, $like, $like, $like, $like);
            }
            $w[] = '(' . implode(' OR ', $parts) . ')';
        }
        if (!empty($f['area_id'])) {
            $ids = admin_area_descendants((int) $f['area_id']);
            $w[] = 'p.administrative_area_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $p = array_merge($p, $ids);
        }
        foreach (['category' => 'p.category', 'status' => 'p.verification_status', 'survey_level' => 'p.survey_level',
                  'declared_status' => 'p.declared_status'] as $k => $col) {
            if (!empty($f[$k])) {
                $w[] = "$col = ?";
                $p[] = $f[$k];
            }
        }
        if ($f['min_area'] !== null) {
            $w[] = 'p.area_m2 >= ?';
            $p[] = $f['min_area'];
        }
        if ($f['max_area'] !== null) {
            $w[] = 'p.area_m2 <= ?';
            $p[] = $f['max_area'];
        }
        if ($f['lat'] !== null && $f['lng'] !== null) {
            // Parcelles contenant exactement le point : pré-filtre par emprise (index), puis test géométrique
            $ids = [];
            $cands = db_all('SELECT p.id, g.geojson FROM parcels p JOIN parcel_geometries g ON g.parcel_id = p.id
                             AND g.geometry_version = p.current_geometry_version
                             WHERE p.min_lat <= ? AND p.max_lat >= ? AND p.min_lng <= ? AND p.max_lng >= ? LIMIT 500',
                [$f['lat'], $f['lat'], $f['lng'], $f['lng']]);
            foreach ($cands as $c) {
                $ring = json_decode($c['geojson'], true)['coordinates'][0] ?? null;
                if ($ring && Geo::pointInRing([$f['lng'], $f['lat']], $ring)) {
                    $ids[] = (int) $c['id'];
                }
            }
            $w[] = $ids ? 'p.id IN (' . implode(',', $ids) . ')' : '0 = 1';
        }
        if (!empty($f['date_from'])) {
            $w[] = 'p.created_at >= ?';
            $p[] = $f['date_from'] . ' 00:00:00';
        }
        if (!empty($f['date_to'])) {
            $w[] = 'p.created_at <= ?';
            $p[] = $f['date_to'] . ' 23:59:59';
        }
        if (!empty($f['overlap'])) {
            $w[] = 'p.has_overlap_warning = 1';
        }
        if (!empty($f['no_owner'])) {
            $w[] = 'NOT EXISTS (SELECT 1 FROM parcel_owners po2 WHERE po2.parcel_id = p.id AND po2.valid_to IS NULL)';
        }
        if (!empty($f['owner_id']) && can('owner.view_personal')) {
            $w[] = 'EXISTS (SELECT 1 FROM parcel_owners po3 WHERE po3.parcel_id = p.id AND po3.owner_id = ?)';
            $p[] = $f['owner_id'];
        }
        return [implode(' AND ', $w), $p];
    }

    public static function orderBy(?string $sort, ?string $dir): string
    {
        $col = self::SORTS[$sort ?? ''] ?? 'p.created_at';
        $d = strtolower((string) $dir) === 'asc' ? 'ASC' : 'DESC';
        return "$col $d, p.id $d";
    }

    /** Recherche paginée pour la liste. */
    public static function search(array $f, int $limit, int $offset, ?string $sort = null, ?string $dir = null): array
    {
        [$where, $params] = self::where($f);
        $total = (int) db_value("SELECT COUNT(*) FROM parcels p WHERE $where", $params);
        $rows = db_all("SELECT p.id, p.parcel_reference, p.cadastral_reference, p.category, p.area_m2, p.perimeter_m,
                               p.verification_status, p.survey_level, p.declared_status, p.has_overlap_warning, p.is_demo,
                               p.centroid_lat, p.centroid_lng, p.created_at, p.locality_description, a.name AS area_name,
                               (SELECT COUNT(*) FROM parcel_owners po WHERE po.parcel_id = p.id AND po.valid_to IS NULL) AS owner_count
                        FROM parcels p LEFT JOIN administrative_areas a ON a.id = p.administrative_area_id
                        WHERE $where ORDER BY " . self::orderBy($sort, $dir) . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset), $params);
        return ['total' => $total, 'rows' => $rows];
    }
}
