<?php
/**
 * API Découpage administratif.
 *   GET ?action=geojson        -> limites importées (FeatureCollection)
 *   GET ?action=children&id=   -> sous-zones
 */
declare(strict_types=1);

define('NP_API', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

require_login();
require_permission('parcel.view');

switch (api_action('geojson')) {
    case 'geojson':
        $features = [];
        foreach (db_all('SELECT id, name, area_type, geojson FROM administrative_areas WHERE geojson IS NOT NULL LIMIT 2000') as $a) {
            $geom = json_decode($a['geojson'], true);
            if (!$geom) {
                continue;
            }
            $features[] = ['type' => 'Feature', 'geometry' => $geom, 'properties' => ['id' => (int) $a['id'], 'name' => $a['name'],
                           'type' => $a['area_type'], 'type_label' => label('area_type', $a['area_type'])]];
        }
        header('Cache-Control: private, max-age=300');
        json_response(['type' => 'FeatureCollection', 'features' => $features]);

    case 'children':
        $id = input_int('id');
        $rows = $id ? db_all('SELECT id, name, area_type, code FROM administrative_areas WHERE parent_id = ? ORDER BY name', [$id])
                    : db_all('SELECT id, name, area_type, code FROM administrative_areas WHERE parent_id IS NULL ORDER BY name');
        json_response(['ok' => true, 'areas' => $rows]);
}
json_error('Action inconnue.', 400);
