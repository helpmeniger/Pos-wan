<?php
declare(strict_types=1);

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Response;
use App\Models\Person;
use App\Services\BackupService;
use App\Services\FileStorage;
use App\Services\UploadService;
use App\Services\ValidationException;

test('Photographies : JPEG valide ré-encodé, métadonnées supprimées', function () {
    $img = UploadService::processPhoto(tmp_file(jpeg_bytes(800, 1000, true), '.jpg'));
    assert_same('image/jpeg', $img['mime']);
    assert_not_contains('GPS-SECRET', $img['data'], 'EXIF supprimé');
    assert_same([800, 1000], [$img['width'], $img['height']]);
    $big = UploadService::processPhoto(tmp_file(jpeg_bytes(3200, 2400), '.jpg'));
    assert_same(1600, $big['width'], 'redimensionnement');
});

test('Photographies : PNG accepté, image trop petite / fausse image / format interdit refusés', function () {
    $im = imagecreatetruecolor(300, 300);
    ob_start();
    imagepng($im);
    $png = (string) ob_get_clean();
    assert_same('image/jpeg', UploadService::processPhoto(tmp_file($png, '.png'))['mime']);
    assert_throws(ValidationException::class, fn () => UploadService::processPhoto(tmp_file(jpeg_bytes(120, 150), '.jpg')), 'Qualité insuffisante');
    assert_throws(ValidationException::class, fn () => UploadService::processPhoto(tmp_file("<?php system('x'); ?>", '.jpg')), 'Format refusé');
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
    assert_throws(ValidationException::class, fn () => UploadService::processPhoto(tmp_file($gif, '.gif')), 'Format refusé');
    assert_throws(ValidationException::class, fn () => UploadService::processPhoto(tmp_file(substr(jpeg_bytes(), 0, 200), '.jpg')));
    assert_throws(ValidationException::class, fn () => UploadService::processPhoto(tmp_file('', '.jpg')), 'vide');
    // Polyglotte : en-tête JPEG suivi de code PHP — le ré-encodage élimine la charge.
    $poly = jpeg_bytes() . '<?php echo "pwned"; ?>';
    assert_not_contains('<?php', UploadService::processPhoto(tmp_file($poly, '.jpg'))['data']);
});

test('Photographies : taille maximale', function () {
    App\Core\Config::set('uploads.photo_max_bytes', 1000);
    try {
        assert_throws(ValidationException::class, fn () => UploadService::processPhoto(tmp_file(jpeg_bytes(), '.jpg')), 'taille maximale');
    } finally {
        App\Core\Config::set('uploads.photo_max_bytes', 5 * 1024 * 1024);
    }
});

test('Documents : PDF valide accepté, PDF actif et exécutable refusés', function () {
    $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n";
    assert_same('application/pdf', UploadService::processDocument(tmp_file($pdf, '.pdf'))['mime']);
    assert_throws(ValidationException::class, fn () => UploadService::processDocument(
        tmp_file("%PDF-1.4\n<< /OpenAction << /S /JavaScript /JS (x) >> >>\n%%EOF", '.pdf')), 'contenu actif');
    assert_throws(ValidationException::class, fn () => UploadService::processDocument(tmp_file("MZ\x90\x00binaire", '.pdf')));
    assert_same('rapport_2026__.pdf', UploadService::safeName('../../etc/rapport_2026<>.pdf'));
});

test('Stockage : fichiers chiffrés au repos, intégrité vérifiée, chemins contrôlés', function () {
    $data = jpeg_bytes();
    $name = FileStorage::put('photos', $data);
    $raw = (string) file_get_contents(FileStorage::path('photos', $name));
    assert_false(str_contains($raw, "\xFF\xD8\xFF"), 'aucune signature JPEG en clair');
    assert_same($data, FileStorage::get('photos', $name));
    $raw[40] = $raw[40] === 'A' ? 'B' : 'A';
    file_put_contents(FileStorage::path('photos', $name), $raw);
    assert_throws(RuntimeException::class, fn () => FileStorage::get('photos', $name), 'altéré');
    assert_throws(InvalidArgumentException::class, fn () => FileStorage::path('photos', '../../config/config.php'));
    assert_throws(InvalidArgumentException::class, fn () => FileStorage::path('backups', 'abcd/' . str_repeat('a', 32) . '.bin'));
    assert_true(Crypto::key('storage') !== Crypto::key('backup'), 'clés distinctes');
});

test('CSRF : jeton requis et comparé en temps constant', function () {
    $_SESSION = [];
    $t = Csrf::token();
    assert_same(64, strlen($t));
    assert_true(Csrf::validate($t));
    assert_false(Csrf::validate('x' . substr($t, 1)));
    assert_false(Csrf::validate(null));
    Csrf::rotate();
    assert_false(Csrf::validate($t), 'jeton renouvelé');
});

test('XSS : échappement systématique des sorties', function () {
    assert_same('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', e("<script>alert('x')</script>"));
    $agent = make_user('xss.agent', 'enregistrement');
    act_as($agent);
    $id = Person::create(person_data(['other_names' => '<img src=x onerror=alert(1)>']), $agent);
    $_GET = ['r' => 'persons.show', 'id' => (string) $id];
    $html = (new App\Controllers\PersonController())->show();
    $_GET = [];
    assert_not_contains('<img src=x onerror', $html);
    assert_contains('&lt;img src=x onerror=alert(1)&gt;', $html);
});

test('CSV : neutralisation des formules (injection CSV)', function () {
    $r = Response::csv(['a'], [['=HYPERLINK("http://x")'], ['+1'], ['normal']], 'x.csv');
    assert_contains("'=HYPERLINK", $r->body);
    assert_contains("'+1", $r->body);
    assert_same('no-store, private', $r->headers['Cache-Control']);
});

test('Journal d\'audit : chaîne intègre, altération détectée, données sensibles filtrées', function () {
    Audit::log('test.event', 'person', 1, ['password' => 'secret123', 'motif' => 'ok', 'csrf_token' => 'abc'], null, 'testeur');
    $row = Database::one('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
    assert_not_contains('secret123', (string) $row['details']);
    assert_not_contains('abc', (string) $row['details']);
    assert_contains('ok', (string) $row['details']);
    assert_true(Audit::verifyChain()['ok'], 'chaîne valide');
    Database::run("UPDATE audit_log SET username = 'falsifie' WHERE id = :id", ['id' => (int) $row['id']]);
    $res = Audit::verifyChain();
    assert_false($res['ok']);
    assert_same((int) $row['id'], $res['broken_at']);
    Database::run("UPDATE audit_log SET username = 'testeur' WHERE id = :id", ['id' => (int) $row['id']]);
    assert_true(Audit::verifyChain()['ok']);
    $mid = (int) Database::value('SELECT MIN(id) FROM audit_log') + 1;
    $saved = Database::one('SELECT * FROM audit_log WHERE id = :id', ['id' => $mid]);
    Database::run('DELETE FROM audit_log WHERE id = :id', ['id' => $mid]);
    assert_false(Audit::verifyChain()['ok'], 'suppression détectée');
    Database::insert('audit_log', $saved);
    assert_true(Audit::verifyChain()['ok']);
});

test('Sauvegarde et restauration complètes (base + fichiers), avec sauvegarde de sécurité', function () {
    $agent = make_user('bk.agent', 'enregistrement');
    act_as($agent);
    $pid = Person::create(person_data(['last_name' => 'Sauvegarde']), $agent);
    $photo = FileStorage::put('photos', jpeg_bytes());
    Database::insert('photos', ['person_id' => $pid, 'storage_name' => $photo, 'mime_type' => 'image/jpeg', 'width' => 400, 'height' => 500,
        'size_bytes' => 10, 'sha256_original' => str_repeat('a', 64), 'authorization_ref' => 'A', 'uploaded_at' => date('Y-m-d H:i:s')]);
    $persons = (int) Database::value('SELECT COUNT(*) FROM persons');
    $name = BackupService::create();
    $path = BackupService::pathFor($name);
    $raw = (string) file_get_contents($path);
    assert_not_contains('Sauvegarde', $raw, 'sauvegarde chiffrée');
    assert_same($persons, BackupService::inspect($path)['tables']['persons']['rows']);

    // Modifications après la sauvegarde
    Database::run("UPDATE persons SET last_name = 'MODIFIE' WHERE id = :id", ['id' => $pid]);
    Person::create(person_data(['last_name' => 'Apres']), $agent);
    unlink(FileStorage::path('photos', $photo));
    $extra = FileStorage::put('documents', 'fichier ajouté après');

    $res = BackupService::restore($path);
    BackupService::logRestore($name, $res, $agent, 'bk.agent');
    assert_same('Sauvegarde', Database::value('SELECT last_name FROM persons WHERE id = :id', ['id' => $pid]));
    assert_same($persons, (int) Database::value('SELECT COUNT(*) FROM persons'));
    assert_true(is_file(FileStorage::path('photos', $photo)), 'photo restaurée');
    assert_false(is_file(FileStorage::path('documents', $extra)), 'fichier postérieur retiré');
    assert_true($res['safety'] !== null && is_file(BackupService::dir() . '/' . $res['safety']), 'sauvegarde de sécurité');
    assert_true(Audit::verifyChain()['ok'], 'journal intègre après restauration');
    assert_same('backup.restore', Database::value('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1'));
});

test('Sauvegarde : fichier altéré, tronqué ou étranger refusé', function () {
    $path = BackupService::pathFor(BackupService::create());
    $raw = (string) file_get_contents($path);
    $bad = tmp_file(substr($raw, 0, 60) . chr(ord($raw[60]) ^ 1) . substr($raw, 61), '.didbak');
    assert_throws(RuntimeException::class, fn () => BackupService::inspect($bad));
    assert_throws(RuntimeException::class, fn () => BackupService::inspect(tmp_file(substr($raw, 0, (int) (strlen($raw) / 2)), '.didbak')));
    assert_throws(RuntimeException::class, fn () => BackupService::inspect(tmp_file('PK zip quelconque', '.didbak')));
    assert_throws(ValidationException::class, fn () => BackupService::pathFor('../../config/config.php'));
});

test('Résilience : base indisponible → erreur contrôlée sans fuite d\'informations', function () {
    $pass = App\Core\Config::get('db.password');
    App\Core\Config::set('db.password', 'mauvais-mot-de-passe');
    Database::reset();
    try {
        assert_throws(PDOException::class, fn () => Database::value('SELECT 1'));
    } finally {
        App\Core\Config::set('db.password', $pass);
        Database::reset();
    }
    assert_same(1, (int) Database::value('SELECT 1'));
});
