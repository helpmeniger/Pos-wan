<?php
/**
 * Planificateur MARWA CONNECT — à exécuter CHAQUE MINUTE.
 *
 * Windows (Planificateur de tâches) :
 *   Programme : C:\wamp64\bin\php\php8.x.x\php.exe
 *   Arguments : C:\wamp64\www\marwa-connect\cli\scheduler.php
 *   Déclencheur : quotidien, répéter toutes les 1 minute pendant 1 jour.
 * Linux (cron) : * * * * * php /chemin/marwa-connect/cli/scheduler.php
 *
 * Tâches : surveillance et synchronisation des routeurs (selon leur intervalle), relevé des
 * sessions, usage et expiration des tickets, re-poussée des tickets en attente, alertes,
 * commandes en ligne expirées, sauvegarde quotidienne. Options : --force (tous les routeurs).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}
require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\App;
use App\Core\Database;
use App\Core\Logger;
use App\Services\BackupService;
use App\Services\SyncService;
use App\Services\TicketService;

if (!App::installed()) {
    fwrite(STDERR, "Application non installée.\n");
    exit(1);
}
// Verrou : une seule exécution à la fois.
$lock = fopen(STORAGE_PATH . '/cache/scheduler.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Planificateur déjà en cours.\n";
    exit(0);
}
@set_time_limit(0);
$start = microtime(true);
$force = in_array('--force', $argv, true);
$out = static fn (string $m) => print('[' . date('H:i:s') . "] {$m}\n");

$routers = $force ? Database::fetchAll("SELECT * FROM routers WHERE deleted_at IS NULL AND status = 'active'") : SyncService::dueRouters();
foreach ($routers as $r) {
    $res = SyncService::syncRouter($r, null, 'scheduled');
    $out(($res['ok'] ? 'OK ' : 'ÉCHEC ') . $r['name'] . ' : ' . $res['message']);
    \App\Services\MikroTik\RouterService::close((int) $r['id']);
}
$n = TicketService::expireDue();
if ($n) {
    $out("{$n} ticket(s) expiré(s).");
}
try {
    SyncService::housekeeping();
} catch (Throwable $e) {
    Logger::error('Planificateur (maintenance) : ' . $e->getMessage(), [], 'scheduler');
}
if (setting('backup.auto_daily', true)) {
    $last = Database::value("SELECT MAX(created_at) FROM backups WHERE trigger_type = 'scheduled'");
    if (!$last || strtotime((string) $last) < strtotime('today 02:00') && (int) date('G') >= 2) {
        try {
            $b = BackupService::create('scheduled');
            $out('Sauvegarde quotidienne : ' . basename($b['file']));
        } catch (Throwable $e) {
            Logger::error('Sauvegarde planifiée : ' . $e->getMessage(), [], 'scheduler');
        }
    }
}
file_put_contents(STORAGE_PATH . '/cache/scheduler.last', date('c'));
$out(sprintf('Terminé en %.1f s.', microtime(true) - $start));
flock($lock, LOCK_UN);
