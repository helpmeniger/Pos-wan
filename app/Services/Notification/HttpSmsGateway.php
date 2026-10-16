<?php
declare(strict_types=1);

namespace App\Services\Notification;

use App\Core\Config;
use RuntimeException;

/**
 * Pilote SMS générique pour les API HTTP des fournisseurs (agrégateurs SMS, opérateurs) :
 * l'adresse, le format du corps et les en-têtes se règlent dans .env, sans programmer.
 *
 *   SMS_DRIVER=http
 *   SMS_HTTP_URL=https://api.fournisseur.example/sms/send
 *   SMS_HTTP_FORMAT=json                      (json | form | get)
 *   SMS_HTTP_BODY={"to":"{to}","from":"{sender}","text":"{message}"}
 *   SMS_HTTP_HEADERS="Authorization: Bearer VOTRE_CLE"   (plusieurs en-têtes séparés par ||)
 *   SMS_HTTP_PHONE_FORMAT=e164                (e164 = +22790123456 | digits = 22790123456 | local = 90123456)
 *
 * Reportez-vous à la documentation officielle de votre fournisseur pour les noms des champs.
 */
final class HttpSmsGateway implements SmsGateway
{
    /** Remplaçable dans les tests : fn(string $method, string $url, array $headers, ?string $body): int */
    public static $transport = null;

    public function name(): string
    {
        return 'http';
    }

    public function send(string $phone, string $message): void
    {
        $c = Config::get('services.sms.http');
        if (empty($c['url'])) {
            throw new RuntimeException('SMS_HTTP_URL non configuré.');
        }
        $to = match ($c['phone_format'] ?? 'e164') {
            'digits' => ltrim($phone, '+'),
            'local' => preg_replace('/^\+?227/', '', $phone),
            default => $phone,
        };
        $vars = ['{to}' => $to, '{message}' => $message, '{sender}' => (string) Config::get('services.sms.sender', 'MARWA')];
        $format = $c['format'] ?? 'json';
        $template = (string) ($c['body'] ?: '{"to":"{to}","from":"{sender}","text":"{message}"}');
        $headers = array_values(array_filter(array_map('trim', explode('||', (string) ($c['headers'] ?? '')))));
        $url = (string) $c['url'];
        $body = null;
        if ($format === 'json') {
            $body = strtr($template, array_map(fn ($v) => substr(json_encode($v, JSON_UNESCAPED_UNICODE), 1, -1), $vars));
            $headers[] = 'Content-Type: application/json';
            $method = 'POST';
        } elseif ($format === 'form') {
            $body = strtr($template, array_map('rawurlencode', $vars));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $method = 'POST';
        } else {
            $url .= (str_contains($url, '?') ? '&' : '?') . strtr($template, array_map('rawurlencode', $vars));
            $method = 'GET';
        }
        $status = self::$transport ? (self::$transport)($method, $url, $headers, $body) : self::request($method, $url, $headers, $body);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Fournisseur SMS : réponse HTTP $status.");
        }
    }

    private static function request(string $method, string $url, array $headers, ?string $body): int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($status === 0) {
            throw new RuntimeException('Fournisseur SMS injoignable : ' . $err);
        }
        return $status;
    }
}
