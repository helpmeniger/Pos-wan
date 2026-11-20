<?php
declare(strict_types=1);

namespace Addons\Fiscal\Secef;

/**
 * Client du pont MARWA SECEF (service Node.js + Playwright sur CET ordinateur, 127.0.0.1).
 * Authentification : jeton partagé (en-tête X-Pont-Jeton). Les identifiants e-SECeF ne sont envoyés au pont
 * que dans le corps des requêtes qui en ont besoin ; ils ne sont jamais journalisés (voir redact()).
 */
final class PontClient
{
    public function __construct(private string $base, private string $jeton)
    {
    }

    /** @return array{ok: bool, status: int, data: array, error: ?string} */
    public function call(string $method, string $path, ?array $body = null, int $timeout = 30): array
    {
        $url = rtrim($this->base, '/') . $path;
        $host = parse_url($url, PHP_URL_HOST);
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'Le pont doit être local (127.0.0.1).'];
        }
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-Pont-Jeton: ' . $this->jeton];
        $payload = $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_UNICODE);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = $raw === false ? curl_error($ch) : null;
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $payload ?? '', 'timeout' => $timeout, 'ignore_errors' => true]]);
            $raw = @file_get_contents($url, false, $ctx);
            $status = 0;
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $status = (int) $m[1];
                }
            }
            $err = $raw === false ? 'pont injoignable' : null;
        }
        if ($raw === false || $raw === null) {
            $timeoutHit = $err !== null && stripos($err, 'timed out') !== false;
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => $timeoutHit ? 'timeout' : 'Pont MARWA SECEF injoignable (' . $err . '). Démarrez-le sur cet ordinateur.'];
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'status' => $status, 'data' => [], 'error' => 'Réponse illisible du pont (HTTP ' . $status . ').'];
        }
        return ['ok' => $status >= 200 && $status < 300 && !empty($data['ok']), 'status' => $status, 'data' => $data, 'error' => $data['erreur'] ?? null];
    }

    /** Copie d'une requête sans aucun identifiant (journaux). */
    public static function redact(array $body): array
    {
        unset($body['acces']);
        return $body;
    }
}
