<?php
/** Applique les migrations en attente (mise à jour sans perte de données). Usage : php bin/migrate.php */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

$backup = App\Services\BackupService::create('avant_migration');
echo "Sauvegarde de sécurité : {$backup['sql']}\n";
$applied = App\Services\InstallService::migrate();
echo $applied ? 'Migrations appliquées : ' . implode(', ', $applied) . "\n" : "Base déjà à jour.\n";
