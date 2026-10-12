<?php
declare(strict_types=1);

namespace Verifia\Sessions;

use Verifia\Database;
use Verifia\EventLog;
use Verifia\Settings;

/**
 * Gestion des sessions de surveillance.
 * L'interface modifie l'état DEMANDÉ (desired_state) ; le moteur Node/Playwright
 * lit cet état, agit sur le navigateur et rapporte l'état RÉEL (run_state).
 */
final class SessionService
{
    public const EDITABLE = [
        'name', 'start_url', 'analysis_enabled', 'autoscroll_enabled', 'scroll_step_px', 'scroll_delay_ms',
        'processing_interval_ms', 'max_posts', 'max_duration_min', 'window_x', 'window_y', 'window_w', 'window_h',
    ];

    public static function validateUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            throw new \InvalidArgumentException('URL invalide : seules les adresses http(s) sont acceptées.');
        }
        return $url;
    }

    public static function siteFor(string $url): int
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $domain = preg_replace('/^(www|m|mobile|web)\./', '', $host);
        $id = Database::value('SELECT id FROM monitored_sites WHERE domain = ?', [$domain]);
        if ($id) {
            return (int) $id;
        }
        $adapter = match (true) {
            str_contains($domain, 'facebook.com') => 'facebook',
            $domain === 'x.com' || str_contains($domain, 'twitter.com') => 'x',
            default => 'generic',
        };
        Database::run('INSERT IGNORE INTO monitored_sites (domain, name, adapter) VALUES (?, ?, ?)', [$domain, $domain, $adapter]);
        return (int) Database::value('SELECT id FROM monitored_sites WHERE domain = ?', [$domain]);
    }

    public static function create(array $data, ?int $userId): int
    {
        $url = self::validateUrl((string) ($data['start_url'] ?? ''));
        $name = trim((string) ($data['name'] ?? '')) ?: (string) parse_url($url, PHP_URL_HOST);
        $row = [
            'uid'        => self::uuid(),
            'site_id'    => self::siteFor($url),
            'name'       => mb_substr($name, 0, 120),
            'start_url'  => $url,
            'created_by' => $userId,
        ];
        $row += self::sanitize($data, ['name', 'start_url']);
        $id = Database::insert('monitoring_sessions', $row);
        EventLog::log('info', 'session', "Session « {$row['name']} » créée ($url)", $id);
        EventLog::audit($userId, 'session_create', 'session', $id, ['url' => $url]);
        return $id;
    }

    public static function update(int $id, array $data, ?int $userId): void
    {
        $fields = self::sanitize($data);
        if (isset($data['start_url'])) {
            $fields['start_url'] = self::validateUrl((string) $data['start_url']);
            $fields['site_id'] = self::siteFor($fields['start_url']);
        }
        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new \InvalidArgumentException('Le nom ne peut pas être vide.');
            }
            $fields['name'] = mb_substr($name, 0, 120);
        }
        if (!$fields) {
            return;
        }
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
        Database::run("UPDATE monitoring_sessions SET $set WHERE id = :id", $fields + ['id' => $id]);
        // Le moteur relit la configuration à chaque cycle ; une commande force la prise en compte immédiate.
        self::command($id, 'reload_config', null, $userId);
        EventLog::audit($userId, 'session_update', 'session', $id, $fields);
    }

    /** Filtre et borne les paramètres numériques. */
    private static function sanitize(array $d, array $skip = []): array
    {
        $out = [];
        $ranges = [
            'scroll_step_px' => [50, 5000], 'scroll_delay_ms' => [500, 600000], 'processing_interval_ms' => [500, 600000],
            'max_posts' => [1, 100000], 'max_duration_min' => [1, 10080],
            'window_x' => [-20000, 20000], 'window_y' => [-20000, 20000], 'window_w' => [320, 10000], 'window_h' => [240, 10000],
        ];
        foreach ($ranges as $k => [$min, $max]) {
            if (!in_array($k, $skip, true) && array_key_exists($k, $d)) {
                $out[$k] = ($d[$k] === '' || $d[$k] === null) && str_starts_with($k, 'window_')
                    ? null
                    : max($min, min($max, (int) $d[$k]));
            }
        }
        foreach (['analysis_enabled', 'autoscroll_enabled'] as $k) {
            if (array_key_exists($k, $d)) {
                $out[$k] = filter_var($d[$k], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }
        return $out;
    }

    public static function get(int $id): ?array
    {
        return Database::one(
            'SELECT s.*, ms.domain, ms.adapter, ms.automation_allowed FROM monitoring_sessions s
               LEFT JOIN monitored_sites ms ON ms.id = s.site_id WHERE s.id = ?',
            [$id]
        );
    }

    public static function list(bool $includeClosed = false): array
    {
        return Database::all(
            'SELECT s.*, ms.domain, ms.adapter FROM monitoring_sessions s LEFT JOIN monitored_sites ms ON ms.id = s.site_id '
            . ($includeClosed ? '' : "WHERE s.desired_state <> 'closed' ")
            . 'ORDER BY s.id'
        );
    }

    /**
     * Applique une action utilisateur. Retourne [ok, message].
     * Actions : start, pause, resume, stop, close, restart, scan_once, analysis_on, analysis_off, open_window
     */
    public static function action(int $id, string $action, ?int $userId): array
    {
        $s = self::get($id);
        if (!$s) {
            return [false, 'Session introuvable.'];
        }
        if ($s['desired_state'] === 'closed' && $action !== 'reopen') {
            return [false, 'Session fermée.'];
        }
        if (in_array($action, ['start', 'resume', 'restart'], true) && Settings::get('emergency_stop') === '1') {
            return [false, "L'arrêt immédiat est actif. Levez-le depuis le tableau de bord avant de redémarrer."];
        }
        switch ($action) {
            case 'start':
            case 'restart':
                if ($s['desired_state'] !== 'running') {
                    [$ok, $msg] = self::capacityCheck();
                    if (!$ok) {
                        return [false, $msg];
                    }
                }
                self::setDesired($id, 'running');
                if ($action === 'restart') {
                    self::command($id, 'restart', null, $userId);
                }
                Database::run('UPDATE monitoring_sessions SET started_at = ? WHERE id = ?', [Database::now(), $id]);
                break;
            case 'pause':
                self::setDesired($id, 'paused');
                break;
            case 'resume':
                self::setDesired($id, 'running');
                break;
            case 'stop':
                self::setDesired($id, 'stopped');
                break;
            case 'close':
                self::setDesired($id, 'closed');
                Database::run('UPDATE monitoring_sessions SET closed_at = ? WHERE id = ?', [Database::now(), $id]);
                break;
            case 'reopen':
                Database::run("UPDATE monitoring_sessions SET desired_state = 'stopped', run_state = 'stopped', closed_at = NULL WHERE id = ?", [$id]);
                break;
            case 'scan_once':
            case 'open_window':
            case 'focus':
                if ($s['run_state'] === 'stopped' || $s['run_state'] === 'closed') {
                    return [false, "Le navigateur de cette session n'est pas ouvert. Démarrez-la d'abord."];
                }
                self::command($id, $action, null, $userId);
                break;
            case 'analysis_on':
            case 'analysis_off':
                Database::run('UPDATE monitoring_sessions SET analysis_enabled = ? WHERE id = ?', [$action === 'analysis_on' ? 1 : 0, $id]);
                break;
            case 'autoscroll_on':
            case 'autoscroll_off':
                Database::run('UPDATE monitoring_sessions SET autoscroll_enabled = ? WHERE id = ?', [$action === 'autoscroll_on' ? 1 : 0, $id]);
                break;
            default:
                return [false, 'Action inconnue.'];
        }
        EventLog::log('info', 'session', "Action « $action » demandée", $id);
        EventLog::audit($userId, 'session_' . $action, 'session', $id);
        return [true, 'Action transmise au moteur de surveillance.'];
    }

    private static function setDesired(int $id, string $state): void
    {
        Database::run('UPDATE monitoring_sessions SET desired_state = ? WHERE id = ?', [$state, $id]);
    }

    public static function command(int $sessionId, string $command, ?array $payload, ?int $userId): void
    {
        Database::insert('session_commands', [
            'session_id' => $sessionId,
            'command'    => $command,
            'payload'    => $payload ? json_encode($payload) : null,
            'created_by' => $userId,
        ]);
    }

    /**
     * Capacité : limite configurée ET mémoire libre rapportée par le moteur.
     * Le nombre de navigateurs n'est jamais illimité.
     */
    public static function capacity(): array
    {
        $max = Settings::int('max_concurrent_sessions', 4);
        $active = (int) Database::value("SELECT COUNT(*) FROM monitoring_sessions WHERE desired_state IN ('running','paused')");
        $hb = Database::one("SELECT host_info_json, last_seen_at FROM service_heartbeats WHERE service = 'monitor'");
        $memLimit = null;
        $host = null;
        if ($hb && strtotime($hb['last_seen_at'] . ' UTC') > time() - 30) {
            $host = json_decode((string) $hb['host_info_json'], true) ?: null;
            if (isset($host['free_mem_mb'])) {
                $per = max(100, Settings::int('session_memory_mb', 450));
                $reserve = Settings::int('memory_reserve_mb', 1500);
                $openBrowsers = (int) ($host['open_browsers'] ?? 0);
                $memLimit = $openBrowsers + intdiv(max(0, (int) $host['free_mem_mb'] - $reserve), $per);
            }
        }
        $limit = $memLimit === null ? $max : min($max, $memLimit);
        return ['configured_max' => $max, 'memory_limit' => $memLimit, 'limit' => $limit, 'active' => $active, 'host' => $host];
    }

    public static function capacityCheck(): array
    {
        $c = self::capacity();
        if ($c['active'] >= $c['limit']) {
            $why = $c['memory_limit'] !== null && $c['memory_limit'] < $c['configured_max']
                ? "mémoire libre insuffisante (limite calculée : {$c['memory_limit']})"
                : "limite configurée de {$c['configured_max']} sessions simultanées";
            return [false, "Capacité atteinte : {$c['active']} session(s) active(s), $why. Arrêtez une session ou ajustez les paramètres."];
        }
        return [true, null];
    }

    /** Arrêt immédiat global : toutes les sessions passent à « stopped », le moteur ferme les navigateurs. */
    public static function emergencyStop(?int $userId): int
    {
        Settings::set('emergency_stop', '1');
        $n = Database::run("UPDATE monitoring_sessions SET desired_state = 'stopped' WHERE desired_state IN ('running','paused')")->rowCount();
        EventLog::log('critical', 'web', "ARRÊT IMMÉDIAT déclenché : $n session(s) arrêtée(s)");
        EventLog::audit($userId, 'emergency_stop', null, null, ['sessions' => $n]);
        return $n;
    }

    public static function releaseEmergencyStop(?int $userId): void
    {
        Settings::set('emergency_stop', '0');
        EventLog::log('info', 'web', 'Arrêt immédiat levé');
        EventLog::audit($userId, 'emergency_release');
    }

    public static function errors(int $id, int $limit = 50): array
    {
        return Database::all(
            "SELECT level, component, message, created_at FROM system_events WHERE session_id = ? ORDER BY id DESC LIMIT " . (int) $limit,
            [$id]
        );
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
