<?php
declare(strict_types=1);

/**
 * Environnement de test isolé : base « <db.name>_test », stockage et clés
 * dans tests/.tmp (supprimés à chaque exécution). La configuration de
 * connexion est lue dans config/config.php (même serveur, autre base).
 */
$root = dirname(__DIR__);
$tmp = $root . '/tests/.tmp';

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

if (!getenv('DOSSIER_ID_TEST_KEEP')) {
    rrmdir($tmp);
}
@mkdir($tmp . '/storage', 0750, true);

$base = require $root . '/config/config.php';
$base['app']['env'] = 'demo';
$base['app']['base_url'] = '';
$base['db']['name'] = ($base['db']['name'] ?? 'dossier_id') . '_test';
$base['storage']['path'] = $tmp . '/storage';
$base['storage']['key_dir'] = $tmp . '/keys';
$configFile = $tmp . '/config.test.php';
file_put_contents($configFile, '<?php return ' . var_export($base, true) . ';');
putenv('DOSSIER_ID_CONFIG=' . $configFile);

require $root . '/app/bootstrap.php';
require __DIR__ . '/TestCase.php';

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Services\Migrator;

/** Réinitialise complètement la base de test et applique les migrations. */
function reset_database(): void
{
    $name = (string) Config::get('db.name');
    if (!str_ends_with($name, '_test')) {
        throw new RuntimeException('Sécurité : la base de test doit se terminer par _test.');
    }
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', Config::get('db.host'), (int) Config::get('db.port', 3306)),
        (string) Config::get('db.user'), (string) Config::get('db.password'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec("DROP DATABASE IF EXISTS `$name`");
    $pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    Database::reset();
    App\Core\Settings::clearCache();
    Migrator::migrate();
}

Crypto::ensureKey('storage');
Crypto::ensureKey('backup');
foreach (['photos', 'documents', 'backups', 'logs', 'tmp'] as $d) {
    @mkdir(Config::get('storage.path') . '/' . $d, 0750, true);
}
reset_database();

// ------------------------------------------------------------------ fabriques
const TEST_PASSWORD = 'Test!Motdepasse2026';

function make_user(string $username, string $role, array $extra = []): int
{
    $now = date('Y-m-d H:i:s');
    return Database::insert('users', $extra + [
        'username' => $username, 'full_name' => ucfirst($username), 'service' => 'Service de test',
        'role_id' => (int) Database::value('SELECT id FROM roles WHERE code = :c', ['c' => $role]),
        'password_hash' => App\Core\Auth::hashPassword(TEST_PASSWORD), 'must_change_password' => 0,
        'created_at' => $now, 'updated_at' => $now,
    ]);
}

function act_as(?int $userId): void
{
    $_SESSION = [];
    App\Core\Auth::reset();
    if ($userId) {
        App\Core\Auth::loginAs($userId);
    }
}

function person_data(array $over = []): array
{
    return $over + [
        'last_name' => 'TESTEUR', 'first_name' => 'Fictif', 'other_names' => null, 'birth_date' => '1990-01-01',
        'declared_age' => null, 'birth_place' => 'Ville-Test', 'sex' => null, 'nationality' => null, 'profession' => null,
        'admin_id_type' => null, 'admin_id_number' => null, 'admin_id_verified' => 0, 'registration_date' => '2026-01-01',
        'registration_place' => 'Bureau test', 'registering_service' => 'Service test', 'access_level' => 'standard',
    ];
}

function jpeg_bytes(int $w = 400, int $h = 500, bool $withExif = false): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 120, 140, 160));
    imagefilledellipse($im, (int) ($w / 2), (int) ($h / 2), (int) ($w / 2), (int) ($h / 2), imagecolorallocate($im, 90, 60, 40));
    ob_start();
    imagejpeg($im, null, 90);
    $data = (string) ob_get_clean();
    if ($withExif) {
        // Segment APP1 « Exif » factice contenant une chaîne repérable, inséré après SOI.
        $payload = "Exif\0\0" . 'GPS-SECRET-48.8566N';
        $data = substr($data, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($data, 2);
    }
    return $data;
}

function tmp_file(string $contents, string $suffix = '.bin'): string
{
    $p = Config::get('storage.path') . '/tmp/t_' . bin2hex(random_bytes(6)) . $suffix;
    file_put_contents($p, $contents);
    return $p;
}
