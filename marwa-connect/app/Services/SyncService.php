<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Services\MikroTik\RouterOSClient;
use App\Services\MikroTik\RouterService;
use Throwable;

/**
 * Synchronisation complète d'un routeur (manuelle ou planifiée) et tâches périodiques.
 */
final class SyncService
{
    /** Met à jour l'historique des sessions Wi-Fi à partir de /ip/hotspot/active. */
    public static function recordSessions(array $router, array $active): int
    {
        $now = date('Y-m-d H:i:s');
        $seenIds = [];
        foreach ($active as $a) {
            $uptime = RouterOSClient::parseDuration($a['uptime'] ?? '');
            $user = (string) ($a['user'] ?? '');
            $rosId = (string) ($a['.id'] ?? '');
            $existing = Database::fetch(
                'SELECT id FROM wifi_sessions WHERE router_id = :r AND is_active = 1 AND ros_session_id = :sid AND username = :u LIMIT 1',
                ['r' => $router['id'], 'sid' => $rosId, 'u' => $user]
            );
            $values = [
                'ip_address' => $a['address'] ?? null, 'mac_address' => isset($a['mac-address']) ? substr($a['mac-address'], 0, 17) : null,
                'uptime_seconds' => $uptime, 'bytes_in' => (int) ($a['bytes-in'] ?? 0), 'bytes_out' => (int) ($a['bytes-out'] ?? 0), 'last_seen_at' => $now,
            ];
            if ($existing) {
                Database::update('wifi_sessions', $values, 'id = :id', ['id' => $existing['id']]);
                $seenIds[] = (int) $existing['id'];
            } else {
                $ticketId = Database::value('SELECT id FROM tickets WHERE username = :u', ['u' => $user]);
                $seenIds[] = Database::insert('wifi_sessions', $values + [
                    'router_id' => $router['id'], 'ticket_id' => $ticketId ?: null, 'username' => $user, 'ros_session_id' => $rosId,
                    'login_at' => date('Y-m-d H:i:s', time() - $uptime), 'is_active' => 1,
                ]);
            }
        }
        $sql = 'UPDATE wifi_sessions SET is_active = 0, logout_at = last_seen_at WHERE router_id = :r AND is_active = 1';
        if ($seenIds) {
            $sql .= ' AND id NOT IN (' . implode(',', $seenIds) . ')';
        }
        Database::query($sql, ['r' => $router['id']]);
        return count($active);
    }

    /** Synchronisation complète ; renvoie un résumé. */
    public static function syncRouter(array $router, ?int $userId = null, string $type = 'scheduled'): array
    {
        $start = microtime(true);
        $status = RouterService::checkStatus($router);
        if (!$status['online']) {
            RouterService::logSync((int) $router['id'], $type, 'failed', 0, (string) $status['error'], $start, $userId);
            NotificationService::notify('sync_failed', 'Synchronisation impossible', "« {$router['name']} » : {$status['error']}", 'warning',
                '/routers/' . $router['id'], 'routers.view', 'sync_failed:' . $router['id'], (int) $router['id']);
            return ['ok' => false, 'message' => (string) $status['error']];
        }
        try {
            $router = RouterService::find((int) $router['id']);
            $i = RouterService::syncInterfaces($router);
            $p = RouterService::syncProfiles($router);
            $sessions = RouterService::activeSessions($router);
            self::recordSessions($router, $sessions);
            $u = TicketService::syncUsage($router, $sessions);
            $pushed = TicketService::retryPending((int) $router['id']);
            $expired = TicketService::expireDue((int) $router['id']);
            self::autoDisconnect($router, $sessions);
            Database::update('routers', ['last_sync_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $router['id']]);
            $msg = "{$i} interface(s), {$p} profil(s), " . count($sessions) . " session(s), {$u['updated']} ticket(s) vérifié(s), {$u['activated']} activé(s), {$u['missing']} absent(s), {$pushed} re-poussé(s), {$expired} expiré(s)";
            RouterService::logSync((int) $router['id'], $type, 'success', $u['updated'], $msg, $start, $userId);
            return ['ok' => true, 'message' => $msg];
        } catch (Throwable $e) {
            RouterService::close((int) $router['id']);
            RouterService::logSync((int) $router['id'], $type, 'failed', 0, $e->getMessage(), $start, $userId);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** Déconnexion automatique des sessions dont le ticket n'est plus valide. */
    public static function autoDisconnect(array $router, array $sessions): int
    {
        $n = 0;
        foreach ($sessions as $s) {
            $t = Database::fetch("SELECT status FROM tickets WHERE username = :u", ['u' => $s['user'] ?? '']);
            if ($t && in_array($t['status'], ['expired', 'suspended', 'cancelled', 'used'], true)) {
                try {
                    RouterService::disconnectActive($router, (string) $s['.id']);
                    $n++;
                } catch (Throwable) {
                }
            }
        }
        return $n;
    }

    /** Routeurs dont l'intervalle de synchronisation est échu. */
    public static function dueRouters(): array
    {
        return Database::fetchAll(
            "SELECT * FROM routers WHERE deleted_at IS NULL AND status = 'active' AND sync_enabled = 1
               AND (last_checked_at IS NULL OR last_checked_at <= DATE_SUB(NOW(), INTERVAL sync_interval_minutes MINUTE))
             ORDER BY last_checked_at"
        );
    }

    /** Commandes en ligne payées mais sans paiement confirmé depuis trop longtemps, commandes expirées. */
    public static function housekeeping(): void
    {
        Database::query("UPDATE online_orders SET status = 'expired' WHERE status IN ('created','awaiting_payment') AND expires_at < NOW()");
        $minutes = NotificationService::threshold('payment_unconfirmed', 15);
        if ($minutes !== null) {
            $n = (int) Database::value("SELECT COUNT(*) FROM online_orders WHERE status = 'awaiting_payment' AND created_at <= DATE_SUB(NOW(), INTERVAL :m MINUTE)", ['m' => (int) $minutes]);
            if ($n > 0) {
                NotificationService::notify('payment_unconfirmed', 'Paiements non confirmés', "{$n} commande(s) en ligne attendent une confirmation de paiement.", 'warning', '/sales?tab=online', 'payments.manage', 'payment_unconfirmed');
            }
        }
        $expiredToday = (int) Database::value("SELECT COUNT(*) FROM tickets WHERE status = 'expired' AND updated_at >= CURDATE()");
        if ($expiredToday > 0) {
            NotificationService::notify('ticket_expired', 'Tickets expirés', "{$expiredToday} ticket(s) ont expiré aujourd'hui.", 'info', '/tickets?status=expired', 'tickets.view', 'ticket_expired:' . date('Ymd'));
        }
        TicketService::checkLowStock();
        Database::query('DELETE FROM rate_limits WHERE expires_at < NOW()');
        $days = (int) setting('security.audit_retention_days', 365);
        if ($days > 30) {
            Database::query('DELETE FROM router_status_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)');
            Database::query('DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL :d DAY)', ['d' => $days]);
        }
    }
}
