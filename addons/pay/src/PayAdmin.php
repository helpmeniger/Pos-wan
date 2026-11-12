<?php
declare(strict_types=1);

namespace Addons\Pay;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/** Administration de Marwa Pay : validations, secrets, traçabilité. Toute modification est inscrite au journal d'audit. */
final class PayAdmin
{
    public const KINDS = ['card' => 'Carte bancaire', 'wallet' => 'Portefeuille / paiement en ligne', 'mobile_money' => 'Mobile Money', 'bank' => 'Banque', 'terminal' => 'TPE (terminal)', 'other' => 'Autre'];
    public const SALE_METHODS = ['card' => 'Carte', 'mobile_money' => 'Mobile money', 'transfer' => 'Virement'];
    public const SECRET_FIELDS = ['api_key' => 'Clé d\'API', 'secret_key' => 'Clé secrète', 'bearer_token' => 'Jeton (Bearer)', 'basic_user' => 'Utilisateur (Basic)',
        'basic_password' => 'Mot de passe (Basic)', 'client_id' => 'OAuth2 : client_id', 'client_secret' => 'OAuth2 : client_secret',
        'webhook_secret' => 'Secret de signature des notifications (fourni par le prestataire)', 'client_key_password' => 'Mot de passe de la clé privée (mTLS)'];
    public const SETTINGS = ['test_sales' => '0', 'require_electronic' => '0'];

    private static function audit(string $action, string $entity, ?int $id, ?array $before = null, ?array $after = null): void
    {
        Audit::log('pay.' . $action, $entity, $id, ['before' => $before ? Secrets::redact($before) : null, 'after' => $after ? Secrets::redact($after) : null]);
    }

    public static function assertNoSecrets(mixed $cfg, string $parent = ''): void
    {
        if (!is_array($cfg)) {
            return;
        }
        foreach ($cfg as $k => $v) {
            $key = (string) $k;
            if (preg_match(Secrets::SENSITIVE, $key) && !preg_match('/(_url|_path|_header|^header|^type|_map|_status|^refund_reference|_template)$/i', $key)
                && !in_array($parent, ['response', 'endpoints', 'webhook', 'status_map', 'refund_status_map'], true)) {
                throw new BusinessException("La configuration contient un champ secret « $key » : saisissez-le dans la zone « Secrets » (chiffrée).");
            }
            self::assertNoSecrets($v, $key);
        }
    }

    private static function json(string $raw): array
    {
        $raw = trim($raw);
        $cfg = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($cfg)) {
            throw new BusinessException('Configuration : JSON invalide (' . json_last_error_msg() . ').');
        }
        self::assertNoSecrets($cfg);
        return $cfg;
    }

    // ------------------------------------------------------------------ prestataires

    public static function saveProvider(array $in, ?int $id = null): int
    {
        $before = $id ? DB::one('SELECT * FROM payment_providers WHERE id = ?', [$id]) : null;
        $name = trim((string) ($in['name'] ?? ''));
        $code = strtolower(trim((string) ($in['code'] ?? ($before['code'] ?? ''))));
        $cat = (string) ($in['category'] ?? 'other');
        if ($name === '' || !preg_match('/^[a-z0-9_]{2,40}$/', $code) || !in_array($cat, ['card', 'wallet', 'mobile_money', 'bank', 'terminal', 'local', 'other'], true)) {
            throw new BusinessException('Nom, code (lettres, chiffres, _) et catégorie obligatoires.');
        }
        $verified = !empty($in['verified']) ? 1 : 0;
        $doc = trim((string) ($in['official_doc_ref'] ?? ''));
        if ($verified && $doc === '') {
            throw new BusinessException('« Intégration vérifiée » exige la référence de la documentation officielle et des essais effectués.');
        }
        $data = ['code' => $code, 'name' => mb_substr($name, 0, 120), 'category' => $cat, 'country' => strtoupper(substr(trim((string) ($in['country'] ?? '')), 0, 2)) ?: null,
            'website' => mb_substr(trim((string) ($in['website'] ?? '')), 0, 200) ?: null, 'official_doc_ref' => $doc ?: null, 'verified' => $verified,
            'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 3000) ?: null, 'active' => !empty($in['active']) || !$id ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')];
        if ($id) {
            DB::update('payment_providers', $data, 'id = ?', [$id]);
        } else {
            if (DB::value('SELECT 1 FROM payment_providers WHERE code = ?', [$code])) {
                throw new BusinessException('Ce code existe déjà.');
            }
            $id = DB::insert('payment_providers', $data);
        }
        self::audit($before ? 'provider.update' : 'provider.create', 'payment_provider', $id, $before, $data);
        return $id;
    }

    // ------------------------------------------------------------------ connecteurs (API)

    public static function connector(int $id): array
    {
        $c = DB::one('SELECT * FROM payment_connectors WHERE id = ?', [$id]);
        if (!$c) {
            throw new BusinessException('Connecteur introuvable.');
        }
        return $c;
    }

    public static function saveConnector(array $in, ?int $id = null): int
    {
        $before = $id ? self::connector($id) : null;
        $name = trim((string) ($in['name'] ?? ''));
        $driver = (string) ($in['driver'] ?? '');
        $env = (string) ($in['environment'] ?? 'test');
        if ($name === '' || mb_strlen($name) > 120 || !isset(DriverRegistry::gateways()[$driver]) || !in_array($env, ['test', 'prod'], true)) {
            throw new BusinessException('Nom, connecteur et environnement obligatoires.');
        }
        if (DriverRegistry::isSimulation($driver) && $env === 'prod') {
            throw new BusinessException('La simulation est réservée à l\'environnement de TEST.');
        }
        $cfg = self::json((string) ($in['config_json'] ?? ''));
        if ($driver === 'simulation') {
            $sc = (string) ($in['scenario'] ?? ($cfg['scenario'] ?? 'success'));
            if (!in_array($sc, ['success', 'decline', 'pending', 'pending_forever', 'network_loss', 'invalid', 'refund_decline', 'refund_pending'], true)) {
                throw new BusinessException('Scénario inconnu.');
            }
            $cfg['scenario'] = $sc;
        }
        $old = $before ? (json_decode((string) $before['config_json'], true) ?: []) : [];
        if (isset($old['openapi']) && !isset($cfg['openapi'])) {
            $cfg['openapi'] = $old['openapi'];
        }
        $store = (int) ($in['store_id'] ?? 0) ?: null;
        $prov = (int) ($in['provider_id'] ?? 0) ?: null;
        $data = ['name' => mb_substr($name, 0, 120), 'driver' => $driver, 'environment' => $env, 'provider_id' => $prov && DB::value('SELECT 1 FROM payment_providers WHERE id = ?', [$prov]) ? $prov : null,
            'account_label' => mb_substr(trim((string) ($in['account_label'] ?? '')), 0, 120) ?: null, 'store_id' => $store && DB::value('SELECT 1 FROM stores WHERE id = ?', [$store]) ? $store : null,
            'config_json' => $cfg ? json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : null,
            'timeout_s' => max(5, min(120, (int) ($in['timeout_s'] ?? 30))), 'updated_at' => date('Y-m-d H:i:s')];
        if ($id) {
            DB::update('payment_connectors', $data + ['status' => 'unknown'], 'id = ?', [$id]);
        } else {
            $id = DB::insert('payment_connectors', $data + ['active' => 0, 'created_by' => Auth::id()]);
        }
        self::audit($before ? 'connector.update' : 'connector.create', 'payment_connector', $id, $before, $data);
        return $id;
    }

    public static function toggleConnector(int $id): bool
    {
        $c = self::connector($id);
        $on = !(int) $c['active'];
        if ($on && (DriverRegistry::info($c['driver'])['skeleton'] ?? false)) {
            throw new BusinessException('Connecteur squelette non développé : activation impossible.');
        }
        DB::update('payment_connectors', ['active' => $on ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        self::audit($on ? 'connector.enable' : 'connector.disable', 'payment_connector', $id);
        return $on;
    }

    public static function deleteConnector(int $id): void
    {
        $c = self::connector($id);
        if (DB::value('SELECT 1 FROM payment_transactions WHERE connector_id = ? LIMIT 1', [$id])) {
            throw new BusinessException('Ce connecteur a des transactions (historique à conserver) : désactivez-le.');
        }
        if (DB::value('SELECT 1 FROM payment_methods WHERE connector_id = ? LIMIT 1', [$id])) {
            throw new BusinessException('Un moyen de paiement utilise ce connecteur : modifiez-le d\'abord.');
        }
        DB::run('DELETE FROM payment_api_credentials WHERE connector_id = ?', [$id]);
        DB::run('DELETE FROM payment_connectors WHERE id = ?', [$id]);
        self::removeCertificates($id);
        self::audit('connector.delete', 'payment_connector', $id, $c);
    }

    public static function test(string $type, int $id): GatewayResult
    {
        $row = $type === 'terminal' ? DB::one('SELECT * FROM payment_terminals WHERE id = ?', [$id]) : self::connector($id);
        if (!$row) {
            throw new BusinessException('Introuvable.');
        }
        $d = DriverRegistry::make($row);
        $r = PayEngine::call($d, $type, $row, null, null, 'testConnection', fn() => $d->testConnection());
        if ($type === 'terminal') {
            DB::update('payment_terminals', ['status' => $r->isOk() ? 'ok' : 'error', 'last_seen_at' => $r->isOk() ? date('Y-m-d H:i:s') : $row['last_seen_at'], 'last_message' => mb_substr($r->message, 0, 255)], 'id = ?', [$id]);
        } else {
            DB::update('payment_connectors', ['status' => $r->isOk() ? 'ok' : 'error', 'last_test_at' => date('Y-m-d H:i:s'), 'last_test_message' => mb_substr($r->message, 0, 255)], 'id = ?', [$id]);
        }
        return $r;
    }

    public static function saveSecrets(string $type, int $id, array $values, array $clear): int
    {
        $n = 0;
        foreach ($values as $k => $v) {
            $v = trim((string) $v);
            if ($v !== '' && (isset(self::SECRET_FIELDS[$k]) || $k === 'bridge_secret')) {
                Secrets::put($type, $id, (string) $k, $v, Auth::id());
                $n++;
            }
        }
        foreach ($clear as $k) {
            if (isset(self::SECRET_FIELDS[$k]) || $k === 'bridge_secret') {
                Secrets::put($type, $id, (string) $k, '');
                $n++;
            }
        }
        self::audit('secrets', 'payment_' . $type, $id, null, ['changed' => $n]);
        return $n;
    }

    // ------------------------------------------------------------------ certificats (mTLS)

    public static function certDir(int $connectorId): string
    {
        $d = STORAGE_PATH . '/pay/certs/' . $connectorId;
        if (!is_dir($d)) {
            @mkdir($d, 0700, true);
        }
        return $d;
    }

    public static function saveCertificate(int $connectorId, string $kind, string $tmp): array
    {
        self::connector($connectorId);
        if (!in_array($kind, ['ca', 'client_cert', 'client_key'], true) || !is_file($tmp) || filesize($tmp) > 200000) {
            throw new BusinessException('Type ou fichier de certificat invalide (200 Ko au plus).');
        }
        $pem = (string) file_get_contents($tmp);
        $info = ['kind' => $kind, 'sha256' => hash('sha256', $pem)];
        if ($kind === 'client_key') {
            if (!str_contains($pem, 'PRIVATE KEY')) {
                throw new BusinessException('Clé privée PEM attendue.');
            }
        } else {
            $x = function_exists('openssl_x509_parse') ? @openssl_x509_parse($pem) : false;
            if (!$x) {
                throw new BusinessException('Certificat PEM illisible.');
            }
            if ((int) $x['validTo_time_t'] < time()) {
                throw new BusinessException('Certificat expiré.');
            }
            $info += ['subject' => $x['name'] ?? '', 'valid_to' => date('Y-m-d', (int) $x['validTo_time_t'])];
        }
        $path = self::certDir($connectorId) . '/' . $kind . '.pem';
        file_put_contents($path, $pem);
        @chmod($path, 0600);
        self::audit('certificate', 'payment_connector', $connectorId, null, $info);
        return $info;
    }

    public static function certificates(int $connectorId): array
    {
        $out = [];
        foreach (['ca', 'client_cert', 'client_key'] as $k) {
            $f = STORAGE_PATH . "/pay/certs/$connectorId/$k.pem";
            if (is_file($f)) {
                $x = $k !== 'client_key' && function_exists('openssl_x509_parse') ? @openssl_x509_parse((string) file_get_contents($f)) : null;
                $out[$k] = ['sha256' => hash_file('sha256', $f), 'valid_to' => $x ? date('Y-m-d', (int) $x['validTo_time_t']) : null, 'subject' => $x['name'] ?? null];
            }
        }
        return $out;
    }

    private static function removeCertificates(int $connectorId): void
    {
        foreach (glob(STORAGE_PATH . "/pay/certs/$connectorId/*.pem") ?: [] as $f) {
            @unlink($f);
        }
    }

    // ------------------------------------------------------------------ webhooks, OpenAPI

    public static function createWebhook(int $connectorId): array
    {
        self::connector($connectorId);
        DB::run('DELETE FROM payment_webhooks WHERE connector_id = ?', [$connectorId]);
        $token = bin2hex(random_bytes(20));
        $secret = bin2hex(random_bytes(24));
        $id = DB::insert('payment_webhooks', ['connector_id' => $connectorId, 'token' => $token, 'secret_enc' => Secrets::encrypt($secret)]);
        self::audit('webhook.create', 'payment_connector', $connectorId);
        return ['id' => $id, 'token' => $token, 'secret' => $secret];
    }

    public static function importOpenApi(int $connectorId, string $json): array
    {
        $c = self::connector($connectorId);
        $doc = strlen($json) <= 2_000_000 ? json_decode($json, true) : null;
        if (!is_array($doc) || (!isset($doc['openapi']) && !isset($doc['swagger'])) || !is_array($doc['paths'] ?? null)) {
            throw new BusinessException('Fichier OpenAPI (JSON) invalide : clés « openapi » et « paths » attendues (le YAML doit être converti en JSON).');
        }
        $ops = [];
        foreach ($doc['paths'] as $path => $methods) {
            foreach ((array) $methods as $m => $op) {
                if (in_array(strtolower((string) $m), ['get', 'post', 'put', 'patch', 'delete'], true) && count($ops) < 300) {
                    $ops[] = ['method' => strtoupper((string) $m), 'path' => mb_substr((string) $path, 0, 200), 'summary' => mb_substr((string) (($op['summary'] ?? '') ?: ($op['operationId'] ?? '')), 0, 160)];
                }
            }
        }
        $servers = array_values(array_filter(array_map(fn($s) => is_array($s) && is_string($s['url'] ?? null) ? mb_substr($s['url'], 0, 200) : null, (array) ($doc['servers'] ?? []))));
        $cfg = json_decode((string) $c['config_json'], true) ?: [];
        $cfg['openapi'] = ['title' => mb_substr((string) ($doc['info']['title'] ?? ''), 0, 160), 'version' => mb_substr((string) ($doc['info']['version'] ?? ''), 0, 40), 'servers' => $servers, 'operations' => $ops];
        if (empty($cfg['base_url']) && $servers) {
            $cfg['base_url'] = $servers[0];
        }
        DB::update('payment_connectors', ['config_json' => json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$connectorId]);
        self::audit('connector.openapi', 'payment_connector', $connectorId, null, ['operations' => count($ops)]);
        return $cfg['openapi'];
    }

    // ------------------------------------------------------------------ moyens de paiement

    public static function saveMethod(array $in, ?int $id = null): int
    {
        $before = $id ? DB::one('SELECT * FROM payment_methods WHERE id = ?', [$id]) : null;
        $label = trim((string) ($in['label'] ?? ''));
        $kind = (string) ($in['kind'] ?? '');
        $sm = (string) ($in['sale_method'] ?? '');
        $cur = strtoupper(trim((string) ($in['currency'] ?? PayEngine::shopCurrency())));
        if ($label === '' || !isset(self::KINDS[$kind]) || !isset(self::SALE_METHODS[$sm]) || !DB::value('SELECT 1 FROM payment_currencies WHERE code = ? AND active = 1', [$cur])) {
            throw new BusinessException('Libellé, type, règlement en caisse et devise active obligatoires.');
        }
        $conn = (int) ($in['connector_id'] ?? 0) ?: null;
        if ($kind !== 'terminal' && !$conn) {
            throw new BusinessException('Choisissez le connecteur (prestataire) qui exécutera ce moyen de paiement.');
        }
        if ($kind === 'terminal') {
            $conn = null; // le terminal est celui affecté à la caisse
        }
        $num = fn(string $k) => ($v = str_replace(',', '.', trim((string) ($in[$k] ?? '')))) === '' ? null : (is_numeric($v) && (float) $v >= 0 ? (float) $v : throw new BusinessException('Valeur numérique invalide : ' . $k));
        $regs = implode(',', array_filter(array_map('intval', (array) ($in['register_ids'] ?? []))));
        $data = ['label' => mb_substr($label, 0, 80), 'kind' => $kind, 'sale_method' => $sm, 'connector_id' => $conn, 'currency' => $cur,
            'fee_percent' => $num('fee_percent') ?? 0, 'fee_fixed' => $num('fee_fixed') ?? 0, 'min_amount' => $num('min_amount'), 'max_amount' => $num('max_amount'),
            'expiry_minutes' => max(1, min(1440, (int) ($in['expiry_minutes'] ?? 15))), 'register_ids' => $regs ?: null, 'sort' => (int) ($in['sort'] ?? 0),
            'icon' => preg_match('/^bi-[a-z0-9-]{1,30}$/', (string) ($in['icon'] ?? '')) ? $in['icon'] : null, 'updated_at' => date('Y-m-d H:i:s')];
        if ($id) {
            DB::update('payment_methods', $data, 'id = ?', [$id]);
        } else {
            $id = DB::insert('payment_methods', $data + ['active' => 0]);
        }
        self::audit($before ? 'method.update' : 'method.create', 'payment_method', $id, $before, $data);
        return $id;
    }

    public static function toggleMethod(int $id): bool
    {
        $m = DB::one('SELECT * FROM payment_methods WHERE id = ?', [$id]);
        if (!$m) {
            throw new BusinessException('Moyen introuvable.');
        }
        $on = !(int) $m['active'];
        if ($on && !isset(payment_methods()[$m['sale_method']])) {
            throw new BusinessException('Activez d\'abord le règlement « ' . method_label($m['sale_method']) . ' » dans Paramètres → Ventes.');
        }
        DB::update('payment_methods', ['active' => $on ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        self::audit($on ? 'method.enable' : 'method.disable', 'payment_method', $id);
        return $on;
    }

    // ------------------------------------------------------------------ paramètres, devises, taux

    public static function saveSettings(array $in): void
    {
        foreach (self::SETTINGS as $k => $def) {
            $v = !empty($in[$k]) ? '1' : '0';
            DB::run('INSERT INTO payment_settings (skey, value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by), updated_at = NOW()', [$k, $v, Auth::id()]);
        }
        $active = array_map('strtoupper', (array) ($in['currencies'] ?? []));
        $active[] = PayEngine::shopCurrency();
        DB::run('UPDATE payment_currencies SET active = 0');
        foreach ($active as $c) {
            DB::run('UPDATE payment_currencies SET active = 1 WHERE code = ?', [$c]);
        }
        self::audit('settings', 'payment_settings', null, null, ['settings' => array_intersect_key($in, self::SETTINGS), 'currencies' => $active]);
    }

    public static function addCurrency(string $code, string $name, int $decimals): void
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z]{3}$/', $code) || trim($name) === '' || $decimals < 0 || $decimals > 3) {
            throw new BusinessException('Code ISO (3 lettres), nom et décimales (0 à 3) obligatoires.');
        }
        DB::run('INSERT IGNORE INTO payment_currencies (code, name, decimals) VALUES (?, ?, ?)', [$code, mb_substr(trim($name), 0, 60), $decimals]);
        self::audit('currency.add', 'payment_currency', null, null, ['code' => $code]);
    }

    public static function addRate(array $in): int
    {
        $from = strtoupper(trim((string) ($in['from_currency'] ?? '')));
        $to = strtoupper(trim((string) ($in['to_currency'] ?? '')));
        $rate = str_replace(',', '.', trim((string) ($in['rate'] ?? '')));
        $src = trim((string) ($in['source'] ?? ''));
        $date = (string) ($in['valid_from'] ?? date('Y-m-d'));
        if ($from === $to || !DB::value('SELECT 1 FROM payment_currencies WHERE code = ?', [$from]) || !DB::value('SELECT 1 FROM payment_currencies WHERE code = ?', [$to])
            || !is_numeric($rate) || (float) $rate <= 0 || !\App\Core\Validator::isDate($date)) {
            throw new BusinessException('Devises différentes, taux positif et date valides obligatoires.');
        }
        if (mb_strlen($src) < 3) {
            throw new BusinessException('Source du taux obligatoire (prestataire, banque, parité officielle, date).');
        }
        $id = DB::insert('payment_exchange_rates', ['from_currency' => $from, 'to_currency' => $to, 'rate' => (float) $rate, 'source' => mb_substr($src, 0, 255), 'valid_from' => $date, 'created_by' => Auth::id()]);
        self::audit('rate.add', 'payment_exchange_rate', $id, null, ['from' => $from, 'to' => $to, 'rate' => $rate, 'source' => $src]);
        return $id;
    }

    // ------------------------------------------------------------------ terminaux (TPE)

    public const CONNECTIONS = ['standalone' => 'Autonome (aucune liaison)', 'usb' => 'USB', 'serial' => 'COM / RS232', 'bluetooth' => 'Bluetooth', 'ethernet' => 'Ethernet',
        'tcp' => 'TCP/IP', 'wifi' => 'Wi-Fi', 'http_local' => 'API HTTP locale', 'android_sdk' => 'SDK Android (API locale)'];

    public static function terminal(int $id): array
    {
        $t = DB::one('SELECT * FROM payment_terminals WHERE id = ?', [$id]);
        if (!$t) {
            throw new BusinessException('Terminal introuvable.');
        }
        return $t;
    }

    public static function saveTerminal(array $in, ?int $id = null): int
    {
        $before = $id ? self::terminal($id) : null;
        $name = trim((string) ($in['name'] ?? ''));
        $driver = (string) ($in['driver'] ?? '');
        $conn = (string) ($in['connection'] ?? 'standalone');
        $env = (string) ($in['environment'] ?? 'test');
        $info = DriverRegistry::terminals()[$driver] ?? null;
        if ($name === '' || !$info || !isset(self::CONNECTIONS[$conn]) || !in_array($env, ['test', 'prod'], true)) {
            throw new BusinessException('Nom, pilote, mode de connexion et environnement obligatoires.');
        }
        if ($driver !== 'terminal_simulation' && !in_array($conn, $info['transports'], true)) {
            throw new BusinessException('Ce pilote ne gère pas la connexion « ' . self::CONNECTIONS[$conn] . ' » (modes possibles : ' . implode(', ', array_map(fn($c) => self::CONNECTIONS[$c] ?? $c, $info['transports'])) . ').');
        }
        if ($driver === 'terminal_simulation' && $env === 'prod') {
            throw new BusinessException('Le terminal simulé est réservé à l\'environnement de TEST.');
        }
        $ip = trim((string) ($in['ip_address'] ?? ''));
        $port = (int) ($in['port'] ?? 0) ?: null;
        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('/^[a-z0-9.-]{1,253}$/i', $ip) || ($port !== null && ($port < 1 || $port > 65535))) {
            throw new BusinessException('Adresse IP ou port invalide.');
        }
        if (in_array($driver, ['terminal_tcp'], true) && ($ip === '' || !$port)) {
            throw new BusinessException('Adresse IP et port du terminal obligatoires.');
        }
        $dev = trim((string) ($in['device_path'] ?? ''));
        if ($dev !== '' && !preg_match('#^(COM[1-9][0-9]?|/dev/tty(USB|ACM|S|rfcomm)[0-9]{1,2})$#', $dev)) {
            throw new BusinessException('Port invalide (ex. COM3, /dev/ttyUSB0, /dev/rfcomm0).');
        }
        $bridge = rtrim(trim((string) ($in['bridge_url'] ?? '')), '/');
        if ($bridge !== '' && !preg_match('#^https?://[a-z0-9.-]+(:\d{1,5})?$#i', $bridge)) {
            throw new BusinessException('Adresse du pont invalide (ex. http://192.168.1.20:8765).');
        }
        if ($driver === 'terminal_usb' && ($dev === '' || $bridge === '')) {
            throw new BusinessException('Terminal USB / COM / Bluetooth : port et adresse du pont local obligatoires.');
        }
        $store = (int) ($in['store_id'] ?? 0) ?: null;
        $reg = (int) ($in['register_id'] ?? 0) ?: null;
        if ($reg && !DB::value('SELECT 1 FROM cash_registers WHERE id = ?', [$reg])) {
            throw new BusinessException('Caisse inconnue.');
        }
        $cfg = self::json((string) ($in['config_json'] ?? ''));
        if ($driver === 'terminal_simulation') {
            $cfg['scenario'] = in_array($in['scenario'] ?? '', ['pending', 'success', 'decline', 'pending_forever', 'network_loss', 'invalid'], true) ? $in['scenario'] : ($cfg['scenario'] ?? 'pending');
        }
        $data = ['name' => mb_substr($name, 0, 120), 'manufacturer' => mb_substr(trim((string) ($in['manufacturer'] ?? '')), 0, 80) ?: null, 'model' => mb_substr(trim((string) ($in['model'] ?? '')), 0, 80) ?: null,
            'serial_number' => mb_substr(trim((string) ($in['serial_number'] ?? '')), 0, 80) ?: null, 'driver' => $driver, 'connection' => $conn, 'ip_address' => $ip ?: null, 'port' => $port,
            'device_path' => $dev ?: null, 'bridge_url' => $bridge ?: null, 'environment' => $env, 'store_id' => $store, 'register_id' => $reg,
            'config_json' => $cfg ? json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : null,
            'timeout_s' => max(10, min(300, (int) ($in['timeout_s'] ?? 90))), 'updated_at' => date('Y-m-d H:i:s')];
        if ($id) {
            DB::update('payment_terminals', $data + ['status' => 'unknown'], 'id = ?', [$id]);
        } else {
            $id = DB::insert('payment_terminals', $data + ['active' => 0]);
        }
        self::audit($before ? 'terminal.update' : 'terminal.create', 'payment_terminal', $id, $before, $data);
        return $id;
    }

    public static function toggleTerminal(int $id): bool
    {
        $t = self::terminal($id);
        $on = !(int) $t['active'];
        DB::update('payment_terminals', ['active' => $on ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        self::audit($on ? 'terminal.enable' : 'terminal.disable', 'payment_terminal', $id);
        return $on;
    }

    public static function deleteTerminal(int $id): void
    {
        $t = self::terminal($id);
        if (DB::value('SELECT 1 FROM payment_transactions WHERE terminal_id = ? LIMIT 1', [$id])) {
            throw new BusinessException('Ce terminal a des transactions (historique à conserver) : désactivez-le.');
        }
        DB::run('DELETE FROM payment_api_credentials WHERE terminal_id = ?', [$id]);
        DB::run('DELETE FROM payment_terminals WHERE id = ?', [$id]);
        self::audit('terminal.delete', 'payment_terminal', $id, $t);
    }

    /** Génère le secret partagé avec le pont local (affiché une seule fois, à recopier dans bridge.json). */
    public static function pairBridge(int $id): string
    {
        self::terminal($id);
        $secret = bin2hex(random_bytes(24));
        Secrets::put('terminal', $id, 'bridge_secret', $secret, Auth::id());
        self::audit('terminal.bridge_pair', 'payment_terminal', $id);
        return $secret;
    }
}
