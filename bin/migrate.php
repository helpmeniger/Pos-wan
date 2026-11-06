<?php
/** Applique les migrations en attente (mise à jour sans perte de données). Usage : php bin/migrate.php */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

$backup = App\Services\BackupService::create('avant_migration', false);
echo "Sauvegarde de sécurité : {$backup['sql']}\n";
$applied = App\Services\InstallService::migrate();
echo $applied ? 'Migrations appliquées : ' . implode(', ', $applied) . "\n" : "Base déjà à jour.\n";
// Contrôle de la structure : tables et colonnes manquantes (base restaurée, mise à jour interrompue…)
$r = App\Services\SchemaSync::repair(false);
$n = count($r['created']) + count($r['columns']) + count($r['enums']);
echo $n ? 'Structure synchronisée : ' . count($r['created']) . ' table(s) créée(s), ' . count($r['columns']) . ' colonne(s) ajoutée(s), ' . count($r['enums']) . " liste(s) de valeurs élargie(s).\n" : "Structure de la base complète.\n";
// Fichiers CSS/JS des modules actifs (absents du paquet du logiciel) recopiés si besoin
App\Core\Addons::syncAssets();
foreach ($r['errors'] as $e) {
    fwrite(STDERR, "Erreur : $e\n");
}
exit($r['errors'] ? 1 : 0);
