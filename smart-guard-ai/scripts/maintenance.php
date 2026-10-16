<?php
declare(strict_types=1);

/** Tâche planifiée (toutes les heures conseillé) : rétention, quota disque, santé IA, rapports de la veille. */
require __DIR__ . '/cli.php';

$res = App\Services\MaintenanceService::runScheduled();
out(json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
if (in_array('--backup', $argv, true)) {
    $b = App\Services\BackupService::backup('planifie');
    out($b['ok'] ? 'Sauvegarde : ' . $b['file'] : 'Échec sauvegarde : ' . $b['error']);
}
