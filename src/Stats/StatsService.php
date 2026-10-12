<?php
declare(strict_types=1);

namespace Verifia\Stats;

use Verifia\Database;
use Verifia\Queue\JobQueue;
use Verifia\Sessions\SessionService;

/** Statistiques calculées exclusivement à partir des données enregistrées. */
final class StatsService
{
    public static function overview(): array
    {
        $q = fn(string $sql) => (int) Database::value($sql);
        $services = [];
        foreach (Database::all('SELECT service, pid, host_info_json, last_seen_at FROM service_heartbeats') as $h) {
            $age = time() - strtotime($h['last_seen_at'] . ' UTC');
            $services[$h['service']] = [
                'alive' => $age < 30,
                'age_s' => $age,
                'pid'   => $h['pid'],
                'info'  => json_decode((string) $h['host_info_json'], true),
            ];
        }
        return [
            'sessions_active'      => $q("SELECT COUNT(*) FROM monitoring_sessions WHERE run_state IN ('running','starting','paused')"),
            'sessions_total'       => $q("SELECT COUNT(*) FROM monitoring_sessions WHERE desired_state <> 'closed'"),
            'sites_monitored'      => $q("SELECT COUNT(DISTINCT site_id) FROM monitoring_sessions WHERE desired_state IN ('running','paused')"),
            'publications_detected'=> $q('SELECT COUNT(*) FROM detected_publications'),
            'publications_analyzed'=> $q("SELECT COUNT(*) FROM detected_publications WHERE status = 'analyzed'"),
            'alerts_unread'        => $q("SELECT COUNT(*) FROM alerts WHERE status = 'new'"),
            'alerts_priority'      => $q("SELECT COUNT(*) FROM alerts WHERE priority IN ('high','critical') AND status NOT IN ('verified','ignored')"),
            'alerts_total'         => $q('SELECT COUNT(*) FROM alerts'),
            'image_matches'        => $q('SELECT COUNT(DISTINCT image_id) FROM image_matches'),
            'pending_verifications'=> $q("SELECT COUNT(*) FROM alerts WHERE status IN ('new','read','in_review')"),
            'navigation_errors'    => $q("SELECT COUNT(*) FROM system_events WHERE component IN ('monitor','session') AND level IN ('error','critical') AND created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR"),
            'queue'                => JobQueue::stats(),
            'capacity'             => SessionService::capacity(),
            'services'             => $services,
            'server_memory_mb'     => round(memory_get_usage(true) / 1048576, 1),
            'generated_at'         => gmdate('c'),
        ];
    }

    /** Séries horaires sur 24 h pour les graphiques. */
    public static function timeline(int $hours = 24): array
    {
        $hours = max(1, min(168, $hours));
        $series = [];
        $map = [
            'publications' => "SELECT DATE_FORMAT(first_seen_at, '%Y-%m-%d %H:00') h, COUNT(*) n FROM detected_publications WHERE first_seen_at >= UTC_TIMESTAMP() - INTERVAL $hours HOUR GROUP BY h",
            'alerts'       => "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00') h, COUNT(*) n FROM alerts WHERE created_at >= UTC_TIMESTAMP() - INTERVAL $hours HOUR GROUP BY h",
            'errors'       => "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00') h, COUNT(*) n FROM system_events WHERE level IN ('error','critical') AND created_at >= UTC_TIMESTAMP() - INTERVAL $hours HOUR GROUP BY h",
        ];
        $labels = [];
        for ($i = $hours - 1; $i >= 0; $i--) {
            $labels[] = gmdate('Y-m-d H:00', time() - $i * 3600);
        }
        foreach ($map as $k => $sql) {
            $rows = array_column(Database::all($sql), 'n', 'h');
            $series[$k] = array_map(fn($l) => (int) ($rows[$l] ?? 0), $labels);
        }
        return ['labels' => $labels, 'series' => $series];
    }
}
