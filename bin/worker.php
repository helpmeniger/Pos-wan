<?php
declare(strict_types=1);
/**
 * Moteur d'analyse en arrière-plan (processus indépendant du serveur web et du navigateur).
 *   php bin/worker.php            boucle continue
 *   php bin/worker.php --once     traite les tâches disponibles puis s'arrête
 * Arrêt propre : créer storage/run/worker.stop (fait par stop.bat).
 */
require dirname(__DIR__) . '/src/bootstrap.php';

use Verifia\Analysis\AnalysisEngine;
use Verifia\Config;
use Verifia\Database;
use Verifia\EventLog;
use Verifia\Queue\JobQueue;

$once = in_array('--once', $argv, true);
$workerId = 'worker-' . gethostname() . '-' . getmypid();
$stopFile = Config::storagePath('run/worker.stop');
$engine = new AnalysisEngine();
$lastRecover = 0;
$lastBeat = 0;
$processed = 0;

EventLog::log('info', 'worker', "Moteur d'analyse démarré ($workerId)");
fwrite(STDOUT, "[VERIFIA] Moteur d'analyse démarré ($workerId)\n");

while (true) {
    if (is_file($stopFile)) {
        @unlink($stopFile);
        break;
    }
    try {
        if (time() - $lastBeat >= 5) {
            Database::run(
                'INSERT INTO service_heartbeats (service, pid, host_info_json, last_seen_at) VALUES (\'worker\', ?, ?, ?)
                 ON DUPLICATE KEY UPDATE pid = VALUES(pid), host_info_json = VALUES(host_info_json), last_seen_at = VALUES(last_seen_at)',
                [getmypid(), json_encode(['processed' => $processed, 'memory_mb' => round(memory_get_usage(true) / 1048576, 1)]), Database::now()]
            );
            $lastBeat = time();
        }
        if (time() - $lastRecover >= 60) {
            if ($n = JobQueue::recoverStale()) {
                EventLog::log('warning', 'worker', "$n tâche(s) interrompue(s) remise(s) en file");
            }
            $lastRecover = time();
        }
        $job = JobQueue::claim($workerId);
    } catch (\PDOException $e) {
        fwrite(STDERR, '[VERIFIA] Base de données indisponible : ' . $e->getMessage() . "\n");
        Database::reset();
        sleep(5);
        continue;
    }
    if (!$job) {
        if ($once) {
            break;
        }
        usleep(500_000);
        continue;
    }
    $t0 = microtime(true);
    try {
        $r = $engine->analyze((int) $job['publication_id']);
        JobQueue::complete((int) $job['id']);
        $processed++;
        fwrite(STDOUT, sprintf("[%s] publication #%d : %s (score %d)%s en %d ms\n", gmdate('H:i:s'), $job['publication_id'], $r['priority'], $r['score'], $r['alert_id'] ? " → alerte #{$r['alert_id']}" : '', (microtime(true) - $t0) * 1000));
    } catch (\Throwable $e) {
        $outcome = JobQueue::fail($job, $e->getMessage());
        fwrite(STDERR, "[VERIFIA] Échec tâche #{$job['id']} ($outcome) : {$e->getMessage()}\n");
    }
}
EventLog::log('info', 'worker', "Moteur d'analyse arrêté ($workerId, $processed tâches)");
fwrite(STDOUT, "[VERIFIA] Moteur d'analyse arrêté.\n");
