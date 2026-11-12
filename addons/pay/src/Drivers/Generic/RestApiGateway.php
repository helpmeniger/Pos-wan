<?php
declare(strict_types=1);

namespace Addons\Pay\Drivers\Generic;

use Addons\Pay\Drivers\AbstractDriver;
use Addons\Pay\GatewayResult;
use Addons\Pay\Secrets;
use App\Core\DB;

/**
 * Connecteur générique REST / JSON. Il ne devient compatible avec un prestataire qu'une fois configuré
 * d'après la documentation officielle de son API et testé sur son environnement de test (sandbox).
 *
 * config_json (aucun secret) :
 *  base_url, verify_ssl, headers{}, auth{type: none|api_key|bearer|basic|oauth2, header, token_url, scope},
 *  endpoints{test|initiate|status|cancel|void|refund|refund_status: {method, path, body}}   (gabarits {{chemin}}, {{json:chemin}})
 *  response{reference, status, amount, currency, fees, redirect_url, authorization_code, payer_hint, refund_reference, refund_status}  (chemins)
 *  status_map{valeur du prestataire: succeeded|pending|processing|declined|cancelled|expired}
 *  refund_status_map{valeur: refunded|pending|declined}
 *  amount_unit (major|minor : centimes), reject_http[400,402,409,422]
 *  webhook{signature_header, prefix, encoding(hex|base64), reference, status, amount, currency, event_id}
 * Secrets : api_key, bearer_token, basic_user, basic_password, client_id, client_secret, webhook_secret.
 */
class RestApiGateway extends AbstractDriver
{
    public static function info(): array
    {
        return ['code' => 'generic_rest', 'label' => 'API REST / JSON générique', 'kind' => 'gateway', 'transports' => ['https'], 'verified' => false,
            'notes' => 'Client HTTPS configurable (API Key, Bearer, Basic, OAuth2, SSL, mTLS). Compatible avec un prestataire seulement après configuration d\'après sa documentation officielle et essais en sandbox.'];
    }

    public static function capabilities(): array
    {
        return ['testConnection', 'initiate', 'getStatus', 'cancel', 'void', 'refund', 'getRefundStatus', 'webhook'];
    }

    // ------------------------------------------------------------------ gabarits

    protected static function at(mixed $data, string $path): mixed
    {
        if ($path === '') {
            return null;
        }
        foreach (explode('.', $path) as $k) {
            if (is_array($data) && array_key_exists($k, $data)) {
                $data = $data[$k];
            } else {
                return null;
            }
        }
        return $data;
    }

    protected static function render(string $tpl, array $vars, string $escape = 'json'): string
    {
        return (string) preg_replace_callback('/\{\{\s*(json:)?([a-zA-Z0-9_.]*)\s*\}\}/', function ($m) use ($vars, $escape) {
            $v = self::at($vars, $m[2]);
            if ($m[1] === 'json:') {
                return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            }
            $s = is_scalar($v) ? (string) $v : '';
            return match ($escape) {
                'xml' => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                'url' => rawurlencode($s),
                'json' => substr((string) json_encode($s, JSON_UNESCAPED_UNICODE), 1, -1),
                default => $s,
            };
        }, $tpl);
    }

    /** Montant envoyé : unités principales ou centimes selon le prestataire. */
    protected function amountOut(float $a): float|int
    {
        return ($this->config['amount_unit'] ?? 'major') === 'minor' ? (int) round($a * 100) : $a;
    }

    protected function amountIn(mixed $v): ?float
    {
        if (!is_numeric($v)) {
            return null;
        }
        return ($this->config['amount_unit'] ?? 'major') === 'minor' ? round((float) $v / 100, 2) : round((float) $v, 2);
    }

    // ------------------------------------------------------------------ transport

    protected function endpoint(string $name): ?array
    {
        $e = $this->config['endpoints'][$name] ?? null;
        return is_array($e) && !empty($e['path']) ? $e : null;
    }

    protected function url(string $path, array $vars): ?string
    {
        $base = rtrim((string) ($this->config['base_url'] ?? ''), '/');
        $rel = ltrim(self::render($path, $vars, 'url'), '/');
        $url = $rel === '' ? $base : $base . '/' . $rel;
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || !parse_url($url, PHP_URL_HOST) || ($scheme !== 'https' && $this->prod())) {
            return null; // HTTPS obligatoire en production
        }
        return $url;
    }

    protected function authHeaders(): array|GatewayResult
    {
        $a = (array) ($this->config['auth'] ?? []);
        switch ((string) ($a['type'] ?? 'none')) {
            case 'none':
                return [];
            case 'api_key':
                $k = $this->secret('api_key');
                return $k ? [((string) ($a['header'] ?? 'X-Api-Key')) . ': ' . $k] : GatewayResult::make(GatewayResult::CONFIG_ERROR, 'Clé d\'API non renseignée.');
            case 'bearer':
                $k = $this->secret('bearer_token');
                return $k ? ['Authorization: Bearer ' . $k] : GatewayResult::make(GatewayResult::CONFIG_ERROR, 'Jeton (Bearer) non renseigné.');
            case 'basic':
                $u = $this->secret('basic_user');
                $p = $this->secret('basic_password');
                return $u !== null && $p !== null ? ['Authorization: Basic ' . base64_encode($u . ':' . $p)] : GatewayResult::make(GatewayResult::CONFIG_ERROR, 'Identifiants non renseignés.');
            case 'oauth2':
                $t = $this->oauthToken();
                return $t instanceof GatewayResult ? $t : ['Authorization: Bearer ' . $t];
        }
        return GatewayResult::make(GatewayResult::CONFIG_ERROR, 'Type d\'authentification inconnu.');
    }

    protected function oauthToken(bool $force = false): string|GatewayResult
    {
        $id = (int) ($this->target['id'] ?? 0);
        if (!$force && ($cached = \Addons\Pay\Secrets::get('connector', $id, 'oauth_token', true)) !== null) {
            return $cached;
        }
        $a = (array) ($this->config['auth'] ?? []);
        $cid = $this->secret('client_id');
        $sec = $this->secret('client_secret');
        $tokenUrl = (string) ($a['token_url'] ?? '');
        if (!$cid || !$sec || !preg_match($this->prod() ? '#^https://#i' : '#^https?://#i', $tokenUrl)) {
            return GatewayResult::make(GatewayResult::CONFIG_ERROR, 'OAuth2 : URL du jeton (HTTPS), client_id ou client_secret manquant.');
        }
        $r = $this->http('POST', $tokenUrl, ['Content-Type: application/x-www-form-urlencoded', 'Authorization: Basic ' . base64_encode($cid . ':' . $sec)],
            http_build_query(array_filter(['grant_type' => 'client_credentials', 'scope' => $a['scope'] ?? null])));
        if ($r['error']) {
            return GatewayResult::make($r['timeout'] ? GatewayResult::TIMEOUT : GatewayResult::UNREACHABLE, 'OAuth2 : ' . $r['error']);
        }
        $j = json_decode($r['body'], true);
        if ($r['status'] >= 400 || !is_array($j) || empty($j['access_token'])) {
            return GatewayResult::make(GatewayResult::AUTH_FAILED, 'OAuth2 : jeton refusé (HTTP ' . $r['status'] . ').');
        }
        Secrets::put('connector', $id, 'oauth_token', (string) $j['access_token'], null, date('Y-m-d H:i:s', time() + max(60, (int) ($j['expires_in'] ?? 3600))));
        return (string) $j['access_token'];
    }

    /** @return array{status: int, body: string, error: ?string, timeout: bool, sent: string} */
    protected function http(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        $verify = $this->prod() || ($this->config['verify_ssl'] ?? true) !== false;
        $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout()), CURLOPT_TIMEOUT => $this->timeout(), CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0];
        foreach ($this->certificates() as $kind => $path) {
            $opts[[ 'ca' => CURLOPT_CAINFO, 'client_cert' => CURLOPT_SSLCERT, 'client_key' => CURLOPT_SSLKEY][$kind]] = $path;
        }
        if (($pw = $this->secret('client_key_password')) !== null) {
            $opts[CURLOPT_KEYPASSWD] = $pw;
        }
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $out = ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => is_string($resp) ? $resp : '', 'error' => $errno ? curl_error($ch) : null,
            'timeout' => $errno === CURLE_OPERATION_TIMEDOUT, 'sent' => $method . ' ' . $url . "\n" . implode("\n", $headers) . "\n\n" . (string) $body];
        curl_close($ch);
        return $out;
    }

    /** Certificats (CA, client) déposés pour ce connecteur, rangés hors du dossier public. */
    protected function certificates(): array
    {
        $out = [];
        $dir = STORAGE_PATH . '/pay/certs/' . (int) ($this->target['id'] ?? 0);
        foreach (['ca', 'client_cert', 'client_key'] as $k) {
            if (is_file("$dir/$k.pem")) {
                $out[$k] = "$dir/$k.pem";
            }
        }
        return $out;
    }

    /** Appel d'un point d'accès et décodage JSON. @return array{0: ?array, 1: GatewayResult|null, 2: array} */
    protected function request(string $name, array $vars, bool $withBody): array
    {
        $ep = $this->endpoint($name);
        if (!$ep) {
            return [null, GatewayResult::unsupported($name, static::info()['label'] . ' (point d\'accès non configuré)'), []];
        }
        $url = $this->url((string) $ep['path'], $vars);
        if (!$url) {
            return [null, GatewayResult::make(GatewayResult::CONFIG_ERROR, 'URL invalide (HTTPS obligatoire en production).'), []];
        }
        $auth = $this->authHeaders();
        if ($auth instanceof GatewayResult) {
            return [null, $auth, []];
        }
        $headers = ['Accept: application/json'];
        foreach ((array) ($this->config['headers'] ?? []) as $k => $v) {
            if (preg_match('/^[A-Za-z0-9-]{1,60}$/', (string) $k) && !preg_match('/[\r\n]/', (string) $v)) {
                $headers[] = $k . ': ' . self::render((string) $v, $vars, 'raw');
            }
        }
        $body = null;
        if ($withBody) {
            $body = !empty($ep['body']) ? self::render((string) $ep['body'], $vars) : (string) json_encode($vars, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Idempotency-Key: ' . substr((string) ($vars['idempotency_key'] ?? ''), 0, 64);
        }
        $method = strtoupper((string) ($ep['method'] ?? ($withBody ? 'POST' : 'GET')));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return [null, GatewayResult::make(GatewayResult::CONFIG_ERROR, 'Méthode HTTP non autorisée.'), []];
        }
        $r = $this->http($method, $url, array_merge($headers, $auth), $body);
        if ($r['status'] === 401 && (($this->config['auth']['type'] ?? '') === 'oauth2')) {
            $t = $this->oauthToken(true);
            if (!$t instanceof GatewayResult) {
                $r = $this->http($method, $url, array_merge($headers, ['Authorization: Bearer ' . $t]), $body);
            }
        }
        $meta = ['sent' => $r['sent'], 'body' => $r['body'], 'http' => $r['status']];
        if ($r['error'] !== null) {
            return [null, GatewayResult::make($r['timeout'] ? GatewayResult::TIMEOUT : GatewayResult::UNREACHABLE, 'Connexion impossible : ' . $r['error'], [], $r['sent']), $meta];
        }
        $code = $r['status'];
        $j = json_decode($r['body'], true);
        if ($code === 401 || $code === 403) {
            return [null, GatewayResult::make(GatewayResult::AUTH_FAILED, 'Authentification refusée (HTTP ' . $code . ').', [], $r['sent'], $r['body'], $code), $meta];
        }
        if ($code === 404 && in_array($name, ['status', 'refund_status'], true)) {
            return [null, GatewayResult::make(GatewayResult::NOT_FOUND, 'Transaction inconnue du prestataire.', [], $r['sent'], $r['body'], $code), $meta];
        }
        if ($code >= 500 || in_array($code, [408, 429], true)) {
            return [null, GatewayResult::make(GatewayResult::TIMEOUT, 'Prestataire indisponible (HTTP ' . $code . ') : issue inconnue.', [], $r['sent'], $r['body'], $code), $meta];
        }
        if ($code >= 400) {
            $reject = array_map('intval', (array) ($this->config['reject_http'] ?? [400, 402, 409, 422]));
            return [null, GatewayResult::make(in_array($code, $reject, true) ? GatewayResult::DECLINED : GatewayResult::CONFIG_ERROR, 'Demande refusée (HTTP ' . $code . ').', [], $r['sent'], $r['body'], $code), $meta];
        }
        if (!is_array($j)) {
            return [null, GatewayResult::make(GatewayResult::INVALID_RESPONSE, 'Réponse illisible (JSON attendu).', [], $r['sent'], $r['body'], $code), $meta];
        }
        return [$j, null, $meta];
    }

    /** Réponse JSON → résultat normalisé selon response + status_map. */
    protected function interpret(array $j, array $meta, string $mapKey = 'status_map', string $statusPath = 'status'): GatewayResult
    {
        $resp = (array) ($this->config['response'] ?? []);
        $raw = self::at($j, (string) ($resp[$statusPath] ?? ''));
        $map = (array) ($this->config[$mapKey] ?? []);
        $status = is_scalar($raw) ? ($map[(string) $raw] ?? null) : null;
        $allowed = $mapKey === 'status_map' ? ['succeeded', 'pending', 'processing', 'declined', 'cancelled', 'expired'] : ['refunded', 'pending', 'declined'];
        if (!in_array($status, $allowed, true)) {
            return GatewayResult::make(GatewayResult::INVALID_RESPONSE, 'Statut du prestataire non reconnu (« ' . (is_scalar($raw) ? (string) $raw : '?') . ' ») : complétez status_map.', [], $meta['sent'], $meta['body'], $meta['http']);
        }
        $data = [];
        foreach (['provider_reference' => 'reference', 'currency' => 'currency', 'redirect_url' => 'redirect_url', 'authorization_code' => 'authorization_code', 'payer_hint' => 'payer_hint'] as $k => $f) {
            $v = self::at($j, (string) ($resp[$f] ?? ''));
            if (is_scalar($v) && (string) $v !== '') {
                $data[$k] = (string) $v;
            }
        }
        if ($mapKey === 'refund_status_map' && ($v = self::at($j, (string) ($resp['refund_reference'] ?? ''))) !== null && is_scalar($v)) {
            $data['provider_reference'] = (string) $v;
        }
        foreach (['amount', 'fees'] as $f) {
            $v = $this->amountIn(self::at($j, (string) ($resp[$f] ?? '')));
            if ($v !== null) {
                $data[$f] = $v;
            }
        }
        return GatewayResult::make($status, 'Réponse du prestataire : ' . (string) $raw, $data, $meta['sent'], $meta['body'], $meta['http']);
    }

    protected function vars(array $p): array
    {
        return array_merge($p, ['amount' => $this->amountOut((float) ($p['amount'] ?? 0)), 'amount_decimal' => number_format((float) ($p['amount'] ?? 0), 2, '.', '')]);
    }

    public function testConnection(): GatewayResult
    {
        [$j, $err, $meta] = $this->request('test', [], false);
        return $err ?? GatewayResult::make(GatewayResult::OK, 'Prestataire joignable, authentification acceptée.', [], $meta['sent'], $meta['body'], $meta['http']);
    }

    public function initiate(array $p): GatewayResult
    {
        [$j, $err, $meta] = $this->request('initiate', $this->vars($p), true);
        return $err ?? $this->interpret($j, $meta);
    }

    public function getStatus(array $tx): GatewayResult
    {
        [$j, $err, $meta] = $this->request('status', ['reference' => $tx['provider_reference'], 'number' => $tx['number'], 'idempotency_key' => $tx['idempotency_key']], false);
        return $err ?? $this->interpret($j, $meta);
    }

    public function cancel(array $tx): GatewayResult
    {
        $ep = $tx['status'] === 'succeeded' ? 'void' : 'cancel';
        [$j, $err, $meta] = $this->request($ep, ['reference' => $tx['provider_reference'], 'number' => $tx['number'], 'idempotency_key' => $tx['idempotency_key']], true);
        return $err ?? $this->interpret($j, $meta);
    }

    public function refund(array $tx, array $r): GatewayResult
    {
        [$j, $err, $meta] = $this->request('refund', $this->vars($r) + ['reference' => $tx['provider_reference'], 'transaction_number' => $tx['number']], true);
        return $err ?? $this->interpret($j, $meta, 'refund_status_map', 'refund_status');
    }

    public function getRefundStatus(array $tx, array $refund): GatewayResult
    {
        [$j, $err, $meta] = $this->request('refund_status', ['reference' => $tx['provider_reference'], 'refund_reference' => $refund['provider_reference'], 'number' => $refund['number'], 'idempotency_key' => $refund['idempotency_key']], false);
        return $err ?? $this->interpret($j, $meta, 'refund_status_map', 'refund_status');
    }

    /** Vérifie la signature d'une notification (HMAC-SHA256 du corps avec le secret du prestataire, sinon celui du webhook). */
    public function webhookSignatureValid(string $body, array $headers, string $defaultSecret): bool
    {
        $w = (array) ($this->config['webhook'] ?? []);
        $name = strtolower((string) ($w['signature_header'] ?? 'X-Pay-Signature'));
        $sent = '';
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === $name) {
                $sent = trim((string) $v);
            }
        }
        $secret = $this->secret('webhook_secret') ?? $defaultSecret;
        $mac = hash_hmac('sha256', $body, $secret, ($w['encoding'] ?? 'hex') === 'base64');
        $expected = (string) ($w['prefix'] ?? 'sha256=') . (($w['encoding'] ?? 'hex') === 'base64' ? base64_encode($mac) : $mac);
        return $sent !== '' && hash_equals($expected, $sent);
    }

    public function parseWebhook(string $body, array $headers): ?array
    {
        $j = json_decode($body, true);
        $w = (array) ($this->config['webhook'] ?? []);
        if (!is_array($j)) {
            return null;
        }
        $ref = self::at($j, (string) ($w['reference'] ?? 'reference'));
        $raw = self::at($j, (string) ($w['status'] ?? 'status'));
        return is_scalar($ref) && (string) $ref !== '' ? ['reference' => (string) $ref, 'status' => (string) (((array) ($this->config['status_map'] ?? []))[(string) $raw] ?? ''),
            'amount' => $this->amountIn(self::at($j, (string) ($w['amount'] ?? 'amount'))), 'currency' => self::at($j, (string) ($w['currency'] ?? 'currency')),
            'event_id' => (string) (self::at($j, (string) ($w['event_id'] ?? 'event_id')) ?? '')] : null;
    }
}
