<?php
/**
 * Modules complémentaires en ligne de commande.
 *   php bin/addons.php list
 *   php bin/addons.php install <code>     (sauvegarde + installation / mise à jour)
 *   php bin/addons.php enable <code>
 *   php bin/addons.php disable <code>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Addons;

[$cmd, $code] = [$argv[1] ?? 'list', $argv[2] ?? ''];
try {
    switch ($cmd) {
        case 'list':
            foreach (Addons::discover() as $c => $m) {
                printf("%-24s %-10s %-24s %s\n", $c, $m['version'] ?? '?', Addons::status($c), $m['errors'] ? implode(' ', $m['errors']) : ($m['name'] ?? ''));
            }
            if (!Addons::discover()) {
                echo "Aucun module dans " . Addons::dir() . "\n";
            }
            break;
        case 'install':
            $applied = Addons::install($code);
            echo "Module « $code » installé / mis à jour." . ($applied ? ' Migrations : ' . implode(', ', $applied) : '') . "\n";
            break;
        case 'enable':
        case 'disable':
            Addons::setActive($code, $cmd === 'enable');
            echo "Module « $code » " . ($cmd === 'enable' ? 'activé' : 'désactivé') . ".\n";
            break;
        default:
            fwrite(STDERR, "Commandes : list | install <code> | enable <code> | disable <code>\n");
            exit(1);
    }
} catch (App\Core\BusinessException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
