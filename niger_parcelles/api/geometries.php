<?php
/**
 * API Géométries (JSON).
 *   GET  ?action=versions&id=        -> toutes les versions des limites d'une parcelle
 *   POST ?action=measure {geometry}  -> validation + superficie/périmètre géodésiques (serveur)
 *   GET  ?action=overlaps&id=        -> chevauchements d'une parcelle
 *   POST ?action=resolve_overlap {id, status, notes}
 */
declare(strict_types=1);

define('NP_API', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login();
require_permission('parcel.view');

switch (api_action()) {
    case 'versions':
        $id = input_int('id', 0);
        ParcelService::getOrFail($id);
        json_response(['ok' => true, 'versions' => array_map(static fn ($v) => [
            'version' => (int) $v['geometry_version'], 'area_m2' => (float) $v['area_m2'], 'perimeter_m' => (float) $v['perimeter_m'],
            'vertex_count' => (int) $v['vertex_count'], 'source' => $v['source'], 'source_crs' => $v['source_crs'], 'reason' => $v['change_reason'],
            'created_at' => $v['created_at'], 'created_by' => $v['created_by_name'], 'geometry' => json_decode($v['geojson'], true),
        ], ParcelService::geometryVersions($id))]);

    case 'measure':
        api_require_post();
        [$rings, $m] = ParcelService::prepareGeometry(json_input()['geometry'] ?? null);
        json_response(['ok' => true, 'measure' => $m, 'geometry' => Geo::toGeoJson($rings)]);

    case 'overlaps':
        $id = input_int('id', 0);
        ParcelService::getOrFail($id);
        json_response(['ok' => true, 'overlaps' => ParcelService::overlapsOf($id)]);

    case 'resolve_overlap':
        api_require_post();
        require_permission('overlap.resolve');
        $b = json_input();
        ParcelService::resolveOverlap((int) ($b['id'] ?? 0), (string) ($b['status'] ?? ''), isset($b['notes']) ? (string) $b['notes'] : null, (int) $user['id']);
        json_response(['ok' => true]);
}
json_error('Action inconnue.', 400);
