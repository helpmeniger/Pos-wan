<?php
declare(strict_types=1);

/**
 * Installation / mise à jour de la base : crée la base si nécessaire (avec un compte
 * administrateur MySQL fourni), importe le schéma, les données de référence et applique
 * les migrations de database/migrations dans l'ordre (une seule fois chacune).
 * Usage : php scripts/install_db.php [--root-user=root] [--root-pass=] [--create-user]
 */
require __DIR__ . '/cli.php';

use App\Core\Database;
use App\Core\Env;

$opts = getopt('', ['root-user::', 'root-pass::', 'create-user']);
$host = (string) Env::get('DB_HOST', '127.0.0.1');
$port = Env::int('DB_PORT', 3306);
$name = (string) Env::get('DB_NAME', 'smart_guard_ai');
$user = (string) Env::get('DB_USER', 'smartguard');
$pass = (string) Env::get('DB_PASS', '');
if (!preg_match('/^[A-Za-z0-9_]+$/', $name) || !preg_match('/^[A-Za-z0-9_]+$/', $user)) {
    out('[ERREUR] DB_NAME / DB_USER : lettres, chiffres et _ uniquement.');
    exit(1);
}

if (isset($opts['root-user'])) {
    $root = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", (string) $opts['root-user'], (string) ($opts['root-pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $root->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    out("Base « {$name} » prête.");
    if (isset($opts['create-user'])) {
        if (strlen($pass) < 12) {
            out('[ERREUR] Définissez DB_PASS (12 caractères minimum) dans .env avant de créer l\'utilisateur.');
            exit(1);
        }
        $q = $root->quote($pass);
        $root->exec("CREATE USER IF NOT EXISTS '{$user}'@'localhost' IDENTIFIED BY {$q}");
        $root->exec("CREATE USER IF NOT EXISTS '{$user}'@'127.0.0.1' IDENTIFIED BY {$q}");
        $root->exec("ALTER USER '{$user}'@'localhost' IDENTIFIED BY {$q}");
        $root->exec("ALTER USER '{$user}'@'127.0.0.1' IDENTIFIED BY {$q}");
        foreach (['localhost', '127.0.0.1'] as $h) {
            $root->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, LOCK TABLES ON `{$name}`.* TO '{$user}'@'{$h}'");
        }
        $root->exec('FLUSH PRIVILEGES');
        out("Utilisateur MySQL « {$user} » créé avec des droits limités à la base « {$name} ».");
    }
}

$pdo = Database::pdo();
/** Exécute un fichier SQL instruction par instruction (les erreurs sont toutes signalées). */
$run = static function (string $file) use ($pdo): void {
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $stmt) {
        if (trim($stmt) !== '') {
            $pdo->exec($stmt);
        }
    }
};
$hasSchema = (bool) $pdo->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn();
if (!$hasSchema) {
    out('Import du schéma…');
    $run(BASE_PATH . '/database/schema.sql');
}
out('Données de référence…');
$run(BASE_PATH . '/database/seeds/001_reference_data.sql');
$applied = array_column(Database::fetchAll('SELECT version FROM schema_migrations'), 'version');
$files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
sort($files);
foreach ($files as $f) {
    $v = basename($f, '.sql');
    if (in_array($v, $applied, true)) {
        continue;
    }
    out("Migration {$v}…");
    $run($f);
    Database::insert('schema_migrations', ['version' => $v]);
}
out('Base de données à jour.');
