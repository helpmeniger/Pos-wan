<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Services\Notification\MailSender;
use App\Services\Notification\SmsSender;

/**
 * Centre de notifications : in-app + événement temps réel (toujours),
 * e-mail / SMS selon les canaux demandés et la configuration.
 */
final class NotificationService
{
    public static function notify(int $userId, string $type, string $title, string $body, array $data = [], array $channels = []): int
    {
        $id = Database::insert('notifications', [
            'user_id' => $userId,
            'type' => $type,
            'title' => mb_substr($title, 0, 150),
            'body' => mb_substr($body, 0, 500),
            'data' => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
        ]);
        RealtimeService::push($userId, 'notification', $data['order_id'] ?? null, ['id' => $id, 'type' => $type, 'title' => $title, 'body' => $body] + $data);
        if ($channels) {
            $u = Database::first('SELECT phone, email, status FROM users WHERE id = :id', ['id' => $userId]);
            if ($u && $u['status'] !== 'deleted') {
                if (in_array('sms', $channels, true) && $u['phone']) {
                    self::queue('sms', $u['phone'], null, $title . ' — ' . $body);
                }
                if (in_array('email', $channels, true) && $u['email']) {
                    self::queue('email', $u['email'], 'MARWA — ' . $title, $body);
                }
            }
        }
        return $id;
    }

    /** Ajoute un message externe et tente l'envoi immédiat ; l'échec est consigné pour reprise. */
    public static function queue(string $channel, string $recipient, ?string $subject, string $body): int
    {
        $driver = $channel === 'sms' ? (string) \App\Core\Config::get('services.sms.driver') : (string) \App\Core\Config::get('services.mail.driver');
        $id = Database::insert('outbound_messages', [
            'channel' => $channel, 'recipient' => $recipient, 'subject' => $subject, 'body' => $body, 'driver' => $driver,
        ]);
        self::deliver($id);
        return $id;
    }

    public static function deliver(int $id): void
    {
        $m = Database::first('SELECT * FROM outbound_messages WHERE id = :id', ['id' => $id]);
        if (!$m || $m['status'] === 'sent') {
            return;
        }
        try {
            $via = $m['channel'] === 'sms'
                ? SmsSender::send($m['recipient'], $m['body'])
                : MailSender::send($m['recipient'], (string) $m['subject'], $m['body']);
            Database::update('outbound_messages', ['status' => 'sent', 'driver' => $via, 'sent_at' => date('Y-m-d H:i:s'), 'attempts' => (int) $m['attempts'] + 1], 'id = :id', ['id' => $id]);
        } catch (\Throwable $e) {
            Logger::warning('Échec d\'envoi ' . $m['channel'], ['id' => $id, 'error' => $e->getMessage()]);
            Database::update('outbound_messages', ['status' => (int) $m['attempts'] >= 4 ? 'failed' : 'queued', 'attempts' => (int) $m['attempts'] + 1, 'error' => mb_substr($e->getMessage(), 0, 255)], 'id = :id', ['id' => $id]);
        }
    }

    /** Notifie les parties d'une commande (client, prestataire, commerce). */
    public static function order(array $order, string $type, string $title, string $body, array $to = ['customer'], array $channels = []): void
    {
        $data = ['order_id' => (int) $order['id'], 'reference' => $order['reference'], 'status' => $order['status']];
        $targets = [];
        if (in_array('customer', $to, true)) {
            $targets[] = (int) $order['customer_id'];
        }
        if (in_array('provider', $to, true) && $order['provider_id']) {
            $targets[] = (int) $order['provider_id'];
        }
        if (in_array('merchant', $to, true) && $order['merchant_id']) {
            $owner = Database::value('SELECT owner_user_id FROM merchants WHERE id = :id', ['id' => $order['merchant_id']]);
            if ($owner) {
                $targets[] = (int) $owner;
            }
        }
        foreach (array_unique($targets) as $uid) {
            self::notify($uid, $type, $title, $body, $data, $channels);
        }
    }

    public static function staff(string $permission, string $type, string $title, string $body, array $data = []): void
    {
        $ids = Database::select(
            "SELECT DISTINCT u.id FROM users u JOIN role_permissions rp ON rp.role_id = u.role_id JOIN permissions p ON p.id = rp.permission_id
              WHERE p.code = :p AND u.status = 'active'",
            ['p' => $permission]
        );
        foreach ($ids as $r) {
            self::notify((int) $r['id'], $type, $title, $body, $data);
        }
    }
}
