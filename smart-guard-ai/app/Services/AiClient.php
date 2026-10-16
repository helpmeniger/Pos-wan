<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Core\Logger;

/**
 * Client HTTP du service IA Python (local). Authentification par clé partagée X-API-Key.
 * Le navigateur n'accède JAMAIS directement au service IA : tout passe par PHP, qui
 * vérifie les droits de l'utilisateur.
 */
final class AiClient
{
    public static function baseUrl(): string
    {
        return rtrim((string) Env::get('AI_SERVICE_URL', 'http://127.0.0.1:8765'), '/');
    }

    /** @return array{ok:bool,status:int,data:mixed,error:?string,raw?:string,content_type?:string} */
    public static function request(string $method, string $path, ?array $json = null, array $opts = []): array
    {
        $ch = curl_init(self::baseUrl() . $path);
        $headers = ['X-API-Key: ' . (string) Env::get('AI_SERVICE_KEY', ''), 'Accept: application/json'];
        $timeout = $opts['timeout'] ?? Env::int('AI_TIMEOUT_SEC', 20);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADER => false,
        ]);
        if (isset($opts['multipart'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['multipart']);
        } elseif ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            Logger::warning('Service IA injoignable', ['path' => $path, 'error' => $err]);
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Service IA injoignable. Vérifiez qu\'il est démarré (ai-service\\run_ai.bat).'];
        }
        if (!empty($opts['raw'])) {
            return ['ok' => $status === 200, 'status' => $status, 'data' => null, 'raw' => (string) $body, 'content_type' => $ctype,
                'error' => $status === 200 ? null : 'Erreur du service IA (' . $status . ')'];
        }
        $data = json_decode((string) $body, true);
        $ok = $status >= 200 && $status < 300;
        $error = null;
        if (!$ok) {
            $detail = is_array($data) ? ($data['detail'] ?? $data['error'] ?? null) : null;
            if (is_array($detail)) {
                $detail = implode(' ; ', array_map(static fn ($d) => is_array($d) ? ($d['msg'] ?? json_encode($d)) : (string) $d, $detail));
            }
            $error = 'Service IA : ' . ($detail ?: 'erreur HTTP ' . $status);
        }
        return ['ok' => $ok, 'status' => $status, 'data' => $data, 'error' => $error];
    }

    public static function health(): array
    {
        return self::request('GET', '/health', null, ['timeout' => 4]);
    }
}
