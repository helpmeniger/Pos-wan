<?php
declare(strict_types=1);

/**
 * Importe des points de repère RÉELS depuis OpenStreetMap (API Overpass) pour une ville active.
 *   php bin/import-landmarks.php --city=Niamey [--radius=12000] [--overpass=https://overpass-api.de/api/interpreter]
 * Données © contributeurs OpenStreetMap (licence ODbL) — vérifiables et corrigeables dans l'administration.
 */
require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Database;

$o = getopt('', ['city:', 'radius:', 'overpass:']);
$city = Database::first('SELECT * FROM cities WHERE name = :n', ['n' => $o['city'] ?? 'Niamey']);
if (!$city) {
    fwrite(STDERR, "Ville introuvable.\n");
    exit(1);
}
$radius = (int) ($o['radius'] ?? 12000);
$filters = [
    'aeroway' => ['aerodrome' => 'aéroport'],
    'amenity' => ['hospital' => 'hôpital', 'clinic' => 'clinique', 'marketplace' => 'marché', 'bus_station' => 'gare routière', 'university' => 'université',
        'place_of_worship' => 'lieu de culte', 'bank' => 'banque', 'pharmacy' => 'pharmacie', 'police' => 'police', 'school' => 'école', 'fuel' => 'station-service'],
    'tourism' => ['hotel' => 'hôtel', 'museum' => 'musée'],
    'leisure' => ['stadium' => 'stade', 'park' => 'parc'],
    'shop' => ['supermarket' => 'supermarché', 'mall' => 'centre commercial'],
];
$parts = '';
foreach ($filters as $key => $vals) {
    $parts .= sprintf('nwr["%s"~"^(%s)$"]["name"](around:%d,%F,%F);', $key, implode('|', array_keys($vals)), $radius, $city['lat'], $city['lng']);
}
$query = "[out:json][timeout:60];($parts);out center tags;";
$ch = curl_init($o['overpass'] ?? 'https://overpass-api.de/api/interpreter');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['data' => $query]), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_USERAGENT => 'MARWA/1.0 landmark import']);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($body === false || $code !== 200) {
    fwrite(STDERR, "Overpass indisponible (HTTP $code). Réessayez plus tard.\n");
    exit(1);
}
$data = json_decode((string) $body, true);
$n = 0;
foreach ($data['elements'] ?? [] as $el) {
    $lat = $el['lat'] ?? $el['center']['lat'] ?? null;
    $lng = $el['lon'] ?? $el['center']['lon'] ?? null;
    $name = $el['tags']['name:fr'] ?? $el['tags']['name'] ?? null;
    if ($lat === null || !$name) {
        continue;
    }
    $category = 'autre';
    foreach ($filters as $key => $vals) {
        if (isset($el['tags'][$key], $vals[$el['tags'][$key]])) {
            $category = $vals[$el['tags'][$key]];
        }
    }
    $n += Database::execute('INSERT INTO landmarks (city_id, name, category, lat, lng, source, verified, is_meeting_point) VALUES (:c, :n, :cat, :la, :ln, :s, 1, :mp)
                             ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category), lat = VALUES(lat), lng = VALUES(lng)',
        ['c' => $city['id'], 'n' => mb_substr($name, 0, 150), 'cat' => $category, 'la' => $lat, 'ln' => $lng, 's' => 'osm:' . $el['type'] . '/' . $el['id'],
         'mp' => in_array($category, ['aéroport', 'gare routière', 'marché', 'hôpital', 'stade'], true) ? 1 : 0]) > 0 ? 1 : 0;
}
echo "$n points de repère importés ou mis à jour pour {$city['name']} (source : OpenStreetMap).\n";
