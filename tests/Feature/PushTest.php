<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Services\NotificationService;
use App\Services\Push\WebPush;
use App\Services\PushService;
use Tests\TestCase;

/** Notifications push : abonnement sécurisé, chiffrement de bout en bout, VAPID, nettoyage. */
final class PushTest extends TestCase
{
    /** Déchiffrement côté « navigateur » (RFC 8291 / 8188), pour vérifier ce que le serveur envoie. */
    private function decrypt(string $body, \OpenSSLAsymmetricKey $uaPriv, string $uaPub, string $auth): string
    {
        $salt = substr($body, 0, 16);
        $idlen = ord($body[20]);
        $asPub = substr($body, 21, $idlen);
        $cipher = substr($body, 21 + $idlen);
        $ecdh = openssl_pkey_derive(WebPush::publicKeyFromPoint($asPub), $uaPriv, 32);
        $hk = fn ($salt, $ikm, $info, $len) => substr(hash_hmac('sha256', $info . "\x01", hash_hmac('sha256', $ikm, $salt, true), true), 0, $len);
        $ikm = $hk($auth, $ecdh, "WebPush: info\x00" . $uaPub . $asPub, 32);
        $cek = $hk($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = $hk($salt, $ikm, "Content-Encoding: nonce\x00", 12);
        $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));
        $this->assertTrue($plain !== false, 'Message déchiffrable par le destinataire');
        return rtrim((string) $plain, "\x00\x02");
    }

    public function testSubscribeValidationAndEncryptedDelivery(): void
    {
        $driver = $this->user('driver');
        $t = $this->token((int) $driver['id']);
        $ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $uaPub = WebPush::publicPoint($ua);
        $auth = random_bytes(16);
        $body = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc' . $driver['id'], 'p256dh' => WebPush::b64uEncode($uaPub), 'auth' => WebPush::b64uEncode($auth)];

        // Protection SSRF : seuls les services de push officiels sont acceptés
        foreach (['http://fcm.googleapis.com/x', 'https://127.0.0.1/x', 'https://intranet.local/push', 'https://evil.com/fcm.googleapis.com'] as $bad) {
            $this->assertStatus(422, $this->call('POST', '/api/push/subscribe', ['endpoint' => $bad] + $body, $t), $bad);
        }
        $this->assertStatus(422, $this->call('POST', '/api/push/subscribe', ['p256dh' => WebPush::b64uEncode(random_bytes(65))] + $body, $t), 'Clé invalide refusée');
        $this->assertTrue(in_array($this->call('POST', '/api/push/subscribe', $body)->status, [401, 419], true), 'Connexion obligatoire');
        $this->assertStatus(200, $this->call('POST', '/api/push/subscribe', $body, $t));
        $key = $this->data($this->call('GET', '/api/push/key', [], $t))['public_key'];
        $this->assertSame(65, strlen(WebPush::b64uDecode($key)));

        $sent = [];
        PushService::$transport = function (array $reqs) use (&$sent) { $sent = $reqs; return array_map(fn () => 201, $reqs); };
        try {
            NotificationService::notify((int) $driver['id'], 'new_request', 'Nouvelle demande', 'Taxi · départ Plateau · 1 500 FCFA', ['order_id' => 42]);
            $this->assertSame(1, PushService::flush(), 'Une notification envoyée');
            $req = array_values($sent)[0];
            $this->assertSame($body['endpoint'], $req['url']);
            $h = implode("\n", $req['headers']);
            $this->assertTrue(str_contains($h, 'Content-Encoding: aes128gcm') && str_contains($h, 'Urgency: high') && str_contains($h, 'TTL: 300'), 'En-têtes Web Push');
            $this->assertFalse(str_contains($req['body'], 'Plateau'), 'Contenu illisible par le service de push');

            preg_match('/vapid t=([^,]+), k=(\S+)/', $h, $m);
            [$hd, $cl, $sig] = explode('.', $m[1]);
            $claims = json_decode(WebPush::b64uDecode($cl), true);
            $this->assertSame('https://fcm.googleapis.com', $claims['aud']);
            $raw = WebPush::b64uDecode($sig);
            $der = $this->rawToDer($raw);
            $pubPem = WebPush::publicKeyFromPoint(WebPush::b64uDecode($m[2]));
            $this->assertSame(1, openssl_verify($hd . '.' . $cl, $der, $pubPem, OPENSSL_ALGO_SHA256), 'Signature VAPID valide');

            $msg = json_decode($this->decrypt($req['body'], $ua, $uaPub, $auth), true);
            $this->assertSame('Nouvelle demande', $msg['title']);
            $this->assertSame('order-42', $msg['tag']);
            $this->assertTrue(str_ends_with($msg['url'], '/prestataire#/'), 'Ouvre l\'espace prestataire');

            // Abonnement expiré (410) : supprimé automatiquement
            PushService::$transport = fn (array $reqs) => array_map(fn () => 410, $reqs);
            NotificationService::notify((int) $driver['id'], 'test', 'Essai', 'Essai');
            PushService::flush();
            $this->assertSame(0, (int) Database::value('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = :u', ['u' => $driver['id']]));
        } finally {
            PushService::$transport = null;
            PushService::forget();
        }
    }

    private function rawToDer(string $raw): string
    {
        $int = function (string $x) {
            $x = ltrim($x, "\0");
            if (ord($x[0]) & 0x80) {
                $x = "\0" . $x;
            }
            return "\x02" . chr(strlen($x)) . $x;
        };
        $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
        return "\x30" . chr(strlen($seq)) . $seq;
    }
}
