<?php
declare(strict_types=1);

/**
 * Vérification de l'environnement (WampServer / Windows ou Linux).
 * Usage : php scripts/check_env.php
 * Code de sortie : 0 si aucun point bloquant, 1 sinon.
 */
require __DIR__ . '/cli.php';

$errors = 0;
out('DOSSIER ID — vérification de l\'environnement');
out(str_repeat('=', 60));

out('PHP');
if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
    ok('PHP ' . PHP_VERSION . ' (' . PHP_OS_FAMILY . ')');
} else {
    fail('PHP 8.1 minimum requis, version détectée : ' . PHP_VERSION);
    $errors++;
}
foreach (['pdo_mysql' => true, 'sodium' => true, 'gd' => true, 'fileinfo' => true, 'mbstring' => true, 'zip' => true,
          'openssl' => false, 'intl' => false, 'exif' => false] as $ext => $required) {
    if (extension_loaded($ext)) {
        ok("Extension $ext");
    } elseif ($required) {
        fail("Extension $ext manquante (WampServer : clic gauche > PHP > Extensions PHP > $ext)");
        $errors++;
    } else {
        warn("Extension $ext absente (recommandée)");
    }
}
defined('PASSWORD_ARGON2ID') ? ok('Argon2id disponible pour le hachage des mots de passe') : warn('Argon2id indisponible : bcrypt sera utilisé');
if (extension_loaded('gd')) {
    $gd = gd_info();
    (!empty($gd['JPEG Support']) && !empty($gd['PNG Support'])) ? ok('GD : JPEG et PNG') : (fail('GD sans support JPEG/PNG') || $errors++);
}
$toBytes = function (string $v): int {
    $n = (int) $v;
    return match (strtolower(substr(trim($v), -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
};
$upl = $toBytes((string) ini_get('upload_max_filesize'));
$post = $toBytes((string) ini_get('post_max_size'));
($upl >= 10 << 20) ? ok('upload_max_filesize = ' . ini_get('upload_max_filesize'))
    : warn('upload_max_filesize = ' . ini_get('upload_max_filesize') . ' (10M recommandé pour les documents ; voir docs/INSTALLATION.md)');
($post > $upl) ? ok('post_max_size = ' . ini_get('post_max_size')) : warn('post_max_size doit être supérieur à upload_max_filesize');
ini_get('session.use_strict_mode') !== false ? ok('Sessions PHP disponibles') : warn('Configuration de session inhabituelle');

out();
out('Configuration');
$configFile = dirname(__DIR__) . '/config/config.php';
if (!is_file($configFile)) {
    fail('config/config.php absent : copiez config/config.example.php puis adaptez-le.');
    out();
    out($errors + 1 . ' point(s) bloquant(s).');
    exit(1);
}
ok('config/config.php présent');
try {
    cli_bootstrap();
} catch (Throwable $e) {
    fail('Chargement impossible : ' . $e->getMessage());
    exit(1);
}
use App\Core\Config;
use App\Core\Database;

if (Config::get('db.password') === 'CHANGER_CE_MOT_DE_PASSE') {
    warn('Le mot de passe de base de données est celui de l\'exemple : changez-le.');
}
out('  Mode : ' . (Config::isDemo() ? 'démonstration (données fictives)' : 'production'));

out();
out('Base de données');
try {
    $v = (string) Database::value('SELECT VERSION()');
    ok("Connexion réussie ($v)");
    $isMaria = stripos($v, 'mariadb') !== false;
    $num = preg_replace('/[^0-9.].*$/', '', $v);
    if (($isMaria && version_compare($num, '10.6', '<')) || (!$isMaria && version_compare($num, '8.0', '<'))) {
        warn('Version ancienne : MySQL 8.0+ ou MariaDB 10.6+ recommandé.');
    }
    $pending = App\Services\Migrator::pending();
    $applied = App\Services\Migrator::applied();
    if (!$applied) {
        warn('Schéma non installé : exécutez php scripts/install.php');
    } elseif ($pending) {
        warn(count($pending) . ' migration(s) en attente : exécutez php scripts/install.php');
    } else {
        ok('Schéma à jour (' . end($applied) . ')');
    }
} catch (Throwable $e) {
    fail('Connexion impossible : ' . $e->getMessage());
    out('         Vérifiez que MySQL/MariaDB est démarré (icône WampServer verte) et les paramètres db.* de config.php.');
    $errors++;
}

out();
out('Stockage et clés');
$storage = (string) Config::get('storage.path');
$public = realpath(dirname(__DIR__) . '/public');
foreach (['photos', 'documents', 'backups', 'logs', 'tmp'] as $d) {
    $p = $storage . '/' . $d;
    if (!is_dir($p)) {
        @mkdir($p, 0750, true);
    }
    is_writable($p) ? ok("storage/$d accessible en écriture") : (fail("storage/$d non accessible en écriture") || $errors++);
}
$realStorage = realpath($storage);
if ($realStorage && $public && str_starts_with($realStorage, $public)) {
    fail('Le stockage est situé dans public/ : il serait accessible depuis le navigateur.');
    $errors++;
} else {
    ok('Stockage hors du répertoire public');
}
is_file($storage . '/.htaccess') ? ok('storage/.htaccess présent (accès direct refusé)') : warn('storage/.htaccess absent');
$keyDir = (string) Config::get('storage.key_dir');
foreach (['storage', 'backup'] as $k) {
    $path = App\Core\Crypto::keyPath($k);
    if (is_file($path)) {
        try {
            App\Core\Crypto::key($k);
            ok("Clé « $k » valide");
        } catch (Throwable $e) {
            fail($e->getMessage());
            $errors++;
        }
    } else {
        warn("Clé « $k » absente : elle sera créée par scripts/install.php");
    }
}
$realKey = realpath($keyDir);
$www = realpath(dirname(__DIR__, 2));
if ($realKey && $realKey === realpath(dirname(__DIR__) . '/config')) {
    warn('Les clés sont dans config/ : préférez un répertoire hors de l\'arborescence web.');
} elseif ($realKey && $www && str_starts_with($realKey, $www . DIRECTORY_SEPARATOR)) {
    warn('Les clés sont dans l\'arborescence web (' . $www . ') : préférez un répertoire hors de C:\\wamp64\\www.');
} elseif ($realKey) {
    ok("Répertoire des clés : $realKey");
}

out();
if ($errors) {
    out("$errors point(s) bloquant(s) à corriger.");
    exit(1);
}
out('Aucun point bloquant.');
exit(0);
