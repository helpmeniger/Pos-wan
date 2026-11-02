<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;

/**
 * Centre de notifications : création (avec anti-répétition), règles d'alerte
 * et diffusion facultative par e-mail / WhatsApp.
 */
final class NotificationService
{
    /**
     * @param string $type   code de règle (router_offline, cpu_high…) ou type libre
     * @param string|null $permission permission requise pour voir la notification (null = tous)
     */
    public static function notify(
        string $type,
        string $title,
        string $message,
        string $level = 'info',
        ?string $link = null,
        ?string $permission = null,
        ?string $dedupeKey = null,
        ?int $routerId = null,
        ?int $userId = null,
        array $data = []
    ): ?int {
        $rule = Database::fetch('SELECT * FROM alert_rules WHERE code = :c', ['c' => $type]);
        if ($rule && !(int) $rule['is_enabled']) {
            return null;
        }
        if ($rule) {
            $level = $rule['level'] === 'info' && $level !== 'info' ? $level : $rule['level'];
        }
        $cooldown = (int) ($rule['cooldown_minutes'] ?? 30);
        if ($dedupeKey !== null && $cooldown > 0) {
            $recent = Database::value(
                'SELECT id FROM notifications WHERE dedupe_key = :k AND created_at >= :since LIMIT 1',
                ['k' => $dedupeKey, 'since' => date('Y-m-d H:i:s', time() - $cooldown * 60)]
            );
            if ($recent) {
                return null;
            }
        }
        $id = Database::insert('notifications', [
            'user_id' => $userId,
            'permission' => $permission,
            'type' => $type,
            'level' => $level,
            'title' => mb_substr($title, 0, 150),
            'message' => mb_substr($message, 0, 1000),
            'link' => $link,
            'router_id' => $routerId,
            'dedupe_key' => $dedupeKey,
            'data' => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
        ]);
        $channels = $rule ? (json_decode((string) $rule['channels'], true) ?: []) : [];
        foreach (array_diff($channels, ['app']) as $channel) {
            try {
                self::dispatch($channel, $title, $message);
            } catch (\Throwable $e) {
                Logger::warning("Envoi {$channel} échoué : " . $e->getMessage(), [], 'notifications');
            }
        }
        return $id;
    }

    public static function channelConfig(string $channel): ?array
    {
        $row = Database::fetch('SELECT * FROM notification_channels WHERE channel = :c', ['c' => $channel]);
        if (!$row) {
            return null;
        }
        $row['config'] = $row['config_enc'] ? (json_decode(Crypto::decrypt($row['config_enc']), true) ?: []) : [];
        $row['recipients'] = json_decode((string) $row['recipients'], true) ?: [];
        return $row;
    }

    public static function dispatch(string $channel, string $title, string $message): void
    {
        $cfg = self::channelConfig($channel);
        if (!$cfg || !(int) $cfg['is_active'] || !$cfg['recipients']) {
            return;
        }
        $company = (string) setting('company.name', 'MARWA CONNECT');
        foreach ($cfg['recipients'] as $to) {
            if ($channel === 'email') {
                Mailer::send($cfg['config'], (string) $to, "[{$company}] {$title}", $message);
            } elseif ($channel === 'whatsapp') {
                self::whatsapp($cfg['config'], (string) $to, "*{$company}* — {$title}\n{$message}");
            }
        }
    }

    /** WhatsApp Business Cloud API (Meta) : jeton + identifiant du numéro expéditeur. */
    public static function whatsapp(array $cfg, string $to, string $text): void
    {
        if (empty($cfg['token']) || empty($cfg['phone_number_id'])) {
            throw new \RuntimeException('WhatsApp non configuré.');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Extension curl requise.');
        }
        $ch = curl_init('https://graph.facebook.com/v19.0/' . rawurlencode((string) $cfg['phone_number_id']) . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['token'], 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'messaging_product' => 'whatsapp',
                'to' => preg_replace('/\D/', '', $to),
                'type' => 'text',
                'text' => ['body' => $text],
            ]),
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("WhatsApp HTTP {$code} " . ($err ?: mb_substr((string) $body, 0, 200)));
        }
    }

    public static function threshold(string $code, ?float $default = null): ?float
    {
        $v = Database::value('SELECT threshold FROM alert_rules WHERE code = :c AND is_enabled = 1', ['c' => $code]);
        return $v === null ? $default : (float) $v;
    }
}
