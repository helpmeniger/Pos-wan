<?php
declare(strict_types=1);

namespace Verifia\Monitor;

use Verifia\Config;
use Verifia\Database;
use Verifia\EventLog;
use Verifia\Ingest\PublicationIngestor;
use Verifia\Settings;

/**
 * API interne appelée UNIQUEMENT par le moteur de surveillance local (Node/Playwright).
 * Protection : liste d'adresses autorisées (boucle locale) + jeton partagé (comparaison à temps constant).
 * Le moteur n'envoie jamais de cookies, mots de passe ou jetons des sites surveillés.
 */
final class MonitorApi
{
    private const RUN_STATES = ['stopped', 'starting', 'running', 'paused', 'error', 'closed'];
    private const CONN_STATES = ['unknown', 'ok', 'login_required', 'captcha', 'blocked', 'offline'];

    public static function authorize(?string $ip, ?string $token): bool
    {
        $allowed = (array) Config::get('monitor.allowed_ips', ['127.0.0.1', '::1']);
        $expected = (string) Config::get('monitor.token', '');
        if ($expected === '' || $expected === 'CHANGE-ME') {
            return false;
        }
        return in_array((string) $ip, $allowed, true) && is_string($token) && hash_equals($expected, $token);
    }

    public static function handle(string $action, array $in): array
    {
        return match ($action) {
            'poll'         => self::poll($in),
            'ingest'       => self::ingest($in),
            'event'        => self::event($in),
            'command_done' => self::commandDone($in),
            default        => throw new \InvalidArgumentException('Action inconnue'),
        };
    }

    /** Battement de cœur + rapport d'état des sessions ; retourne la configuration et les commandes en attente. */
    public static function poll(array $in): array
    {
        $host = is_array($in['host'] ?? null) ? $in['host'] : [];
        Database::run(
            'INSERT INTO service_heartbeats (service, pid, host_info_json, last_seen_at) VALUES (\'monitor\', ?, ?, ?)
             ON DUPLICATE KEY UPDATE pid = VALUES(pid), host_info_json = VALUES(host_info_json), last_seen_at = VALUES(last_seen_at)',
            [(int) ($host['pid'] ?? 0), json_encode($host), Database::now()]
        );
        foreach ((array) ($in['sessions'] ?? []) as $s) {
            $id = (int) ($s['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $prev = Database::one('SELECT run_state, connection_state FROM monitoring_sessions WHERE id = ?', [$id]);
            if (!$prev) {
                continue;
            }
            $run = in_array($s['run_state'] ?? '', self::RUN_STATES, true) ? $s['run_state'] : $prev['run_state'];
            $conn = in_array($s['connection_state'] ?? '', self::CONN_STATES, true) ? $s['connection_state'] : $prev['connection_state'];
            Database::run(
                'UPDATE monitoring_sessions SET run_state = ?, connection_state = ?, status_message = ?, current_url = ?, last_heartbeat_at = ? WHERE id = ?',
                [$run, $conn, mb_substr((string) ($s['status_message'] ?? ''), 0, 500) ?: null, self::url($s['current_url'] ?? null), Database::now(), $id]
            );
            if ($prev['connection_state'] !== $conn && in_array($conn, ['login_required', 'captcha', 'blocked'], true)) {
                EventLog::log('warning', 'session', 'Intervention manuelle requise : ' . $conn . ' — ' . ($s['status_message'] ?? ''), $id);
            }
        }
        // États déclarés « fermés » : plus rien à piloter
        Database::run("UPDATE monitoring_sessions SET run_state = 'closed' WHERE desired_state = 'closed' AND run_state = 'stopped'");

        $sessions = Database::all(
            "SELECT s.id, s.uid, s.name, s.start_url, s.desired_state, s.analysis_enabled, s.autoscroll_enabled,
                    s.scroll_step_px, s.scroll_delay_ms, s.processing_interval_ms, s.max_posts, s.max_duration_min,
                    s.window_x, s.window_y, s.window_w, s.window_h, s.posts_seen, ms.adapter, ms.automation_allowed
               FROM monitoring_sessions s LEFT JOIN monitored_sites ms ON ms.id = s.site_id
              WHERE s.desired_state <> 'closed' OR s.run_state NOT IN ('closed','stopped')"
        );
        $commands = Database::all("SELECT id, session_id, command, payload FROM session_commands WHERE status = 'pending' ORDER BY id LIMIT 100");
        if ($commands) {
            $ids = array_column($commands, 'id');
            Database::run(
                "UPDATE session_commands SET status = 'delivered', delivered_at = ? WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')',
                array_merge([Database::now()], $ids)
            );
        }
        Settings::clearCache();
        return [
            'emergency_stop' => Settings::get('emergency_stop') === '1',
            'max_sessions'   => Settings::int('max_concurrent_sessions', 4),
            'session_memory_mb' => Settings::int('session_memory_mb', 450),
            'memory_reserve_mb' => Settings::int('memory_reserve_mb', 1500),
            'session_previews' => Settings::get('session_previews', '1') === '1',
            'sessions'       => array_map(function ($s) {
                foreach (['id', 'analysis_enabled', 'autoscroll_enabled', 'scroll_step_px', 'scroll_delay_ms', 'processing_interval_ms', 'max_posts', 'max_duration_min', 'posts_seen', 'automation_allowed'] as $k) {
                    $s[$k] = (int) $s[$k];
                }
                return $s;
            }, $sessions),
            'commands'       => array_map(fn($c) => ['id' => (int) $c['id'], 'session_id' => (int) $c['session_id'], 'command' => $c['command'], 'payload' => $c['payload'] ? json_decode($c['payload'], true) : null], $commands),
        ];
    }

    public static function ingest(array $in): array
    {
        $sid = (int) ($in['session_id'] ?? 0);
        $session = Database::one('SELECT id, analysis_enabled, desired_state FROM monitoring_sessions WHERE id = ?', [$sid]);
        if (!$session) {
            throw new \InvalidArgumentException('Session inconnue');
        }
        if (!(int) $session['analysis_enabled']) {
            return ['accepted' => 0, 'results' => [], 'note' => 'Analyse désactivée pour cette session'];
        }
        $out = [];
        foreach (array_slice((array) ($in['posts'] ?? []), 0, 50) as $post) {
            if (!is_array($post)) {
                continue;
            }
            try {
                $r = PublicationIngestor::ingest($post, $sid, self::url($in['page_url'] ?? null), 'monitor');
            } catch (\Throwable $e) {
                EventLog::log('error', 'ingest', 'Échec d\'enregistrement d\'une publication : ' . $e->getMessage(), $sid);
                // « error » : le moteur ne marque pas la publication comme vue et la renverra au cycle suivant.
                $r = ['status' => 'error', 'reason' => 'Erreur serveur temporaire'];
            }
            $out[] = ['key' => $post['key'] ?? null] + $r;
        }
        return ['accepted' => count(array_filter($out, fn($r) => $r['status'] === 'created')), 'results' => $out];
    }

    public static function event(array $in): array
    {
        $level = in_array($in['level'] ?? '', ['debug', 'info', 'warning', 'error', 'critical'], true) ? $in['level'] : 'info';
        $sid = isset($in['session_id']) && Database::value('SELECT id FROM monitoring_sessions WHERE id = ?', [(int) $in['session_id']]) ? (int) $in['session_id'] : null;
        EventLog::log($level, 'monitor', (string) ($in['message'] ?? ''), $sid, is_array($in['context'] ?? null) ? $in['context'] : []);
        return ['ok' => true];
    }

    public static function commandDone(array $in): array
    {
        Database::run('UPDATE session_commands SET status = ? WHERE id = ?', [!empty($in['ok']) ? 'done' : 'failed', (int) ($in['id'] ?? 0)]);
        return ['ok' => true];
    }

    private static function url(mixed $u): ?string
    {
        return is_string($u) && preg_match('#^https?://#i', $u) ? substr($u, 0, 2048) : null;
    }
}
