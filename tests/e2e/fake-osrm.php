<?php
/**
 * FAUX serveur OSRM, uniquement pour les tests automatiques hors ligne (l'environnement de test
 * n'a pas accès à Internet). Il renvoie un itinéraire « en L » (nord-sud puis est-ouest) au format
 * exact de l'API OSRM route v1, avec des étapes de manœuvre. Ne jamais l'utiliser en production.
 *   php -S 127.0.0.1:8099 tests/e2e/fake-osrm.php
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!preg_match('#^/route/v1/[a-z]+/([-\d.]+),([-\d.]+);([-\d.]+),([-\d.]+)$#', $path, $m)) {
    http_response_code(400);
    echo json_encode(['code' => 'InvalidUrl']);
    return true;
}
[$fLng, $fLat, $tLng, $tLat] = array_map('floatval', array_slice($m, 1));
$dist = static function ($a, $b) {
    $r = M_PI / 180;
    $x = sin(($b[0] - $a[0]) * $r / 2) ** 2 + cos($a[0] * $r) * cos($b[0] * $r) * sin(($b[1] - $a[1]) * $r / 2) ** 2;
    return 12742000 * asin(sqrt($x));
};
$corner = [$tLat, $fLng];
$pts = [];
foreach ([[[$fLat, $fLng], $corner], [$corner, [$tLat, $tLng]]] as [$a, $b]) {
    $n = max(1, (int) ceil($dist($a, $b) / 40));
    for ($i = 0; $i < $n; $i++) {
        $pts[] = [$a[0] + ($b[0] - $a[0]) * $i / $n, $a[1] + ($b[1] - $a[1]) * $i / $n];
    }
}
$pts[] = [$tLat, $tLng];
$d1 = $dist([$fLat, $fLng], $corner);
$d2 = $dist($corner, [$tLat, $tLng]);
$goingNorth = $tLat > $fLat;
$goingEast = $tLng > $fLng;
$modifier = ($goingNorth === $goingEast) ? 'right' : 'left';
$steps = [
    ['distance' => $d1, 'duration' => $d1 / 8, 'name' => 'Boulevard de Test', 'maneuver' => ['type' => 'depart', 'location' => [$fLng, $fLat]]],
    ['distance' => $d2, 'duration' => $d2 / 8, 'name' => 'Rue Simulée', 'maneuver' => ['type' => 'turn', 'modifier' => $modifier, 'location' => [$corner[1], $corner[0]]]],
    ['distance' => 0, 'duration' => 0, 'name' => '', 'maneuver' => ['type' => 'arrive', 'location' => [$tLng, $tLat]]],
];
header('Content-Type: application/json');
echo json_encode(['code' => 'Ok', 'routes' => [[
    'distance' => $d1 + $d2, 'duration' => ($d1 + $d2) / 8,
    'geometry' => ['type' => 'LineString', 'coordinates' => array_map(static fn ($p) => [$p[1], $p[0]], $pts)],
    'legs' => [['steps' => $steps]],
]]]);
return true;
