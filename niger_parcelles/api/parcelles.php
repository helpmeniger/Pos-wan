<?php
/**
 * API Parcelles (JSON).
 *   GET  ?action=list&west=&south=&east=&north=&zoom=[&filtres]  -> GeoJSON pour la carte
 *   GET  ?action=get&id=                                         -> fiche détaillée
 *   POST ?action=check            {geometry, id?}                 -> validation + chevauchements (sans enregistrer)
 *   POST ?action=create           {attributes, geometry, acknowledge}
 *   POST ?action=update           {id, attributes}
 *   POST ?action=update_geometry  {id, geometry, reason, acknowledge}
 *   POST ?action=status           {id, status, comment}
 *   POST ?action=archive          {id, reason}
 */
declare(strict_types=1);

define('NP_API', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login();
require_permission('parcel.view');
$uid = (int) $user['id'];

switch (api_action('list')) {
    case 'list':
        $west = input_float('west');
        $south = input_float('south');
        $east = input_float('east');
        $north = input_float('north');
        $zoom = max(0, min(22, input_int('zoom', 10)));
        $f = ParcelQuery::filtersFrom($_GET);
        [$where, $params] = ParcelQuery::where($f);
        if ($west !== null && $south !== null && $east !== null && $north !== null) {
            $where .= ' AND p.min_lat <= ? AND p.max_lat >= ? AND p.min_lng <= ? AND p.max_lng >= ?';
            array_push($params, $north, $south, $east, $west);
        }
        $limit = max(100, min(5000, (int) setting('map_max_features', 1500)));
        $total = (int) db_value("SELECT COUNT(*) FROM parcels p WHERE $where", $params);
        $asPoints = $zoom < 13;
        $sql = 'SELECT p.id, p.parcel_reference, p.verification_status, p.category, p.survey_level, p.has_overlap_warning,
                       p.area_m2, p.centroid_lat, p.centroid_lng, p.is_demo' . ($asPoints ? '' : ', g.geojson') . '
                FROM parcels p ' . ($asPoints ? '' : 'JOIN parcel_geometries g ON g.parcel_id = p.id AND g.geometry_version = p.current_geometry_version ') .
               "WHERE $where ORDER BY p.area_m2 DESC LIMIT $limit";
        $tol = $zoom >= 17 ? 0 : ($zoom >= 15 ? 0.5 : 2.0);
        $rows = db_all($sql, $params);
        // Noms des propriétaires actuels (affichage sur la carte) : profils autorisés uniquement
        $ownerNames = [];
        $withOwners = !empty($_GET['owners']) && can('owner.view_personal');
        if ($withOwners && $rows) {
            $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
            foreach (db_all('SELECT po.parcel_id, o.owner_type, o.first_name, o.last_name, o.organization_name, po.share_percentage
                             FROM parcel_owners po JOIN owners o ON o.id = po.owner_id
                             WHERE po.valid_to IS NULL AND po.parcel_id IN (' . implode(',', $ids) . ') ORDER BY po.id') as $o) {
                $ownerNames[(int) $o['parcel_id']][] = owner_display_name($o)
                    . ($o['share_percentage'] !== null ? ' (' . rtrim(rtrim(number_format((float) $o['share_percentage'], 2, ',', ''), '0'), ',') . ' %)' : '');
            }
            audit_log('carte_affichage_proprietaires', null, null, ['parcelles' => count($ownerNames)]);
        }
        $features = [];
        foreach ($rows as $r) {
            if ($asPoints) {
                $geom = ['type' => 'Point', 'coordinates' => [(float) $r['centroid_lng'], (float) $r['centroid_lat']]];
            } else {
                $geom = json_decode($r['geojson'], true);
                if ($tol > 0 && isset($geom['coordinates'])) {
                    $geom['coordinates'] = Geo::simplify($geom['coordinates'], $tol);
                }
            }
            $features[] = ['type' => 'Feature', 'id' => (int) $r['id'], 'geometry' => $geom, 'properties' => [
                'id' => (int) $r['id'], 'ref' => $r['parcel_reference'], 'status' => $r['verification_status'],
                'category' => $r['category'], 'survey' => $r['survey_level'], 'overlap' => (int) $r['has_overlap_warning'] === 1,
                'area' => (float) $r['area_m2'], 'demo' => (int) $r['is_demo'] === 1,
            ] + ($withOwners ? ['owners' => implode(' ; ', $ownerNames[(int) $r['id']] ?? [])] : [])];
        }
        json_response(['type' => 'FeatureCollection', 'features' => $features, 'total' => $total,
                       'truncated' => $total > count($features), 'mode' => $asPoints ? 'points' : 'polygons']);

    case 'get':
        $id = input_int('id', 0);
        $p = ParcelService::getOrFail($id);
        $areas = admin_area_breakdown($p['administrative_area_id'] ? (int) $p['administrative_area_id'] : null);
        $out = [
            'id' => (int) $p['id'], 'parcel_reference' => $p['parcel_reference'], 'cadastral_reference' => $p['cadastral_reference'],
            'administrative_area_id' => $p['administrative_area_id'] ? (int) $p['administrative_area_id'] : null,
            'areas' => $areas, 'locality_description' => $p['locality_description'], 'address' => $p['address'],
            'category' => $p['category'], 'category_label' => label('category', $p['category']),
            'area_m2' => (float) $p['area_m2'], 'perimeter_m' => (float) $p['perimeter_m'],
            'centroid' => [(float) $p['centroid_lat'], (float) $p['centroid_lng']],
            'survey_level' => $p['survey_level'], 'survey_level_label' => label('survey_level', $p['survey_level']),
            'declared_status' => $p['declared_status'], 'declared_status_label' => label('declared_status', $p['declared_status']),
            'title_reference' => $p['title_reference'], 'verification_status' => $p['verification_status'],
            'verification_status_label' => label('verification_status', $p['verification_status']),
            'has_overlap_warning' => (int) $p['has_overlap_warning'] === 1, 'is_demo' => (int) $p['is_demo'] === 1,
            'is_archived' => (int) $p['is_archived'] === 1, 'created_at' => $p['created_at'], 'created_by_name' => $p['created_by_name'],
            'geometry_version' => (int) $p['current_geometry_version'], 'geometry' => ParcelService::currentGeometry($id),
            'can_edit' => can_edit_parcel($p), 'transitions' => array_map(static fn ($s) => ['value' => $s, 'label' => label('verification_status', $s)],
                allowed_status_transitions($p)),
            'overlaps' => array_map(static fn ($o) => ['id' => (int) $o['id'], 'other_id' => (int) $o['other_id'], 'other_reference' => $o['other_reference'],
                'type' => $o['overlap_type'], 'status' => $o['status'], 'status_label' => label('overlap_status', $o['status'])],
                ParcelService::overlapsOf($id)),
        ];
        $owners = OwnerService::currentOwners($id);
        $out['owner_count'] = count($owners);
        if (can('owner.view_personal')) {
            $out['owners'] = array_map(static fn ($o) => ['owner_id' => (int) $o['owner_id'], 'name' => owner_display_name($o),
                'role' => label('ownership_role', $o['ownership_role']), 'share' => $o['share_percentage'] !== null ? (float) $o['share_percentage'] : null,
                'status' => label('link_status', $o['verification_status']), 'phone' => $o['phone']], $owners);
        }
        if (can('document.view')) {
            $out['document_count'] = (int) db_value('SELECT COUNT(*) FROM documents WHERE parcel_id = ? AND is_archived = 0', [$id]);
        }
        json_response(['ok' => true, 'parcel' => $out]);

    case 'check':
        api_require_post();
        $body = json_input();
        [$rings, $m] = ParcelService::prepareGeometry($body['geometry'] ?? null);
        $excl = isset($body['id']) ? (int) $body['id'] : null;
        $overlaps = ParcelService::findOverlaps($rings, $excl);
        json_response(['ok' => true, 'measure' => $m, 'overlaps' => $overlaps]);

    case 'create':
        api_require_post();
        require_permission('parcel.create');
        $body = json_input();
        $res = ParcelService::create((array) ($body['attributes'] ?? []), $body['geometry'] ?? null, $uid, !empty($body['acknowledge']));
        json_response(['ok' => true] + $res, 201);

    case 'update':
        api_require_post();
        $body = json_input();
        $p = ParcelService::getOrFail((int) ($body['id'] ?? 0));
        if (!can_edit_parcel($p)) {
            json_error('Vous ne pouvez pas modifier cette parcelle.', 403);
        }
        json_response(['ok' => true] + ParcelService::updateAttributes((int) $p['id'], (array) ($body['attributes'] ?? []), $uid));

    case 'update_geometry':
        api_require_post();
        $body = json_input();
        $p = ParcelService::getOrFail((int) ($body['id'] ?? 0));
        if (!can_edit_parcel($p)) {
            json_error('Vous ne pouvez pas modifier les limites de cette parcelle.', 403);
        }
        $reason = isset($body['reason']) ? mb_substr(trim((string) $body['reason']), 0, 255) : null;
        json_response(['ok' => true] + ParcelService::updateGeometry((int) $p['id'], $body['geometry'] ?? null, $uid, $reason ?: null, !empty($body['acknowledge'])));

    case 'status':
        api_require_post();
        $body = json_input();
        ParcelService::changeStatus((int) ($body['id'] ?? 0), (string) ($body['status'] ?? ''), $uid,
            isset($body['comment']) ? mb_substr(trim((string) $body['comment']), 0, 1000) : null);
        json_response(['ok' => true]);

    case 'archive':
        api_require_post();
        require_permission('parcel.archive');
        $body = json_input();
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($reason === '') {
            json_error('Indiquez le motif de l\'archivage.', 422);
        }
        ParcelService::archive((int) ($body['id'] ?? 0), $uid, mb_substr($reason, 0, 255));
        json_response(['ok' => true]);
}
json_error('Action inconnue.', 400);
