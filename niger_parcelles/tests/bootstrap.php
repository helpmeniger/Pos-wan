<?php
/**
 * Amorçage des tests : charge l'application en mode CLI et prépare une base
 * de test DÉDIÉE (jamais la base de production), recréée à chaque exécution.
 *
 * Variables d'environnement :
 *   NP_DB_HOST, NP_DB_PORT, NP_DB_USER, NP_DB_PASS  (connexion MySQL)
 *   NP_TEST_DB  nom de la base de test (défaut : niger_parcelles_test)
 */
declare(strict_types=1);

define('NP_CLI', true);
$testDb = getenv('NP_TEST_DB') ?: 'niger_parcelles_test';
if (!preg_match('/_test$/', $testDb)) {
    fwrite(STDERR, "Par sécurité, le nom de la base de test doit se terminer par _test.\n");
    exit(2);
}
putenv('NP_DB_NAME=' . $testDb);

require dirname(__DIR__) . '/includes/bootstrap.php';
require __DIR__ . '/TestCase.php';

// Stockage isolé pour les tests
$GLOBALS['np_config']['storage_path'] = sys_get_temp_dir() . '/np_test_storage_' . getmypid();
foreach (['', 'documents', 'exports', 'logs', 'imports', 'backups'] as $d) {
    @mkdir(storage_path($d), 0777, true);
}

/** (Re)crée la base de test à partir de schema.sql. */
function reset_test_database(): void
{
    $c = require APP_ROOT . '/config/database.php';
    $server = db_connect($c, false);
    $server->exec('DROP DATABASE IF EXISTS `' . $c['database'] . '`');
    $server->exec('CREATE DATABASE `' . $c['database'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    SqlRunner::runFile(db(), APP_ROOT . '/database/schema.sql');
    setting('__reset__');
}

/** Crée un utilisateur de test et le définit comme utilisateur courant. */
function as_user(string $roleCode, string $username = null): array
{
    $username = $username ?? $roleCode . '_' . bin2hex(random_bytes(3));
    $roleId = (int) db_value('SELECT id FROM roles WHERE code = ?', [$roleCode]);
    db_query('INSERT INTO users (username, password_hash, full_name, role_id) VALUES (?, ?, ?, ?)',
        [$username, password_hash('Test-Password-123', PASSWORD_DEFAULT), 'Test ' . $roleCode, $roleId]);
    $id = (int) db()->lastInsertId();
    $GLOBALS['np_cli_user'] = ['id' => $id, 'username' => $username, 'full_name' => 'Test ' . $roleCode, 'role_code' => $roleCode,
                               'role_name' => $roleCode, 'is_active' => 1, 'must_change_password' => 0];
    return $GLOBALS['np_cli_user'];
}

/** Rectangle GeoJSON de $w x $h mètres dont le coin sud-ouest est ($lng, $lat). */
function rect_geom(float $lng, float $lat, float $w = 20, float $h = 30): array
{
    $dLng = $w / (111320 * cos(deg2rad($lat)));
    $dLat = $h / 110574;
    return ['type' => 'Polygon', 'coordinates' => [[[$lng, $lat], [$lng + $dLng, $lat], [$lng + $dLng, $lat + $dLat], [$lng, $lat + $dLat], [$lng, $lat]]]];
}

register_shutdown_function(static function (): void {
    $dir = storage_path();
    if (is_dir($dir) && strpos($dir, 'np_test_storage_') !== false) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
});
