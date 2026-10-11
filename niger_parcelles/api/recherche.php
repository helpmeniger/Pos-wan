<?php
/**
 * API de recherche / autocomplétion (parcelles, propriétaires autorisés, zones).
 *   GET ?q=texte[&map=1]
 */
declare(strict_types=1);

define('NP_API', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

require_login();
require_permission('parcel.view');

$q = input_str('q', 100);
if ($q === null || mb_strlen($q) < 2) {
    json_response(['ok' => true, 'results' => []]);
}
$like = '%' . addcslashes($q, '%_\\') . '%';
$results = [];

foreach (db_all('SELECT p.id, p.parcel_reference, p.cadastral_reference, p.area_m2, p.centroid_lat, p.centroid_lng, a.name AS area_name
                 FROM parcels p LEFT JOIN administrative_areas a ON a.id = p.administrative_area_id
                 WHERE p.is_archived = 0 AND (p.parcel_reference LIKE ? OR p.cadastral_reference LIKE ?)
                 ORDER BY p.parcel_reference LIMIT 8', [$like, $like]) as $p) {
    $results[] = ['type' => 'parcel', 'id' => (int) $p['id'], 'icon' => 'bounding-box', 'label' => $p['parcel_reference'],
        'sub' => trim(($p['cadastral_reference'] ? 'Cadastre : ' . $p['cadastral_reference'] . ' · ' : '') . ($p['area_name'] ?? '') . ' · ' . fmt_area($p['area_m2']), ' ·'),
        'url' => 'parcelle_details.php?id=' . (int) $p['id'], 'center' => [(float) $p['centroid_lat'], (float) $p['centroid_lng']]];
}

if (can('owner.view_personal')) {
    $owners = db_all("SELECT o.id, o.owner_type, o.first_name, o.last_name, o.organization_name, o.file_reference,
                             (SELECT COUNT(*) FROM parcel_owners po WHERE po.owner_id = o.id AND po.valid_to IS NULL) AS n
                      FROM owners o WHERE o.is_archived = 0 AND (CONCAT_WS(' ', o.last_name, o.first_name) LIKE ?
                         OR CONCAT_WS(' ', o.first_name, o.last_name) LIKE ? OR o.organization_name LIKE ? OR o.file_reference LIKE ?)
                      ORDER BY o.last_name LIMIT 6", [$like, $like, $like, $like]);
    foreach ($owners as $o) {
        if (!empty($_GET['map'])) {
            // Sur la carte : proposer les parcelles en cours de ce propriétaire
            foreach (db_all('SELECT p.id, p.parcel_reference, p.centroid_lat, p.centroid_lng FROM parcel_owners po JOIN parcels p ON p.id = po.parcel_id
                             WHERE po.owner_id = ? AND po.valid_to IS NULL AND p.is_archived = 0 LIMIT 5', [$o['id']]) as $p) {
                $results[] = ['type' => 'parcel', 'id' => (int) $p['id'], 'icon' => 'person', 'label' => $p['parcel_reference'],
                              'sub' => 'Propriétaire : ' . owner_display_name($o), 'url' => 'parcelle_details.php?id=' . (int) $p['id']];
            }
        } else {
            $results[] = ['type' => 'owner', 'id' => (int) $o['id'], 'icon' => 'person', 'label' => owner_display_name($o),
                          'sub' => (int) $o['n'] . ' parcelle(s)' . ($o['file_reference'] ? ' · Dossier ' . $o['file_reference'] : ''),
                          'url' => 'proprietaire_details.php?id=' . (int) $o['id']];
        }
    }
}

foreach (db_all('SELECT id, name, area_type, min_lat, max_lat, min_lng, max_lng FROM administrative_areas WHERE name LIKE ? ORDER BY area_type, name LIMIT 6', [$like]) as $a) {
    $results[] = ['type' => 'area', 'id' => (int) $a['id'], 'icon' => 'pin-map', 'label' => $a['name'], 'sub' => label('area_type', $a['area_type']),
                  'url' => 'parcelles.php?area_id=' . (int) $a['id'],
                  'bbox' => $a['min_lat'] !== null ? [(float) $a['min_lng'], (float) $a['min_lat'], (float) $a['max_lng'], (float) $a['max_lat']] : null];
}

json_response(['ok' => true, 'results' => $results]);
