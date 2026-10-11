<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Services\Access;
use App\Services\BackupService;

final class DashboardController extends Controller
{
    public function index(): string
    {
        $data = ['title' => 'Tableau de bord'];

        if (Auth::can('stats.view')) {
            $data['stats'] = [
                'persons_active'   => (int) Database::value("SELECT COUNT(*) FROM persons WHERE status = 'actif'"),
                'persons_archived' => (int) Database::value("SELECT COUNT(*) FROM persons WHERE status = 'archive'"),
                'visits_total'     => (int) Database::value('SELECT COUNT(*) FROM visits'),
                'visits_30d'       => (int) Database::value('SELECT COUNT(*) FROM visits WHERE visit_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)'),
                'updated_7d'       => (int) Database::value('SELECT COUNT(*) FROM persons WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'),
                'visits_open'      => (int) Database::value("SELECT COUNT(*) FROM visits WHERE closure_status = 'ouvert'"),
            ];
            // Statistiques administratives agrégées (aucune donnée individuelle).
            $data['by_service'] = Database::all(
                'SELECT service, COUNT(*) AS n FROM visits WHERE visit_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                 GROUP BY service ORDER BY n DESC LIMIT 8'
            );
            $data['by_month'] = Database::all(
                "SELECT DATE_FORMAT(visit_date, '%Y-%m') AS m, COUNT(*) AS n FROM visits
                 WHERE visit_date >= DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL 11 MONTH)
                 GROUP BY m ORDER BY m"
            );
        }

        if (Auth::can('persons.view')) {
            $data['recent'] = Database::all(
                'SELECT p.id, p.file_number, p.last_name, p.first_name, p.updated_at, p.access_level, p.status
                 FROM persons p WHERE ' . Access::personSqlFilter('p') . ' ORDER BY p.updated_at DESC LIMIT 8'
            );
        }

        if (Auth::can('audit.view') || Auth::can('users.manage')) {
            $data['alerts'] = $this->securityAlerts();
        }
        if (Auth::can('audit.view')) {
            $data['last_ops'] = Database::all(
                "SELECT created_at, username, action, entity_type, entity_id FROM audit_log
                 WHERE action NOT IN ('person.view','photo.view') ORDER BY id DESC LIMIT 10"
            );
        }
        if (Auth::can('backups.manage') || Auth::can('settings.manage')) {
            $data['db_status'] = $this->dbStatus();
        }
        return $this->view('dashboard/index', $data);
    }

    private function securityAlerts(): array
    {
        $alerts = [];
        $failed = (int) Database::value('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)');
        if ($failed > 0) {
            $alerts[] = ['level' => $failed >= 10 ? 'danger' : 'warning', 'text' => "$failed échec(s) de connexion au cours des dernières 24 h."];
        }
        $locked = (int) Database::value('SELECT COUNT(*) FROM users WHERE locked_until > NOW()');
        if ($locked > 0) {
            $alerts[] = ['level' => 'danger', 'text' => "$locked compte(s) actuellement verrouillé(s)."];
        }
        $denied = (int) Database::value("SELECT COUNT(*) FROM audit_log WHERE action IN ('access.denied','csrf.invalid') AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        if ($denied > 0) {
            $alerts[] = ['level' => 'warning', 'text' => "$denied tentative(s) d'accès refusée(s) sur 7 jours."];
        }
        $perm = (int) Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'permissions.update' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        if ($perm > 0) {
            $alerts[] = ['level' => 'info', 'text' => "Matrice des permissions modifiée $perm fois sur 30 jours (à contrôler)."];
        }
        $demo = (int) Database::value('SELECT COUNT(*) FROM users WHERE is_demo = 1 AND is_active = 1');
        if ($demo > 0 && !\App\Core\Config::isDemo()) {
            $alerts[] = ['level' => 'danger', 'text' => "$demo compte(s) de démonstration actif(s) en production."];
        }
        $lastBackup = BackupService::list()[0]['mtime'] ?? null;
        if ($lastBackup === null) {
            $alerts[] = ['level' => 'warning', 'text' => 'Aucune sauvegarde n\'a encore été réalisée.'];
        } elseif ($lastBackup < time() - 7 * 86400) {
            $alerts[] = ['level' => 'warning', 'text' => 'Dernière sauvegarde datant de plus de 7 jours.'];
        }
        if (Auth::can('audit.view')) {
            $chain = Audit::verifyChain();
            if (!$chain['ok']) {
                $alerts[] = ['level' => 'danger', 'text' => "Intégrité du journal d'audit compromise (entrée n° {$chain['broken_at']})."];
            }
        }
        return $alerts;
    }

    private function dbStatus(): array
    {
        $size = (float) Database::value(
            'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()'
        );
        return [
            'version' => (string) Database::value('SELECT VERSION()'),
            'schema'  => BackupService::schemaVersion(),
            'size_mb' => round($size / 1048576, 2),
            'last_backup' => BackupService::list()[0]['mtime'] ?? null,
        ];
    }
}
