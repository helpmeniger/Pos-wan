<?php
/**
 * Sauvegarde automatique (à planifier avec le Planificateur de tâches Windows).
 * Usage : php bin/backup.php [--copy-to="E:\\Sauvegardes"]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

$o = getopt('', ['copy-to:']);
$r = App\Services\BackupService::create('auto');
echo "Sauvegarde créée : {$r['sql']} ({$r['tables']} tables, {$r['rows']} lignes)\n";
if (!empty($o['copy-to'])) {
    $dest = rtrim($o['copy-to'], '/\\');
    if (!is_dir($dest) || !is_writable($dest)) {
        fwrite(STDERR, "Destination de copie inaccessible : $dest\n");
        exit(2);
    }
    foreach ([$r['sql'], $r['sql'] . '.sha256', $r['zip'], $r['zip'] ? $r['zip'] . '.sha256' : null] as $f) {
        if ($f) {
            copy(App\Services\BackupService::dir() . '/' . $f, $dest . DIRECTORY_SEPARATOR . $f);
        }
    }
    echo "Copie externe effectuée vers $dest\n";
}
