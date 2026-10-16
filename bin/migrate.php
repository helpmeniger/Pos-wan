<?php
declare(strict_types=1);

/**
 * Applique les migrations SQL de database/migrations dans l'ordre.
 *   php bin/migrate.php            applique les migrations manquantes
 *   php bin/migrate.php --fresh    SUPPRIME la base puis la recrée (développement uniquement)
 *   php bin/migrate.php --database=marwa_test
 */
require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Config;
use App\Core\Database;
use App\Support\SqlRunner;

$opts = getopt('', ['fresh', 'database:', 'force']);
$dbName = $opts['database'] ?? Config::get('database.name');
if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $dbName)) {
    fwrite(STDERR, "Nom de base invalide.\n");
    exit(1);
}
if (isset($opts['fresh']) && Config::get('app.env') === 'production' && !isset($opts['force'])) {
    fwrite(STDERR, "--fresh refusé en production (ajoutez --force si vous êtes certain).\n");
    exit(1);
}
$result = SqlRunner::migrate((string) $dbName, isset($opts['fresh']));
foreach ($result as $line) {
    echo $line, PHP_EOL;
}
