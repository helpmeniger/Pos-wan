<?php
declare(strict_types=1);

namespace Verifia\Queue;

use Verifia\Database;
use Verifia\EventLog;

/**
 * File de tâches persistante en base (aucun service externe requis sous WampServer).
 * - priorité : 1 = critique ... 9 = faible ; à priorité égale, ordre d'arrivée ;
 * - réservation atomique par UPDATE ... ORDER BY ... LIMIT 1 (sûr avec plusieurs workers) ;
 * - nouvelles tentatives avec délai exponentiel, puis statut « failed ».
 */
final class JobQueue
{
    public const STALE_LOCK_SECONDS = 600;

    public static function push(int $publicationId, int $priority = 5, string $type = 'full_analysis', int $maxAttempts = 3): int
    {
        return Database::insert('analysis_jobs', [
            'publication_id' => $publicationId,
            'job_type'       => $type,
            'priority'       => max(1, min(9, $priority)),
            'max_attempts'   => $maxAttempts,
            'available_at'   => Database::now(),
        ]);
    }

    /** Réserve la prochaine tâche disponible ou retourne null. */
    public static function claim(string $workerId): ?array
    {
        $lock = substr($workerId, 0, 40) . ':' . bin2hex(random_bytes(8));
        $n = Database::run(
            "UPDATE analysis_jobs
                SET status = 'running', locked_by = ?, locked_at = ?, attempts = attempts + 1
              WHERE status = 'pending' AND available_at <= ?
              ORDER BY priority ASC, id ASC
              LIMIT 1",
            [$lock, Database::now(), Database::now()]
        )->rowCount();
        if ($n === 0) {
            return null;
        }
        return Database::one("SELECT * FROM analysis_jobs WHERE locked_by = ? AND status = 'running'", [$lock]);
    }

    public static function complete(int $jobId): void
    {
        Database::run("UPDATE analysis_jobs SET status = 'done', finished_at = ?, locked_by = NULL WHERE id = ?", [Database::now(), $jobId]);
    }

    public static function fail(array $job, string $error): string
    {
        $attempts = (int) $job['attempts'];
        if ($attempts < (int) $job['max_attempts']) {
            $delay = 15 * (2 ** ($attempts - 1)); // 15 s, 30 s, 60 s...
            Database::run(
                "UPDATE analysis_jobs SET status = 'pending', locked_by = NULL, last_error = ?, available_at = ? WHERE id = ?",
                [mb_substr($error, 0, 2000), gmdate('Y-m-d H:i:s', time() + $delay), $job['id']]
            );
            return 'retry';
        }
        Database::run(
            "UPDATE analysis_jobs SET status = 'failed', locked_by = NULL, last_error = ?, finished_at = ? WHERE id = ?",
            [mb_substr($error, 0, 2000), Database::now(), $job['id']]
        );
        Database::run("UPDATE detected_publications SET status = 'failed' WHERE id = ?", [$job['publication_id']]);
        EventLog::log('error', 'worker', "Analyse abandonnée après $attempts tentatives (publication #{$job['publication_id']})", null, ['error' => $error]);
        return 'failed';
    }

    /** Remet en file les tâches dont le worker a disparu (panne, arrêt brutal). */
    public static function recoverStale(): int
    {
        return Database::run(
            "UPDATE analysis_jobs SET status = 'pending', locked_by = NULL, last_error = 'Verrou expiré (worker interrompu)'
              WHERE status = 'running' AND locked_at < ?",
            [gmdate('Y-m-d H:i:s', time() - self::STALE_LOCK_SECONDS)]
        )->rowCount();
    }

    public static function stats(): array
    {
        $rows = Database::all('SELECT status, COUNT(*) n FROM analysis_jobs GROUP BY status');
        $out = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
        foreach ($rows as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }
}
