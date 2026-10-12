<?php
declare(strict_types=1);

/** Charge le jeu de données de DÉMONSTRATION : php bin/seed.php [--database=marwa] */
require dirname(__DIR__) . '/bootstrap/app.php';
require MARWA_ROOT . '/database/seeds/DemoSeeder.php';

use App\Core\Config;
use App\Core\Database;

if (Config::get('app.env') === 'production') {
    fwrite(STDERR, "Refusé : le jeu de démonstration ne doit pas être chargé en production.\n");
    exit(1);
}
$opts = getopt('', ['database:']);
if (isset($opts['database'])) {
    $c = Config::get('database');
    Database::setPdo(Database::connect(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $opts['database']), $c['user'], $c['password']));
}
if (Database::value('SELECT COUNT(*) FROM users WHERE is_demo = 1') > 0) {
    fwrite(STDERR, "Les données de démonstration sont déjà présentes (php bin/migrate.php --fresh pour repartir de zéro).\n");
    exit(1);
}
foreach (\Database\Seeds\DemoSeeder::run() as $line) {
    echo $line, PHP_EOL;
}
