<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * Notifications internes (fonctionnent sans Internet). Les canaux externes (e-mail, SMS,
 * WhatsApp, push) pourront être ajoutés en implémentant NotificationChannel ; aucun service
 * payant n'est intégré par défaut.
 */
final class NotificationService
{
    /** @var NotificationChannel[] */
    private static array $channels = [];

    public static function register(NotificationChannel $channel): void
    {
        self::$channels[] = $channel;
    }

    public static function notify(?int $storeId, string $type, string $title, string $severity = 'info', ?string $body = null,
                                  ?string $link = null, ?string $dedupeKey = null, int $dedupeMinutes = 30): ?int
    {
        if ($dedupeKey !== null) {
            $exists = Database::value('SELECT id FROM notifications WHERE dedupe_key = :k AND created_at > :t LIMIT 1',
                ['k' => $dedupeKey, 't' => date('Y-m-d H:i:s', time() - $dedupeMinutes * 60)]);
            if ($exists) {
                return null;
            }
        }
        $id = Database::insert('notifications', [
            'store_id' => $storeId, 'type' => $type, 'severity' => $severity, 'title' => mb_substr($title, 0, 200),
            'body' => $body === null ? null : mb_substr($body, 0, 1000), 'link' => $link, 'dedupe_key' => $dedupeKey,
        ]);
        foreach (self::$channels as $ch) {
            try {
                $ch->send($storeId, $type, $title, $body, $severity);
            } catch (\Throwable $e) {
                Logger::error('Canal de notification en échec : ' . $e->getMessage());
            }
        }
        return $id;
    }

    public static function unreadFor(int $userId, int $storeId, int $limit = 15): array
    {
        return Database::fetchAll(
            'SELECT n.* FROM notifications n
             LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = :u
             WHERE (n.store_id = :s OR n.store_id IS NULL) AND (n.user_id IS NULL OR n.user_id = :u2) AND r.notification_id IS NULL
             ORDER BY n.id DESC LIMIT ' . (int) $limit, ['u' => $userId, 's' => $storeId, 'u2' => $userId]);
    }

    public static function countUnread(int $userId, int $storeId): int
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM notifications n
             LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = :u
             WHERE (n.store_id = :s OR n.store_id IS NULL) AND (n.user_id IS NULL OR n.user_id = :u2) AND r.notification_id IS NULL
               AND n.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)', ['u' => $userId, 's' => $storeId, 'u2' => $userId]);
    }

    public static function markRead(int $userId, int $storeId, ?int $id = null): void
    {
        if ($id !== null) {
            Database::execute('INSERT IGNORE INTO notification_reads (notification_id, user_id)
                SELECT id, :u FROM notifications WHERE id = :id AND (store_id = :s OR store_id IS NULL)', ['u' => $userId, 'id' => $id, 's' => $storeId]);
            return;
        }
        Database::execute('INSERT IGNORE INTO notification_reads (notification_id, user_id)
            SELECT id, :u FROM notifications WHERE (store_id = :s OR store_id IS NULL)', ['u' => $userId, 's' => $storeId]);
    }
}
