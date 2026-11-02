<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;
use App\Services\MikroTik\RouterOSClient;
use App\Services\MikroTik\RouterService;
use RuntimeException;
use Throwable;

/**
 * Cœur commercial : génération des tickets, création réelle sur le routeur,
 * synchronisation de l'usage, expiration, suspension.
 */
final class TicketService
{
    public const STATUSES = [
        'available' => 'Disponible', 'sold' => 'Vendu', 'active' => 'Actif', 'used' => 'Épuisé',
        'expired' => 'Expiré', 'suspended' => 'Suspendu', 'cancelled' => 'Annulé',
    ];
    public const ACTIVATION = [
        'first_login' => 'À la première connexion',
        'first_auth' => 'À la première authentification',
        'on_generation' => 'Dès la génération',
    ];
    public const VALIDITY = [
        'elapsed_time' => 'Temps réel écoulé',
        'cumulative_uptime' => 'Temps de connexion cumulé',
        'data_quota' => 'Quota de données',
        'fixed_date' => 'Date fixe',
    ];
    private const CHARSETS = [
        'numeric' => '23456789',
        'alpha' => 'abcdefghjkmnpqrstuvwxyz',
        'alphanumeric' => 'abcdefghjkmnpqrstuvwxyz23456789',
        'alphanumeric_upper' => 'ABCDEFGHJKMNPQRSTUVWXYZ23456789',
    ];

    public static function validitySeconds(array $offer): ?int
    {
        if (!$offer['validity_value'] || !$offer['validity_unit']) {
            return null;
        }
        return (int) $offer['validity_value'] * match ($offer['validity_unit']) {
            'minutes' => 60, 'hours' => 3600, 'days' => 86400, 'weeks' => 604800, 'months' => 2592000, default => 0,
        };
    }

    public static function durationLabel(array $offer): string
    {
        if (!$offer['validity_value']) {
            if ($offer['time_limit_seconds']) {
                return human_duration((int) $offer['time_limit_seconds']);
            }
            return $offer['data_limit_bytes'] ? bytes_human((int) $offer['data_limit_bytes']) : 'Illimité';
        }
        $units = ['minutes' => 'minute', 'hours' => 'heure', 'days' => 'jour', 'weeks' => 'semaine', 'months' => 'mois'];
        $u = $units[$offer['validity_unit']] ?? $offer['validity_unit'];
        $n = (int) $offer['validity_value'];
        return $n . ' ' . ($n > 1 && $u !== 'mois' ? $u . 's' : $u);
    }

    private static function randomString(int $length, string $charset): string
    {
        $chars = self::CHARSETS[$charset] ?? self::CHARSETS['alphanumeric_upper'];
        $max = strlen($chars) - 1;
        $s = '';
        for ($i = 0; $i < $length; $i++) {
            $s .= $chars[random_int(0, $max)];
        }
        return $s;
    }

    /**
     * Génère un lot de tickets puis les crée sur le routeur.
     * @param array{offer_id:int,router_id:int,quantity:int,prefix?:string,username_length?:int,password_length?:int,
     *              charset?:string,same_credentials?:bool,activation_mode?:string,validity_mode?:string,
     *              shared_users?:int,expires_at?:?string,reseller_id?:?int} $opts
     * @return array{batch_id:int,batch_code:string,created:int,pushed:int,failed:int,error:?string}
     */
    public static function generate(array $opts, ?int $userId = null): array
    {
        $offer = Database::fetch("SELECT * FROM offers WHERE id = :id AND status = 'active'", ['id' => (int) $opts['offer_id']]);
        if (!$offer) {
            throw new RuntimeException('Offre introuvable ou inactive.');
        }
        $router = RouterService::find((int) $opts['router_id']);
        if (!$router || $router['status'] !== 'active') {
            throw new RuntimeException('Routeur introuvable ou désactivé.');
        }
        $qty = (int) $opts['quantity'];
        if ($qty < 1 || $qty > 5000) {
            throw new RuntimeException('La quantité doit être comprise entre 1 et 5 000.');
        }
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($opts['prefix'] ?? setting('tickets.default_prefix', 'MC'))));
        $ulen = max(4, min(16, (int) ($opts['username_length'] ?? setting('tickets.username_length', 8))));
        $plen = max(4, min(16, (int) ($opts['password_length'] ?? setting('tickets.password_length', 6))));
        $charset = (string) ($opts['charset'] ?? setting('tickets.charset', 'alphanumeric_upper'));
        $same = !empty($opts['same_credentials']);
        $activation = (string) ($opts['activation_mode'] ?? $offer['activation_mode']);
        $validityMode = (string) ($opts['validity_mode'] ?? $offer['validity_mode']);
        $shared = max(1, (int) ($opts['shared_users'] ?? $offer['shared_users']));
        $validity = self::validitySeconds($offer);
        $fixedExpiry = !empty($opts['expires_at']) ? date('Y-m-d H:i:s', strtotime((string) $opts['expires_at'])) : null;
        if ($validityMode === 'fixed_date' && !$fixedExpiry) {
            if (!$validity) {
                throw new RuntimeException('Mode « date fixe » : indiquez une date d\'expiration.');
            }
            $fixedExpiry = date('Y-m-d H:i:s', time() + $validity);
        }
        $expiresAt = $fixedExpiry ?? ($activation === 'on_generation' && $validity ? date('Y-m-d H:i:s', time() + $validity) : null);

        // Profil Hotspot de l'offre : créé/mis à jour sur le routeur si possible.
        Database::query('INSERT IGNORE INTO offer_router (offer_id, router_id) VALUES (:o, :r)', ['o' => $offer['id'], 'r' => $router['id']]);
        $profile = $offer['mikrotik_profile'] ?: ('MC-' . $offer['code']);
        $routerError = null;
        try {
            $profile = RouterService::ensureProfile($router, $offer);
        } catch (Throwable $e) {
            $routerError = $e->getMessage();
        }

        // Identifiants uniques sur l'ensemble des routeurs.
        $usernames = [];
        $guard = 0;
        while (count($usernames) < $qty) {
            if (++$guard > 50) {
                throw new RuntimeException('Impossible de générer suffisamment d\'identifiants uniques : augmentez la longueur.');
            }
            $candidates = [];
            while (count($candidates) < ($qty - count($usernames)) * 2 + 5) {
                $candidates[$prefix . self::randomString($ulen, $charset)] = true;
            }
            $candidates = array_keys(array_diff_key($candidates, $usernames));
            foreach (array_chunk($candidates, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $taken = array_column(Database::fetchAll("SELECT username FROM tickets WHERE username IN ({$ph})", $chunk), 'username');
                foreach (array_diff($chunk, $taken) as $u) {
                    if (count($usernames) < $qty) {
                        $usernames[$u] = true;
                    }
                }
            }
        }
        $usernames = array_keys($usernames);

        $batchCode = 'L' . date('ymdHis') . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
        $batchId = 0;
        Database::transaction(static function () use (&$batchId, $batchCode, $offer, $router, $opts, $qty, $prefix, $ulen, $plen, $charset, $same, $activation, $validityMode, $shared, $fixedExpiry, $expiresAt, $usernames, $profile, $userId) {
            $batchId = Database::insert('ticket_batches', [
                'batch_code' => $batchCode, 'offer_id' => $offer['id'], 'router_id' => $router['id'],
                'reseller_id' => $opts['reseller_id'] ?? null, 'quantity' => $qty, 'prefix' => $prefix ?: null,
                'username_length' => $ulen, 'password_length' => $plen, 'charset' => $charset, 'same_credentials' => $same ? 1 : 0,
                'activation_mode' => $activation, 'validity_mode' => $validityMode, 'shared_users' => $shared,
                'expires_at' => $fixedExpiry, 'status' => 'pushing', 'created_by' => $userId,
            ]);
            $pdo = Database::connection();
            $cols = '(batch_id, offer_id, router_id, reseller_id, username, password_enc, hotspot_profile, price, status, activation_mode, validity_mode, time_limit_seconds, data_limit_bytes, shared_users, expires_at, created_by)';
            foreach (array_chunk($usernames, 200) as $chunk) {
                $rows = [];
                $params = [];
                foreach ($chunk as $u) {
                    $pwd = $same ? $u : self::randomString($plen, $charset === 'alpha' ? 'alpha' : ($charset === 'numeric' ? 'numeric' : 'alphanumeric'));
                    $rows[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                    array_push($params, $batchId, $offer['id'], $router['id'], $opts['reseller_id'] ?? null, $u, Crypto::encrypt($pwd), $profile,
                        $offer['price'], 'available', $activation, $validityMode, $offer['time_limit_seconds'], $offer['data_limit_bytes'], $shared, $expiresAt, $userId);
                }
                $pdo->prepare("INSERT INTO tickets {$cols} VALUES " . implode(',', $rows))->execute($params);
            }
        });

        $result = ['batch_id' => $batchId, 'batch_code' => $batchCode, 'created' => $qty, 'pushed' => 0, 'failed' => 0, 'error' => $routerError];
        if ($routerError === null) {
            $push = self::pushBatch($batchId);
            $result['pushed'] = $push['pushed'];
            $result['failed'] = $push['failed'];
            $result['error'] = $push['error'];
        } else {
            Database::update('ticket_batches', ['status' => 'failed', 'failed_count' => $qty, 'last_error' => mb_substr($routerError, 0, 1000)], 'id = :id', ['id' => $batchId]);
        }
        AuditService::log('tickets.generate', 'ticket_batch', $batchId, "Génération de {$qty} ticket(s) {$offer['name']} sur {$router['name']}", null, $result, $userId);
        return $result;
    }

    /** Attributs RouterOS d'un ticket. */
    private static function userAttrs(array $t, string $batchCode): array
    {
        $attrs = [
            'name' => $t['username'],
            'password' => Crypto::decrypt($t['password_enc']),
            'profile' => $t['hotspot_profile'] ?: 'default',
            'comment' => 'MC:' . $batchCode,
        ];
        $router = RouterService::find((int) $t['router_id']);
        if ($router && $router['hotspot_server']) {
            $attrs['server'] = $router['hotspot_server'];
        }
        $uptime = (int) $t['time_limit_seconds'];
        if ($t['validity_mode'] === 'elapsed_time') {
            // Sécurité : même si le serveur est injoignable, le temps cumulé ne dépasse pas la validité.
            $offer = Database::fetch('SELECT * FROM offers WHERE id = :id', ['id' => $t['offer_id']]);
            $uptime = $uptime ?: (int) (self::validitySeconds($offer ?? []) ?? 0);
        }
        if ($uptime > 0) {
            $attrs['limit-uptime'] = RouterOSClient::formatDuration($uptime);
        }
        if ((int) $t['data_limit_bytes'] > 0) {
            $attrs['limit-bytes-total'] = (string) $t['data_limit_bytes'];
        }
        if ($t['bound_mac']) {
            $attrs['mac-address'] = $t['bound_mac'];
        }
        if (in_array($t['status'], ['suspended', 'cancelled', 'expired'], true)) {
            $attrs['disabled'] = 'yes';
        }
        return $attrs;
    }

    /** Crée sur le routeur les tickets d'un lot non encore poussés. */
    public static function pushBatch(int $batchId): array
    {
        $batch = Database::fetch('SELECT * FROM ticket_batches WHERE id = :id', ['id' => $batchId]);
        $tickets = Database::fetchAll('SELECT * FROM tickets WHERE batch_id = :b AND on_router = 0 AND status NOT IN (\'cancelled\')', ['b' => $batchId]);
        $out = self::pushTickets($tickets, (string) $batch['batch_code']);
        $pushedTotal = (int) Database::value('SELECT COUNT(*) FROM tickets WHERE batch_id = :b AND on_router = 1', ['b' => $batchId]);
        $failedTotal = (int) $batch['quantity'] - $pushedTotal;
        Database::update('ticket_batches', [
            'pushed_count' => $pushedTotal,
            'failed_count' => max(0, $failedTotal),
            'status' => $failedTotal <= 0 ? 'completed' : ($pushedTotal > 0 ? 'partial' : 'failed'),
            'last_error' => $out['error'] ? mb_substr($out['error'], 0, 1000) : null,
        ], 'id = :id', ['id' => $batchId]);
        return $out;
    }

    /** @param array<int,array> $tickets tickets d'un même routeur */
    public static function pushTickets(array $tickets, string $batchCode): array
    {
        if (!$tickets) {
            return ['pushed' => 0, 'failed' => 0, 'error' => null];
        }
        $router = RouterService::find((int) $tickets[0]['router_id']);
        try {
            $client = RouterService::client($router);
            // Les profils Hotspot des offres doivent exister sur le routeur avant les comptes.
            foreach (array_unique(array_map(static fn ($t) => (int) $t['offer_id'], $tickets)) as $offerId) {
                $offer = Database::fetch('SELECT * FROM offers WHERE id = :id', ['id' => $offerId]);
                if ($offer) {
                    RouterService::ensureProfile($router, $offer);
                }
            }
            $commands = [];
            foreach ($tickets as $i => $t) {
                $commands[$i] = ['/ip/hotspot/user/add', self::userAttrs($t, $batchCode)];
            }
            $results = $client->batch($commands);
        } catch (Throwable $e) {
            RouterService::close((int) $router['id']);
            return ['pushed' => 0, 'failed' => count($tickets), 'error' => $e->getMessage()];
        }
        $pushed = 0;
        $failed = 0;
        $lastError = null;
        foreach ($results as $i => $r) {
            $t = $tickets[$i];
            if ($r['ok']) {
                Database::update('tickets', ['on_router' => 1, 'ros_id' => $r['ret'], 'push_error' => null], 'id = :id', ['id' => $t['id']]);
                $pushed++;
            } elseif (str_contains((string) $r['error'], 'already have user')) {
                // Déjà présent (re-poussée après coupure) : on le considère créé.
                Database::update('tickets', ['on_router' => 1, 'push_error' => null], 'id = :id', ['id' => $t['id']]);
                $pushed++;
            } else {
                Database::update('tickets', ['push_error' => mb_substr((string) $r['error'], 0, 500)], 'id = :id', ['id' => $t['id']]);
                $failed++;
                $lastError = $r['error'];
            }
        }
        return ['pushed' => $pushed, 'failed' => $failed, 'error' => $lastError];
    }

    /** Re-pousse les tickets en attente (lots partiels ou échoués) d'un routeur. */
    public static function retryPending(int $routerId): int
    {
        $n = 0;
        foreach (Database::fetchAll("SELECT id FROM ticket_batches WHERE router_id = :r AND status IN ('pushing','partial','failed')", ['r' => $routerId]) as $b) {
            $n += self::pushBatch((int) $b['id'])['pushed'];
        }
        return $n;
    }

    /**
     * Synchronise l'usage réel des tickets depuis /ip/hotspot/user (uptime, octets) :
     * première utilisation, liaison MAC, expiration, épuisement.
     */
    public static function syncUsage(array $router, ?array $activeSessions = null): array
    {
        $users = RouterService::hotspotUsers($router);
        $byName = [];
        foreach ($users as $u) {
            $byName[$u['name'] ?? ''] = $u;
        }
        $macByUser = [];
        foreach ($activeSessions ?? [] as $a) {
            $macByUser[$a['user'] ?? ''] ??= $a['mac-address'] ?? null;
        }
        $tickets = Database::fetchAll(
            "SELECT t.*, o.validity_value, o.validity_unit, o.mac_binding FROM tickets t JOIN offers o ON o.id = t.offer_id
              WHERE t.router_id = :r AND t.status IN ('available','sold','active') AND t.on_router = 1",
            ['r' => $router['id']]
        );
        $stats = ['activated' => 0, 'used' => 0, 'missing' => 0, 'updated' => 0];
        $now = date('Y-m-d H:i:s');
        foreach ($tickets as $t) {
            $u = $byName[$t['username']] ?? null;
            if (!$u) {
                // Supprimé manuellement sur le routeur : n'est plus vendable.
                Database::update('tickets', ['on_router' => 0, 'push_error' => 'Absent du routeur lors de la synchronisation', 'last_synced_at' => $now], 'id = :id', ['id' => $t['id']]);
                if ($t['batch_id']) {
                    // Le lot sera re-poussé (routeur réinitialisé, compte supprimé à la main…).
                    Database::query("UPDATE ticket_batches SET status = 'partial' WHERE id = :b", ['b' => $t['batch_id']]);
                }
                $stats['missing']++;
                continue;
            }
            $uptime = RouterOSClient::parseDuration($u['uptime'] ?? '');
            $in = (int) ($u['bytes-in'] ?? 0);
            $out = (int) ($u['bytes-out'] ?? 0);
            $changes = ['uptime_seconds' => $uptime, 'bytes_in' => $in, 'bytes_out' => $out, 'last_synced_at' => $now];
            if ($uptime > 0 || $in > 0 || isset($macByUser[$t['username']])) {
                if (!$t['first_used_at']) {
                    $changes['first_used_at'] = date('Y-m-d H:i:s', time() - min($uptime, 3600));
                    $stats['activated']++;
                    $validity = self::validitySeconds($t);
                    if (!$t['expires_at'] && $validity && in_array($t['validity_mode'], ['elapsed_time'], true)) {
                        $changes['expires_at'] = date('Y-m-d H:i:s', strtotime($changes['first_used_at']) + $validity);
                    }
                    if ((int) $t['mac_binding'] && !$t['bound_mac'] && !empty($macByUser[$t['username']])) {
                        $changes['bound_mac'] = $macByUser[$t['username']];
                        try {
                            RouterService::setUserMac($router, $t['username'], $changes['bound_mac']);
                        } catch (Throwable) {
                        }
                    }
                }
                if (in_array($t['status'], ['available', 'sold'], true)) {
                    $changes['status'] = 'active';
                    if ($t['status'] === 'available' && !$t['sold_at']) {
                        $changes['sold_at'] = $now; // Utilisé hors caisse (vendu sur papier).
                    }
                }
                $limitUp = RouterOSClient::parseDuration($u['limit-uptime'] ?? '');
                $limitBytes = (int) ($u['limit-bytes-total'] ?? 0);
                if (($limitUp > 0 && $uptime >= $limitUp) || ($limitBytes > 0 && ($in + $out) >= $limitBytes)) {
                    $changes['status'] = 'used';
                    $stats['used']++;
                }
            }
            Database::update('tickets', $changes, 'id = :id', ['id' => $t['id']]);
            $stats['updated']++;
        }
        return $stats;
    }

    /** Expire les tickets échus et les désactive sur leur routeur. */
    public static function expireDue(?int $routerId = null): int
    {
        $sql = "SELECT * FROM tickets WHERE status IN ('available','sold','active') AND expires_at IS NOT NULL AND expires_at <= NOW()";
        $params = [];
        if ($routerId) {
            $sql .= ' AND router_id = :r';
            $params['r'] = $routerId;
        }
        $n = 0;
        foreach (Database::fetchAll($sql . ' LIMIT 1000', $params) as $t) {
            Database::update('tickets', ['status' => 'expired'], 'id = :id', ['id' => $t['id']]);
            $n++;
            if ($t['on_router']) {
                $router = RouterService::find((int) $t['router_id']);
                try {
                    if ($router && $router['connection_state'] !== 'offline') {
                        RouterService::setUserDisabled($router, $t['username'], true);
                    }
                } catch (Throwable $e) {
                    Logger::info("Désactivation différée de {$t['username']} : " . $e->getMessage(), [], 'mikrotik');
                }
            }
        }
        return $n;
    }

    /** Change le statut d'un ticket (suspendre, réactiver, annuler) et l'applique au routeur. */
    public static function changeStatus(array $ticket, string $action, ?int $userId = null): string
    {
        $map = ['suspend' => 'suspended', 'cancel' => 'cancelled'];
        if ($action === 'reactivate') {
            if ($ticket['status'] !== 'suspended') {
                throw new RuntimeException('Seul un ticket suspendu peut être réactivé.');
            }
            $new = $ticket['first_used_at'] ? 'active' : ($ticket['sold_at'] ? 'sold' : 'available');
            if ($ticket['expires_at'] && strtotime($ticket['expires_at']) <= time()) {
                $new = 'expired';
            }
        } elseif (isset($map[$action])) {
            if (in_array($ticket['status'], ['cancelled'], true)) {
                throw new RuntimeException('Ticket déjà annulé.');
            }
            $new = $map[$action];
        } else {
            throw new RuntimeException('Action inconnue.');
        }
        $routerMsg = '';
        if ($ticket['on_router']) {
            $router = RouterService::find((int) $ticket['router_id']);
            try {
                RouterService::setUserDisabled($router, $ticket['username'], $new !== 'active' && $new !== 'sold' && $new !== 'available');
            } catch (Throwable $e) {
                $routerMsg = ' (routeur non mis à jour : ' . $e->getMessage() . ')';
            }
        }
        Database::update('tickets', ['status' => $new, 'version' => (int) $ticket['version'] + 1], 'id = :id', ['id' => $ticket['id']]);
        AuditService::log('ticket.' . $action, 'ticket', (int) $ticket['id'], "Ticket {$ticket['username']} : {$ticket['status']} → {$new}{$routerMsg}", ['status' => $ticket['status']], ['status' => $new], $userId);
        return $new . $routerMsg;
    }

    /** Stock vendable : sur le routeur, disponible, non affecté à un revendeur. */
    public static function stock(?int $routerId = null): array
    {
        $sql = "SELECT t.router_id, t.offer_id, o.name AS offer_name, o.price, r.name AS router_name, COUNT(*) AS n
                  FROM tickets t JOIN offers o ON o.id = t.offer_id JOIN routers r ON r.id = t.router_id
                 WHERE t.status = 'available' AND t.on_router = 1 AND t.reseller_id IS NULL";
        $params = [];
        if ($routerId) {
            $sql .= ' AND t.router_id = :r';
            $params['r'] = $routerId;
        }
        return Database::fetchAll($sql . ' GROUP BY t.router_id, t.offer_id ORDER BY r.name, o.price', $params);
    }

    public static function checkLowStock(): void
    {
        $threshold = NotificationService::threshold('stock_low', (float) setting('tickets.low_stock_threshold', 20));
        if ($threshold === null) {
            return;
        }
        $rows = Database::fetchAll(
            "SELECT r.id AS router_id, r.name AS router_name, o.id AS offer_id, o.name AS offer_name,
                    (SELECT COUNT(*) FROM tickets t WHERE t.router_id = r.id AND t.offer_id = o.id AND t.status = 'available' AND t.on_router = 1 AND t.reseller_id IS NULL) AS n
               FROM offer_router orr JOIN offers o ON o.id = orr.offer_id AND o.status = 'active'
               JOIN routers r ON r.id = orr.router_id AND r.status = 'active' AND r.deleted_at IS NULL"
        );
        foreach ($rows as $r) {
            if ((int) $r['n'] < $threshold) {
                NotificationService::notify('stock_low', 'Stock de tickets faible',
                    "{$r['offer_name']} sur {$r['router_name']} : {$r['n']} ticket(s) disponible(s).", 'warning',
                    '/tickets/generate?offer_id=' . $r['offer_id'] . '&router_id=' . $r['router_id'], 'tickets.generate',
                    'stock_low:' . $r['router_id'] . ':' . $r['offer_id'], (int) $r['router_id']);
            }
        }
    }
}
