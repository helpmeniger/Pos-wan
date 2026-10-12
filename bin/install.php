<?php
declare(strict_types=1);
/**
 * Installation / mise à jour de VERIFIA.
 *   php bin/install.php [--db-host=127.0.0.1] [--db-port=3306] [--db-name=verifia] [--db-user=root] [--db-pass=]
 *                       [--base-url=http://localhost/verifia/public]
 *                       [--admin-user=admin --admin-pass=MotDePasseLong]
 * Idempotent : peut être relancé sans perte de données.
 */
$root = dirname(__DIR__);
$opts = getopt('', ['db-host::', 'db-port::', 'db-name::', 'db-user::', 'db-pass::', 'base-url::', 'admin-user::', 'admin-pass::', 'admin-name::', 'config::']);
$configFile = $opts['config'] ?? ($root . '/config/config.php');

function out(string $m): void { fwrite(STDOUT, $m . PHP_EOL); }
function fail(string $m): never { fwrite(STDERR, "ERREUR : $m" . PHP_EOL); exit(1); }

out('== Installation de VERIFIA ==');
require __DIR__ . '/check_lib.php';
[$okReq, $report] = verifia_check_requirements($root, false);
foreach ($report as $line) {
    out($line);
}
if (!$okReq) {
    fail('Prérequis manquants (voir ci-dessus).');
}

// 1. Fichier de configuration
if (!is_file($configFile)) {
    $tpl = file_get_contents($root . '/config/config.example.php');
    $host = $opts['db-host'] ?? '127.0.0.1';
    $port = $opts['db-port'] ?? '3306';
    $name = $opts['db-name'] ?? 'verifia';
    $repl = [
        "'mysql:host=127.0.0.1;port=3306;dbname=verifia;charset=utf8mb4'" => var_export("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", true),
        "'user'     => 'root'" => "'user'     => " . var_export($opts['db-user'] ?? 'root', true),
        "'password' => ''" => "'password' => " . var_export($opts['db-pass'] ?? '', true),
        "'token'           => 'CHANGE-ME'" => "'token'           => " . var_export(bin2hex(random_bytes(32)), true),
        "'base_url'  => 'http://localhost/verifia/public'" => "'base_url'  => " . var_export($opts['base-url'] ?? 'http://localhost/verifia/public', true),
    ];
    file_put_contents($configFile, strtr($tpl, $repl));
    out("Configuration créée : $configFile (jeton du moteur généré aléatoirement)");
} else {
    out("Configuration existante conservée : $configFile");
}
putenv('VERIFIA_CONFIG=' . $configFile);
require $root . '/src/bootstrap.php';

// 2. Base de données
$dsn = (string) Verifia\Config::get('db.dsn');
if (!preg_match('/dbname=([A-Za-z0-9_]+)/', $dsn, $m)) {
    fail('Nom de base invalide dans db.dsn');
}
$dbName = $m[1];
try {
    $server = new PDO(preg_replace('/dbname=[^;]+;?/', '', $dsn), (string) Verifia\Config::get('db.user'), (string) Verifia\Config::get('db.password'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    fail('Connexion MySQL impossible : ' . $e->getMessage() . "\nVérifiez que WampServer est démarré (icône verte) et les identifiants dans config/config.php.");
}
$server->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$server->exec("USE `$dbName`");
$sql = file_get_contents($root . '/database/schema.sql');
// Découpage simple sur « ; » en fin de ligne (le schéma ne contient pas de procédures stockées).
foreach (preg_split('/;\s*$/m', $sql) as $stmt) {
    $stmt = trim(preg_replace('/^--.*$/m', '', $stmt));
    if ($stmt !== '') {
        $server->exec($stmt);
    }
}
out("Base « $dbName » prête (" . count($server->query('SHOW TABLES')->fetchAll()) . ' tables).');

// 3. Dossiers de stockage
foreach (['images', 'thumbs', 'reports', 'logs', 'profiles', 'run'] as $d) {
    $p = Verifia\Config::storagePath($d);
    if (!is_dir($p)) {
        mkdir($p, 0775, true);
    }
}
out('Dossiers de stockage prêts.');

// 4. Administrateur
if (!empty($opts['admin-user']) && !empty($opts['admin-pass'])) {
    if (Verifia\Database::value('SELECT id FROM users WHERE username = ?', [$opts['admin-user']])) {
        out("L'utilisateur {$opts['admin-user']} existe déjà.");
    } else {
        try {
            Verifia\Auth::createUser($opts['admin-user'], $opts['admin-name'] ?? 'Administrateur', $opts['admin-pass'], 'admin');
            out("Administrateur « {$opts['admin-user']} » créé.");
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage());
        }
    }
} elseif (!(int) Verifia\Database::value('SELECT COUNT(*) FROM users')) {
    out("Aucun utilisateur : ouvrez l'interface, la page de première configuration vous demandera de créer l'administrateur.");
}
out('Installation terminée.');
