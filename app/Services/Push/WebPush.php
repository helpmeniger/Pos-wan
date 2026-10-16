<?php
declare(strict_types=1);

namespace App\Services\Push;

use RuntimeException;

/**
 * Web Push sans dépendance externe (PHP + OpenSSL) :
 *  - chiffrement du message « aes128gcm » (RFC 8188) avec la dérivation de clés Web Push (RFC 8291) ;
 *  - authentification du serveur d'application par jeton VAPID ES256 (RFC 8292).
 * Le service de push du navigateur (Google FCM, Mozilla, Apple, Microsoft) ne peut pas lire le contenu.
 */
final class WebPush
{
    /** En-tête DER SubjectPublicKeyInfo d'une clé publique P-256 non compressée (65 octets). */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        if ($d === false) {
            throw new RuntimeException('Encodage base64url invalide.');
        }
        return $d;
    }

    /**
     * Options OpenSSL : sous Windows (WampServer), PHP ne trouve généralement pas de fichier
     * openssl.cnf et la création de clés échoue. MARWA fournit config/openssl.cnf.
     */
    public static function sslArgs(array $extra = []): array
    {
        $cnf = defined('MARWA_ROOT') ? MARWA_ROOT . '/config/openssl.cnf' : '';
        return $extra + ($cnf && is_file($cnf) ? ['config' => $cnf] : []);
    }

    /** Clé P-256 éphémère ou permanente (essaie d'abord la configuration système). */
    public static function newEcKey(): \OpenSSLAsymmetricKey
    {
        $opts = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $key = @openssl_pkey_new($opts) ?: @openssl_pkey_new(self::sslArgs($opts));
        if ($key) {
            while (openssl_error_string()) {
                // vide la file d'erreurs du premier essai
            }
        } else {
            $err = [];
            while ($e = openssl_error_string()) {
                $err[] = $e;
            }
            throw new RuntimeException('OpenSSL ne peut pas générer de clé P-256 (' . implode(' ; ', array_slice($err, 0, 2)) . '). Vérifiez l\'extension openssl de PHP.');
        }
        return $key;
    }

    /** Nouvelle paire de clés P-256 : ['private_pem' => …, 'public' => point non compressé (65 octets)] */
    public static function generateKeyPair(): array
    {
        $key = self::newEcKey();
        if (!@openssl_pkey_export($key, $pem) && !@openssl_pkey_export($key, $pem, null, self::sslArgs())) {
            throw new RuntimeException('OpenSSL ne peut pas exporter la clé (openssl.cnf introuvable).');
        }
        return ['private_pem' => $pem, 'public' => self::publicPoint($key)];
    }

    public static function publicPoint(\OpenSSLAsymmetricKey $key): string
    {
        $d = openssl_pkey_get_details($key);
        if (!isset($d['ec']['x'], $d['ec']['y'])) {
            throw new RuntimeException('Clé EC invalide.');
        }
        return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    public static function publicKeyFromPoint(string $point): \OpenSSLAsymmetricKey
    {
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new RuntimeException('Clé publique P-256 invalide.');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(self::P256_SPKI_PREFIX) . $point), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $k = openssl_pkey_get_public($pem);
        if (!$k) {
            throw new RuntimeException('Clé publique refusée par OpenSSL.');
        }
        return $k;
    }

    private static function hkdf(string $salt, string $ikm, string $info, int $length): string
    {
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $length);
    }

    /**
     * Chiffre un message pour un abonnement (RFC 8291). Retourne le corps HTTP binaire.
     * $uaPublic : clé « p256dh » du navigateur (65 octets) ; $authSecret : secret « auth » (16 octets).
     * $asKeyPem / $salt ne servent qu'aux tests (valeurs aléatoires sinon).
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret, ?string $asKeyPem = null, ?string $salt = null): string
    {
        if (strlen($authSecret) !== 16) {
            throw new RuntimeException('Secret d\'authentification invalide.');
        }
        $as = $asKeyPem ? openssl_pkey_get_private($asKeyPem) : self::newEcKey();
        if (!$as) {
            throw new RuntimeException('Clé éphémère indisponible.');
        }
        $asPublic = self::publicPoint($as);
        $ecdh = openssl_pkey_derive(self::publicKeyFromPoint($uaPublic), $as, 32);
        if ($ecdh === false || strlen($ecdh) !== 32) {
            throw new RuntimeException('Échange de clés ECDH impossible.');
        }
        $ecdh = str_pad($ecdh, 32, "\0", STR_PAD_LEFT);
        $salt ??= random_bytes(16);
        // RFC 8291 §3.4 : IKM = HKDF(auth_secret, ecdh_secret, "WebPush: info" || 0x00 || ua_public || as_public, 32)
        $ikm = self::hkdf($authSecret, $ecdh, "WebPush: info\x00" . $uaPublic . $asPublic, 32);
        // RFC 8188 §2.2 et §2.3
        $cek = self::hkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = self::hkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);
        $tag = '';
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Chiffrement AES-GCM impossible.');
        }
        $recordSize = 4096;
        return $salt . pack('N', $recordSize) . chr(65) . $asPublic . $cipher . $tag;
    }

    /** Jeton VAPID (JWT ES256) pour l'origine du service de push. */
    public static function vapidJwt(string $endpoint, string $subject, string $privatePem, int $ttl = 43200): string
    {
        $p = parse_url($endpoint);
        $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        $header = self::b64uEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64uEncode(json_encode(['aud' => $aud, 'exp' => time() + $ttl, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
        $data = $header . '.' . $claims;
        if (!openssl_sign($data, $der, $privatePem, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signature VAPID impossible.');
        }
        return $data . '.' . self::b64uEncode(self::derToRaw($der));
    }

    /** Signature ECDSA DER (SEQUENCE{INTEGER r, INTEGER s}) → r||s sur 64 octets (format JWS). */
    public static function derToRaw(string $der): string
    {
        $pos = 2;
        if (ord($der[1]) & 0x80) {
            $pos += ord($der[1]) & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = substr($der, $pos + 2, $len);
            $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $out;
    }
}
