<?php
declare(strict_types=1);

/**
 * Tâches de fond : attribution (expirations, vagues suivantes), relance des messages,
 * purge des positions GPS et des données techniques.
 *   php bin/worker.php --once      (à planifier chaque minute : cron / Planificateur de tâches Windows)
 *   php bin/worker.php             (boucle continue, toutes les 10 s)
 */
require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Database;
use App\Services\DispatchService;
use App\Services\NotificationService;
use App\Services\TrackingService;

$once = in_array('--once', $argv, true);
$lastPurge = 0;
do {
    try {
        $stats = DispatchService::tick();
        foreach (Database::select("SELECT id FROM outbound_messages WHERE status = 'queued' AND attempts < 5 ORDER BY id LIMIT 50") as $m) {
            NotificationService::deliver((int) $m['id']);
        }
        if (time() - $lastPurge > 3600) {
            $purged = TrackingService::purgeHistory();
            Database::execute('DELETE FROM rate_limits WHERE window_start < :t', ['t' => time() - 86400]);
            Database::execute('DELETE FROM realtime_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)');
            Database::execute('DELETE FROM api_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
            Database::execute('DELETE FROM otp_codes WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
            $lastPurge = time();
            echo date('d/m/Y H:i:s') . " purge : $purged positions GPS supprimées\n";
        }
        if (array_sum($stats) > 0) {
            echo date('d/m/Y H:i:s') . ' ' . json_encode($stats) . "\n";
        }
    } catch (Throwable $e) {
        App\Core\Logger::error('Worker : ' . $e->getMessage());
        fwrite(STDERR, $e->getMessage() . "\n");
        Database::setPdo(null);
    }
    if (!$once) {
        sleep(10);
    }
} while (!$once);
