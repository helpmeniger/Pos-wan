<?php
declare(strict_types=1);

namespace Addons\Fiscal\Drivers\Generic;

use Addons\Fiscal\DriverResult;
use Addons\Fiscal\Drivers\AbstractDriver;
use Addons\Fiscal\Secrets;
use App\Core\DB;

/**
 * Pilote générique REST / JSON (HTTP). Compatible avec une API seulement si sa configuration
 * (URL, authentification, gabarit de requête, correspondance de la réponse) a été établie
 * d'après la documentation officielle de cette API et testée sur son environnement de test.
 *
 * config_json (non secret) :
 *  base_url, verify_ssl (true), headers {…}, auth {type: none|api_key|bearer|basic|oauth2, header, token_url, scope},
 *  endpoints {test|certify|status|certificate|device|daily_report|close_day|cancel|credit_note: {method, path, body}},
 *  request_template (gabarit JSON avec {{chemin}} ; absent = facture normalisée envoyée telle quelle),
 *  response_map {fiscal_number, device_id, signature, qr_payload, counters: chemin}, success {path, values[]},
 *  reject_http [400, 409, 422].
 * Secrets (fiscal_api_credentials) : api_key, bearer_token, basic_user, basic_password, client_id, client_secret.
 * Certificats (fiscal_certificates) : ca, client_cert, client_key (+ mot de passe chiffré).
 */
class RestApiDriver extends AbstractDriver
{
    use Mapping;

    public static function info(): array
    {
        return ['code' => 'generic_rest', 'label' => 'API REST / JSON générique', 'kind' => 'api', 'transports' => ['http', 'rest', 'cloud'], 'verified' => false,
            'notes' => 'Client HTTP configurable. La compatibilité avec une API donnée dépend de la configuration établie d\'après sa documentation officielle.'];
    }

    public static function capabilities(): array
    {
        return ['testConnection', 'authenticate', 'certifyInvoice', 'getInvoiceStatus', 'getCertificate', 'getDeviceInfo', 'getDailyReport', 'closeDay', 'cancelInvoice', 'issueCreditNote'];
    }

    protected const ENDPOINT_OF = ['testConnection' => 'test', 'certifyInvoice' => 'certify', 'getInvoiceStatus' => 'status', 'getCertificate' => 'certificate',
        'getDeviceInfo' => 'device', 'getDailyReport' => 'daily_report', 'closeDay' => 'close_day', 'cancelInvoice' => 'cancel', 'issueCreditNote' => 'credit_note'];

    protected function endpoint(string $fn): ?array
    {
        $e = $this->config['endpoints'][self::ENDPOINT_OF[$fn] ?? ''] ?? null;
        return is_array($e) && !empty($e['path']) ? $e : null;
    }

    /** Vérifie l'URL : https obligatoire en production, pas de schéma exotique. */
    protected function url(string $path, array $vars): ?string
    {
        $base = rtrim((string) ($this->config['base_url'] ?? ''), '/');
        $rel = ltrim(self::render($path, array_map(fn($v) => is_scalar($v) ? rawurlencode((string) $v) : $v, $vars)), '/');
        $url = $rel === '' ? $base : $base . '/' . $rel;
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || !parse_url($url, PHP_URL_HOST)) {
            return null;
        }
        if ($scheme !== 'https' && ($this->connector['environment'] ?? 'test') === 'prod') {
            return null;
        }
        return $url;
    }

    // ------------------------------------------------------------------ authentification

    /** En-têtes d'authentification (valeurs secrètes lues côté serveur seulement). */
    protected function authHeaders(): array|DriverResult
    {
        $a = (array) ($this->config['auth'] ?? []);
        $type = (string) ($a['type'] ?? 'none');
        switch ($type) {
            case 'none':
                return [];
            case 'api_key':
                $k = $this->secret('api_key');
                return $k ? [((string) ($a['header'] ?? 'X-Api-Key')) . ': ' . $k] : DriverResult::fail(DriverResult::CONFIG_ERROR, 'Clé d\'API non renseignée.');
            case 'bearer':
                $k = $this->secret('bearer_token');
                return $k ? ['Authorization: Bearer ' . $k] : DriverResult::fail(DriverResult::CONFIG_ERROR, 'Jeton (Bearer) non renseigné.');
            case 'basic':
                $u = $this->secret('basic_user');
                $p = $this->secret('basic_password');
                return $u !== null && $p !== null ? ['Authorization: Basic ' . base64_encode($u . ':' . $p)] : DriverResult::fail(DriverResult::CONFIG_ERROR, 'Identifiants non renseignés.');
            case 'oauth2':
                $t = $this->oauthToken();
                return $t instanceof DriverResult ? $t : ['Authorization: Bearer ' . $t];
        }
        return DriverResult::fail(DriverResult::CONFIG_ERROR, 'Type d\'authentification inconnu.');
    }

    /** OAuth2 « client credentials », jeton mis en cache chiffré jusqu'à son expiration. */
    private function oauthToken(bool $force = false): string|DriverResult
    {
        $id = (int) ($this->connector['id'] ?? 0);
        if (!$force) {
            $row = DB::one('SELECT value_enc FROM fiscal_api_credentials WHERE connector_id = ? AND ckey = ? AND expires_at > NOW() + INTERVAL 30 SECOND', [$id, 'oauth_token']);
            if ($row) {
                return Secrets::decrypt((string) $row['value_enc']);
            }
        }
        $a = (array) ($this->config['auth'] ?? []);
        $cid = $this->secret('client_id');
        $sec = $this->secret('client_secret');
        $tokenUrl = (string) ($a['token_url'] ?? '');
        if (!$cid || !$sec || !$tokenUrl || !preg_match('#^https?://#i', $tokenUrl) || (($this->connector['environment'] ?? '') === 'prod' && !str_starts_with(strtolower($tokenUrl), 'https://'))) {
            return DriverResult::fail(DriverResult::CONFIG_ERROR, 'OAuth2 : URL du jeton, identifiant ou secret client manquant.');
        }
        $body = http_build_query(array_filter(['grant_type' => 'client_credentials', 'scope' => $a['scope'] ?? null]));
        $r = $this->http('POST', $tokenUrl, ['Content-Type: application/x-www-form-urlencoded', 'Authorization: Basic ' . base64_encode($cid . ':' . $sec)], $body);
        if ($r['error']) {
            return DriverResult::fail($r['timeout'] ? DriverResult::TIMEOUT : DriverResult::UNREACHABLE, 'OAuth2 : ' . $r['error']);
        }
        $j = json_decode($r['body'], true);
        if ($r['status'] >= 400 || !is_array($j) || empty($j['access_token'])) {
            return DriverResult::fail(DriverResult::AUTH_FAILED, 'OAuth2 : jeton refusé (HTTP ' . $r['status'] . ').');
        }
        Secrets::put($id, 'oauth_token', (string) $j['access_token']);
        DB::run('UPDATE fiscal_api_credentials SET expires_at = NOW() + INTERVAL ? SECOND WHERE connector_id = ? AND ckey = ?', [max(60, (int) ($j['expires_in'] ?? 3600)), $id, 'oauth_token']);
        return (string) $j['access_token'];
    }

    // ------------------------------------------------------------------ transport HTTP

    /** @return array{status: int, body: string, error: ?string, timeout: bool, sent: string} */
    protected function http(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout()), CURLOPT_TIMEOUT => $this->timeout(), CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        // Vérification SSL : toujours en production ; désactivable seulement en test, explicitement
        $verify = ($this->connector['environment'] ?? 'test') === 'prod' || ($this->config['verify_ssl'] ?? true) !== false;
        $opts[CURLOPT_SSL_VERIFYPEER] = $verify;
        $opts[CURLOPT_SSL_VERIFYHOST] = $verify ? 2 : 0;
        foreach ($this->certificates() as $kind => $c) {
            if ($kind === 'ca') {
                $opts[CURLOPT_CAINFO] = $c['path'];
            } elseif ($kind === 'client_cert') {
                $opts[CURLOPT_SSLCERT] = $c['path'];
            } elseif ($kind === 'client_key') {
                $opts[CURLOPT_SSLKEY] = $c['path'];
                if (!empty($c['password_enc'])) {
                    $opts[CURLOPT_KEYPASSWD] = Secrets::decrypt((string) $c['password_enc']);
                }
            }
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

    protected function certificates(): array
    {
        if (empty($this->connector['id'])) {
            return [];
        }
        $out = [];
        foreach (DB::all('SELECT kind, path, password_enc FROM fiscal_certificates WHERE connector_id = ?', [$this->connector['id']]) as $c) {
            if (is_file($c['path'])) {
                $out[$c['kind']] = $c;
            }
        }
        return $out;
    }

    /** Appel d'un point d'accès configuré et interprétation normalisée de la réponse. */
    protected function request(string $fn, array $vars, ?string $body = null, bool $expectCertification = false): DriverResult
    {
        $ep = $this->endpoint($fn);
        if (!$ep) {
            return DriverResult::unsupported($fn, static::info()['label'] . ' (point d\'accès non configuré)');
        }
        $url = $this->url((string) $ep['path'], $vars);
        if (!$url) {
            return DriverResult::fail(DriverResult::CONFIG_ERROR, 'URL invalide (HTTPS obligatoire en production).');
        }
        $auth = $this->authHeaders();
        if ($auth instanceof DriverResult) {
            return $auth;
        }
        $headers = ['Accept: application/json'];
        foreach ((array) ($this->config['headers'] ?? []) as $k => $v) {
            if (preg_match('/^[A-Za-z0-9-]{1,60}$/', (string) $k) && !preg_match('/[\r\n]/', (string) $v)) {
                $headers[] = $k . ': ' . $v;
            }
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $method = strtoupper((string) ($ep['method'] ?? ($body !== null ? 'POST' : 'GET')));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return DriverResult::fail(DriverResult::CONFIG_ERROR, 'Méthode HTTP non autorisée.');
        }
        $r = $this->http($method, $url, array_merge($headers, $auth), $body);
        if ($r['status'] === 401 && (($this->config['auth']['type'] ?? '') === 'oauth2')) {
            $t = $this->oauthToken(true); // jeton expiré côté serveur : un seul renouvellement
            if (!$t instanceof DriverResult) {
                $r = $this->http($method, $url, array_merge($headers, ['Authorization: Bearer ' . $t]), $body);
            }
        }
        return $this->interpret($fn, $r, $expectCertification);
    }

    protected function interpret(string $fn, array $r, bool $expectCertification): DriverResult
    {
        $sent = $r['sent'];
        if ($r['error'] !== null) {
            return DriverResult::fail($r['timeout'] ? DriverResult::TIMEOUT : DriverResult::UNREACHABLE, 'Connexion impossible : ' . $r['error'], [], $sent);
        }
        $code = $r['status'];
        $decoded = json_decode($r['body'], true);
        if ($code === 401 || $code === 403) {
            return DriverResult::fail(DriverResult::AUTH_FAILED, 'Authentification refusée (HTTP ' . $code . ').', [], $sent, $r['body'], $code);
        }
        if ($code === 404 && $fn === 'getInvoiceStatus') {
            return DriverResult::fail(DriverResult::NOT_FOUND, 'Facture inconnue du service.', [], $sent, $r['body'], $code);
        }
        if ($code >= 500 || $code === 429 || $code === 408) {
            return DriverResult::fail(DriverResult::UNREACHABLE, 'Service indisponible (HTTP ' . $code . ').', [], $sent, $r['body'], $code);
        }
        if ($code >= 400) {
            $reject = array_map('intval', (array) ($this->config['reject_http'] ?? [400, 409, 422]));
            return DriverResult::fail(in_array($code, $reject, true) ? DriverResult::REJECTED : DriverResult::CONFIG_ERROR, 'Requête refusée (HTTP ' . $code . ').', is_array($decoded) ? ['body' => $decoded] : [], $sent, $r['body'], $code);
        }
        if ($code < 200 || $code >= 300 || !is_array($decoded)) {
            return DriverResult::fail(DriverResult::INVALID_RESPONSE, 'Réponse illisible (HTTP ' . $code . ', JSON attendu).', [], $sent, $r['body'], $code);
        }
        // Le critère de succès métier (config.success) s'applique aux réponses de certification et d'état
        $biz = $expectCertification || $fn === 'getInvoiceStatus' ? $this->businessSuccess($decoded) : null;
        if ($biz === false) {
            return DriverResult::fail($expectCertification ? DriverResult::REJECTED : DriverResult::INVALID_RESPONSE, 'Le service indique un échec.', ['body' => $decoded], $sent, $r['body'], $code);
        }
        if ($expectCertification) {
            return new DriverResult(DriverResult::CERTIFIED, 'Réponse positive du service.', $this->mapResponse($decoded), $sent, $r['body'], $code);
        }
        return DriverResult::ok($decoded, 'OK', $sent, $r['body'], $code);
    }

    private function payload(array $invoice): string
    {
        $tpl = $this->config['request_template'] ?? null;
        if (is_string($tpl) && trim($tpl) !== '') {
            return self::render($tpl, $invoice, 'json');
        }
        $copy = $invoice;
        unset($copy['unmapped_rates']);
        return (string) json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function testConnection(): DriverResult
    {
        return $this->request('testConnection', []);
    }

    public function authenticate(): DriverResult
    {
        $h = $this->authHeaders();
        return $h instanceof DriverResult ? $h : DriverResult::ok([], 'Identifiants disponibles' . ((($this->config['auth']['type'] ?? '') === 'oauth2') ? ' (jeton OAuth2 obtenu).' : '.'));
    }

    public function certifyInvoice(array $invoice): DriverResult
    {
        return $this->request('certifyInvoice', $invoice, $this->payload($invoice), true);
    }

    public function issueCreditNote(array $creditNote): DriverResult
    {
        return $this->request('issueCreditNote', $creditNote, $this->payload($creditNote), true);
    }

    public function getInvoiceStatus(string $idempotencyKey): DriverResult
    {
        $r = $this->request('getInvoiceStatus', ['idempotency_key' => $idempotencyKey]);
        if (!$r->isOk()) {
            return $r;
        }
        $decoded = json_decode((string) $r->response, true) ?: [];
        $data = $this->mapResponse($decoded);
        $biz = $this->businessSuccess($decoded);
        if (empty($data['fiscal_number']) || $biz === false) {
            return DriverResult::fail(DriverResult::NOT_FOUND, 'Aucune certification trouvée pour cette facture.', [], $r->request, $r->response, $r->httpStatus);
        }
        return new DriverResult(DriverResult::CERTIFIED, 'Certification trouvée sur le service.', $data, $r->request, $r->response, $r->httpStatus);
    }

    public function getCertificate(string $fiscalNumber): DriverResult
    {
        return $this->request('getCertificate', ['fiscal_number' => $fiscalNumber]);
    }

    public function getDeviceInfo(): DriverResult
    {
        return $this->request('getDeviceInfo', []);
    }

    public function getDailyReport(string $date): DriverResult
    {
        return $this->request('getDailyReport', ['date' => $date]);
    }

    public function closeDay(string $date): DriverResult
    {
        return $this->request('closeDay', ['date' => $date], (string) json_encode(['date' => $date]));
    }

    public function cancelInvoice(string $fiscalNumber, string $reason): DriverResult
    {
        return $this->request('cancelInvoice', ['fiscal_number' => $fiscalNumber, 'reason' => $reason], (string) json_encode(['fiscal_number' => $fiscalNumber, 'reason' => $reason], JSON_UNESCAPED_UNICODE));
    }
}
