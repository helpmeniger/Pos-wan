<?php
/**
 * Tests d'intégration sur une base MySQL de test dédiée (recréée) :
 * schéma, parcelles, références, géométries, historique, chevauchements,
 * propriétaires, mutations, documents, importation, exports, sauvegarde.
 */
declare(strict_types=1);

if (!defined('NP_TESTS_LOADED')) {
    define('NP_TESTS_LOADED', true);
    require __DIR__ . '/bootstrap.php';
}

suite('Base de données');

test('Connexion MySQL et création des tables depuis schema.sql', function () {
    reset_test_database();
    $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['users', 'roles', 'owners', 'parcels', 'parcel_geometries', 'parcel_owners', 'documents', 'parcel_history',
              'administrative_areas', 'audit_logs', 'settings', 'mutations', 'parcel_overlaps', 'import_batches', 'login_attempts'] as $t) {
        assert_true(in_array($t, $tables, true), "table $t");
    }
    assert_same(5, (int) db_value('SELECT COUNT(*) FROM roles'));
    assert_same('NIA', db_value("SELECT code FROM administrative_areas WHERE name = 'Niamey' AND area_type = 'region'"));
    assert_same('utf8mb4', db_value("SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'owners' AND COLUMN_NAME = 'last_name'"));
    assert_same('InnoDB', db_value("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parcels'"));
});

test('Données de démonstration importables et marquées fictives', function () {
    SqlRunner::runFile(db(), APP_ROOT . '/database/demo_data.sql');
    assert_same(11, (int) db_value('SELECT COUNT(*) FROM parcels WHERE is_demo = 1'));
    assert_same(0, (int) db_value('SELECT COUNT(*) FROM parcels WHERE is_demo = 0'));
    $r = BackupService::purgeDemo();
    assert_same(11, $r['parcels']);
    assert_same(0, (int) db_value('SELECT COUNT(*) FROM parcels'));
    assert_same(0, (int) db_value('SELECT COUNT(*) FROM owners'));
});

suite('Parcelles et cartographie');

test('Création d\'une parcelle : référence unique NIG-NIA-AAAA-NNNNNN et géométrie enregistrée', function () {
    $u = as_user('agent_terrain', 'agent1');
    $niamey = (int) db_value("SELECT id FROM administrative_areas WHERE name = 'Niamey I'");
    // La séquence n'est jamais remise à zéro (même après purge des données de démonstration)
    $GLOBALS['seq0'] = (int) db_value('SELECT COALESCE(MAX(seq_value), 0) FROM parcel_reference_sequences WHERE year = ?', [(int) date('Y')]);
    $r = ParcelService::create(['category' => 'residentiel', 'administrative_area_id' => $niamey, 'locality_description' => 'Test'], rect_geom(2.10, 13.50), $u['id']);
    assert_same(sprintf('NIG-NIA-%s-%06d', date('Y'), $GLOBALS['seq0'] + 1), $r['parcel_reference']);
    assert_near(600, $r['area_m2'], 5);
    $p = ParcelService::get($r['id']);
    assert_same('brouillon', $p['verification_status']);
    assert_same(1, (int) $p['current_geometry_version']);
    $GLOBALS['p1'] = $r['id'];
});

test('Rechargement de la géométrie depuis MySQL (GeoJSON identique)', function () {
    $g = ParcelService::currentGeometry($GLOBALS['p1']);
    assert_same('Polygon', $g['type']);
    $expected = Geo::normalizePolygon(rect_geom(2.10, 13.50));
    assert_same($expected, $g['coordinates']);
});

test('Références : séquence sans réattribution, préfixe absent si zone sans code', function () {
    $u = $GLOBALS['np_cli_user'];
    $r2 = ParcelService::create(['category' => 'agricole'], rect_geom(2.20, 13.60), $u['id']);
    $n = $GLOBALS['seq0'];
    assert_same(sprintf('NIG-%s-%06d', date('Y'), $n + 2), $r2['parcel_reference'], 'sans code de zone : pas de préfixe');
    $dosso = (int) db_value("SELECT id FROM administrative_areas WHERE code = 'DOS'");
    $r3 = ParcelService::create(['category' => 'agricole', 'administrative_area_id' => $dosso], rect_geom(3.20, 13.05), $u['id']);
    assert_same(sprintf('NIG-DOS-%s-%06d', date('Y'), $n + 3), $r3['parcel_reference']);
    assert_throws(fn () => db_query('INSERT INTO parcels (parcel_reference, area_m2, perimeter_m, centroid_lat, centroid_lng, min_lat, max_lat, min_lng, max_lng)
        VALUES (?, 1, 1, 13, 2, 13, 13, 2, 2)', [$r3['parcel_reference']]), PDOException::class);
    ParcelService::archive($r2['id'], $u['id'], 'test');
    $r4 = ParcelService::create(['category' => 'agricole'], rect_geom(2.30, 13.70), $u['id']);
    assert_same(sprintf('NIG-%s-%06d', date('Y'), $n + 4), $r4['parcel_reference'], 'une référence archivée n\'est jamais réutilisée');
    $GLOBALS['p4'] = $r4['id'];
});

test('Géométrie invalide refusée côté serveur (auto-intersection, hors Niger)', function () {
    $u = $GLOBALS['np_cli_user'];
    $bow = ['type' => 'Polygon', 'coordinates' => [[[2.1, 13.5], [2.101, 13.501], [2.101, 13.5], [2.1, 13.501], [2.1, 13.5]]]];
    assert_throws(fn () => ParcelService::create(['category' => 'autre'], $bow, $u['id']), UserFacingException::class, 'croisent');
    assert_throws(fn () => ParcelService::create(['category' => 'autre'], rect_geom(2.35, 48.85), $u['id']), UserFacingException::class, 'dehors');
    assert_throws(fn () => ParcelService::create(['category' => 'piscine'], rect_geom(2.1, 13.5), $u['id']), UserFacingException::class, 'non autorisée');
});

test('Chevauchement : avertissement (409) puis enregistrement confirmé et signalé', function () {
    $u = $GLOBALS['np_cli_user'];
    $g = rect_geom(2.10 + 0.00005, 13.50 + 0.00005);
    try {
        ParcelService::create(['category' => 'commercial'], $g, $u['id']);
        fail('Avertissement attendu');
    } catch (UserFacingException $e) {
        assert_same(409, $e->getCode());
        assert_contains('Chevauchement', implode(' ', $e->data['warnings']));
    }
    $r = ParcelService::create(['category' => 'commercial'], $g, $u['id'], true);
    assert_same(1, count($r['overlaps']));
    assert_same(1, (int) db_value('SELECT has_overlap_warning FROM parcels WHERE id = ?', [$GLOBALS['p1']]));
    assert_same(1, (int) db_value("SELECT COUNT(*) FROM parcel_overlaps WHERE status = 'a_verifier'"));
    $GLOBALS['p_overlap'] = $r['id'];
});

test('Parcelles contiguës (limite commune) : pas de faux chevauchement', function () {
    $u = $GLOBALS['np_cli_user'];
    $base = rect_geom(2.15, 13.55);
    ParcelService::create(['category' => 'residentiel'], $base, $u['id']);
    $bb = Geo::bbox(Geo::normalizePolygon($base));
    // Partage exact du côté nord, puis du côté est
    $north = ['type' => 'Polygon', 'coordinates' => [[[$bb['min_lng'], $bb['max_lat']], [$bb['max_lng'], $bb['max_lat']], [$bb['max_lng'], $bb['max_lat'] + 0.0002],
        [$bb['min_lng'], $bb['max_lat'] + 0.0002], [$bb['min_lng'], $bb['max_lat']]]]];
    assert_same([], ParcelService::create(['category' => 'residentiel'], $north, $u['id'])['overlaps']);
    $east = ['type' => 'Polygon', 'coordinates' => [[[$bb['max_lng'], $bb['min_lat']], [$bb['max_lng'] + 0.0002, $bb['min_lat']], [$bb['max_lng'] + 0.0002, $bb['max_lat']],
        [$bb['max_lng'], $bb['max_lat']], [$bb['max_lng'], $bb['min_lat']]]]];
    assert_same([], ParcelService::create(['category' => 'residentiel'], $east, $u['id'])['overlaps']);
});

test('Modification des limites : nouvelle version, ancienne conservée, chevauchement corrigé', function () {
    $u = $GLOBALS['np_cli_user'];
    $res = ParcelService::updateGeometry($GLOBALS['p_overlap'], rect_geom(2.11, 13.51), $u['id'], 'Relevé GPS');
    assert_same(2, $res['version']);
    $versions = ParcelService::geometryVersions($GLOBALS['p_overlap']);
    assert_same(2, count($versions));
    assert_same('Relevé GPS', $versions[0]['change_reason']);
    assert_same(0, (int) db_value("SELECT COUNT(*) FROM parcel_overlaps WHERE status = 'a_verifier'"));
    assert_same(0, (int) db_value('SELECT has_overlap_warning FROM parcels WHERE id = ?', [$GLOBALS['p1']]));
    assert_same('corrige', db_value('SELECT status FROM parcel_overlaps LIMIT 1'));
});

test('Doublon probable détecté (géométrie identique)', function () {
    $overlaps = ParcelService::findOverlaps(Geo::normalizePolygon(rect_geom(2.10, 13.50)), null);
    assert_same('doublon_probable', $overlaps[0]['type']);
});

test('Historique : création, géométrie, statut', function () {
    $actions = array_column(ParcelService::historyEntries($GLOBALS['p_overlap']), 'action_type');
    assert_true(in_array('creation', $actions, true));
    assert_true(in_array('geometrie', $actions, true));
    assert_true(in_array('chevauchement', $actions, true));
    $h = db_one("SELECT * FROM parcel_history WHERE parcel_id = ? AND action_type = 'geometrie'", [$GLOBALS['p_overlap']]);
    assert_same(1, json_decode($h['previous_data'], true)['version']);
    assert_true(isset(json_decode($h['previous_data'], true)['geometrie']['coordinates']), 'ancienne géométrie dans l\'historique');
});

test('Circuit de vérification : transitions contrôlées par rôle', function () {
    $agent = $GLOBALS['np_cli_user'];
    ParcelService::changeStatus($GLOBALS['p1'], 'soumise', $agent['id'], 'Dossier complet');
    assert_throws(fn () => ParcelService::changeStatus($GLOBALS['p1'], 'verifiee', $agent['id']), UserFacingException::class, 'pas autorisé');
    $p = ParcelService::get($GLOBALS['p1']);
    assert_false(can_edit_parcel($p), 'un agent ne modifie plus une parcelle soumise');
    $verif = as_user('verificateur');
    ParcelService::changeStatus($GLOBALS['p1'], 'verifiee', $verif['id']);
    $p = ParcelService::get($GLOBALS['p1']);
    assert_same('verifiee', $p['verification_status']);
    assert_same($verif['id'], (int) $p['verified_by']);
    $GLOBALS['np_cli_user'] = $agent;
});

test('Un agent ne peut pas modifier la parcelle d\'un autre agent', function () {
    $other = as_user('agent_terrain');
    assert_false(can_edit_parcel(ParcelService::get($GLOBALS['p4'])));
    as_user('admin_foncier');
    assert_true(can_edit_parcel(ParcelService::get($GLOBALS['p4'])));
    unset($other);
});

suite('Propriétaires et mutations');

test('Création d\'un propriétaire, validation et doublons potentiels', function () {
    $u = as_user('admin_foncier');
    $o1 = OwnerService::create(['owner_type' => 'personne_physique', 'last_name' => 'TESTNOM', 'first_name' => 'Prénom', 'phone' => '+227 90 00 00 01'], $u['id']);
    assert_true($o1 > 0);
    assert_throws(fn () => OwnerService::create(['owner_type' => 'personne_physique', 'first_name' => 'Sans nom'], $u['id']), UserFacingException::class, 'obligatoire');
    assert_throws(fn () => OwnerService::create(['owner_type' => 'personne_morale'], $u['id']), UserFacingException::class, 'dénomination');
    assert_throws(fn () => OwnerService::create(['last_name' => 'X', 'phone' => '<script>'], $u['id']), UserFacingException::class, 'téléphone');
    $dups = OwnerService::findDuplicates(['phone' => '90000001']);
    assert_same($o1, (int) $dups[0]['id']);
    $o2 = OwnerService::create(['owner_type' => 'personne_morale', 'organization_name' => 'Société Test'], $u['id']);
    $GLOBALS['o1'] = $o1;
    $GLOBALS['o2'] = $o2;
});

test('Un propriétaire associé à plusieurs parcelles ; copropriété avec quotes-parts', function () {
    $u = $GLOBALS['np_cli_user'];
    OwnerService::link($GLOBALS['p1'], $GLOBALS['o1'], ['ownership_role' => 'coproprietaire', 'share_percentage' => '60'], $u['id']);
    OwnerService::link($GLOBALS['p4'], $GLOBALS['o1'], ['ownership_role' => 'proprietaire'], $u['id']);
    assert_same(2, count(OwnerService::parcelsOf($GLOBALS['o1'])));
    OwnerService::link($GLOBALS['p1'], $GLOBALS['o2'], ['ownership_role' => 'coproprietaire', 'share_percentage' => '40'], $u['id']);
    $o3 = OwnerService::create(['last_name' => 'TROIS'], $u['id']);
    assert_throws(fn () => OwnerService::link($GLOBALS['p1'], $o3, ['share_percentage' => '5'], $u['id']), UserFacingException::class, '100');
    assert_throws(fn () => OwnerService::link($GLOBALS['p1'], $GLOBALS['o1'], [], $u['id']), UserFacingException::class, 'déjà associé');
    assert_same(2, count(OwnerService::currentOwners($GLOBALS['p1'])));
    $GLOBALS['o3'] = $o3;
});

test('Mutation : anciens liens clos (historique conservé), nouveaux liens créés', function () {
    $u = $GLOBALS['np_cli_user'];
    $mid = OwnerService::recordMutation($GLOBALS['p1'], ['mutation_type' => 'vente', 'mutation_date' => date('Y-m-d'), 'document_reference' => 'ACTE-TEST-1'],
        [['owner_id' => $GLOBALS['o3'], 'ownership_role' => 'proprietaire', 'verification_status' => 'documente']], null, $u['id']);
    assert_true($mid > 0);
    $current = OwnerService::currentOwners($GLOBALS['p1']);
    assert_same(1, count($current));
    assert_same($GLOBALS['o3'], (int) $current[0]['owner_id']);
    $past = OwnerService::pastOwners($GLOBALS['p1']);
    assert_same(2, count($past));
    assert_same($mid, (int) $past[0]['mutation_out_id']);
    assert_same(1, count(OwnerService::mutations($GLOBALS['p1'])));
    $actions = array_column(ParcelService::historyEntries($GLOBALS['p1']), 'action_type');
    assert_true(in_array('mutation', $actions, true) && in_array('proprietaire_fin', $actions, true));
    assert_throws(fn () => OwnerService::recordMutation($GLOBALS['p1'], ['mutation_type' => 'vente', 'mutation_date' => '2999-01-01'], [['owner_id' => 1]], null, $u['id']),
        UserFacingException::class, 'futur');
});

test('Archivage d\'un propriétaire sans perte d\'historique', function () {
    OwnerService::setArchived($GLOBALS['o2'], true);
    assert_same(1, (int) db_value('SELECT is_archived FROM owners WHERE id = ?', [$GLOBALS['o2']]));
    assert_true(count(OwnerService::parcelsOf($GLOBALS['o2'])) === 1, 'lien historique conservé');
    assert_throws(fn () => OwnerService::link($GLOBALS['p4'], $GLOBALS['o2'], [], $GLOBALS['np_cli_user']['id']), UserFacingException::class, 'archivé');
});

suite('Documents');

test('Stockage privé sous nom aléatoire, historique, refus des fichiers dangereux', function () {
    $u = $GLOBALS['np_cli_user'];
    $tmp = tempnam(sys_get_temp_dir(), 'doc');
    file_put_contents($tmp, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n");
    $id = DocumentService::store(['error' => 0, 'tmp_name' => $tmp, 'name' => 'Acte de vente.pdf', 'size' => filesize($tmp)],
        ['parcel_id' => $GLOBALS['p1'], 'document_type' => 'acte', 'title' => 'Acte'], $u['id'], false);
    $d = DocumentService::get($id);
    assert_true((bool) preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.bin$#', $d['storage_filename']), 'nom interne : ' . $d['storage_filename']);
    assert_not_contains('Acte', $d['storage_filename']);
    assert_true(is_file(storage_path('documents/' . $d['storage_filename'])));
    assert_same(hash_file('sha256', $tmp), $d['sha256']);
    assert_true(in_array('document_ajout', array_column(ParcelService::historyEntries($GLOBALS['p1']), 'action_type'), true));
    DocumentService::setStatus($id, 'verifie', $u['id']);
    assert_same('verifie', db_value('SELECT verification_status FROM documents WHERE id = ?', [$id]));
    file_put_contents($tmp, '<?php echo "pirate"; ?>');
    assert_throws(fn () => DocumentService::store(['error' => 0, 'tmp_name' => $tmp, 'name' => 'photo.jpg'], ['parcel_id' => $GLOBALS['p1']], $u['id'], false), UserFacingException::class);
    assert_throws(fn () => DocumentService::store(['error' => 0, 'tmp_name' => $tmp, 'name' => 'x.pdf'], [], $u['id'], false), UserFacingException::class);
    @unlink($tmp);
});

suite('Recherche, injection SQL et permissions');

test('Recherche par référence, propriétaire (autorisé), superficie, coordonnées GPS', function () {
    as_user('admin_foncier');
    $ref = db_value('SELECT parcel_reference FROM parcels WHERE id = ?', [$GLOBALS['p1']]);
    $r = ParcelQuery::search(ParcelQuery::filtersFrom(['q' => $ref]), 10, 0);
    assert_same(1, $r['total']);
    $r = ParcelQuery::search(ParcelQuery::filtersFrom(['q' => 'TROIS']), 10, 0);
    assert_same(1, $r['total'], 'recherche par nom de propriétaire');
    $c = db_one('SELECT centroid_lat, centroid_lng FROM parcels WHERE id = ?', [$GLOBALS['p1']]);
    $r = ParcelQuery::search(ParcelQuery::filtersFrom(['lat' => $c['centroid_lat'], 'lng' => $c['centroid_lng']]), 10, 0);
    assert_same((int) $GLOBALS['p1'], (int) $r['rows'][0]['id']);
    $r = ParcelQuery::search(ParcelQuery::filtersFrom(['min_area' => '100000']), 10, 0);
    assert_same(0, $r['total']);
});

test('Profil « consultation » : pas de recherche par nom de propriétaire', function () {
    as_user('consultation');
    $r = ParcelQuery::search(ParcelQuery::filtersFrom(['q' => 'TROIS']), 10, 0);
    assert_same(0, $r['total']);
    assert_false(can('owner.view_personal'));
});

test('Tentatives d\'injection SQL neutralisées (requêtes préparées)', function () {
    as_user('admin_foncier');
    $before = (int) db_value('SELECT COUNT(*) FROM parcels');
    foreach (["' OR '1'='1", "'; DROP TABLE parcels; --", '1 UNION SELECT password_hash FROM users', "%' OR 1=1 #"] as $evil) {
        $r = ParcelQuery::search(ParcelQuery::filtersFrom(['q' => $evil, 'category' => $evil, 'status' => $evil, 'area_id' => $evil, 'sort' => $evil]), 10, 0, $evil, $evil);
        assert_same(0, $r['total'], "injection : $evil");
    }
    assert_same($before, (int) db_value('SELECT COUNT(*) FROM parcels'), 'table intacte');
    assert_throws(fn () => ParcelService::sanitizeAttributes(['administrative_area_id' => '1 OR 1=1']), UserFacingException::class);
});

suite('Importation et exportation');

test('Import GeoJSON (WGS84) : analyse, aperçu, confirmation transactionnelle', function () {
    $u = as_user('admin_foncier');
    $fc = ['type' => 'FeatureCollection', 'features' => [
        ['type' => 'Feature', 'properties' => ['name' => 'Lot 1', 'ref_cadastrale' => 'CAD-IMP-1', 'categorie' => 'Résidentiel'], 'geometry' => rect_geom(2.40, 13.40)],
        ['type' => 'Feature', 'properties' => ['name' => 'Lot 2'], 'geometry' => rect_geom(2.40 + 0.0005, 13.40)],
        ['type' => 'Feature', 'properties' => ['name' => 'Invalide'], 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[2.4, 13.4], [2.401, 13.401], [2.401, 13.4], [2.4, 13.401], [2.4, 13.4]]]]],
        ['type' => 'Feature', 'properties' => ['name' => 'Copie'], 'geometry' => rect_geom(2.40, 13.40)],
    ]];
    $f = storage_path('imports/test.geojson');
    file_put_contents($f, json_encode($fc));
    $state = ImportService::analyze($f, 'geojson', 'test.geojson', 'auto', $u['id']);
    assert_same('EPSG:4326', $state['crs']);
    assert_same(4, $state['summary']['total']);
    assert_same(1, $state['summary']['errors']);
    assert_same('residentiel', $state['items'][0]['attributes']['category']);
    assert_contains('Doublon', implode(' ', $state['items'][3]['warnings']));
    $before = (int) db_value('SELECT COUNT(*) FROM parcels');
    $res = ImportService::commit($state['token'], ['survey_level' => 'releve_terrain'], $u['id']);
    assert_same('termine', $res['status']);
    assert_same(1, count($res['imported']), 'seule l\'entité sans avertissement est importée');
    assert_same('Lot 2', $res['imported'][0]['label']);
    assert_same(2, count($res['skipped']), 'doublons écartés sans confirmation');
    assert_same(1, count($res['errors']));
    assert_same($before + 1, (int) db_value('SELECT COUNT(*) FROM parcels'));
    assert_same('CAD-IMP-1', $state['items'][0]['attributes']['cadastral_reference'], 'colonne ref_cadastrale reconnue');
    assert_same('import', db_value('SELECT source FROM parcel_geometries WHERE parcel_id = ?', [$res['imported'][0]['id']]));
    assert_same(1, (int) db_value('SELECT COUNT(*) FROM import_batches'));
});

test('Import CSV de sommets en UTM 31N (système choisi par l\'utilisateur)', function () {
    $u = $GLOBALS['np_cli_user'];
    [$x, $y] = Crs::wgs84ToUtm(2.50, 13.45, 31);
    $csv = "identifiant;x;y;ordre;quartier\n";
    foreach ([[0, 0], [25, 0], [25, 40], [0, 40]] as $i => [$dx, $dy]) {
        $csv .= sprintf("A1;%.3f;%.3f;%d;Quartier test\n", $x + $dx, $y + $dy, $i + 1);
    }
    $f = storage_path('imports/test.csv');
    file_put_contents($f, $csv);
    assert_throws(fn () => ImportService::analyze($f, 'csv', 'test.csv', 'auto', $u['id']), UserFacingException::class, 'système de coordonnées');
    file_put_contents($f, $csv);
    $state = ImportService::analyze($f, 'csv', 'test.csv', 'EPSG:32631', $u['id']);
    assert_same(1, $state['summary']['valid']);
    assert_near(1000, $state['items'][0]['measure']['area_m2'], 5, 'lot 25 × 40 m');
    assert_same('Quartier test', $state['items'][0]['attributes']['locality_description']);
    $res = ImportService::commit($state['token'], [], $u['id']);
    assert_same(1, count($res['imported']));
});

test('Import KML (Google Earth) et Shapefile ZIP', function () {
    $u = $GLOBALS['np_cli_user'];
    $kml = '<?xml version="1.0"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document><Placemark><name>Champ KML</name>'
        . '<ExtendedData><Data name="lot"><value>KML-7</value></Data></ExtendedData><Polygon><outerBoundaryIs><LinearRing><coordinates>'
        . '2.60,13.30,0 2.601,13.30,0 2.601,13.301,0 2.60,13.301,0 2.60,13.30,0</coordinates></LinearRing></outerBoundaryIs></Polygon></Placemark></Document></kml>';
    $f = storage_path('imports/test.kml');
    file_put_contents($f, $kml);
    $state = ImportService::analyze($f, 'kml', 'test.kml', 'auto', $u['id']);
    assert_same(1, $state['summary']['valid']);
    assert_same('KML-7', $state['items'][0]['attributes']['cadastral_reference']);
    $xxe = storage_path('imports/xxe.kml');
    file_put_contents($xxe, '<?xml version="1.0"?><!DOCTYPE k [<!ENTITY x SYSTEM "file:///etc/passwd">]><kml>&x;</kml>');
    assert_throws(fn () => ImportService::analyze($xxe, 'kml', 'x.kml', 'auto', $u['id']), UserFacingException::class, 'DOCTYPE');

    require_once __DIR__ . '/unit_test_helpers.php';
    $zip = storage_path('imports/test.zip');
    [$x, $y] = Crs::wgs84ToUtm(2.70, 13.20, 31);
    np_make_shapefile_zip($zip, [[[$x, $y], [$x, $y + 30], [$x + 20, $y + 30], [$x + 20, $y], [$x, $y]]], [['Lot SHP', 'SHP-1']],
        'PROJCS["WGS_1984_UTM_Zone_31N",GEOGCS["GCS_WGS_1984"]]');
    $state = ImportService::analyze($zip, 'zip', 'test.zip', 'auto', $u['id']);
    assert_same('EPSG:32631', $state['crs']);
    assert_same(1, $state['summary']['valid']);
    $res = ImportService::commit($state['token'], [], $u['id']);
    assert_same(1, count($res['imported']));
});

test('Export GeoJSON : FeatureCollection WGS84 rechargeable', function () {
    $rows = db_all('SELECT p.parcel_reference, g.geojson FROM parcels p JOIN parcel_geometries g ON g.parcel_id = p.id AND g.geometry_version = p.current_geometry_version');
    $fc = ['type' => 'FeatureCollection', 'features' => array_map(static fn ($r) => ['type' => 'Feature', 'geometry' => json_decode($r['geojson'], true),
        'properties' => ['reference' => $r['parcel_reference']]], $rows)];
    $f = storage_path('imports/export.geojson');
    file_put_contents($f, json_encode($fc));
    $state = ImportService::analyze($f, 'geojson', 'export.geojson', 'auto', $GLOBALS['np_cli_user']['id']);
    assert_same(count($rows), $state['summary']['total']);
    assert_same(0, $state['summary']['errors'], 'toutes les géométries exportées sont valides');
    @unlink(storage_path('imports/' . $state['token'] . '.json'));
});

test('Fiche PDF, rapport et statistiques calculés depuis la base', function () {
    as_user('admin_general');
    $pdf = PdfService::parcelSheet($GLOBALS['p1']);
    assert_same('%PDF-', substr($pdf, 0, 5));
    $stats = StatsService::compute([]);
    assert_same((int) db_value('SELECT COUNT(*) FROM parcels WHERE is_archived = 0'), $stats['kpi']['total']);
    assert_same(1, $stats['kpi']['verified']);
    foreach (array_keys(ReportService::REPORTS) as $k) {
        $r = ReportService::run($k, []);
        assert_true(is_array($r['rows']), $k);
    }
    $mut = ReportService::run('mutations', []);
    assert_same(1, count($mut['rows']));
});

suite('Sauvegarde et restauration');

test('Sauvegarde SQL puis restauration : données identiques', function () {
    as_user('admin_general');
    $count = (int) db_value('SELECT COUNT(*) FROM parcels');
    $hist = (int) db_value('SELECT COUNT(*) FROM parcel_history');
    $name = BackupService::createSql();
    $path = BackupService::path($name);
    db()->exec('DELETE FROM parcel_overlaps');
    db()->exec('SET FOREIGN_KEY_CHECKS = 0');
    db()->exec('DELETE FROM parcel_history');
    db()->exec('SET FOREIGN_KEY_CHECKS = 1');
    $r = BackupService::restore($path);
    assert_true($r['statements'] > 10);
    assert_same($count, (int) db_value('SELECT COUNT(*) FROM parcels'));
    assert_same($hist, (int) db_value('SELECT COUNT(*) FROM parcel_history'));
    assert_throws(fn () => BackupService::path('../../config/database.php'), UserFacingException::class);
});
