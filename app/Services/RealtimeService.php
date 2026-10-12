<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Temps réel par interrogation incrémentale : chaque utilisateur lit uniquement
 * ses propres événements (GET /api/events?since=ID). Compatible WampServer sans
 * démon WebSocket ; un serveur WebSocket pourra consommer la même table plus tard.
 */
final class RealtimeService
{
    public static function push(int $userId, string $type, ?int $orderId = null, array $payload = []): void
    {
        Database::insert('realtime_events', [
            'user_id' => $userId,
            'type' => $type,
            'order_id' => $orderId,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public static function pushOrder(array $order, string $type, array $payload = []): void
    {
        $ids = [(int) $order['customer_id']];
        if (!empty($order['provider_id'])) {
            $ids[] = (int) $order['provider_id'];
        }
        if (!empty($order['merchant_id'])) {
            $owner = Database::value('SELECT owner_user_id FROM merchants WHERE id = :id', ['id' => $order['merchant_id']]);
            if ($owner) {
                $ids[] = (int) $owner;
            }
        }
        foreach (array_unique($ids) as $id) {
            self::push($id, $type, (int) $order['id'], $payload + ['order_id' => (int) $order['id'], 'status' => $order['status'], 'reference' => $order['reference']]);
        }
    }

    public static function since(int $userId, int $sinceId, int $limit = 50): array
    {
        $rows = Database::select(
            'SELECT id, type, order_id, payload, created_at FROM realtime_events WHERE user_id = :u AND id > :s ORDER BY id ASC LIMIT ' . max(1, min(200, $limit)),
            ['u' => $userId, 's' => $sinceId]
        );
        foreach ($rows as &$r) {
            $r['payload'] = json_decode((string) $r['payload'], true);
        }
        return $rows;
    }

    public static function latestId(int $userId): int
    {
        return (int) Database::value('SELECT COALESCE(MAX(id),0) FROM realtime_events WHERE user_id = :u', ['u' => $userId]);
    }
}
