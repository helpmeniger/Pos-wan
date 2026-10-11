<?php
declare(strict_types=1);

/**
 * Chargement des DONNÉES FICTIVES de démonstration.
 * Interdit lorsque app.env = 'production'.
 *
 *   php scripts/seed_demo.php           ajoute les données (si aucune fiche de démo n'existe)
 *   php scripts/seed_demo.php --force   ajoute même si des données de démo existent
 *
 * Toutes les personnes, références et documents sont inventés. Les « portraits »
 * sont des silhouettes synthétiques dessinées par le script (aucun visage réel).
 */
require __DIR__ . '/cli.php';
cli_bootstrap();

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Models\Person;
use App\Models\Procedure;
use App\Models\Visit;
use App\Services\FileStorage;

$args = cli_args();
if (!Config::isDemo()) {
    fail('Mode production : chargement des données fictives refusé.');
    exit(1);
}
if (!isset($args['force']) && (int) Database::value('SELECT COUNT(*) FROM persons WHERE is_demo = 1') > 0) {
    ok('Données de démonstration déjà présentes (utilisez --force pour en ajouter).');
    exit(0);
}

out('Chargement des données FICTIVES de démonstration…');
mt_srand(2026);

/** Silhouette synthétique (aucun visage réel), variable selon la graine. */
function demo_portrait(int $seed, int $w = 480, int $h = 600, bool $small = false): string
{
    mt_srand($seed);
    $im = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($im, mt_rand(170, 225), mt_rand(180, 230), mt_rand(190, 235));
    imagefill($im, 0, 0, $bg);
    $skin = [[92, 58, 38], [128, 84, 56], [160, 112, 78], [196, 150, 112], [70, 44, 30]][mt_rand(0, 4)];
    $sk = imagecolorallocate($im, ...$skin);
    $cloth = imagecolorallocate($im, mt_rand(20, 120), mt_rand(20, 120), mt_rand(40, 160));
    $hair = imagecolorallocate($im, mt_rand(10, 60), mt_rand(10, 40), mt_rand(5, 30));
    $cx = (int) ($w / 2 + mt_rand(-15, 15));
    $headW = (int) ($w * mt_rand(36, 44) / 100);
    $headH = (int) ($headW * mt_rand(122, 138) / 100);
    $headY = (int) ($h * 0.38);
    imagefilledellipse($im, $cx, $h + (int) ($h * 0.05), (int) ($w * 1.05), (int) ($h * 0.75), $cloth);
    imagefilledrectangle($im, $cx - (int) ($headW * 0.18), $headY, $cx + (int) ($headW * 0.18), (int) ($h * 0.72), $sk);
    if (mt_rand(0, 1)) {
        imagefilledellipse($im, $cx, $headY - (int) ($headH * 0.12), (int) ($headW * 1.08), (int) ($headH * 0.85), $hair);
    }
    imagefilledellipse($im, $cx, $headY, $headW, $headH, $sk);
    $dark = imagecolorallocate($im, 30, 30, 35);
    $eyeY = $headY - (int) ($headH * 0.06);
    $eyeDx = (int) ($headW * mt_rand(17, 22) / 100);
    imagefilledellipse($im, $cx - $eyeDx, $eyeY, (int) ($headW * 0.1), (int) ($headW * 0.06), $dark);
    imagefilledellipse($im, $cx + $eyeDx, $eyeY, (int) ($headW * 0.1), (int) ($headW * 0.06), $dark);
    imagesetthickness($im, max(2, (int) ($w / 160)));
    imagearc($im, $cx, $headY + (int) ($headH * 0.2), (int) ($headW * 0.35), (int) ($headH * 0.12), 20, 160, $dark);
    // Mention « FICTIF » incrustée
    $white = imagecolorallocate($im, 255, 255, 255);
    $band = imagecolorallocatealpha($im, 160, 30, 30, 30);
    imagefilledrectangle($im, 0, 0, $w, (int) ($h * 0.07), $band);
    imagestring($im, 5, 10, 8, 'PORTRAIT FICTIF - DEMO', $white);
    ob_start();
    imagejpeg($im, null, 88);
    imagedestroy($im);
    return (string) ob_get_clean();
}

function demo_pdf(string $title): string
{
    $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $title);
    $stream = "BT /F1 16 Tf 60 760 Td (DOCUMENT FICTIF - DEMONSTRATION) Tj 0 -30 Td /F1 12 Tf ($text) Tj ET";
    $objs = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Length ' . strlen($stream) . " >>\nstream\n$stream\nendstream",
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objs as $i => $o) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n$o\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $off) {
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }
    return $pdf . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}

// ---------------------------------------------------------------- Comptes de démonstration
$demoPassword = 'Demo!Dossier2026';
$roles = array_column(Database::all('SELECT id, code FROM roles'), 'id', 'code');
$users = [
    ['admin.demo', 'Administrateur (démo)', 'admin', 'Administration technique', 1],
    ['agent.demo', 'Agent d\'enregistrement (démo)', 'enregistrement', 'Bureau des enregistrements', 1],
    ['consult.demo', 'Agent habilité (démo)', 'consultation', 'Service des enquêtes', 1],
    ['controle.demo', 'Responsable de contrôle (démo)', 'controleur', 'Inspection interne', 1],
    ['inactif.demo', 'Compte désactivé (démo)', 'consultation', 'Service des enquêtes', 0],
];
$uid = [];
foreach ($users as [$u, $name, $role, $service, $active]) {
    $existing = Database::value('SELECT id FROM users WHERE username = :u', ['u' => $u]);
    if ($existing) {
        $uid[$u] = (int) $existing;
        continue;
    }
    $now = date('Y-m-d H:i:s');
    $uid[$u] = Database::insert('users', [
        'username' => $u, 'full_name' => $name, 'service' => $service, 'role_id' => (int) $roles[$role],
        'password_hash' => Auth::hashPassword($demoPassword), 'must_change_password' => 0,
        'is_active' => $active, 'disabled_reason' => $active ? null : 'Compte de démonstration désactivé',
        'is_demo' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
}
ok('Comptes de démonstration : ' . implode(', ', array_keys($uid)) . " (mot de passe : $demoPassword)");
$agent = $uid['agent.demo'];

// ---------------------------------------------------------------- Fiches fictives
$addPhoto = function (int $personId, int $seed, string $date) use ($agent): void {
    $raw = demo_portrait($seed);
    $name = FileStorage::put('photos', $raw);
    $primary = (int) Database::value("SELECT COUNT(*) FROM photos WHERE person_id = :p AND is_primary = 1", ['p' => $personId]) === 0;
    Database::insert('photos', [
        'person_id' => $personId, 'storage_name' => $name, 'mime_type' => 'image/jpeg', 'width' => 480, 'height' => 600,
        'size_bytes' => strlen($raw), 'sha256_original' => hash('sha256', $raw), 'authorization_ref' => 'AUT-DEMO-' . $seed,
        'taken_at' => $date, 'is_primary' => $primary ? 1 : 0, 'uploaded_by' => $agent, 'uploaded_at' => $date . ' 10:00:00',
    ]);
};
$base = [
    'other_names' => null, 'declared_age' => null, 'sex' => null, 'nationality' => 'Fictive', 'profession' => null,
    'admin_id_type' => null, 'admin_id_number' => null, 'admin_id_verified' => 0, 'birth_place' => null,
    'registration_place' => 'Commissariat central (fictif)', 'registering_service' => 'Bureau des enregistrements',
    'access_level' => 'standard',
];
$people = [
    // clé => données ; scénarios indiqués en commentaire
    'aminata'  => ['last_name' => 'DIALLO', 'first_name' => 'Aminata', 'birth_date' => '1991-03-14', 'birth_place' => 'Ville-A', 'sex' => 'F',
                   'admin_id_type' => "Carte nationale d'identité", 'admin_id_number' => 'DEMO-100001', 'admin_id_verified' => 1,
                   'profession' => 'Commerçante', 'registration_date' => '2025-11-03'],                         // plusieurs passages + procédures
    'moussa'   => ['last_name' => 'GARBA', 'first_name' => 'Moussa', 'birth_date' => '1985-07-02', 'birth_place' => 'Ville-B', 'sex' => 'M',
                   'admin_id_type' => 'Passeport', 'admin_id_number' => 'DEMO-P-55021', 'registration_date' => '2025-12-10'],
    'musa'     => ['last_name' => 'GARBA', 'first_name' => 'Musa', 'birth_date' => '1985-07-02', 'birth_place' => 'Ville-B', 'sex' => 'M',
                   'registration_date' => '2026-04-22', 'registering_service' => 'Antenne Nord (fictive)'],  // doublon potentiel de « moussa »
    'ibrahim'  => ['last_name' => 'SANI', 'first_name' => 'Ibrahim', 'birth_date' => '1979-01-20', 'sex' => 'M', 'profession' => 'Chauffeur',
                   'registration_date' => '2025-10-15'],                                                    // personne acquittée
    'fatima'   => ['last_name' => 'BELLO', 'first_name' => 'Fatima', 'birth_date' => '2000-09-09', 'sex' => 'F',
                   'registration_date' => '2026-01-12'],                                                    // dossier clôturé
    'oumarou'  => ['last_name' => 'OUMAROU', 'first_name' => 'Abdoulaye', 'declared_age' => 34, 'sex' => 'M',
                   'registration_date' => '2026-02-05', 'other_names' => 'Abdou'],                          // âge déclaré seulement, sans photo
    'umaru'    => ['last_name' => 'UMARU', 'first_name' => 'Abdoulaye', 'declared_age' => 35, 'sex' => 'M',
                   'registration_date' => '2026-06-18'],                                                    // orthographe approchante
    'mariama'  => ['last_name' => 'OUSMANE', 'first_name' => 'Mariama', 'birth_date' => '1995-12-01', 'sex' => 'F',
                   'registration_date' => '2026-03-03', 'access_level' => 'restreint'],                     // accès restreint
    'yacouba'  => ['last_name' => 'MAMANE', 'first_name' => 'Yacouba', 'birth_date' => '1970-05-30', 'sex' => 'M',
                   'registration_date' => '2025-09-01'],                                                    // fiche archivée
    'zeinab'   => ['last_name' => 'ADAMOU', 'first_name' => 'Zeinabou', 'birth_date' => '1988-11-11', 'sex' => 'F',
                   'admin_id_type' => "Carte nationale d'identité", 'admin_id_number' => 'DEMO-100777', 'registration_date' => '2026-05-09'],
    'zeinab2'  => ['last_name' => 'ADAMOU', 'first_name' => 'Zeinab', 'birth_date' => '1988-11-11', 'sex' => 'F',
                   'admin_id_type' => "Carte nationale d'identité", 'admin_id_number' => 'DEMO 100 777', 'registration_date' => '2026-07-21'], // même identifiant
    'kader'    => ['last_name' => 'IDRISSA', 'first_name' => 'Abdoul Kader', 'birth_date' => '1999-02-17', 'sex' => 'M',
                   'registration_date' => '2026-08-30'],
];
$ids = [];
$seed = 100;
foreach ($people as $key => $d) {
    $data = array_merge($base, $d);
    $ids[$key] = Person::create($data, $agent, true);
    if ($key !== 'oumarou') {
        $addPhoto($ids[$key], $seed++, $data['registration_date']);
    }
}
// Photographies complémentaires et similaires pour la paire de doublons
$addPhoto($ids['aminata'], 300, '2026-06-01');
$addPhoto($ids['musa'], 101, '2026-04-22'); // même silhouette que « moussa » (graine 101)
// Remplissage pour la pagination
$fill = [['TRAORE', 'Issa'], ['KONE', 'Awa'], ['SOW', 'Oumar'], ['BA', 'Kadidia'], ['CISSE', 'Seydou'], ['TOURE', 'Hawa'],
         ['KEITA', 'Mamadou'], ['DIARRA', 'Salimata'], ['COULIBALY', 'Bakary'], ['SIDIBE', 'Rokia'], ['CAMARA', 'Lamine'],
         ['BARRY', 'Djenabou'], ['SYLLA', 'Moctar'], ['KANTE', 'Fanta'], ['DEMBELE', 'Adama'], ['SANGARE', 'Aissata']];
foreach ($fill as $i => [$ln, $fn]) {
    $ids["fill$i"] = Person::create(array_merge($base, [
        'last_name' => $ln, 'first_name' => $fn, 'birth_date' => sprintf('%d-%02d-%02d', 1960 + $i * 2, ($i % 12) + 1, ($i % 27) + 1),
        'registration_date' => date('Y-m-d', strtotime('-' . (20 + $i * 17) . ' days')),
    ]), $agent, true);
    if ($i % 3 === 0) {
        $addPhoto($ids["fill$i"], 500 + $i, date('Y-m-d', strtotime('-' . (20 + $i * 17) . ' days')));
    }
}
ok(count($ids) . ' fiches fictives créées');

// ---------------------------------------------------------------- Passages
$services = ['Bureau des enregistrements', 'Service des documents administratifs', 'Brigade de recherche (fictive)', 'Accueil du public', 'Antenne Nord (fictive)'];
$reasons = ['Demande de document administratif', 'Convocation', 'Déclaration de perte', 'Audition', 'Vérification d\'identité', 'Dépôt de plainte'];
$n = 0;
foreach ($ids as $key => $pid) {
    $count = match ($key) { 'aminata' => 5, 'moussa', 'ibrahim' => 3, 'fatima', 'zeinab' => 2, default => mt_rand(0, 2) };
    for ($i = 0; $i < $count; $i++) {
        $date = date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 360) . ' days ' . mt_rand(8, 17) . ':' . mt_rand(10, 59)));
        Visit::create($pid, [
            'visit_date' => $date, 'location' => 'Commissariat central (fictif)', 'service' => $services[array_rand($services)],
            'reason' => $reasons[array_rand($reasons)], 'reference' => sprintf('REF-DEMO-%04d', ++$n),
            'responsible_agent' => 'Agent fictif n° ' . mt_rand(10, 99),
            'observations' => $i === 0 ? 'Observation fictive : pièce présentée et vérifiée.' : null,
            'closure_status' => mt_rand(0, 3) ? 'cloture' : 'ouvert',
        ], $agent);
    }
}
ok("$n passages fictifs créés");

// ---------------------------------------------------------------- Procédures
$proc = function (string $key, array $d) use ($ids, $agent): int {
    return Procedure::create($ids[$key], array_merge([
        'pv_reference' => null, 'facts_date' => null, 'court_reference' => null, 'decision_text' => null,
        'decision_date' => null, 'decision_final' => 'inconnu', 'justification_ref' => null, 'notes' => null,
        'status_justification' => 'Enregistrement initial (démonstration)',
    ], $d), $agent);
};
// Aminata : plusieurs procédures, dont une condamnation documentée
$proc('aminata', ['procedure_number' => 'PROC-DEMO-2025-0012', 'pv_reference' => 'PV-DEMO-0451', 'facts_date' => '2025-10-28',
    'facts_nature' => 'Témoignage dans une affaire de vol (faits fictifs)', 'investigating_service' => 'Brigade de recherche (fictive)', 'status' => 'entendue']);
$pa = $proc('aminata', ['procedure_number' => 'PROC-DEMO-2026-0037', 'pv_reference' => 'PV-DEMO-0712', 'facts_date' => '2026-01-15',
    'facts_nature' => 'Fraude documentaire alléguée (faits fictifs)', 'investigating_service' => 'Brigade de recherche (fictive)', 'status' => 'mise_en_cause']);
Procedure::update($pa, array_merge(Database::one('SELECT * FROM procedures WHERE id = :id', ['id' => $pa]), [
    'status' => 'poursuivie', 'court_reference' => 'PARQUET-DEMO-2026-118', 'status_justification' => 'Réquisitoire fictif du parquet n° 118',
]), $agent);
Procedure::update($pa, array_merge(Database::one('SELECT * FROM procedures WHERE id = :id', ['id' => $pa]), [
    'status' => 'condamnee', 'court_reference' => 'TRIB-DEMO-2026-044', 'decision_text' => 'Jugement fictif : amende avec sursis',
    'decision_date' => '2026-05-20', 'decision_final' => 'non_definitive', 'justification_ref' => 'Copie du jugement fictif n° 044 (DOC-DEMO-3)',
    'status_justification' => 'Jugement fictif n° 044 du 20/05/2026, appel possible',
]), $agent);
$proc('moussa', ['procedure_number' => 'PROC-DEMO-2026-0051', 'facts_date' => '2026-02-11', 'facts_nature' => 'Différend de voisinage (faits fictifs)',
    'investigating_service' => 'Commissariat central (fictif)', 'status' => 'mise_en_cause']);
$proc('ibrahim', ['procedure_number' => 'PROC-DEMO-2025-0090', 'pv_reference' => 'PV-DEMO-0233', 'facts_date' => '2025-08-03',
    'facts_nature' => 'Accident de la circulation (faits fictifs)', 'investigating_service' => 'Brigade routière (fictive)', 'status' => 'acquittee',
    'court_reference' => 'TRIB-DEMO-2026-007', 'decision_text' => 'Jugement fictif de relaxe', 'decision_date' => '2026-02-14',
    'decision_final' => 'definitive', 'justification_ref' => 'Extrait du greffe fictif n° 007']);
$proc('fatima', ['procedure_number' => 'PROC-DEMO-2026-0003', 'facts_date' => '2026-01-02', 'facts_nature' => 'Perte de documents signalée (faits fictifs)',
    'investigating_service' => 'Commissariat central (fictif)', 'status' => 'cloturee', 'notes' => 'Dossier clôturé : documents retrouvés (fictif).']);
$proc('mariama', ['procedure_number' => 'PROC-DEMO-2026-0099', 'facts_date' => '2026-02-27', 'facts_nature' => 'Affaire sensible fictive (accès restreint)',
    'investigating_service' => 'Brigade de recherche (fictive)', 'status' => 'entendue']);
$proc('yacouba', ['procedure_number' => 'PROC-DEMO-2024-0201', 'facts_date' => '2024-06-10', 'facts_nature' => 'Affaire ancienne fictive',
    'investigating_service' => 'Commissariat central (fictif)', 'status' => 'cloturee']);
ok('Procédures fictives créées (entendue, mise en cause, poursuivie → condamnée documentée, acquittée, clôturée)');

// ---------------------------------------------------------------- Documents
foreach ([['aminata', 'Copie du jugement fictif n° 044', 'DOC-DEMO-3'], ['ibrahim', 'Extrait du greffe fictif n° 007', 'DOC-DEMO-7']] as [$key, $title, $ref]) {
    $pdf = demo_pdf($title);
    Database::insert('documents', [
        'person_id' => $ids[$key], 'title' => $title, 'doc_reference' => $ref, 'original_name' => strtolower($ref) . '.pdf',
        'storage_name' => FileStorage::put('documents', $pdf), 'mime_type' => 'application/pdf', 'size_bytes' => strlen($pdf),
        'sha256' => hash('sha256', $pdf), 'uploaded_by' => $agent, 'uploaded_at' => date('Y-m-d H:i:s'),
    ]);
}
ok('Documents fictifs ajoutés');

// ---------------------------------------------------------------- Archivage
Database::update('persons', ['status' => 'archive', 'archived_at' => date('Y-m-d H:i:s'), 'archived_by' => $agent,
    'archive_reason' => 'Fin de la durée de conservation (démonstration)'], 'id = :id', ['id' => $ids['yacouba']]);
ok('Fiche archivée de démonstration : ' . Database::value('SELECT file_number FROM persons WHERE id = :id', ['id' => $ids['yacouba']]));

// ---------------------------------------------------------------- Fichiers d'essai pour les imports manuels
$dir = BASE_PATH . '/database/seeds/fichiers_test';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
file_put_contents("$dir/photo_valide_fictive.jpg", demo_portrait(900));
file_put_contents("$dir/photo_mauvaise_qualite.jpg", demo_portrait(901, 120, 150));
file_put_contents("$dir/faux_fichier_image.jpg", "<?php echo 'ceci n est pas une image'; ?>");
file_put_contents("$dir/document_fictif.pdf", demo_pdf('Piece justificative fictive'));
file_put_contents("$dir/pdf_avec_javascript.pdf", "%PDF-1.4\n1 0 obj << /Type /Catalog /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >> endobj\n%%EOF\n");
ok('Fichiers d\'essai écrits dans database/seeds/fichiers_test/');

Audit::log('demo.seed', null, null, ['fiches' => count($ids)], null, 'installation');
out();
out('Comptes de démonstration (mot de passe : ' . $demoPassword . ') :');
out('  admin.demo     Administrateur           (comptes, paramètres, sauvegardes — pas d\'accès aux dossiers)');
out('  agent.demo     Agent d\'enregistrement   (création et mise à jour)');
out('  consult.demo   Agent habilité            (consultation, y compris fiches restreintes et archivées)');
out('  controle.demo  Responsable de contrôle   (journal d\'audit)');
out('  inactif.demo   Compte désactivé          (scénario de refus de connexion)');
