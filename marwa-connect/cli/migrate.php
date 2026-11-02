<?php
/**
 * Applique les migrations en attente et les données de référence (idempotent).
 * Usage (WampServer) : C:\wamp64\bin\php\php8.x\php.exe cli\migrate.php
 * Options : --no-seed   --status
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}
require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Database;
use App\Core\Env;
use App\Core\Migrator;

if (!Env::exists()) {
    fwrite(STDERR, "Fichier .env absent : lancez d'abord l'installation web (/install).\n");
    exit(1);
}
$migrator = new Migrator(Database::connection(), static fn (string $m) => print("  {$m}\n"));

if (in_array('--status', $argv, true)) {
    $pending = $migrator->pending();
    echo $pending ? "Migrations en attente :\n  - " . implode("\n  - ", array_map(static fn ($f) => basename($f, '.php'), $pending)) . "\n" : "Base à jour.\n";
    exit(0);
}
echo "MARWA CONNECT — migrations\n";
$migrator->migrate();
if (!in_array('--no-seed', $argv, true)) {
    $migrator->seed();
}
echo "Terminé.\n";
