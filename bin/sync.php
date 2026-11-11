<?php
/**
 * Synchronisation de la base locale vers la base MySQL en ligne (Configuration → Hébergement et synchronisation).
 * À planifier avec le Planificateur de tâches Windows, par exemple toutes les heures :
 *   php bin/sync.php            synchronise si l'option est activée
 *   php bin/sync.php --test     essaie seulement la connexion
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\SyncService;

try {
    if (in_array('--test', $argv, true)) {
        $t = SyncService::test();
        echo "Connexion réussie : MySQL {$t['version']}, {$t['tables']} table(s) en ligne" . ($t['foreign'] ? " (ATTENTION : elles ne viennent pas de ce poste)\n" : "\n");
        exit(0);
    }
    if (!SyncService::config()['enabled']) {
        echo "Synchronisation désactivée dans Configuration → Hébergement et synchronisation.\n";
        exit(0);
    }
    $r = SyncService::run(0, 0);
    echo "Synchronisation terminée : {$r['total']} table(s) contrôlée(s), {$r['rows']} ligne(s) envoyée(s).\n";
    foreach ($r['tables'] as $t) {
        echo "  - $t\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Échec : ' . $e->getMessage() . "\n");
    exit(1);
}
