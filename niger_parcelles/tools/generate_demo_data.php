<?php
/**
 * Génère database/demo_data.sql : données de DÉMONSTRATION FICTIVES.
 * Usage (développeurs) : php tools/generate_demo_data.php
 *
 * Toutes les lignes portent is_demo = 1 et des libellés explicites
 * « [DÉMO - FICTIF] ». Aucun nom, lot ou titre réel n'est utilisé.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/includes/lib/Geo.php';

const YEAR = 2026;
$out = [];
$out[] = "-- =============================================================================";
$out[] = "-- Niger Parcelles - DONNÉES DE DÉMONSTRATION FICTIVES";
$out[] = "-- Générées par tools/generate_demo_data.php. NE PAS utiliser comme données réelles.";
$out[] = "-- Les parcelles sont des rectangles dessinés arbitrairement près de Niamey ;";
$out[] = "-- les propriétaires sont des personnes inventées. Tout est marqué is_demo = 1.";
$out[] = "-- À importer APRÈS schema.sql.";
$out[] = "-- =============================================================================";
$out[] = "SET NAMES utf8mb4;";
$out[] = "";

$esc = static fn (?string $s) => $s === null ? 'NULL' : "'" . str_replace(["\\", "'"], ["\\\\", "''"], $s) . "'";

// Propriétaires fictifs
$owners = [
    ['personne_physique', 'Exemple', 'DÉMO-ALPHA', null, '+227 00 00 00 01', 'declare'],
    ['personne_physique', 'Exemple', 'DÉMO-BETA', null, '+227 00 00 00 02', 'documente'],
    ['personne_physique', 'Exemple', 'DÉMO-GAMMA', null, '+227 00 00 00 03', 'verifie'],
    ['personne_morale', null, null, 'Société fictive DÉMO SARL', '+227 00 00 00 04', 'documente'],
    ['groupement', null, null, 'Famille fictive DÉMO', null, 'conteste'],
];
$out[] = "INSERT INTO owners (id, owner_type, first_name, last_name, organization_name, phone, address, file_reference, info_status, notes, is_demo) VALUES";
$rows = [];
foreach ($owners as $i => $o) {
    $id = 9001 + $i;
    $rows[] = sprintf("(%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, 1)", $id, $esc($o[0]), $esc($o[1]), $esc($o[2]), $esc($o[3]), $esc($o[4]),
        $esc('[DÉMO - FICTIF] Adresse inventée'), $esc('DEMO-DOS-' . ($i + 1)), $esc($o[5]), $esc('[DÉMO - FICTIF] Propriétaire inventé pour la démonstration.'));
}
$out[] = implode(",\n", $rows) . ";";
$out[] = "";

// Parcelles : grille de lots contigus de ~20 m x 30 m + une parcelle chevauchante
$originLng = 2.1550;
$originLat = 13.4800;
$dLng = 20 / (111320 * cos(deg2rad($originLat)));
$dLat = 30 / 110574;
$cats = ['residentiel', 'residentiel', 'commercial', 'terrain_nu', 'en_construction', 'residentiel', 'agricole', 'administratif', 'residentiel', 'terrain_nu'];
$statuses = ['verifiee', 'soumise', 'en_verification', 'brouillon', 'verifiee', 'anomalie', 'brouillon', 'verifiee', 'soumise', 'brouillon'];
$surveys = ['verifiee_autorite', 'releve_terrain', 'documentee', 'indicative', 'documentee', 'releve_terrain', 'indicative', 'verifiee_autorite', 'releve_terrain', 'indicative'];
$parcels = [];
for ($i = 0; $i < 10; $i++) {
    $c = $i % 5;
    $r = intdiv($i, 5);
    $x0 = $originLng + $c * $dLng;
    $y0 = $originLat + $r * $dLat;
    $parcels[] = [[[$x0, $y0], [$x0 + $dLng, $y0], [$x0 + $dLng, $y0 + $dLat], [$x0, $y0 + $dLat], [$x0, $y0]]];
}
// Parcelle 11 : chevauche les lots 2 et 3 (pour illustrer la détection)
$x0 = $originLng + 1.5 * $dLng;
$y0 = $originLat + 0.3 * $dLat;
$parcels[] = [[[$x0, $y0], [$x0 + $dLng, $y0], [$x0 + $dLng, $y0 + 0.5 * $dLat], [$x0, $y0 + 0.5 * $dLat], [$x0, $y0]]];
$cats[] = 'commercial';
$statuses[] = 'anomalie';
$surveys[] = 'indicative';

$pRows = [];
$gRows = [];
$hRows = [];
foreach ($parcels as $i => $coords) {
    $id = 9001 + $i;
    $rings = Geo::normalizePolygon(['type' => 'Polygon', 'coordinates' => $coords]);
    $m = Geo::measure($rings);
    $ref = sprintf('NIG-NIA-%d-%06d', YEAR, $i + 1);
    $overlap = $i === 10 || $i === 1 || $i === 2 ? 1 : 0;
    $created = sprintf('%d-%02d-%02d 09:%02d:00', YEAR, 1 + ($i % 9), 3 + $i, 10 + $i);
    $pRows[] = sprintf("(%d, %s, NULL, 6, %s, %s, %.2f, %.2f, %.7f, %.7f, %.7f, %.7f, %.7f, %.7f, 1, %s, %s, %s, %d, %s, 1, %s)",
        $id, $esc($ref), $esc('[DÉMO - FICTIF] Lot de démonstration n° ' . ($i + 1)), $esc($cats[$i]), $m['area_m2'], $m['perimeter_m'],
        $m['centroid_lat'], $m['centroid_lng'], $m['min_lat'], $m['max_lat'], $m['min_lng'], $m['max_lng'],
        $esc($surveys[$i]), $esc($i % 3 === 0 ? 'attestation_coutumiere' : 'inconnu'), $esc($statuses[$i]), $overlap,
        $esc('[DÉMONSTRATION - données fictives] Cette parcelle n\'existe pas.'), $esc($created));
    $gRows[] = sprintf("(%d, %s, 1, %d, %.2f, %.2f, 'dessin', 'EPSG:4326', 'Création (démonstration)', %s)",
        $id, $esc(json_encode(Geo::toGeoJson($rings))), $m['vertex_count'], $m['area_m2'], $m['perimeter_m'], $esc($created));
    $hRows[] = sprintf("(%d, NULL, 'creation', NULL, %s, %s)", $id, $esc(json_encode(['parcel_reference' => $ref, 'demo' => true], JSON_UNESCAPED_UNICODE)), $esc($created));
}
$out[] = "INSERT INTO parcels (id, parcel_reference, cadastral_reference, administrative_area_id, locality_description, category, area_m2, perimeter_m,
  centroid_lat, centroid_lng, min_lat, max_lat, min_lng, max_lng, current_geometry_version, survey_level, declared_status, verification_status,
  has_overlap_warning, description, is_demo, created_at) VALUES";
$out[] = implode(",\n", $pRows) . ";";
$out[] = "";
$out[] = "INSERT INTO parcel_geometries (parcel_id, geojson, geometry_version, vertex_count, area_m2, perimeter_m, source, source_crs, change_reason, created_at) VALUES";
$out[] = implode(",\n", $gRows) . ";";
$out[] = "";
$out[] = "INSERT INTO parcel_history (parcel_id, user_id, action_type, previous_data, new_data, created_at) VALUES";
$out[] = implode(",\n", $hRows) . ";";
$out[] = "";
$out[] = "INSERT INTO parcel_overlaps (parcel_a_id, parcel_b_id, overlap_type, status) VALUES (9002, 9011, 'chevauchement', 'a_verifier'), (9003, 9011, 'chevauchement', 'a_verifier');";
$out[] = "";
$out[] = "-- Liens propriétaires (dont une copropriété 60/40 et une mutation historique)";
$out[] = "INSERT INTO mutations (id, parcel_id, mutation_type, mutation_date, document_reference, notes) VALUES
(9001, 9001, 'vente', '" . YEAR . "-02-15', 'DEMO-ACTE-0001', '[DÉMO - FICTIF] Vente fictive pour illustrer l''historique.');";
$out[] = "INSERT INTO parcel_owners (parcel_id, owner_id, ownership_role, share_percentage, valid_from, valid_to, verification_status, document_reference, mutation_in_id, mutation_out_id) VALUES
(9001, 9001, 'proprietaire', NULL, '" . (YEAR - 3) . "-05-01', '" . YEAR . "-02-15', 'documente', 'DEMO-ATT-0001', NULL, 9001),
(9001, 9003, 'proprietaire', NULL, '" . YEAR . "-02-15', NULL, 'verifie', 'DEMO-ACTE-0001', 9001, NULL),
(9002, 9001, 'coproprietaire', 60.000, '" . (YEAR - 1) . "-01-10', NULL, 'declare', NULL, NULL, NULL),
(9002, 9002, 'coproprietaire', 40.000, '" . (YEAR - 1) . "-01-10', NULL, 'documente', 'DEMO-ACTE-0002', NULL, NULL),
(9003, 9004, 'proprietaire', NULL, '" . (YEAR - 2) . "-03-20', NULL, 'documente', 'DEMO-CONT-0003', NULL, NULL),
(9005, 9002, 'proprietaire', NULL, NULL, NULL, 'declare', NULL, NULL, NULL),
(9006, 9005, 'ayant_droit', NULL, NULL, NULL, 'conteste', NULL, NULL, NULL),
(9008, 9003, 'proprietaire', NULL, '" . (YEAR - 5) . "-07-01', NULL, 'verifie', 'DEMO-TF-0008', NULL, NULL);";
$out[] = "";
$out[] = "INSERT INTO parcel_reference_sequences (year, seq_value) VALUES (" . YEAR . ", " . count($parcels) . ")
  ON DUPLICATE KEY UPDATE seq_value = GREATEST(seq_value, VALUES(seq_value));";
$out[] = "";

file_put_contents(dirname(__DIR__) . '/database/demo_data.sql', implode("\n", $out) . "\n");
echo "database/demo_data.sql généré (" . count($parcels) . " parcelles fictives).\n";
