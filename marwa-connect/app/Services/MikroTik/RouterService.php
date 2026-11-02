<?php
declare(strict_types=1);

namespace App\Services\MikroTik;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;
use App\Services\NotificationService;
use Throwable;

/**
 * Opérations métier sur un routeur MikroTik (lecture d'état, Hotspot, synchronisation).
 * Les identifiants API ne quittent jamais le serveur.
 */
final class RouterService
{
    /** @var array<int, RouterOSClient> connexions ouvertes pendant la requête */
    private static array $pool = [];

    public static function find(int $id): ?array
    {
        return Database::fetch('SELECT * FROM routers WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    public static function client(array $router): RouterOSClient
    {
        $id = (int) $router['id'];
        if (isset(self::$pool[$id])) {
            return self::$pool[$id];
        }
        $port = (int) ($router['use_ssl'] ? $router['api_ssl_port'] : $router['api_port']);
        $timeout = (int) setting('mikrotik.timeout_seconds', config('mikrotik.connect_timeout', 5));
        $client = new RouterOSClient((string) $router['host'], $port, (bool) $router['use_ssl'], max(2, $timeout), (int) config('mikrotik.read_timeout', 10));
        $client->connect((string) $router['api_username'], Crypto::decrypt((string) $router['api_password_enc']));
        return self::$pool[$id] = $client;
    }

    public static function close(?int $routerId = null): void
    {
        foreach (self::$pool as $id => $c) {
            if ($routerId === null || $routerId === $id) {
                $c->disconnect();
                unset(self::$pool[$id]);
            }
        }
    }

    /** Test de connexion avec des paramètres non encore enregistrés. */
    public static function testCredentials(string $host, int $port, bool $ssl, string $user, string $password): array
    {
        $start = microtime(true);
        $c = new RouterOSClient($host, $port, $ssl, 5, 10);
        $c->connect($user, $password);
        $info = self::readInfo($c);
        $c->disconnect();
        $info['latency_ms'] = (int) round((microtime(true) - $start) * 1000);
        return $info;
    }

    public static function readInfo(RouterOSClient $c): array
    {
        $res = $c->query('/system/resource/print')[0] ?? [];
        $identity = $c->query('/system/identity/print')[0]['name'] ?? null;
        $rb = [];
        try {
            $rb = $c->query('/system/routerboard/print')[0] ?? [];
        } catch (RouterOSException) {
            // CHR / x86 : pas de routerboard.
        }
        $total = (int) ($res['total-memory'] ?? 0);
        $free = (int) ($res['free-memory'] ?? 0);
        return [
            'identity' => $identity,
            'version' => $res['version'] ?? null,
            'board_name' => $res['board-name'] ?? null,
            'model' => $rb['model'] ?? ($res['board-name'] ?? null),
            'serial' => $rb['serial-number'] ?? null,
            'architecture' => $res['architecture-name'] ?? null,
            'cpu' => $res['cpu'] ?? null,
            'cpu_count' => (int) ($res['cpu-count'] ?? 1),
            'cpu_load' => (int) ($res['cpu-load'] ?? 0),
            'free_memory' => $free,
            'total_memory' => $total,
            'memory_used_pct' => $total > 0 ? (int) round(100 - $free * 100 / $total) : null,
            'free_hdd' => (int) ($res['free-hdd-space'] ?? 0),
            'total_hdd' => (int) ($res['total-hdd-space'] ?? 0),
            'uptime' => RouterOSClient::parseDuration($res['uptime'] ?? ''),
        ];
    }

    public static function info(array $router): array
    {
        return self::readInfo(self::client($router));
    }

    public static function interfaces(array $router): array
    {
        return self::client($router)->query('/interface/print', ['.proplist' => '.id,name,type,mac-address,mtu,running,disabled,rx-byte,tx-byte,comment']);
    }

    public static function traffic(array $router, string $interface): array
    {
        $r = self::client($router)->query('/interface/monitor-traffic', ['interface' => $interface, 'once' => '']);
        $row = $r[0] ?? [];
        return ['rx_bps' => (int) ($row['rx-bits-per-second'] ?? 0), 'tx_bps' => (int) ($row['tx-bits-per-second'] ?? 0)];
    }

    public static function activeSessions(array $router): array
    {
        return self::client($router)->query('/ip/hotspot/active/print', ['.proplist' => '.id,server,user,address,mac-address,login-by,uptime,session-time-left,idle-time,bytes-in,bytes-out']);
    }

    public static function hotspotUsers(array $router): array
    {
        return self::client($router)->query('/ip/hotspot/user/print', ['.proplist' => '.id,name,profile,uptime,bytes-in,bytes-out,limit-uptime,limit-bytes-total,disabled,comment,mac-address']);
    }

    public static function profiles(array $router): array
    {
        return self::client($router)->query('/ip/hotspot/user/profile/print');
    }

    public static function hotspotServers(array $router): array
    {
        try {
            return self::client($router)->query('/ip/hotspot/print', ['.proplist' => '.id,name,interface,profile,disabled']);
        } catch (RouterOSException) {
            return [];
        }
    }

    public static function logs(array $router, int $limit = 100): array
    {
        $rows = self::client($router)->query('/log/print', ['.proplist' => 'time,topics,message']);
        return array_reverse(array_slice($rows, -$limit));
    }

    public static function disconnectActive(array $router, string $activeId): void
    {
        self::client($router)->exec('/ip/hotspot/active/remove', ['.id' => $activeId]);
    }

    /** Déconnecte toutes les sessions d'un utilisateur Hotspot. */
    public static function kickUser(array $router, string $username): int
    {
        $c = self::client($router);
        $n = 0;
        foreach ($c->query('/ip/hotspot/active/print', ['.proplist' => '.id'], ['user' => $username]) as $a) {
            $c->exec('/ip/hotspot/active/remove', ['.id' => $a['.id']]);
            $n++;
        }
        return $n;
    }

    public static function setUserDisabled(array $router, string $username, bool $disabled): void
    {
        $c = self::client($router);
        $u = $c->query('/ip/hotspot/user/print', ['.proplist' => '.id'], ['name' => $username])[0] ?? null;
        if (!$u) {
            throw new RouterOSException("Utilisateur « {$username} » absent du routeur.");
        }
        $c->exec('/ip/hotspot/user/set', ['.id' => $u['.id'], 'disabled' => $disabled ? 'yes' : 'no']);
        if ($disabled) {
            self::kickUser($router, $username);
        }
    }

    public static function setUserMac(array $router, string $username, string $mac): void
    {
        $c = self::client($router);
        $u = $c->query('/ip/hotspot/user/print', ['.proplist' => '.id'], ['name' => $username])[0] ?? null;
        if ($u) {
            $c->exec('/ip/hotspot/user/set', ['.id' => $u['.id'], 'mac-address' => $mac]);
        }
    }

    public static function removeUser(array $router, string $username): void
    {
        $c = self::client($router);
        foreach ($c->query('/ip/hotspot/user/print', ['.proplist' => '.id'], ['name' => $username]) as $u) {
            $c->exec('/ip/hotspot/user/remove', ['.id' => $u['.id']]);
        }
    }

    public static function reboot(array $router): void
    {
        $c = self::client($router);
        try {
            $c->exec('/system/reboot');
        } catch (RouterOSException) {
            // Le routeur coupe la connexion en redémarrant : comportement normal.
        }
        self::close((int) $router['id']);
    }

    /**
     * Crée ou met à jour le profil Hotspot d'une offre sur le routeur.
     */
    public static function ensureProfile(array $router, array $offer): string
    {
        $name = $offer['mikrotik_profile'] ?: ('MC-' . $offer['code']);
        $attrs = ['shared-users' => (string) max(1, (int) $offer['shared_users'])];
        if ($offer['rate_up'] || $offer['rate_down']) {
            // Format RouterOS : rx/tx vu du routeur = envoi client / réception client.
            $attrs['rate-limit'] = ($offer['rate_up'] ?: '0') . '/' . ($offer['rate_down'] ?: '0');
        }
        $c = self::client($router);
        $existing = $c->query('/ip/hotspot/user/profile/print', ['.proplist' => '.id,name'], ['name' => $name])[0] ?? null;
        if ($existing) {
            $c->exec('/ip/hotspot/user/profile/set', ['.id' => $existing['.id']] + $attrs);
        } else {
            $c->exec('/ip/hotspot/user/profile/add', ['name' => $name] + $attrs);
        }
        Database::query(
            'INSERT INTO hotspot_profiles (router_id, name, rate_limit, shared_users, managed_by_app, synced_at)
             VALUES (:r, :n, :rl, :su, 1, NOW())
             ON DUPLICATE KEY UPDATE rate_limit = VALUES(rate_limit), shared_users = VALUES(shared_users), managed_by_app = 1, synced_at = NOW()',
            ['r' => $router['id'], 'n' => $name, 'rl' => $attrs['rate-limit'] ?? null, 'su' => (int) $attrs['shared-users']]
        );
        Database::query('UPDATE offer_router SET hotspot_profile = :p, profile_synced_at = NOW() WHERE offer_id = :o AND router_id = :r',
            ['p' => $name, 'o' => $offer['id'], 'r' => $router['id']]);
        return $name;
    }

    /** Synchronise les profils Hotspot existants du routeur dans la base. */
    public static function syncProfiles(array $router): int
    {
        $rows = self::profiles($router);
        foreach ($rows as $p) {
            Database::query(
                'INSERT INTO hotspot_profiles (router_id, ros_id, name, rate_limit, shared_users, session_timeout, idle_timeout, keepalive_timeout, address_pool, synced_at)
                 VALUES (:r, :id, :n, :rl, :su, :st, :it, :kt, :ap, NOW())
                 ON DUPLICATE KEY UPDATE ros_id = VALUES(ros_id), rate_limit = VALUES(rate_limit), shared_users = VALUES(shared_users),
                    session_timeout = VALUES(session_timeout), idle_timeout = VALUES(idle_timeout), keepalive_timeout = VALUES(keepalive_timeout),
                    address_pool = VALUES(address_pool), synced_at = NOW()',
                ['r' => $router['id'], 'id' => $p['.id'] ?? null, 'n' => $p['name'] ?? '?', 'rl' => $p['rate-limit'] ?? null,
                 'su' => isset($p['shared-users']) && is_numeric($p['shared-users']) ? (int) $p['shared-users'] : null,
                 'st' => $p['session-timeout'] ?? null, 'it' => $p['idle-timeout'] ?? null, 'kt' => $p['keepalive-timeout'] ?? null, 'ap' => $p['address-pool'] ?? null]
            );
        }
        return count($rows);
    }

    public static function syncInterfaces(array $router): int
    {
        $rows = self::interfaces($router);
        $seen = [];
        foreach ($rows as $i) {
            $seen[] = $i['.id'];
            Database::query(
                'INSERT INTO router_interfaces (router_id, ros_id, name, type, mac_address, mtu, running, disabled, rx_byte, tx_byte, comment, synced_at)
                 VALUES (:r, :id, :n, :t, :mac, :mtu, :run, :dis, :rx, :tx, :c, NOW())
                 ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type), mac_address = VALUES(mac_address), mtu = VALUES(mtu),
                    running = VALUES(running), disabled = VALUES(disabled), rx_byte = VALUES(rx_byte), tx_byte = VALUES(tx_byte),
                    comment = VALUES(comment), synced_at = NOW()',
                ['r' => $router['id'], 'id' => $i['.id'], 'n' => $i['name'] ?? '', 't' => $i['type'] ?? null,
                 'mac' => isset($i['mac-address']) ? substr($i['mac-address'], 0, 17) : null,
                 'mtu' => is_numeric($i['mtu'] ?? null) ? (int) $i['mtu'] : null,
                 'run' => ($i['running'] ?? 'false') === 'true' ? 1 : 0, 'dis' => ($i['disabled'] ?? 'false') === 'true' ? 1 : 0,
                 'rx' => (int) ($i['rx-byte'] ?? 0), 'tx' => (int) ($i['tx-byte'] ?? 0), 'c' => $i['comment'] ?? null]
            );
        }
        if ($seen) {
            $in = implode(',', array_map(static fn ($v) => Database::connection()->quote($v), $seen));
            Database::query("DELETE FROM router_interfaces WHERE router_id = :r AND ros_id NOT IN ({$in})", ['r' => $router['id']]);
        }
        return count($rows);
    }

    /**
     * Vérifie l'état d'un routeur, enregistre un relevé et met à jour son statut.
     * Déclenche les alertes (hors ligne, CPU, mémoire, connexions).
     */
    public static function checkStatus(array $router): array
    {
        $start = microtime(true);
        try {
            $info = self::info($router);
            $latency = (int) round((microtime(true) - $start) * 1000);
            $active = count(self::client($router)->query('/ip/hotspot/active/print', ['.proplist' => '.id']));
            $traffic = ['rx_bps' => null, 'tx_bps' => null];
            $wan = $router['wan_interface'] ?: null;
            if ($wan) {
                try {
                    $traffic = self::traffic($router, $wan);
                } catch (RouterOSException) {
                }
            }
            $wasOffline = $router['connection_state'] === 'offline';
            Database::update('routers', [
                'connection_state' => 'online',
                'last_seen_at' => date('Y-m-d H:i:s'),
                'last_checked_at' => date('Y-m-d H:i:s'),
                'last_error' => null,
                'consecutive_failures' => 0,
                'routeros_version' => $info['version'],
                'board_name' => $info['board_name'],
                'model' => $router['model'] ?: $info['model'],
                'serial_number' => $info['serial'] ?? $router['serial_number'],
                'identity' => $info['identity'],
                'last_cpu' => $info['cpu_load'],
                'last_memory_used_pct' => $info['memory_used_pct'],
                'last_uptime' => $info['uptime'],
                'last_active_users' => $active,
            ], 'id = :id', ['id' => $router['id']]);
            Database::insert('router_status_logs', [
                'router_id' => $router['id'], 'online' => 1, 'latency_ms' => $latency,
                'cpu_load' => $info['cpu_load'], 'free_memory' => $info['free_memory'], 'total_memory' => $info['total_memory'],
                'free_hdd' => $info['free_hdd'], 'uptime_seconds' => $info['uptime'], 'active_users' => $active,
                'rx_bps' => $traffic['rx_bps'], 'tx_bps' => $traffic['tx_bps'],
            ]);
            if ($wasOffline) {
                NotificationService::notify('router_back', 'Routeur reconnecté', "Le routeur « {$router['name']} » est de nouveau joignable.", 'success', '/routers/' . $router['id'], 'routers.view', null, (int) $router['id']);
            }
            self::thresholdAlerts($router, $info, $active, $traffic);
            return ['online' => true, 'latency_ms' => $latency, 'active' => $active] + $info + $traffic;
        } catch (Throwable $e) {
            self::close((int) $router['id']);
            $failures = (int) $router['consecutive_failures'] + 1;
            $limit = max(1, (int) setting('mikrotik.offline_after_failures', 2));
            $state = $failures >= $limit ? 'offline' : $router['connection_state'];
            Database::update('routers', [
                'connection_state' => $state,
                'last_checked_at' => date('Y-m-d H:i:s'),
                'last_error' => mb_substr($e->getMessage(), 0, 500),
                'consecutive_failures' => $failures,
            ], 'id = :id', ['id' => $router['id']]);
            Database::insert('router_status_logs', ['router_id' => $router['id'], 'online' => 0, 'error' => mb_substr($e->getMessage(), 0, 500)]);
            if ($state === 'offline') {
                NotificationService::notify('router_offline', 'Routeur inaccessible',
                    "Le routeur « {$router['name']} » ({$router['host']}) ne répond pas : " . $e->getMessage(),
                    'danger', '/routers/' . $router['id'], 'routers.view', 'router_offline:' . $router['id'], (int) $router['id']);
            }
            Logger::warning("Routeur {$router['name']} injoignable : " . $e->getMessage(), [], 'mikrotik');
            return ['online' => false, 'error' => $e->getMessage()];
        }
    }

    private static function thresholdAlerts(array $router, array $info, int $active, array $traffic): void
    {
        $rid = (int) $router['id'];
        $link = '/monitoring?router=' . $rid;
        if (($t = NotificationService::threshold('cpu_high')) !== null && $info['cpu_load'] >= $t) {
            NotificationService::notify('cpu_high', 'CPU élevé', "« {$router['name']} » : CPU à {$info['cpu_load']} %.", 'warning', $link, 'monitoring.view', 'cpu_high:' . $rid, $rid);
        }
        if (($t = NotificationService::threshold('memory_high')) !== null && $info['memory_used_pct'] !== null && $info['memory_used_pct'] >= $t) {
            NotificationService::notify('memory_high', 'Mémoire saturée', "« {$router['name']} » : mémoire utilisée à {$info['memory_used_pct']} %.", 'warning', $link, 'monitoring.view', 'memory_high:' . $rid, $rid);
        }
        if (($t = NotificationService::threshold('connections_high')) !== null && $active >= $t) {
            NotificationService::notify('connections_high', 'Nombre élevé de connexions', "« {$router['name']} » : {$active} clients connectés.", 'warning', '/hotspot', 'hotspot.view', 'connections_high:' . $rid, $rid);
        }
        $mbps = max((int) $traffic['rx_bps'], (int) $traffic['tx_bps']) / 1_000_000;
        if (($t = NotificationService::threshold('traffic_abnormal')) !== null && $traffic['rx_bps'] !== null && $mbps >= $t) {
            NotificationService::notify('traffic_abnormal', 'Débit anormal', sprintf('« %s » : %.1f Mbit/s sur %s.', $router['name'], $mbps, $router['wan_interface']), 'warning', $link, 'monitoring.view', 'traffic:' . $rid, $rid);
        }
    }

    public static function logSync(int $routerId, string $type, string $status, int $items, string $message, float $start, ?int $userId = null): void
    {
        Database::insert('router_sync_logs', [
            'router_id' => $routerId, 'type' => $type, 'status' => $status, 'items_processed' => $items,
            'message' => mb_substr($message, 0, 1000), 'duration_ms' => (int) round((microtime(true) - $start) * 1000), 'triggered_by' => $userId,
        ]);
    }
}
