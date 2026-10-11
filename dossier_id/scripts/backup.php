<?php
declare(strict_types=1);

/**
 * Sauvegardes en ligne de commande (planifiables avec le Planificateur de tâches Windows).
 *
 *   php scripts/backup.php --create
 *   php scripts/backup.php --list
 *   php scripts/backup.php --verify=dossier_id_YYYYmmdd_HHMMSS_xxxx.didbak
 *   php scripts/backup.php --restore=C:\chemin\fichier.didbak [--yes]
 *   php scripts/backup.php --create --keep=30     conserve les 30 sauvegardes les plus récentes
 */
require __DIR__ . '/cli.php';
cli_bootstrap();

use App\Core\Audit;
use App\Services\BackupService;

$args = cli_args();
try {
    if (isset($args['create'])) {
        $name = BackupService::create();
        Audit::log('backup.create', null, null, ['fichier' => $name, 'origine' => 'cli'], null, 'cli');
        ok("Sauvegarde créée : storage/backups/$name");
        if (isset($args['keep']) && (int) $args['keep'] > 0) {
            foreach (array_slice(BackupService::list(), (int) $args['keep']) as $old) {
                unlink(BackupService::dir() . '/' . $old['name']);
                out('  Supprimée (rotation) : ' . $old['name']);
            }
        }
    } elseif (isset($args['list'])) {
        foreach (BackupService::list() as $b) {
            out(sprintf('  %s  %s  %s', date('Y-m-d H:i', $b['mtime']), str_pad(human_size((int) $b['size']), 10), $b['name']));
        }
    } elseif (isset($args['verify'])) {
        $path = is_file((string) $args['verify']) ? (string) $args['verify'] : BackupService::pathFor((string) $args['verify']);
        $m = BackupService::inspect($path);
        ok('Sauvegarde valide du ' . $m['created_at'] . ' — ' . ($m['tables']['persons']['rows'] ?? 0) . ' fiche(s), ' . count($m['files']) . ' fichier(s)');
    } elseif (isset($args['restore'])) {
        $path = is_file((string) $args['restore']) ? (string) $args['restore'] : BackupService::pathFor((string) $args['restore']);
        if (!isset($args['yes']) && ask('TOUTES les données actuelles seront remplacées. Saisissez RESTAURER pour confirmer') !== 'RESTAURER') {
            fail('Restauration annulée.');
            exit(1);
        }
        $res = BackupService::restore($path);
        BackupService::logRestore(basename($path), $res, null, 'cli');
        ok('Restauration effectuée (sauvegarde du ' . $res['manifest']['created_at'] . ').');
        out('  Sauvegarde de sécurité préalable : ' . $res['safety']);
    } else {
        out('Usage : php scripts/backup.php --create [--keep=N] | --list | --verify=FICHIER | --restore=FICHIER [--yes]');
        exit(1);
    }
} catch (Throwable $e) {
    fail($e->getMessage());
    exit(1);
}
