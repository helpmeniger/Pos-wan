<?php
/**
 * Sauvegarde automatique (base + documents + clé de signature), à planifier.
 *
 *   php tools/sauvegarde_auto.php [jours_de_conservation=30]
 *
 * Sous Windows : utilisez tools\sauvegarde_auto.bat avec le Planificateur de tâches
 * (voir README, « Sauvegarde et restauration »). Les sauvegardes plus anciennes que
 * le délai de conservation sont supprimées ; la plus récente n'est jamais supprimée.
 */
declare(strict_types=1);

define('NP_CLI', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

$keepDays = max(1, (int) ($argv[1] ?? 30));
$GLOBALS['np_cli_user'] = null;
try {
    MigrationService::autoMigrate();
    $sql = BackupService::createSql();
    $zip = BackupService::createDocumentsZip();
    $deleted = 0;
    $list = BackupService::listBackups();
    $newest = array_slice(array_column($list, 'name'), 0, 2);
    foreach ($list as $b) {
        if ($b['date'] < time() - $keepDays * 86400 && !in_array($b['name'], $newest, true)) {
            @unlink(BackupService::dir() . '/' . $b['name']);
            $deleted++;
        }
    }
    $line = sprintf("[%s] Sauvegarde OK : %s%s ; %d ancienne(s) supprimée(s)\n", date('c'), $sql, $zip ? " + $zip" : '', $deleted);
    file_put_contents(storage_path('logs/sauvegardes.log'), $line, FILE_APPEND | LOCK_EX);
    echo $line;
    exit(0);
} catch (Throwable $e) {
    log_error($e);
    file_put_contents(storage_path('logs/sauvegardes.log'), '[' . date('c') . '] ÉCHEC : ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    fwrite(STDERR, 'Échec de la sauvegarde : ' . $e->getMessage() . "\n");
    exit(1);
}
