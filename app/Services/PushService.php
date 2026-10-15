<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Settings;
use App\Services\Push\WebPush;

/**
 * Notifications push (téléphone en veille, application fermée) via le service de push
 * du navigateur. Les messages sont chiffrés de bout en bout (RFC 8291) ; MARWA s'authentifie
 * avec ses clés VAPID (RFC 8292), générées automatiquement et conservées dans storage/vapid.json
 * (ou fournies par VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY_PEM_BASE64 dans .env).
 *
 * Les envois sont regroupés et effectués après la réponse HTTP (aucun ralentissement de l'application).
 */
final class PushService
{
    /** Services de push officiels des navigateurs : aucune autre adresse n'est contactée (anti-SSRF). */
    private const ALLOWED_HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com',
        'web.push.apple.com', 'notify.windows.com', 'wns2-*.notify.windows.com', '*.notify.windows.com', '*.push.apple.com', '*.push.services.mozilla.com'];

    private static ?array $keys = null;
    private static array $outbox = [];
    private static bool $shutdownRegistered = false;
    /** Remplaçable dans les tests : fn(array $requests): array $statuses */
    public static $transport = null;

    public static function enabled(): bool
    {
        return Settings::get('push.enabled', '1') !== '0' && function_exists('openssl_pkey_derive');
    }

    public static function keys(): array
    {
        if (self::$keys !== null) {
            return self::$keys;
        }
        $pub = (string) Env::get('VAPID_PUBLIC_KEY', '');
        $priv = (string) Env::get('VAPID_PRIVATE_KEY_PEM_BASE64', '');
        if ($pub !== '' && $priv !== '') {
            return self::$keys = ['public' => $pub, 'private_pem' => base64_decode($priv)];
        }
        $file = MARWA_ROOT . '/storage/vapid.json';
        $d = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($d) || empty($d['public']) || empty($d['private_pem'])) {
            $k = WebPush::generateKeyPair();
            $d = ['public' => WebPush::b64uEncode($k['public']), 'private_pem' => $k['private_pem'], 'created_at' => date('c')];
            if (@file_put_contents($file, json_encode($d, JSON_PRETTY_PRINT), LOCK_EX) === false) {
                throw new \RuntimeException('Impossible d\'enregistrer les clés VAPID dans storage/.');
            }
            @chmod($file, 0600);
        }
        return self::$keys = ['public' => $d['public'], 'private_pem' => $d['private_pem']];
    }

    public static function publicKey(): string
    {
        return self::keys()['public'];
    }

    public static function validEndpoint(string $endpoint): bool
    {
        $p = parse_url($endpoint);
        if (($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || strlen($endpoint) > 1000) {
            return false;
        }
        $host = strtolower($p['host']);
        foreach (array_merge(self::ALLOWED_HOSTS, array_filter(explode(',', (string) Env::get('PUSH_EXTRA_HOSTS', '')))) as $pattern) {
            if (fnmatch(strtolower(trim($pattern)), $host)) {
                return true;
            }
        }
        return false;
    }

    public static function subscribe(int $userId, array $v, string $userAgent): void
    {
        if (!self::validEndpoint($v['endpoint'])) {
            throw \App\Core\ValidationException::field('endpoint', 'Service de notification non reconnu.');
        }
        $p256 = WebPush::b64uDecode($v['p256dh']);
        $auth = WebPush::b64uDecode($v['auth']);
        if (strlen($p256) !== 65 || $p256[0] !== "\x04" || strlen($auth) !== 16) {
            throw \App\Core\ValidationException::field('keys', 'Clés d\'abonnement invalides.');
        }
        $hash = hash('sha256', $v['endpoint']);
        Database::execute(
            'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth_secret, user_agent) VALUES (:u, :e, :h, :p, :a, :ua)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth_secret = VALUES(auth_secret), user_agent = VALUES(user_agent), failures = 0',
            ['u' => $userId, 'e' => $v['endpoint'], 'h' => $hash, 'p' => $v['p256dh'], 'a' => $v['auth'], 'ua' => mb_substr($userAgent, 0, 255)]
        );
        // Au plus 10 appareils par compte : les plus anciens sont retirés
        $ids = Database::select('SELECT id FROM push_subscriptions WHERE user_id = :u ORDER BY updated_at DESC, id DESC LIMIT 100 OFFSET 10', ['u' => $userId]);
        foreach ($ids as $r) {
            Database::execute('DELETE FROM push_subscriptions WHERE id = :id', ['id' => $r['id']]);
        }
    }

    public static function unsubscribe(int $userId, string $endpoint): void
    {
        Database::execute('DELETE FROM push_subscriptions WHERE user_id = :u AND endpoint_hash = :h', ['u' => $userId, 'h' => hash('sha256', $endpoint)]);
    }

    /** Met en file une notification pour tous les appareils de l'utilisateur. */
    public static function queue(int $userId, array $message): void
    {
        if (!self::enabled()) {
            return;
        }
        $subs = Database::select('SELECT id, endpoint, p256dh, auth_secret FROM push_subscriptions WHERE user_id = :u AND failures < 10', ['u' => $userId]);
        if (!$subs) {
            return;
        }
        foreach ($subs as $s) {
            self::$outbox[] = ['sub' => $s, 'message' => $message, 'urgent' => in_array($message['type'] ?? '', ['new_request', 'order_assigned', 'counter_offer', 'sos'], true)];
        }
        if (!self::$shutdownRegistered && PHP_SAPI !== 'cli') {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function () {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request(); // réponse déjà envoyée au navigateur
                }
                self::flush();
            });
        }
    }

    /** Envoie les notifications en attente (en parallèle). Retourne le nombre d'envois acceptés. */
    public static function flush(): int
    {
        $batch = self::$outbox;
        self::$outbox = [];
        if (!$batch) {
            return 0;
        }
        $k = self::keys();
        $subject = 'mailto:' . (string) Config::get('services.mail.from', 'no-reply@marwa.local');
        $requests = [];
        foreach ($batch as $i => $item) {
            try {
                $payload = json_encode($item['message'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (strlen($payload) > 3000) {
                    $item['message']['body'] = mb_substr((string) $item['message']['body'], 0, 300);
                    $payload = json_encode($item['message'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $s = $item['sub'];
                $requests[$i] = [
                    'url' => $s['endpoint'],
                    'body' => WebPush::encrypt((string) $payload, WebPush::b64uDecode($s['p256dh']), WebPush::b64uDecode($s['auth_secret'])),
                    'headers' => [
                        'Content-Type: application/octet-stream',
                        'Content-Encoding: aes128gcm',
                        'TTL: ' . ($item['urgent'] ? 300 : 86400),
                        'Urgency: ' . ($item['urgent'] ? 'high' : 'normal'),
                        'Authorization: vapid t=' . WebPush::vapidJwt($s['endpoint'], $subject, $k['private_pem']) . ', k=' . $k['public'],
                    ],
                ];
                if (!empty($item['message']['tag'])) {
                    $requests[$i]['headers'][] = 'Topic: ' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $item['message']['tag']), 0, 32);
                }
            } catch (\Throwable $e) {
                Logger::warning('Notification push non chiffrable', ['error' => $e->getMessage()]);
            }
        }
        $statuses = self::$transport ? (self::$transport)($requests) : self::send($requests);
        $ok = 0;
        foreach ($statuses as $i => $code) {
            $sid = (int) $batch[$i]['sub']['id'];
            if ($code >= 200 && $code < 300) {
                $ok++;
                Database::execute('UPDATE push_subscriptions SET failures = 0, last_success_at = NOW() WHERE id = :id', ['id' => $sid]);
            } elseif (in_array($code, [404, 410], true)) {
                Database::execute('DELETE FROM push_subscriptions WHERE id = :id', ['id' => $sid]); // abonnement expiré ou retiré par l'utilisateur
            } else {
                Database::execute('UPDATE push_subscriptions SET failures = failures + 1 WHERE id = :id', ['id' => $sid]);
                Logger::warning('Notification push refusée', ['status' => $code, 'host' => parse_url($batch[$i]['sub']['endpoint'], PHP_URL_HOST)]);
            }
        }
        return $ok;
    }

    /** @return array<int,int> statut HTTP par requête (0 = erreur réseau) */
    private static function send(array $requests): array
    {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($requests as $i => $r) {
            $ch = curl_init($r['url']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $r['body'], CURLOPT_HTTPHEADER => $r['headers'],
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);
        $out = [];
        foreach ($handles as $i => $ch) {
            $out[$i] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    /** Contenu de la notification affichée par le téléphone. */
    public static function message(int $notificationId, string $type, string $title, string $body, array $data, string $role): array
    {
        $space = match ($role) {
            'driver', 'courier' => 'prestataire', 'merchant' => 'commerce', 'business' => 'entreprise',
            'admin', 'support', 'finance' => 'admin', default => 'client',
        };
        $hash = match (true) {
            !isset($data['order_id']) => '',
            $space === 'client' => '#/commande/' . (int) $data['order_id'],
            $space === 'prestataire' => in_array($type, ['new_request', 'counter_offer'], true) ? '#/' : '#/course',
            $space === 'commerce' => '#/commandes',
            $space === 'admin' => '#/commande/' . (int) $data['order_id'],
            default => '',
        };
        return [
            'id' => $notificationId, 'type' => $type, 'title' => $title, 'body' => $body,
            'url' => url($space) . $hash,
            'tag' => isset($data['order_id']) ? 'order-' . (int) $data['order_id'] : 'mw-' . $notificationId,
            'order_id' => $data['order_id'] ?? null,
        ];
    }

    public static function forget(): void
    {
        self::$outbox = [];
    }

    /** Pour les vérifications de l'administration. */
    public static function stats(): array
    {
        return ['subscriptions' => (int) Database::value('SELECT COUNT(*) FROM push_subscriptions'),
            'users' => (int) Database::value('SELECT COUNT(DISTINCT user_id) FROM push_subscriptions'),
            'public_key' => self::enabled() ? self::publicKey() : null];
    }

    public static function assertEnabled(): void
    {
        if (!self::enabled()) {
            throw new HttpException(503, 'Notifications push désactivées.', 'push_disabled');
        }
    }
}
