<?php
/**
 * Tests unitaires (sans base de données) : calculs géographiques, systèmes de
 * coordonnées, lecteurs de formats, générateurs PDF/Excel, découpage SQL,
 * permissions, validation des fichiers.
 */
declare(strict_types=1);

if (!defined('NP_TESTS_LOADED')) {
    define('NP_TESTS_LOADED', true);
    require __DIR__ . '/bootstrap.php';
}

require_once __DIR__ . '/unit_test_helpers.php';

suite('Calculs géographiques (Geo)');

test('Superficie géodésique identique à Turf.js (référence 12 022,69 m²)', function () {
    $r = Geo::normalizePolygon(['type' => 'Polygon', 'coordinates' => [[[2.1, 13.5], [2.101, 13.5], [2.101, 13.501], [2.1, 13.501], [2.1, 13.5]]]]);
    assert_near(12022.69, Geo::polygonArea($r), 0.01, 'aire');
    assert_near(438.64, Geo::polygonPerimeter($r), 0.01, 'périmètre');
});

test('Rectangle de 100 m × 50 m ≈ 5 000 m² (écart < 0,5 %)', function () {
    $g = rect_geom(2.12, 13.51, 100, 50);
    $m = Geo::measure(Geo::normalizePolygon($g));
    assert_near(5000, $m['area_m2'], 25, 'aire');
    assert_near(300, $m['perimeter_m'], 1.5, 'périmètre');
});

test('L\'aire ne dépend pas du sens de saisie et les trous sont déduits', function () {
    $outer = [[2.1, 13.5], [2.102, 13.5], [2.102, 13.502], [2.1, 13.502], [2.1, 13.5]];
    $hole = [[2.1005, 13.5005], [2.1005, 13.5010], [2.1010, 13.5010], [2.1010, 13.5005], [2.1005, 13.5005]];
    $a1 = Geo::polygonArea(Geo::normalizePolygon(['type' => 'Polygon', 'coordinates' => [$outer]]));
    $a2 = Geo::polygonArea(Geo::normalizePolygon(['type' => 'Polygon', 'coordinates' => [array_reverse($outer)]]));
    assert_near($a1, $a2, 0.001);
    $withHole = Geo::polygonArea(Geo::normalizePolygon(['type' => 'Polygon', 'coordinates' => [$outer, $hole]]));
    $holeArea = Geo::polygonArea(Geo::normalizePolygon(['type' => 'Polygon', 'coordinates' => [$hole]]));
    assert_near($a1 - $holeArea, $withHole, 0.01);
});

test('Normalisation : fermeture, doublons consécutifs supprimés, orientation RFC 7946', function () {
    $r = Geo::normalizePolygon(['type' => 'Polygon', 'coordinates' => [[[2.1, 13.5], [2.1, 13.5], [2.1, 13.501], [2.101, 13.501], [2.101, 13.5]]]]);
    assert_same($r[0][0], $r[0][count($r[0]) - 1], 'anneau fermé');
    assert_same(5, count($r[0]), 'doublon supprimé');
    $s = 0;
    for ($i = 0; $i < 4; $i++) {
        $s += $r[0][$i][0] * $r[0][$i + 1][1] - $r[0][$i + 1][0] * $r[0][$i][1];
    }
    assert_true($s > 0, 'anneau extérieur anti-horaire');
});

test('Validation : polygone auto-intersecté (nœud papillon) refusé', function () {
    $r = Geo::normalizePolygon(['type' => 'Polygon', 'coordinates' => [[[2.1, 13.5], [2.101, 13.501], [2.101, 13.5], [2.1, 13.501], [2.1, 13.5]]]]);
    $errs = Geo::validate($r, geo_bounds_default());
    assert_true((bool) $errs);
    assert_contains('croisent', implode(' ', $errs));
});

test('Validation : coordonnées hors du Niger ou inversées refusées', function () {
    $r = Geo::normalizePolygon(rect_geom(2.35, 48.85)); // Paris
    assert_contains('en dehors', implode(' ', Geo::validate($r, geo_bounds_default())));
    assert_throws(fn () => Geo::normalizePolygon(['type' => 'Point', 'coordinates' => [2, 13]]), InvalidArgumentException::class, 'Polygon');
    assert_throws(fn () => Geo::normalizePolygon(['type' => 'Polygon', 'coordinates' => [[[2.1, 13.5], [2.2, 13.5], [2.1, 13.5]]]]), InvalidArgumentException::class, '3 sommets');
    assert_throws(fn () => Geo::normalizePolygon(['type' => 'Polygon', 'coordinates' => [[['a', 13.5], [2.2, 13.5], [2.2, 13.6], [2.1, 13.5]]]]), InvalidArgumentException::class);
});

test('Validation : superficie trop faible refusée', function () {
    $r = Geo::normalizePolygon(rect_geom(2.12, 13.51, 0.5, 0.5));
    assert_contains('trop faible', implode(' ', Geo::validate($r, geo_bounds_default(), 1.0)));
});

test('Chevauchements : contiguïté = aucun, recouvrement = chevauchement, copie = doublon', function () {
    $a = Geo::normalizePolygon(rect_geom(2.12, 13.51, 20, 30));
    $bb = Geo::bbox($a);
    $adjacent = Geo::normalizePolygon(['type' => 'Polygon', 'coordinates' => [[[$bb['max_lng'], $bb['min_lat']], [$bb['max_lng'] + 0.0002, $bb['min_lat']],
        [$bb['max_lng'] + 0.0002, $bb['max_lat']], [$bb['max_lng'], $bb['max_lat']], [$bb['max_lng'], $bb['min_lat']]]]]);
    assert_same('none', Geo::compare($a, $adjacent), 'parcelles contiguës');
    $overlap = Geo::normalizePolygon(rect_geom(2.12 + 0.0001, 13.51 + 0.0001, 20, 30));
    assert_same('overlap', Geo::compare($a, $overlap), 'recouvrement partiel');
    $inside = Geo::normalizePolygon(rect_geom(2.12 + 0.00003, 13.51 + 0.00003, 5, 5));
    assert_same('overlap', Geo::compare($a, $inside), 'inclusion');
    assert_same('duplicate', Geo::compare($a, Geo::normalizePolygon(rect_geom(2.12, 13.51, 20, 30))), 'copie');
    $far = Geo::normalizePolygon(rect_geom(2.2, 13.6));
    assert_same('none', Geo::compare($a, $far), 'éloignées');
});

test('Point dans polygone et simplification Douglas-Peucker', function () {
    $a = Geo::normalizePolygon(rect_geom(2.12, 13.51, 20, 30));
    $c = Geo::centroid($a);
    assert_true(Geo::pointInRing($c, $a[0]));
    assert_false(Geo::pointInRing([2.2, 13.6], $a[0]));
    // Cercle de 64 sommets simplifié
    $ring = [];
    for ($i = 0; $i < 64; $i++) {
        $ring[] = [2.12 + 0.001 * cos(2 * M_PI * $i / 64), 13.51 + 0.001 * sin(2 * M_PI * $i / 64)];
    }
    $ring[] = $ring[0];
    $s = Geo::simplify([$ring], 5.0);
    assert_true(count($s[0]) < 40 && count($s[0]) >= 4, 'simplifié : ' . count($s[0]));
});

suite('Systèmes de coordonnées (Crs)');

test('UTM 31N/32N/33N aller-retour précis au millimètre', function () {
    foreach ([[2.1254, 13.5116, 31], [7.99, 16.97, 32], [12.61, 13.31, 33]] as [$lng, $lat, $zone]) {
        [$x, $y] = Crs::wgs84ToUtm($lng, $lat, $zone);
        [$lng2, $lat2] = Crs::toWgs84($x, $y, 'EPSG:326' . $zone);
        assert_near($lng, $lng2, 1e-8, "lng zone $zone");
        assert_near($lat, $lat2, 1e-8, "lat zone $zone");
    }
});

test('Web Mercator et détection des CRS (.prj, URN GeoJSON)', function () {
    [$lng, $lat] = Crs::toWgs84(236602.0, 1517936.0, 'EPSG:3857');
    assert_near(2.1254, $lng, 0.001);
    assert_near(13.5116, $lat, 0.01);
    assert_same('EPSG:32631', Crs::detect('PROJCS["WGS_1984_UTM_Zone_31N",GEOGCS["GCS_WGS_1984"]]'));
    assert_same('EPSG:4326', Crs::detect('{"type":"name","properties":{"name":"urn:ogc:def:crs:OGC:1.3:CRS84"}}'));
    assert_same('EPSG:32632', Crs::detect('urn:ogc:def:crs:EPSG::32632'));
    assert_same('EPSG:4326', Crs::detect('GEOGCS["GCS_WGS_1984",DATUM["D_WGS_1984"]]'));
    assert_same(null, Crs::detect('PROJCS["Clarke_1880_UTM"]'));
});

suite('Lecteurs de formats');

test('WKT POLYGON et MULTIPOLYGON', function () {
    $g = ImportService::parseWkt('POLYGON((2.1 13.5, 2.2 13.5, 2.2 13.6, 2.1 13.5))');
    assert_same('Polygon', $g['type']);
    assert_same(4, count($g['coordinates'][0]));
    $m = ImportService::parseWkt('MULTIPOLYGON(((2.1 13.5, 2.2 13.5, 2.2 13.6, 2.1 13.5)),((3 14, 3.1 14, 3.1 14.1, 3 14)))');
    assert_same('MultiPolygon', $m['type']);
    assert_same(null, ImportService::parseWkt('LINESTRING(1 2, 3 4)'));
});

test('Shapefile ZIP : géométries, attributs DBF et .prj', function () {
    $zip = tempnam(sys_get_temp_dir(), 'shp') . '.zip';
    [$x, $y] = Crs::wgs84ToUtm(2.12, 13.51, 31);
    np_make_shapefile_zip($zip, [[[$x, $y], [$x, $y + 30], [$x + 20, $y + 30], [$x + 20, $y], [$x, $y]]], [['Lot A', 'CAD-001']],
        'PROJCS["WGS_1984_UTM_Zone_31N",GEOGCS["GCS_WGS_1984"]]');
    $r = ShapefileReader::readZip($zip);
    @unlink($zip);
    assert_same(1, count($r['features']));
    assert_same('Lot A', $r['features'][0]['properties']['NAME']);
    assert_same('CAD-001', $r['features'][0]['properties']['REF_CAD']);
    assert_same('EPSG:32631', Crs::detect($r['prj']));
    $g = Crs::transformGeometry($r['features'][0]['geometry'], 'EPSG:32631');
    $m = Geo::measure(Geo::normalizePolygon($g));
    assert_near(600, $m['area_m2'], 3, 'aire du lot de 20 × 30 m');
});

suite('Générateurs et utilitaires');

test('PDF généré valide (en-tête, xref, accents WinAnsi)', function () {
    $pdf = new SimplePdf('Test');
    $pdf->addPage();
    $pdf->text(40, 40, 'Référence parcellaire : élément été', 12, true);
    $pdf->polygon([[50, 100], [150, 100], [150, 200]], [255, 200, 150], [224, 82, 6]);
    $out = $pdf->output();
    assert_same('%PDF-1.4', substr($out, 0, 8));
    assert_contains('%%EOF', $out);
    assert_contains('/Type /Catalog', $out);
    assert_true(SimplePdf::textWidth('MMM', 10) > SimplePdf::textWidth('iii', 10));
});

test('Classeur Excel .xlsx valide (archive OOXML)', function () {
    $f = tempnam(sys_get_temp_dir(), 'xl');
    $mime = XlsxWriter::write($f, 'Parcelles', ['Référence', 'Superficie'], [['NIG-NIA-2026-000001', 602.5], ['<script>', 1]]);
    assert_same('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $mime);
    $z = new ZipArchive();
    assert_true($z->open($f) === true);
    $sheet = $z->getFromName('xl/worksheets/sheet1.xml');
    $z->close();
    @unlink($f);
    assert_contains('NIG-NIA-2026-000001', $sheet);
    assert_contains('&lt;script&gt;', $sheet, 'contenu échappé');
    assert_contains('<v>602.5</v>', $sheet, 'nombre');
});

test('Découpage SQL : chaînes, commentaires et points-virgules', function () {
    $st = SqlRunner::split("-- commentaire ; \nINSERT INTO t VALUES ('a;b', 'l''apostrophe');\n/* bloc ; */ SELECT 1; # fin ;\nSELECT \"x;y\"");
    assert_same(3, count($st));
    assert_contains("'a;b'", $st[0]);
    assert_same('SELECT "x;y"', $st[2]);
});

test('Matrice des permissions par rôle', function () {
    assert_true(role_has_permission('admin_general', 'user.manage'));
    assert_true(role_has_permission('agent_terrain', 'parcel.create'));
    assert_false(role_has_permission('agent_terrain', 'parcel.validate'));
    assert_false(role_has_permission('agent_terrain', 'user.manage'));
    assert_false(role_has_permission('consultation', 'owner.view_personal'));
    assert_false(role_has_permission('consultation', 'parcel.create'));
    assert_true(role_has_permission('verificateur', 'parcel.validate'));
    assert_false(role_has_permission('verificateur', 'parcel.create'));
    assert_false(role_has_permission(null, 'parcel.view'));
    assert_false(role_has_permission('inconnu', 'parcel.view'));
});

test('Politique de mots de passe et échappement HTML', function () {
    assert_true(password_policy_error('court1') !== null);
    assert_true(password_policy_error('sanschiffreslong') !== null);
    assert_same(null, password_policy_error('Robuste-2026-ok'));
    assert_same('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', e('<script>alert("x")</script>'));
    assert_same('&#039;', e("'"));
});

test('Jeton CSRF : génération et comparaison à temps constant', function () {
    $_SESSION = [];
    $t = csrf_token();
    assert_same(64, strlen($t));
    assert_true(csrf_valid($t));
    assert_false(csrf_valid('x' . substr($t, 1)));
    assert_false(csrf_valid(''));
    assert_false(csrf_valid(null));
});

test('Validation des documents : faux PDF, PHP déguisé, extension interdite', function () {
    $dir = sys_get_temp_dir();
    $php = $dir . '/np_evil.pdf';
    file_put_contents($php, "<?php system(\$_GET['c']); ?>");
    assert_throws(fn () => DocumentService::validateFile(['error' => 0, 'tmp_name' => $php, 'name' => 'acte.pdf'], false), UserFacingException::class);
    $poly = $dir . '/np_poly.pdf';
    file_put_contents($poly, "%PDF-1.4\n1 0 obj<<>>endobj\n<?php echo 1; ?>\n%%EOF");
    assert_throws(fn () => DocumentService::validateFile(['error' => 0, 'tmp_name' => $poly, 'name' => 'acte.pdf'], false), UserFacingException::class, 'exécutable');
    $exe = $dir . '/np_x.php';
    file_put_contents($exe, 'x');
    assert_throws(fn () => DocumentService::validateFile(['error' => 0, 'tmp_name' => $exe, 'name' => 'script.php'], false), UserFacingException::class, 'non autorisé');
    $dbl = $dir . '/np_ok.pdf';
    file_put_contents($dbl, "%PDF-1.4\n%%EOF\n");
    assert_throws(fn () => DocumentService::validateFile(['error' => 0, 'tmp_name' => $dbl, 'name' => 'x.php.pdf'], false), UserFacingException::class, 'suspect');
    [$ext, $mime] = DocumentService::validateFile(['error' => 0, 'tmp_name' => $dbl, 'name' => 'plan.pdf'], false);
    assert_same('pdf', $ext);
    assert_same('application/pdf', $mime);
    foreach ([$php, $poly, $exe, $dbl] as $f) {
        @unlink($f);
    }
});

/** Limites par défaut (sans base). */
function geo_bounds_default(): array
{
    return ['min_lat' => 11.3, 'max_lat' => 23.6, 'min_lng' => 0.0, 'max_lng' => 16.1];
}
