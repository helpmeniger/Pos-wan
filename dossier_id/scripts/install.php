<?php
declare(strict_types=1);

/**
 * Installation / mise à jour de DOSSIER ID.
 *
 *   php scripts/install.php                          installation interactive
 *   php scripts/install.php --create-db              crée aussi la base (compte MySQL disposant du droit CREATE)
 *   php scripts/install.php --admin=identifiant --admin-name="Nom Prénom"   sans question
 *   php scripts/install.php --demo                   charge ensuite les données FICTIVES (mode démo uniquement)
 *
 * Le script est ré-exécutable : il n'applique que les migrations manquantes et
 * ne crée le premier administrateur que si aucun compte n'existe.
 */
require __DIR__ . '/cli.php';

$args = cli_args();
out('DOSSIER ID — installation');
out(str_repeat('=', 60));

$configFile = dirname(__DIR__) . '/config/config.php';
if (!is_file($configFile)) {
    fail('config/config.php absent. Copiez config/config.example.php en config/config.php et renseignez la section db.');
    exit(1);
}
cli_bootstrap();

use App\Controllers\UserController;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Services\Migrator;

// 1. Base de données
if (isset($args['create-db'])) {
    $dbName = (string) Config::get('db.name');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
        fail('Nom de base invalide.');
        exit(1);
    }
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', Config::get('db.host'), (int) Config::get('db.port', 3306)),
        (string) Config::get('db.user'), (string) Config::get('db.password'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    ok("Base « $dbName » disponible");
}
try {
    Database::value('SELECT 1');
    ok('Connexion à la base réussie');
} catch (Throwable $e) {
    fail('Connexion impossible : ' . $e->getMessage());
    exit(1);
}

// 2. Clés de chiffrement
foreach (['storage', 'backup'] as $k) {
    if (Crypto::ensureKey($k)) {
        ok("Clé « $k » générée : " . Crypto::keyPath($k));
        warn('Sauvegardez immédiatement ce fichier sur un support séparé et protégé.');
    } else {
        Crypto::key($k);
        ok("Clé « $k » existante");
    }
}

// 3. Répertoires de stockage
foreach (['photos', 'documents', 'backups', 'logs', 'tmp'] as $d) {
    $p = Config::get('storage.path') . '/' . $d;
    if (!is_dir($p) && !mkdir($p, 0750, true)) {
        fail("Impossible de créer $p");
        exit(1);
    }
}
ok('Répertoires de stockage prêts');

// 4. Migrations
$applied = Migrator::migrate(fn ($m) => out("  $m"));
ok($applied ? count($applied) . ' migration(s) appliquée(s)' : 'Schéma déjà à jour');
if ($applied) {
    Audit::log('install', null, null, ['migrations' => $applied], null, 'installation');
}

// 5. Premier administrateur
$count = (int) Database::value('SELECT COUNT(*) FROM users');
if ($count === 0) {
    out();
    out('Création du premier compte administrateur');
    $username = mb_strtolower((string) ($args['admin'] ?? ask('Identifiant', 'admin')));
    if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
        fail('Identifiant invalide (3 à 60 caractères : a-z, 0-9, . _ -).');
        exit(1);
    }
    $fullName = (string) ($args['admin-name'] ?? ask('Nom complet', 'Administrateur technique'));
    $temp = UserController::temporaryPassword();
    $now = date('Y-m-d H:i:s');
    $id = Database::insert('users', [
        'username' => $username, 'full_name' => mb_substr($fullName, 0, 150), 'service' => 'Administration technique',
        'role_id' => (int) Database::value("SELECT id FROM roles WHERE code = 'admin'"),
        'password_hash' => Auth::hashPassword($temp), 'must_change_password' => 1,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    Audit::log('user.create', 'user', $id, ['username' => $username, 'origine' => 'installation'], null, 'installation');
    out();
    ok("Compte administrateur « $username » créé.");
    out('  Mot de passe temporaire : ' . $temp);
    out('  Il ne sera plus affiché. Le changement est imposé à la première connexion.');
    out('  Ce compte ne donne PAS accès au contenu des dossiers : créez ensuite les comptes des agents.');
} else {
    ok("$count compte(s) existant(s) : aucun administrateur créé");
}

// 6. Données de démonstration
if (isset($args['demo'])) {
    out();
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/seed_demo.php'), $code);
    if ($code !== 0) {
        exit($code);
    }
}

out();
out('Installation terminée. Vérification complète : php scripts/check_env.php');
$base = rtrim((string) Config::get('app.base_url'), '/');
out("Accès : http://localhost$base/");
