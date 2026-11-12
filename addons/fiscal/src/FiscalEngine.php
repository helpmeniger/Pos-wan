<?php
declare(strict_types=1);

namespace Addons\Fiscal;

use App\Core\Addons;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Logger;

/**
 * Moteur central de Marwa Fiscal Connect.
 *
 * Règles non négociables :
 *  - une facture n'est « certified » que si le dispositif a répondu ET que la réponse a passé ResponseValidator ;
 *  - une ligne certifiée n'est plus jamais modifiée (toutes les écritures portent « status <> 'certified' ») ;
 *  - une même facture n'est jamais envoyée deux fois sans d'abord demander son état au dispositif (si possible) ;
 *  - une panne ne bloque jamais l'encaissement : la facture passe en file d'attente, marquée NON CERTIFIÉE.
 */
final class FiscalEngine
{
    /** Délais d'attente entre deux essais (minutes). */
    public const BACKOFF = [1, 2, 5, 15, 30, 60];

    public static function enabled(): bool
    {
        return Addons::isActive('fiscal');
    }

    // ------------------------------------------------------------------ configuration

    /** Profil fiscal actif applicable à une boutique (le plus précis d'abord). */
    public static function profileFor(int $storeId): ?array
    {
        return DB::one('SELECT * FROM fiscal_profiles WHERE active = 1 AND (store_id = ? OR store_id IS NULL) ORDER BY store_id IS NULL, id LIMIT 1', [$storeId]);
    }

    /** Connecteur actif : caisse → boutique → profil → défaut, dans l'environnement du profil. */
    public static function connectorFor(array $profile, ?int $storeId, ?int $registerId): ?array
    {
        $c = DB::one('SELECT * FROM fiscal_connectors WHERE active = 1 AND environment = ?
                AND (register_id = ? OR (register_id IS NULL AND store_id = ?) OR id = ? OR is_default = 1)
            ORDER BY (register_id <=> ?) DESC, (store_id <=> ?) DESC, (id = ?) DESC, is_default DESC, id LIMIT 1',
            [$profile['environment'], $registerId, $storeId, (int) $profile['connector_id'], $registerId, $storeId, (int) $profile['connector_id']]);
        if ($c && $c['kind'] === 'simulation' && $profile['environment'] === 'prod') {
            return null; // séparation TEST / PROD : jamais de simulation en production
        }
        return $c;
    }

    // ------------------------------------------------------------------ validation des réponses

    /** @return string[] erreurs (vide = réponse acceptable comme preuve de certification) */
    public static function validate(DriverResult $r, array $connector): array
    {
        if ($r->code !== DriverResult::CERTIFIED) {
            return ['Réponse non positive (' . $r->code . ').'];
        }
        $d = $r->data;
        $err = [];
        $num = $d['fiscal_number'] ?? null;
        if (!is_string($num) || trim($num) === '' || strlen($num) > 80) {
            $err[] = 'Numéro de certification absent ou invalide.';
        }
        if (trim((string) ($d['signature'] ?? '')) === '' && trim((string) ($d['qr_payload'] ?? '')) === '') {
            $err[] = 'Ni signature ni code QR officiel dans la réponse.';
        }
        if ($connector['kind'] === 'mcf' && trim((string) ($d['device_id'] ?? '')) === '') {
            $err[] = 'Identifiant du dispositif (MCF) absent.';
        }
        $isSim = $connector['kind'] === 'simulation';
        if ($isSim !== !empty($d['simulation']) || ($isSim && !str_starts_with((string) $num, 'SIMU-'))) {
            $err[] = 'Incohérence entre simulation et réponse réelle.';
        }
        $cfg = json_decode((string) ($connector['config_json'] ?? ''), true) ?: [];
        foreach ((array) ($cfg['required_fields'] ?? []) as $f) {
            if (trim((string) ($d[$f] ?? '')) === '') {
                $err[] = "Champ exigé « $f » absent de la réponse.";
            }
        }
        return $err;
    }

    // ------------------------------------------------------------------ certification

    /**
     * Point d'entrée après l'enregistrement d'une vente. Ne lève jamais d'exception vers la caisse.
     * @return array{status: string, message: string, certification: ?array}
     */
    public static function certifySale(int $saleId, bool $sync = true): array
    {
        try {
            if (!self::enabled()) {
                return ['status' => 'not_required', 'message' => 'Module fiscal inactif.', 'certification' => null];
            }
            $sale = DB::one('SELECT id, number, client_uuid, store_id, register_id, status FROM sales WHERE id = ?', [$saleId]);
            if (!$sale || $sale['status'] === 'cancelled') {
                return ['status' => 'not_required', 'message' => 'Vente absente ou annulée.', 'certification' => null];
            }
            $profile = self::profileFor((int) $sale['store_id']);
            if (!$profile) {
                return ['status' => 'not_required', 'message' => 'Aucun profil fiscal actif pour cette boutique.', 'certification' => null];
            }
            $cert = self::ensureRow('invoice', (int) $sale['id'], (int) $sale['id'], $sale['number'], InvoiceNormalizer::invoiceKey($sale), $profile);
            return self::run($cert, $sync);
        } catch (\Throwable $e) {
            Logger::error('fiscal.certify_sale', ['sale_id' => $saleId, 'error' => $e->getMessage()]);
            return ['status' => 'error', 'message' => 'Erreur interne du module fiscal : la facture n\'est PAS certifiée.', 'certification' => null];
        }
    }

    /** Avoir (retour) : certifié seulement si la facture d'origine l'est. */
    public static function certifyReturn(int $returnId, bool $sync = true): array
    {
        try {
            if (!self::enabled()) {
                return ['status' => 'not_required', 'message' => 'Module fiscal inactif.', 'certification' => null];
            }
            $r = DB::one('SELECT r.id, r.number, r.sale_id, s.store_id FROM sale_returns r JOIN sales s ON s.id = r.sale_id WHERE r.id = ?', [$returnId]);
            $orig = $r ? self::forSale((int) $r['sale_id']) : null;
            if (!$r || !$orig) {
                return ['status' => 'not_required', 'message' => 'Facture d\'origine non soumise à certification.', 'certification' => null];
            }
            $profile = DB::one('SELECT * FROM fiscal_profiles WHERE id = ?', [$orig['profile_id']]) ?? self::profileFor((int) $r['store_id']);
            if (!$profile) {
                return ['status' => 'not_required', 'message' => 'Aucun profil fiscal.', 'certification' => null];
            }
            $cert = self::ensureRow('credit_note', (int) $r['id'], (int) $r['sale_id'], $r['number'], InvoiceNormalizer::creditKey($r), $profile);
            return self::run($cert, $sync);
        } catch (\Throwable $e) {
            Logger::error('fiscal.certify_return', ['return_id' => $returnId, 'error' => $e->getMessage()]);
            return ['status' => 'error', 'message' => 'Erreur interne du module fiscal : l\'avoir n\'est PAS certifié.', 'certification' => null];
        }
    }

    public static function forSale(int $saleId): ?array
    {
        return DB::one("SELECT * FROM fiscal_certifications WHERE doc_type = 'invoice' AND doc_id = ?", [$saleId]);
    }

    public static function forReturn(int $returnId): ?array
    {
        return DB::one("SELECT * FROM fiscal_certifications WHERE doc_type = 'credit_note' AND doc_id = ?", [$returnId]);
    }

    /** Ligne de certification unique par document (la contrainte UNIQUE empêche le doublon même en concurrence). */
    private static function ensureRow(string $type, int $docId, int $saleId, string $number, string $key, array $profile): array
    {
        DB::run('INSERT IGNORE INTO fiscal_certifications (doc_type, doc_id, sale_id, local_number, profile_id, environment, status, idempotency_key, payload_hash, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, \'pending\', ?, \'\', NOW())', [$type, $docId, $saleId, $number, $profile['id'], $profile['environment'], $key]);
        return DB::one('SELECT * FROM fiscal_certifications WHERE doc_type = ? AND doc_id = ?', [$type, $docId]);
    }

    private static function result(array $cert, string $message): array
    {
        $cert = DB::one('SELECT * FROM fiscal_certifications WHERE id = ?', [$cert['id']]);
        return ['status' => $cert['status'], 'message' => $message, 'certification' => $cert];
    }

    private static function run(array $cert, bool $sync): array
    {
        if ($cert['status'] === 'certified') {
            return self::result($cert, 'Déjà certifiée : aucun nouvel envoi.');
        }
        if ($cert['status'] === 'rejected') {
            return self::result($cert, 'Refusée par le dispositif : établir un document correctif.');
        }
        if (!$sync) {
            self::enqueue((int) $cert['id'], 0);
            return self::result($cert, 'Mise en file d\'attente de certification.');
        }
        return self::attempt((int) $cert['id']);
    }

    private static function enqueue(int $certId, int $attempts): void
    {
        $wait = self::BACKOFF[min(max(0, $attempts - 1), count(self::BACKOFF) - 1)];
        $at = date('Y-m-d H:i:s', time() + ($attempts === 0 ? 0 : $wait * 60));
        DB::run('INSERT INTO fiscal_pending_queue (certification_id, next_attempt_at, locked_until) VALUES (?, ?, NULL)
                 ON DUPLICATE KEY UPDATE next_attempt_at = VALUES(next_attempt_at), locked_until = NULL', [$certId, $at]);
    }

    private static function dequeue(int $certId): void
    {
        DB::run('DELETE FROM fiscal_pending_queue WHERE certification_id = ?', [$certId]);
    }

    /** Mise à jour d'une certification non encore certifiée (jamais une ligne certifiée). */
    private static function touch(int $certId, array $data, string $fromStatus = 'sending'): int
    {
        $sets = [];
        $vals = [];
        foreach ($data as $k => $v) {
            $sets[] = "`$k` = ?";
            $vals[] = $v;
        }
        $vals[] = $certId;
        $vals[] = $fromStatus;
        return DB::run('UPDATE fiscal_certifications SET ' . implode(', ', $sets) . ", updated_at = NOW() WHERE id = ? AND status = ? AND status <> 'certified'", $vals)->rowCount();
    }

    /** Un essai d'envoi, avec prise exclusive de la ligne. */
    public static function attempt(int $certId, bool $manual = false): array
    {
        $claimed = DB::run("UPDATE fiscal_certifications SET status = 'sending', attempts = attempts + 1, updated_at = NOW()
            WHERE id = ? AND (status = 'pending' OR (status = 'failed' AND ? = 1) OR (status = 'sending' AND updated_at < NOW() - INTERVAL 5 MINUTE))",
            [$certId, $manual ? 1 : 0])->rowCount();
        $cert = DB::one('SELECT * FROM fiscal_certifications WHERE id = ?', [$certId]);
        if (!$cert) {
            throw new BusinessException('Certification introuvable.');
        }
        if (!$claimed) {
            return self::result($cert, $cert['status'] === 'certified' ? 'Déjà certifiée : aucun nouvel envoi.' : 'Envoi déjà en cours ou non relançable.');
        }
        $profile = DB::one('SELECT * FROM fiscal_profiles WHERE id = ?', [$cert['profile_id']]);
        $sale = DB::one('SELECT store_id, register_id, status FROM sales WHERE id = ?', [$cert['sale_id']]);
        if (!$profile || !$sale) {
            self::touch($certId, ['status' => 'failed', 'last_error' => 'Profil fiscal ou vente introuvable.']);
            self::dequeue($certId);
            return self::result($cert, 'Profil fiscal ou vente introuvable.');
        }
        if ($cert['doc_type'] === 'invoice' && $sale['status'] === 'cancelled') {
            self::touch($certId, ['status' => 'failed', 'attempts' => max(0, (int) $cert['attempts'] - 1), 'last_error' => 'Vente annulée : facture non transmise.']);
            self::dequeue($certId);
            return self::result($cert, 'Vente annulée : la facture n\'est pas transmise.');
        }
        $connector = self::connectorFor($profile, (int) $sale['store_id'], $sale['register_id'] !== null ? (int) $sale['register_id'] : null);
        if (!$connector) {
            return self::notSent($cert, 'Aucun connecteur fiscal actif pour cet environnement (' . $profile['environment'] . ').');
        }
        // Avoir : la facture d'origine doit être certifiée
        $original = null;
        if ($cert['doc_type'] === 'credit_note') {
            $original = self::forSale((int) $cert['sale_id']);
            if (!$original || $original['status'] !== 'certified') {
                return self::notSent($cert, 'En attente de la certification de la facture d\'origine.');
            }
        }
        try {
            $invoice = $cert['doc_type'] === 'invoice' ? InvoiceNormalizer::fromSale((int) $cert['doc_id'], $profile) : InvoiceNormalizer::fromReturn((int) $cert['doc_id'], $profile, $original);
        } catch (\Throwable $e) {
            self::touch($certId, ['status' => 'failed', 'attempts' => max(0, (int) $cert['attempts'] - 1), 'last_error' => mb_substr($e->getMessage(), 0, 255)]);
            self::dequeue($certId);
            return self::result($cert, $e->getMessage());
        }
        if ($invoice['unmapped_rates']) {
            self::touch($certId, ['status' => 'failed', 'attempts' => max(0, (int) $cert['attempts'] - 1), 'connector_id' => $connector['id'], 'last_error' => 'Taux de TVA sans groupe fiscal configuré : ' . implode(', ', $invoice['unmapped_rates']) . ' %.']);
            self::dequeue($certId);
            return self::result($cert, 'Taux de TVA sans groupe fiscal configuré : facture non envoyée.');
        }
        self::touch($certId, ['connector_id' => $connector['id'], 'is_simulation' => $connector['kind'] === 'simulation' ? 1 : 0, 'payload_hash' => InvoiceNormalizer::hash($invoice)]);
        $driver = DriverRegistry::make($connector);

        // Après un premier essai sans réponse, on demande d'abord l'état au dispositif (évite la double certification).
        if ((int) $cert['attempts'] > 1 && DriverRegistry::supports($connector, 'getInvoiceStatus')) {
            $st = self::call($driver, $connector, $certId, 'getInvoiceStatus', fn() => $driver->getInvoiceStatus($cert['idempotency_key']));
            if ($st->code === DriverResult::CERTIFIED) {
                return self::store($cert, $connector, $st, 'Certification retrouvée sur le dispositif (pas de second envoi).');
            }
            if ($st->code !== DriverResult::NOT_FOUND) {
                return self::retryLater($cert, 'État inconnu sur le dispositif : ' . $st->message, (int) $connector['max_retries']);
            }
        }
        $fn = $cert['doc_type'] === 'invoice' ? 'certifyInvoice' : 'issueCreditNote';
        $r = self::call($driver, $connector, $certId, $fn, fn() => $fn === 'certifyInvoice' ? $driver->certifyInvoice($invoice) : $driver->issueCreditNote($invoice));
        if ($r->code === DriverResult::CERTIFIED) {
            $errors = self::validate($r, $connector);
            if (!$errors) {
                return self::store($cert, $connector, $r, $r->message ?: 'Certifiée.');
            }
            self::log($certId, (int) $connector['id'], 'validate', DriverResult::INVALID_RESPONSE, null, null, implode(' ', $errors));
            return self::retryLater($cert, 'Réponse refusée par le contrôle : ' . implode(' ', $errors), (int) $connector['max_retries']);
        }
        if ($r->code === DriverResult::REJECTED) {
            self::touch($certId, ['status' => 'rejected', 'last_error' => mb_substr($r->message, 0, 255)]);
            self::dequeue($certId);
            return self::result($cert, $r->message);
        }
        if ($r->retryable()) {
            return self::retryLater($cert, $r->message, (int) $connector['max_retries']);
        }
        // auth_failed, config_error, unsupported… : intervention humaine requise
        self::touch($certId, ['status' => 'failed', 'last_error' => mb_substr($r->message, 0, 255)]);
        self::dequeue($certId);
        return self::result($cert, $r->message);
    }

    /** Rien n'a été transmis : l'essai ne compte pas, nouvelle tentative dans 5 minutes. */
    private static function notSent(array $cert, string $message): array
    {
        DB::run("UPDATE fiscal_certifications SET status = 'pending', attempts = GREATEST(attempts - 1, 0), last_error = ?, updated_at = NOW() WHERE id = ? AND status = 'sending'", [mb_substr($message, 0, 255), $cert['id']]);
        DB::run('INSERT INTO fiscal_pending_queue (certification_id, next_attempt_at, locked_until) VALUES (?, NOW() + INTERVAL 5 MINUTE, NULL)
                 ON DUPLICATE KEY UPDATE next_attempt_at = VALUES(next_attempt_at), locked_until = NULL', [$cert['id']]);
        return self::result($cert, $message . ' Facture en file d\'attente : NON CERTIFIÉE pour l\'instant.');
    }

    private static function retryLater(array $cert, string $message, int $maxRetries): array
    {
        $attempts = (int) DB::value('SELECT attempts FROM fiscal_certifications WHERE id = ?', [$cert['id']]);
        if ($attempts >= max(1, $maxRetries)) {
            self::touch((int) $cert['id'], ['status' => 'failed', 'last_error' => mb_substr('Tentatives épuisées : ' . $message, 0, 255)]);
            self::dequeue((int) $cert['id']);
            return self::result($cert, 'Tentatives épuisées : ' . $message);
        }
        self::touch((int) $cert['id'], ['status' => 'pending', 'last_error' => mb_substr($message, 0, 255)]);
        self::enqueue((int) $cert['id'], $attempts);
        return self::result($cert, $message . ' Facture en file d\'attente : NON CERTIFIÉE pour l\'instant.');
    }

    private static function store(array $cert, array $connector, DriverResult $r, string $message): array
    {
        $d = $r->data;
        $known = self::knownSecrets((int) $connector['id']);
        $proof = ['driver' => $connector['driver'], 'connector' => $connector['name'], 'environment' => $connector['environment'], 'received_at' => date('c'),
            'http_status' => $r->httpStatus, 'request' => Secrets::redact((string) $r->request, $known), 'response' => Secrets::redact((string) $r->response, $known), 'data' => Secrets::redact($d, $known)];
        $n = self::touch((int) $cert['id'], [
            'status' => 'certified', 'fiscal_number' => (string) $d['fiscal_number'], 'device_id' => isset($d['device_id']) ? (string) $d['device_id'] : null,
            'signature' => isset($d['signature']) ? (string) $d['signature'] : null, 'qr_payload' => isset($d['qr_payload']) ? (string) $d['qr_payload'] : null,
            'counters' => isset($d['counters']) ? mb_substr((string) $d['counters'], 0, 255) : null, 'certified_at' => date('Y-m-d H:i:s'),
            'response_hash' => hash('sha256', (string) ($r->response ?? json_encode($d))), 'proof_json' => json_encode($proof, JSON_UNESCAPED_UNICODE), 'last_error' => null,
        ]);
        self::dequeue((int) $cert['id']);
        if ($n === 1) {
            \App\Core\Audit::log('fiscal.certified', 'sale', (int) $cert['sale_id'], ['doc' => $cert['doc_type'], 'number' => $cert['local_number'], 'fiscal_number' => $d['fiscal_number']]);
        }
        return self::result($cert, $message);
    }

    /**
     * Confirmation demandée après un webhook : on n'en croit pas le contenu, on interroge le dispositif.
     * Aucun envoi de facture ici ; si le dispositif ne confirme pas, l'état précédent est rétabli.
     */
    public static function confirm(int $certId): array
    {
        $cert = DB::one('SELECT * FROM fiscal_certifications WHERE id = ?', [$certId]);
        if (!$cert || $cert['status'] === 'certified' || $cert['status'] === 'rejected') {
            return ['status' => $cert['status'] ?? 'unknown', 'message' => 'Rien à confirmer.', 'certification' => $cert];
        }
        $prev = $cert['status'] === 'sending' ? 'pending' : $cert['status'];
        $claimed = DB::run("UPDATE fiscal_certifications SET status = 'sending', updated_at = NOW() WHERE id = ? AND status IN ('pending', 'failed')", [$certId])->rowCount();
        if (!$claimed) {
            return self::result($cert, 'Envoi en cours : confirmation ignorée.');
        }
        $connector = $cert['connector_id'] ? DB::one('SELECT * FROM fiscal_connectors WHERE id = ?', [$cert['connector_id']]) : null;
        if ($connector && DriverRegistry::supports($connector, 'getInvoiceStatus')) {
            $driver = DriverRegistry::make($connector);
            $st = self::call($driver, $connector, $certId, 'getInvoiceStatus', fn() => $driver->getInvoiceStatus($cert['idempotency_key']));
            if ($st->code === DriverResult::CERTIFIED && !self::validate($st, $connector)) {
                return self::store($cert, $connector, $st, 'Certification confirmée par le dispositif.');
            }
        }
        self::touch($certId, ['status' => $prev]);
        return self::result($cert, 'Le dispositif n\'a pas confirmé la certification.');
    }

    // ------------------------------------------------------------------ journaux

    /** Toutes les valeurs secrètes du connecteur, pour les effacer des journaux. */
    public static function knownSecrets(int $connectorId): array
    {
        $out = [];
        foreach (DB::all('SELECT value_enc FROM fiscal_api_credentials WHERE connector_id = ?', [$connectorId]) as $r) {
            try {
                $out[] = Secrets::decrypt((string) $r['value_enc']);
            } catch (\Throwable) {
            }
        }
        return $out;
    }

    /** Appel d'un pilote : chronométré, exceptions converties, requête/réponse journalisées après masquage. */
    public static function call(FiscalDriver $driver, array $connector, ?int $certId, string $action, callable $fn): DriverResult
    {
        $t = microtime(true);
        try {
            $r = $fn();
        } catch (\Throwable $e) {
            $r = DriverResult::fail(DriverResult::UNREACHABLE, 'Erreur du pilote : ' . $e->getMessage());
        }
        $known = self::knownSecrets((int) $connector['id']);
        self::log($certId, (int) $connector['id'], $action, $r->code, $r->httpStatus, (int) round((microtime(true) - $t) * 1000), $r->message,
            $r->request !== null ? (string) Secrets::redact($r->request, $known) : null, $r->response !== null ? (string) Secrets::redact($r->response, $known) : null);
        return $r;
    }

    public static function log(?int $certId, ?int $connectorId, string $action, string $code, ?int $http = null, ?int $ms = null, ?string $message = null, ?string $req = null, ?string $resp = null): void
    {
        DB::insert('fiscal_invoice_logs', ['certification_id' => $certId, 'connector_id' => $connectorId, 'action' => $action, 'result_code' => $code,
            'http_status' => $http, 'duration_ms' => $ms, 'message' => $message !== null ? mb_substr($message, 0, 255) : null,
            'request_redacted' => $req !== null ? mb_substr($req, 0, 200000) : null, 'response_redacted' => $resp !== null ? mb_substr($resp, 0, 200000) : null, 'user_id' => Auth::id()]);
    }

    // ------------------------------------------------------------------ file d'attente

    /** Traite les factures en attente arrivées à échéance. @return array{processed: int, certified: int} */
    public static function processQueue(int $limit = 20): array
    {
        $done = ['processed' => 0, 'certified' => 0];
        if (!self::enabled()) {
            return $done;
        }
        $rows = DB::all('SELECT id, certification_id FROM fiscal_pending_queue WHERE next_attempt_at <= NOW() AND (locked_until IS NULL OR locked_until < NOW()) ORDER BY next_attempt_at LIMIT ' . max(1, min(200, $limit)));
        foreach ($rows as $q) {
            $lock = DB::run('UPDATE fiscal_pending_queue SET locked_until = NOW() + INTERVAL 3 MINUTE WHERE id = ? AND (locked_until IS NULL OR locked_until < NOW())', [$q['id']])->rowCount();
            if (!$lock) {
                continue;
            }
            $r = self::attempt((int) $q['certification_id']);
            $done['processed']++;
            $done['certified'] += $r['status'] === 'certified' ? 1 : 0;
        }
        return $done;
    }

    // ------------------------------------------------------------------ annulation

    /** Message d'interdiction si la vente ne peut plus être annulée localement, sinon null. */
    public static function cancelBlocker(int $saleId): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        $c = self::forSale($saleId);
        if (!$c || $c['status'] === 'rejected' || (in_array($c['status'], ['pending', 'failed'], true) && (int) $c['attempts'] === 0)) {
            return null;
        }
        if ($c['status'] === 'certified') {
            return 'Facture certifiée (' . $c['fiscal_number'] . ') : elle ne peut pas être annulée localement. Établissez un retour / avoir, qui sera certifié à son tour.';
        }
        return 'Facture déjà transmise au dispositif fiscal (état : ' . $c['status'] . ') : attendez la réponse ou établissez un avoir.';
    }

    /** Vente annulée avant tout envoi : la demande de certification est retirée de la file (rien n'a été transmis). */
    public static function onCancelled(int $saleId): void
    {
        $c = self::forSale($saleId);
        if ($c && (int) $c['attempts'] === 0 && $c['status'] === 'pending') {
            self::touch((int) $c['id'], ['status' => 'failed', 'last_error' => 'Vente annulée avant transmission.'], 'pending');
            self::dequeue((int) $c['id']);
        }
    }
}
