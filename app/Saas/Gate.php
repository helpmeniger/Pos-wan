<?php
declare(strict_types=1);

namespace App\Saas;

use App\Core\Request;
use App\Core\Router;
use App\Services\InstallService;

/** Aiguillage des requêtes en mode SaaS (appelé par public/index.php). */
final class Gate
{
    /** Domaine principal : site public + console. Ne revient jamais. */
    public static function central(string $path): never
    {
        $flag = STORAGE_PATH . '/cache/central_schema_' . preg_replace('/[^A-Za-z0-9_]/', '', (string) config('saas.central.database'));
        $files = count(glob(BASE_PATH . '/database/saas/*.sql') ?: []);
        if (!is_file($flag) || (int) file_get_contents($flag) !== $files) {
            if ((int) Central::value("SELECT GET_LOCK('pm_saas_migrate', 30)") === 1) {
                try {
                    Central::migrate();
                    @file_put_contents($flag, (string) $files);
                } finally {
                    Central::value("SELECT RELEASE_LOCK('pm_saas_migrate')");
                }
            }
        }
        $router = new Router();
        require BASE_PATH . '/routes/saas.php';
        $router->dispatch(Request::method(), $path);
        exit;
    }

    /** Espace client : contrôles avant les routes habituelles de POS Marwa. */
    public static function tenant(string $path): void
    {
        $t = Saas::tenant();
        $s = Subscription::state($t);
        if ($s['state'] === 'suspended') {
            Saas::halt(403, 'Espace suspendu', 'L\'espace « ' . $t['company'] . ' » est suspendu' . ($t['suspended_reason'] ? ' (' . $t['suspended_reason'] . ')' : '')
                . '. Vos données sont conservées. Contactez le service : ' . (Central::setting('operator_phone') ?: Central::setting('operator_name') ?: 'votre fournisseur POS Marwa') . '.');
        }
        if (!InstallService::isInstalled()) {
            try {
                Provisioner::finish();
            } catch (\Throwable) {
                Saas::halt(500, 'Création de l\'espace interrompue', 'La préparation de votre espace a rencontré un problème ; il a été signalé. Rechargez la page dans quelques minutes ou contactez le service.');
            }
            if (InstallService::isInstalled()) {
                flash('success', 'Votre espace POS Marwa est prêt. Connectez-vous avec l\'identifiant et le mot de passe choisis à l\'inscription.');
                redirect('/login');
            }
            Saas::halt(503, 'Espace en préparation', 'Votre espace est en cours de création. Rechargez la page dans un instant.');
        }
        // Pages sans objet en ligne : l'installation, la mise à jour des fichiers (faite par
        // l'exploitant pour tous les clients) et la clé de licence (remplacée par l'abonnement)
        if (str_starts_with($path, '/install')) {
            redirect('/');
        }
        if ($path === '/licence') {
            redirect('/abonnement');
        }
        if ($path === '/update' || str_starts_with($path, '/update/')) {
            flash('info', 'Les mises à jour de POS Marwa sont installées automatiquement par le service pour tous les clients.');
            redirect('/abonnement');
        }
    }
}
