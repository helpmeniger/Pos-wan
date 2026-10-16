<?php
declare(strict_types=1);

/** Restauration d'une sauvegarde (.sql.gz). ATTENTION : remplace le contenu actuel de la base. */
require __DIR__ . '/cli.php';

$file = $argv[1] ?? '';
if ($file !== '' && !is_file($file)) {
    $file = STORAGE_PATH . '/backups/' . basename($file);
}
if (!is_file($file)) {
    out('Usage : php scripts/restore.php <fichier .sql.gz>');
    foreach (glob(STORAGE_PATH . '/backups/*.sql.gz') ?: [] as $f) {
        out('  - ' . basename($f));
    }
    exit(1);
}
if (!in_array('--yes', $argv, true) && strtolower(ask('Cette opération REMPLACE toutes les données actuelles. Tapez OUI pour continuer : ')) !== 'oui') {
    out('Annulé.');
    exit(1);
}
out('Sauvegarde de sécurité de l\'état actuel…');
$pre = App\Services\BackupService::backup('cli');
out($pre['ok'] ? '  -> ' . $pre['file'] : '  (échec, poursuite) ' . ($pre['error'] ?? ''));
App\Services\BackupService::restore($file);
out('Restauration terminée : ' . basename($file));
