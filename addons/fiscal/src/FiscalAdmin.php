<?php
declare(strict_types=1);

namespace Addons\Fiscal;

use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/** Opérations d'administration (validation, traçabilité). Les contrôleurs ne font qu'appeler ces méthodes. */
final class FiscalAdmin
{
    public const KINDS = ['mcf' => 'MCF physique', 'api' => 'API (HTTP / SOAP)', 'cloud' => 'Plateforme en ligne', 'simulation' => 'Simulation (test)'];
    public const STATUSES = ['pending' => 'En attente', 'sending' => 'Envoi en cours', 'certified' => 'Certifiée', 'rejected' => 'Refusée', 'failed' => 'Échec (action requise)'];
    public const CERT_MODES = ['unknown' => 'À déterminer (non configuré)', 'none' => 'Aucune certification exigée', 'mcf' => 'Module de contrôle fiscal (MCF)', 'api' => 'API de l\'administration', 'cloud' => 'Plateforme en ligne', 'mixed' => 'Mixte'];

    /** Secrets attendus par pilote (saisie seule, jamais réaffichés). */
    public const SECRET_FIELDS = [
        'generic_rest' => ['api_key' => 'Clé d\'API', 'bearer_token' => 'Jeton (Bearer)', 'basic_user' => 'Utilisateur (Basic)', 'basic_password' => 'Mot de passe (Basic)', 'client_id' => 'OAuth2 : client_id', 'client_secret' => 'OAuth2 : client_secret'],
        'generic_soap' => ['api_key' => 'Clé d\'API', 'bearer_token' => 'Jeton (Bearer)', 'basic_user' => 'Utilisateur (Basic)', 'basic_password' => 'Mot de passe (Basic)', 'client_id' => 'OAuth2 : client_id', 'client_secret' => 'OAuth2 : client_secret'],
    ];

    private static function kindAllowed(string $driver, string $kind): bool
    {
        $k = DriverRegistry::all()[$driver]['kind'] ?? null;
        return match ($k) {
            'simulation' => $kind === 'simulation',
            'api' => in_array($kind, ['api', 'cloud'], true),
            'mcf' => $kind === 'mcf',
            default => false,
        };
    }

    /** Refuse tout secret glissé dans la configuration en clair. */
    public static function assertNoSecrets(mixed $cfg, string $path = ''): void
    {
        if (!is_array($cfg)) {
            return;
        }
        foreach ($cfg as $k => $v) {
            $key = (string) $k;
            if (preg_match(Secrets::SENSITIVE, $key) && !preg_match('/(_url|_path|^header|^type|^signature)$/i', $key) && !in_array($path, ['response_map', 'endpoints'], true)) {
                throw new BusinessException("La configuration contient un champ secret « $key » : saisissez-le dans la zone « Secrets » (chiffrée).");
            }
            self::assertNoSecrets($v, $key);
        }
    }

    public static function connector(int $id): array
    {
        $c = DB::one('SELECT * FROM fiscal_connectors WHERE id = ?', [$id]);
        if (!$c) {
            throw new BusinessException('Connecteur introuvable.');
        }
        return $c;
    }

    public static function saveConnector(array $in, ?int $id = null): int
    {
        $before = $id ? self::connector($id) : null;
        $name = trim((string) ($in['name'] ?? ''));
        $kind = (string) ($in['kind'] ?? '');
        $driver = (string) ($in['driver'] ?? '');
        $env = (string) ($in['environment'] ?? 'test');
        if ($name === '' || mb_strlen($name) > 120) {
            throw new BusinessException('Nom du connecteur obligatoire (120 caractères au plus).');
        }
        if (!isset(self::KINDS[$kind]) || !DriverRegistry::exists($driver) || !self::kindAllowed($driver, $kind)) {
            throw new BusinessException('Type de connecteur et pilote incompatibles.');
        }
        if (!in_array($env, ['test', 'prod'], true)) {
            throw new BusinessException('Environnement invalide.');
        }
        if ($kind === 'simulation' && $env === 'prod') {
            throw new BusinessException('Le connecteur de simulation est réservé à l\'environnement de TEST.');
        }
        $raw = trim((string) ($in['config_json'] ?? ''));
        $cfg = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($cfg)) {
            throw new BusinessException('Configuration : JSON invalide (' . json_last_error_msg() . ').');
        }
        if ($kind === 'simulation') {
            $sc = (string) ($in['scenario'] ?? ($cfg['scenario'] ?? 'accept'));
            if (!in_array($sc, ['accept', 'reject', 'unreachable', 'timeout', 'invalid', 'auth_fail', 'lost_response'], true)) {
                throw new BusinessException('Scénario de simulation inconnu.');
            }
            $cfg['scenario'] = $sc;
        }
        if ($before && isset($before['config_json'])) {
            $old = json_decode((string) $before['config_json'], true) ?: [];
            if (isset($old['openapi']) && !isset($cfg['openapi'])) {
                $cfg['openapi'] = $old['openapi']; // référence importée conservée
            }
        }
        self::assertNoSecrets($cfg);
        $store = (int) ($in['store_id'] ?? 0) ?: null;
        $reg = (int) ($in['register_id'] ?? 0) ?: null;
        if ($store && !DB::value('SELECT 1 FROM stores WHERE id = ?', [$store])) {
            throw new BusinessException('Boutique inconnue.');
        }
        if ($reg && !DB::value('SELECT 1 FROM cash_registers WHERE id = ?' . ($store ? ' AND store_id = ' . $store : ''), [$reg])) {
            throw new BusinessException('Caisse inconnue (ou d\'une autre boutique).');
        }
        $transports = DriverRegistry::all()[$driver]['transports'];
        $transport = in_array((string) ($in['transport'] ?? ''), $transports, true) ? (string) $in['transport'] : ($transports[0] ?? 'none');
        $data = ['name' => $name, 'kind' => $kind, 'driver' => $driver, 'transport' => $transport, 'environment' => $env, 'store_id' => $store, 'register_id' => $reg,
            'config_json' => $cfg ? json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : null,
            'timeout_s' => max(1, min(120, (int) ($in['timeout_s'] ?? 15))), 'max_retries' => max(1, min(20, (int) ($in['max_retries'] ?? 5)))];
        if ($id) {
            DB::update('fiscal_connectors', $data + ['status' => 'unknown', 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            if ($before['environment'] !== $env) {
                DB::update('fiscal_connectors', ['is_default' => 0], 'id = ?', [$id]);
            }
        } else {
            $id = DB::insert('fiscal_connectors', $data + ['active' => 0, 'created_by' => Auth::id()]);
        }
        FiscalAudit::log($before ? 'connector.update' : 'connector.create', 'connector', $id, $before, $data);
        return $id;
    }

    public static function toggleConnector(int $id): bool
    {
        $c = self::connector($id);
        $on = (int) $c['active'] === 1 ? 0 : 1;
        if ($on && (DriverRegistry::all()[$c['driver']]['skeleton'] ?? false)) {
            throw new BusinessException('Ce pilote est un squelette non développé : il ne peut pas être activé.');
        }
        DB::update('fiscal_connectors', ['active' => $on, 'is_default' => $on ? (int) $c['is_default'] : 0, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        FiscalAudit::log($on ? 'connector.enable' : 'connector.disable', 'connector', $id);
        return (bool) $on;
    }

    public static function setDefault(int $id): void
    {
        $c = self::connector($id);
        if (!(int) $c['active']) {
            throw new BusinessException('Activez le connecteur avant d\'en faire le connecteur par défaut.');
        }
        DB::transaction(function () use ($c) {
            DB::run('UPDATE fiscal_connectors SET is_default = 0 WHERE environment = ?', [$c['environment']]);
            DB::run('UPDATE fiscal_connectors SET is_default = 1 WHERE id = ?', [$c['id']]);
        });
        FiscalAudit::log('connector.default', 'connector', (int) $c['id']);
    }

    public static function deleteConnector(int $id): void
    {
        $c = self::connector($id);
        if (DB::value('SELECT 1 FROM fiscal_certifications WHERE connector_id = ? LIMIT 1', [$id])) {
            throw new BusinessException('Ce connecteur a servi à des certifications (preuves à conserver) : désactivez-le au lieu de le supprimer.');
        }
        if (DB::value('SELECT 1 FROM fiscal_profiles WHERE connector_id = ? LIMIT 1', [$id])) {
            throw new BusinessException('Un profil fiscal utilise ce connecteur : changez d\'abord le profil.');
        }
        foreach (DB::all('SELECT path FROM fiscal_certificates WHERE connector_id = ?', [$id]) as $f) {
            if (str_starts_with((string) $f['path'], self::certDir())) {
                @unlink($f['path']);
            }
        }
        DB::run('DELETE FROM fiscal_connectors WHERE id = ?', [$id]);
        FiscalAudit::log('connector.delete', 'connector', $id, $c);
    }

    /** Test de connexion (et lecture des informations du dispositif si possible). */
    public static function testConnector(int $id): DriverResult
    {
        $c = self::connector($id);
        $d = DriverRegistry::make($c);
        $r = FiscalEngine::call($d, $c, null, 'testConnection', fn() => $d->testConnection());
        if ($r->isOk() && DriverRegistry::supports($c, 'getDeviceInfo')) {
            $i = FiscalEngine::call($d, $c, null, 'getDeviceInfo', fn() => $d->getDeviceInfo());
            if ($i->isOk()) {
                $x = $i->data;
                DB::run('INSERT INTO fiscal_devices (connector_id, device_id, serial_number, model, firmware, info_json, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE device_id = VALUES(device_id), serial_number = VALUES(serial_number), model = VALUES(model), firmware = VALUES(firmware), info_json = VALUES(info_json), last_seen_at = NOW()',
                    [$id, self::s($x['device_id'] ?? null), self::s($x['serial_number'] ?? null), self::s($x['model'] ?? null), self::s($x['firmware'] ?? null), json_encode(Secrets::redact($x), JSON_UNESCAPED_UNICODE)]);
            }
        }
        $d->disconnect();
        DB::update('fiscal_connectors', ['status' => $r->isOk() ? 'ok' : 'error', 'last_test_at' => date('Y-m-d H:i:s'), 'last_test_message' => mb_substr($r->message, 0, 255)], 'id = ?', [$id]);
        return $r;
    }

    private static function s(mixed $v): ?string
    {
        return is_scalar($v) && (string) $v !== '' ? mb_substr((string) $v, 0, 120) : null;
    }

    /** Secrets : écriture seule. Un champ laissé vide ne change rien ; « effacer » le supprime. */
    public static function saveSecrets(int $id, array $values, array $clear): int
    {
        self::connector($id);
        $n = 0;
        foreach ($values as $k => $v) {
            $v = trim((string) $v);
            if ($v !== '' && preg_match('/^[a-z0-9_]{2,40}$/', (string) $k)) {
                Secrets::put($id, (string) $k, $v, Auth::id());
                $n++;
            }
        }
        foreach ($clear as $k) {
            if (preg_match('/^[a-z0-9_]{2,40}$/', (string) $k)) {
                Secrets::put($id, (string) $k, '');
                $n++;
            }
        }
        FiscalAudit::log('connector.secrets', 'connector', $id, null, ['changed' => array_merge(array_keys(array_filter($values, fn($v) => trim((string) $v) !== '')), $clear)]);
        return $n;
    }

    // ------------------------------------------------------------------ certificats SSL / mTLS

    public static function certDir(): string
    {
        $d = STORAGE_PATH . '/fiscal/certs';
        if (!is_dir($d)) {
            @mkdir($d, 0700, true);
        }
        return $d;
    }

    public static function saveCertificate(int $connectorId, string $kind, string $tmp, string $original, string $password = ''): int
    {
        self::connector($connectorId);
        if (!in_array($kind, ['ca', 'client_cert', 'client_key'], true)) {
            throw new BusinessException('Type de certificat invalide.');
        }
        if (!is_file($tmp) || filesize($tmp) > 200000) {
            throw new BusinessException('Fichier absent ou trop volumineux (200 Ko au plus).');
        }
        $pem = (string) file_get_contents($tmp);
        $subject = null;
        $from = $to = null;
        if ($kind === 'client_key') {
            if (!str_contains($pem, 'PRIVATE KEY') || !function_exists('openssl_pkey_get_private') || openssl_pkey_get_private($pem, $password) === false) {
                throw new BusinessException('Clé privée PEM illisible ou mot de passe incorrect.');
            }
        } else {
            $x = function_exists('openssl_x509_parse') ? @openssl_x509_parse($pem) : false;
            if (!$x) {
                throw new BusinessException('Certificat PEM illisible.');
            }
            $subject = mb_substr((string) ($x['name'] ?? ''), 0, 255);
            $from = date('Y-m-d H:i:s', (int) $x['validFrom_time_t']);
            $to = date('Y-m-d H:i:s', (int) $x['validTo_time_t']);
            if ((int) $x['validTo_time_t'] < time()) {
                throw new BusinessException('Certificat expiré le ' . fdate($to) . '.');
            }
        }
        $path = self::certDir() . '/' . bin2hex(random_bytes(16)) . '.pem';
        if (file_put_contents($path, $pem) === false) {
            throw new BusinessException('Écriture du certificat impossible.');
        }
        @chmod($path, 0600);
        $old = DB::one('SELECT id, path FROM fiscal_certificates WHERE connector_id = ? AND kind = ?', [$connectorId, $kind]);
        if ($old) {
            DB::run('DELETE FROM fiscal_certificates WHERE id = ?', [$old['id']]);
            if (str_starts_with((string) $old['path'], self::certDir())) {
                @unlink($old['path']);
            }
        }
        $id = DB::insert('fiscal_certificates', ['connector_id' => $connectorId, 'kind' => $kind, 'filename' => mb_substr(basename($original), 0, 160), 'path' => $path,
            'fingerprint_sha256' => hash('sha256', $pem), 'subject' => $subject, 'valid_from' => $from, 'valid_to' => $to,
            'password_enc' => $password !== '' ? Secrets::encrypt($password) : null, 'uploaded_by' => Auth::id()]);
        FiscalAudit::log('certificate.upload', 'connector', $connectorId, null, ['kind' => $kind, 'fingerprint' => hash('sha256', $pem), 'valid_to' => $to]);
        return $id;
    }

    public static function deleteCertificate(int $id): void
    {
        $c = DB::one('SELECT * FROM fiscal_certificates WHERE id = ?', [$id]);
        if (!$c) {
            throw new BusinessException('Certificat introuvable.');
        }
        DB::run('DELETE FROM fiscal_certificates WHERE id = ?', [$id]);
        if (str_starts_with((string) $c['path'], self::certDir())) {
            @unlink($c['path']);
        }
        FiscalAudit::log('certificate.delete', 'connector', (int) $c['connector_id'], ['kind' => $c['kind'], 'fingerprint' => $c['fingerprint_sha256']]);
    }

    // ------------------------------------------------------------------ webhooks

    /** Crée (ou remplace) le webhook d'un connecteur. Le secret n'est montré qu'une fois. @return array{id: int, token: string, secret: string} */
    public static function createWebhook(int $connectorId): array
    {
        self::connector($connectorId);
        DB::run('DELETE FROM fiscal_webhooks WHERE connector_id = ?', [$connectorId]);
        $token = bin2hex(random_bytes(20));
        $secret = bin2hex(random_bytes(24));
        $id = DB::insert('fiscal_webhooks', ['connector_id' => $connectorId, 'token' => $token, 'secret_enc' => Secrets::encrypt($secret)]);
        FiscalAudit::log('webhook.create', 'connector', $connectorId);
        return ['id' => $id, 'token' => $token, 'secret' => $secret];
    }

    // ------------------------------------------------------------------ OpenAPI

    /** Lit une description OpenAPI 3 / Swagger 2 (JSON) pour aider à remplir les points d'accès. Aucune correspondance fiscale n'est devinée. */
    public static function importOpenApi(int $connectorId, string $json): array
    {
        $c = self::connector($connectorId);
        if (strlen($json) > 2_000_000) {
            throw new BusinessException('Fichier OpenAPI trop volumineux (2 Mo au plus).');
        }
        $doc = json_decode($json, true);
        if (!is_array($doc) || (!isset($doc['openapi']) && !isset($doc['swagger'])) || !is_array($doc['paths'] ?? null)) {
            throw new BusinessException('Fichier OpenAPI (JSON) invalide : clés « openapi » et « paths » attendues. Le YAML n\'est pas lu : convertissez-le en JSON.');
        }
        $ops = [];
        foreach ($doc['paths'] as $path => $methods) {
            foreach ((array) $methods as $m => $op) {
                if (in_array(strtolower((string) $m), ['get', 'post', 'put', 'patch', 'delete'], true) && count($ops) < 300) {
                    $ops[] = ['method' => strtoupper((string) $m), 'path' => mb_substr((string) $path, 0, 200), 'summary' => mb_substr((string) (($op['summary'] ?? '') ?: ($op['operationId'] ?? '')), 0, 160)];
                }
            }
        }
        $servers = [];
        foreach ((array) ($doc['servers'] ?? []) as $s) {
            if (!empty($s['url']) && is_string($s['url'])) {
                $servers[] = mb_substr($s['url'], 0, 200);
            }
        }
        if (!$servers && !empty($doc['host'])) {
            $servers[] = ((array) ($doc['schemes'] ?? ['https']))[0] . '://' . $doc['host'] . ($doc['basePath'] ?? '');
        }
        $cfg = json_decode((string) $c['config_json'], true) ?: [];
        $cfg['openapi'] = ['title' => mb_substr((string) ($doc['info']['title'] ?? ''), 0, 160), 'version' => mb_substr((string) ($doc['info']['version'] ?? ''), 0, 40),
            'servers' => $servers, 'operations' => $ops, 'imported_at' => date('Y-m-d H:i:s')];
        if (empty($cfg['base_url']) && $servers) {
            $cfg['base_url'] = $servers[0];
        }
        self::assertNoSecrets($cfg);
        DB::update('fiscal_connectors', ['config_json' => json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$connectorId]);
        FiscalAudit::log('connector.openapi', 'connector', $connectorId, null, ['operations' => count($ops), 'servers' => $servers]);
        return $cfg['openapi'];
    }

    // ------------------------------------------------------------------ pays, taux, profils

    public static function saveCountry(int $id, array $in): void
    {
        $before = DB::one('SELECT * FROM fiscal_countries WHERE id = ?', [$id]);
        if (!$before) {
            throw new BusinessException('Pays introuvable.');
        }
        $t = fn(string $k, int $max) => ($v = trim((string) ($in[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
        $mode = (string) ($in['certification_mode'] ?? 'unknown');
        if (!isset(self::CERT_MODES[$mode])) {
            throw new BusinessException('Mode de certification invalide.');
        }
        $sources = $t('official_sources', 5000);
        $configured = !empty($in['configured']) ? 1 : 0;
        $homolog = !empty($in['homologated']) ? 1 : 0;
        if (($configured || $homolog || $mode !== 'unknown') && $sources === null) {
            throw new BusinessException('Indiquez les textes officiels de référence (loi, arrêté, spécification, date) : aucune règle fiscale n\'est acceptée sans source.');
        }
        $cur = strtoupper(trim((string) ($in['currency'] ?? $before['currency'])));
        if (!preg_match('/^[A-Z]{3}$/', $cur)) {
            throw new BusinessException('Devise : code ISO à 3 lettres.');
        }
        $ret = trim((string) ($in['retention_years'] ?? ''));
        $data = ['currency' => $cur, 'invoice_format' => $t('invoice_format', 120), 'tax_id_label' => $t('tax_id_label', 40), 'tax_regimes' => $t('tax_regimes', 5000),
            'numbering_rules' => $t('numbering_rules', 5000), 'legal_mentions' => $t('legal_mentions', 5000), 'certification_mode' => $mode,
            'authorized_provider' => $t('authorized_provider', 160), 'qr_format' => $t('qr_format', 160), 'signature_format' => $t('signature_format', 160),
            'archiving_rules' => $t('archiving_rules', 5000), 'retention_years' => $ret === '' ? null : max(1, min(99, (int) $ret)), 'official_sources' => $sources,
            'configured' => $configured, 'homologated' => $homolog, 'notes' => $t('notes', 5000), 'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')];
        DB::update('fiscal_countries', $data, 'id = ?', [$id]);
        FiscalAudit::log('country.update', 'country', $id, $before, $data);
    }

    public static function addCountry(string $code, string $name, string $currency): int
    {
        $code = strtoupper(trim($code));
        $currency = strtoupper(trim($currency));
        $name = trim($name);
        if (!preg_match('/^[A-Z]{2}$/', $code) || !preg_match('/^[A-Z]{3}$/', $currency) || $name === '' || mb_strlen($name) > 80) {
            throw new BusinessException('Code pays (2 lettres), nom et devise (3 lettres) obligatoires.');
        }
        if (DB::value('SELECT 1 FROM fiscal_countries WHERE code = ?', [$code])) {
            throw new BusinessException('Ce pays existe déjà.');
        }
        $id = DB::insert('fiscal_countries', ['code' => $code, 'name' => $name, 'currency' => $currency]);
        FiscalAudit::log('country.create', 'country', $id, null, ['code' => $code]);
        return $id;
    }

    public static function saveTaxRate(int $countryId, array $in): int
    {
        $g = strtoupper(trim((string) ($in['group_code'] ?? '')));
        $label = trim((string) ($in['label'] ?? ''));
        $src = trim((string) ($in['official_source'] ?? ''));
        $rate = str_replace(',', '.', trim((string) ($in['rate'] ?? '')));
        $local = str_replace(',', '.', trim((string) ($in['local_rate'] ?? '')));
        if (!preg_match('/^[A-Z0-9]{1,10}$/', $g) || $label === '' || !is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100 || ($local !== '' && (!is_numeric($local) || (float) $local < 0 || (float) $local > 100))) {
            throw new BusinessException('Groupe (lettres/chiffres), libellé et taux entre 0 et 100 obligatoires.');
        }
        if ($src === '') {
            throw new BusinessException('Source officielle obligatoire pour chaque taux (texte, article, date).');
        }
        DB::run('INSERT INTO fiscal_tax_rates (country_id, group_code, label, rate, local_rate, official_source) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE label = VALUES(label), rate = VALUES(rate), local_rate = VALUES(local_rate), official_source = VALUES(official_source), active = 1',
            [$countryId, $g, mb_substr($label, 0, 120), (float) $rate, $local === '' ? null : (float) $local, mb_substr($src, 0, 255)]);
        FiscalAudit::log('tax_rate.save', 'country', $countryId, null, ['group' => $g, 'rate' => $rate, 'local_rate' => $local, 'source' => $src]);
        return (int) DB::value('SELECT id FROM fiscal_tax_rates WHERE country_id = ? AND group_code = ?', [$countryId, $g]);
    }

    public static function deleteTaxRate(int $id): int
    {
        $r = DB::one('SELECT * FROM fiscal_tax_rates WHERE id = ?', [$id]);
        if (!$r) {
            throw new BusinessException('Taux introuvable.');
        }
        DB::run('DELETE FROM fiscal_tax_rates WHERE id = ?', [$id]);
        FiscalAudit::log('tax_rate.delete', 'country', (int) $r['country_id'], $r);
        return (int) $r['country_id'];
    }

    public static function saveProfile(array $in, ?int $id = null): int
    {
        $before = $id ? DB::one('SELECT * FROM fiscal_profiles WHERE id = ?', [$id]) : null;
        if ($id && !$before) {
            throw new BusinessException('Profil introuvable.');
        }
        $name = trim((string) ($in['name'] ?? ''));
        $country = (int) ($in['country_id'] ?? 0);
        $env = (string) ($in['environment'] ?? 'test');
        if ($name === '' || !DB::value('SELECT 1 FROM fiscal_countries WHERE id = ?', [$country]) || !in_array($env, ['test', 'prod'], true)) {
            throw new BusinessException('Nom, pays et environnement obligatoires.');
        }
        $store = (int) ($in['store_id'] ?? 0) ?: null;
        $conn = (int) ($in['connector_id'] ?? 0) ?: null;
        if ($conn) {
            $c = self::connector($conn);
            if ($c['environment'] !== $env) {
                throw new BusinessException('Le connecteur choisi n\'appartient pas au même environnement (TEST / PRODUCTION).');
            }
        }
        $data = ['name' => mb_substr($name, 0, 120), 'country_id' => $country, 'store_id' => $store, 'environment' => $env,
            'seller_nif' => mb_substr(trim((string) ($in['seller_nif'] ?? '')), 0, 40) ?: null, 'seller_regime' => mb_substr(trim((string) ($in['seller_regime'] ?? '')), 0, 80) ?: null,
            'connector_id' => $conn, 'legal_mentions' => mb_substr(trim((string) ($in['legal_mentions'] ?? '')), 0, 3000) ?: null,
            'print_uncertified' => ($in['print_uncertified'] ?? 'mark') === 'block' ? 'block' : 'mark', 'updated_at' => date('Y-m-d H:i:s')];
        if ($id) {
            DB::update('fiscal_profiles', $data, 'id = ?', [$id]);
            if ((int) $before['active']) {
                self::checkActivable($id); // un profil actif doit rester cohérent
            }
        } else {
            $id = DB::insert('fiscal_profiles', $data);
        }
        FiscalAudit::log($before ? 'profile.update' : 'profile.create', 'profile', $id, $before, $data);
        return $id;
    }

    /** Conditions d'activation : en production, pays configuré d'après les textes, NIF et connecteur réel. */
    public static function checkActivable(int $id): void
    {
        $p = DB::one('SELECT p.*, c.configured, c.name AS country FROM fiscal_profiles p JOIN fiscal_countries c ON c.id = p.country_id WHERE p.id = ?', [$id]);
        if (!$p) {
            throw new BusinessException('Profil introuvable.');
        }
        if ($p['environment'] === 'prod') {
            if (!(int) $p['configured']) {
                throw new BusinessException('Production refusée : les règles fiscales du pays « ' . $p['country'] . ' » ne sont pas configurées d\'après les textes officiels (menu Pays).');
            }
            if (!$p['seller_nif'] && !setting('company_nif')) {
                throw new BusinessException('Production refusée : NIF du vendeur absent.');
            }
            $c = $p['connector_id'] ? DB::one('SELECT * FROM fiscal_connectors WHERE id = ?', [$p['connector_id']]) : DB::one("SELECT * FROM fiscal_connectors WHERE active = 1 AND is_default = 1 AND environment = 'prod'");
            if (!$c || $c['kind'] === 'simulation' || !(int) $c['active']) {
                throw new BusinessException('Production refusée : aucun connecteur de production actif.');
            }
        }
    }

    public static function toggleProfile(int $id): bool
    {
        $p = DB::one('SELECT * FROM fiscal_profiles WHERE id = ?', [$id]);
        if (!$p) {
            throw new BusinessException('Profil introuvable.');
        }
        $on = !(int) $p['active'];
        if ($on) {
            self::checkActivable($id);
            // Un seul profil actif par périmètre (boutique précise ou toutes les boutiques)
            DB::run('UPDATE fiscal_profiles SET active = 0 WHERE id <> ? AND ' . ($p['store_id'] ? 'store_id = ' . (int) $p['store_id'] : 'store_id IS NULL'), [$id]);
        }
        DB::update('fiscal_profiles', ['active' => $on ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        FiscalAudit::log($on ? 'profile.enable' : 'profile.disable', 'profile', $id);
        return $on;
    }
}
