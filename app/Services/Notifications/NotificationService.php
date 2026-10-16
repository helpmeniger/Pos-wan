<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Core\Database;

/**
 * Notifications internes (table notifications). Les canaux e-mail / SMS / push
 * passent par la file notification_deliveries (traitée par la tâche planifiée, Phase 3).
 */
class NotificationService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $data */
    public function notify(int $userId, string $type, string $title, ?string $body = null, ?string $link = null, array $data = []): int
    {
        return $this->db->insert('notifications', [
            'user_id' => $userId,
            'type' => $type,
            'title' => mb_substr($title, 0, 160),
            'body' => $body !== null ? mb_substr($body, 0, 1000) : null,
            'link_url' => $link,
            'data_json' => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function latest(int $userId, int $limit = 10): array
    {
        return $this->db->fetchAll(
            'SELECT id, type, title, body, link_url, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)),
            [$userId]
        );
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    /** Marque comme lue une notification APPARTENANT à l'utilisateur (sinon sans effet). */
    public function markRead(int $userId, int $notificationId): bool
    {
        return $this->db->query(
            'UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL',
            [$notificationId, $userId]
        )->rowCount() > 0;
    }

    public function markAllRead(int $userId): int
    {
        return $this->db->query('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$userId])->rowCount();
    }
}
