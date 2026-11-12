<?php
declare(strict_types=1);

namespace Addons\Pay;

use App\Core\Addons;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Logger;
use App\Services\CashService;
use App\Services\Sequence;

/**
 * Moteur de paiement de Marwa Pay Connect.
 *
 * Règles non négociables :
 *  - un paiement n'est « réussi » que sur réponse authentifiée du prestataire (réponse d'API, vérification serveur à serveur,
 *    webhook signé) dont le montant et la devise concordent ; une redirection du client n'est jamais une preuve ;
 *  - une transaction réussie ne peut régler qu'une seule vente (réservation atomique par panier) ;
 *  - un remboursement n'est enregistré comme effectué que si le prestataire l'a confirmé ;
 *  - les transactions TEST ne sont jamais comptées comme des encaissements réels ;
 *  - aucune donnée de carte (numéro complet, CVV, PIN) n'est reçue ni stockée.
 */
final class PayEngine
{
    public const STATUSES = ['pending' => 'En attente', 'processing' => 'En cours', 'succeeded' => 'Réussi', 'declined' => 'Refusé', 'cancelled' => 'Annulé',
        'expired' => 'Expiré', 'refunded' => 'Remboursé', 'partially_refunded' => 'Partiellement remboursé', 'to_verify' => 'À vérifier', 'failed' => 'Échec technique'];
    /** États dont un message du prestataire peut encore faire évoluer la transaction. */
    private const OPEN = ['pending', 'processing', 'to_verify', 'expired'];

    public static function enabled(): bool
    {
        return Addons::isActive('pay');
    }

    public static function setting(string $k, string $default = ''): string
    {
        $v = DB::value('SELECT value FROM payment_settings WHERE skey = ?', [$k]);
        return $v === null ? $default : (string) $v;
    }

    public static function shopCurrency(): string
    {
        return strtoupper((string) setting('currency_code', 'XOF'));
    }

    public static function tx(int $id): array
    {
        $t = DB::one('SELECT * FROM payment_transactions WHERE id = ?', [$id]);
        if (!$t) {
            throw new BusinessException('Transaction introuvable.');
        }
        return $t;
    }

    // ------------------------------------------------------------------ moyens de paiement et cibles

    /** Connecteur (API) ou terminal qui exécutera le paiement pour cette caisse. */
    public static function target(array $method, ?int $registerId, ?int $storeId): ?array
    {
        if ($method['kind'] === 'terminal') {
            $t = DB::one('SELECT * FROM payment_terminals WHERE active = 1 AND (register_id = ? OR (register_id IS NULL AND (store_id = ? OR store_id IS NULL)))
                ORDER BY (register_id <=> ?) DESC, (store_id <=> ?) DESC, id LIMIT 1', [$registerId, $storeId, $registerId, $storeId]);
            return $t ? ['type' => 'terminal', 'row' => $t] : null;
        }
        if (!$method['connector_id']) {
            return null;
        }
        $c = DB::one('SELECT * FROM payment_connectors WHERE id = ? AND active = 1 AND (store_id IS NULL OR store_id = ?)', [$method['connector_id'], $storeId]);
        return $c ? ['type' => 'connector', 'row' => $c] : null;
    }

    /** Moyens actifs pour une caisse (seuls ceux dont le connecteur ou le terminal est actif). */
    public static function methodsFor(?int $registerId, ?int $storeId): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM payment_methods WHERE active = 1 ORDER BY sort, label') as $m) {
            $regs = array_filter(array_map('intval', explode(',', (string) $m['register_ids'])));
            if ($regs && !in_array((int) $registerId, $regs, true)) {
                continue;
            }
            $t = self::target($m, $registerId, $storeId);
            if (!$t) {
                continue;
            }
            $out[] = ['id' => (int) $m['id'], 'label' => $m['label'], 'kind' => $m['kind'], 'sale_method' => $m['sale_method'], 'currency' => $m['currency'],
                'icon' => $m['icon'] ?: ['card' => 'bi-credit-card', 'wallet' => 'bi-wallet2', 'mobile_money' => 'bi-phone', 'bank' => 'bi-bank', 'terminal' => 'bi-credit-card-2-front'][$m['kind']] ?? 'bi-cash-coin',
                'test' => ($t['row']['environment'] ?? 'test') === 'test' || DriverRegistry::isSimulation((string) $t['row']['driver']),
                'needs_phone' => in_array($m['kind'], ['mobile_money', 'wallet'], true) && !empty((json_decode((string) ($t['row']['config_json'] ?? ''), true) ?: [])['needs_phone']),
                'manual' => $t['row']['driver'] === 'terminal_standalone'];
        }
        return $out;
    }

    /** Taux à appliquer (devise de la boutique → devise du moyen) : jamais deviné. */
    public static function rate(string $from, string $to): ?array
    {
        if ($from === $to) {
            return ['rate' => 1.0, 'source' => null];
        }
        $r = DB::one('SELECT rate, source FROM payment_exchange_rates WHERE from_currency = ? AND to_currency = ? AND valid_from <= CURDATE() ORDER BY valid_from DESC, id DESC LIMIT 1', [$from, $to]);
        return $r ? ['rate' => (float) $r['rate'], 'source' => $r['source']] : null;
    }

    // ------------------------------------------------------------------ demande de paiement

    /**
     * @param array $in method_id, amount, cart_uuid, request_key (uuid généré par la caisse pour cette tentative),
     *                  payer_phone (facultatif), customer_id, authorization_code (TPE autonome)
     */
    public static function initiate(array $in): array
    {
        $user = Auth::user();
        if (!$user) {
            throw new BusinessException('Utilisateur non connecté.');
        }
        foreach (['cart_uuid', 'request_key'] as $k) {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) ($in[$k] ?? ''))) {
                throw new BusinessException('Identifiant de paiement invalide. Rechargez la caisse.');
            }
        }
        $key = hash('sha256', 'pay|' . strtolower($in['cart_uuid']) . '|' . strtolower($in['request_key']));
        if ($existing = DB::one('SELECT * FROM payment_transactions WHERE idempotency_key = ?', [$key])) {
            return $existing; // même demande renvoyée (double clic, réseau) : aucun second débit
        }
        $register = $user['register_id'] ? DB::one('SELECT * FROM cash_registers WHERE id = ? AND active = 1', [$user['register_id']]) : null;
        if (!$register) {
            throw new BusinessException('Aucune caisse n\'est associée à votre compte.');
        }
        $session = CashService::openSessionForRegister((int) $register['id']);
        if (!$session && setting('require_cash_session', '1') === '1') {
            throw new BusinessException('La caisse est fermée. Ouvrez une session de caisse.');
        }
        $method = DB::one('SELECT * FROM payment_methods WHERE id = ? AND active = 1', [(int) ($in['method_id'] ?? 0)]);
        $allowed = $method ? array_column(self::methodsFor((int) $register['id'], (int) $register['store_id']), 'id') : [];
        if (!$method || !in_array((int) $method['id'], $allowed, true)) {
            throw new BusinessException('Moyen de paiement indisponible pour cette caisse.');
        }
        if (!isset(payment_methods()[$method['sale_method']])) {
            throw new BusinessException('Le règlement « ' . method_label($method['sale_method']) . ' » est désactivé dans les paramètres de vente.');
        }
        $raw = str_replace([' ', ','], ['', '.'], (string) ($in['amount'] ?? ''));
        if (!is_numeric($raw) || (float) $raw <= 0 || (float) $raw > 1e11) {
            throw new BusinessException('Montant invalide.');
        }
        $amount = round((float) $raw, 2);
        if ($method['min_amount'] !== null && $amount < (float) $method['min_amount'] || $method['max_amount'] !== null && $amount > (float) $method['max_amount']) {
            throw new BusinessException('Montant hors des limites de ce moyen de paiement.');
        }
        $target = self::target($method, (int) $register['id'], (int) $register['store_id']);
        $row = $target['row'];
        $isSim = DriverRegistry::isSimulation((string) $row['driver']);
        if ($isSim && $row['environment'] === 'prod') {
            throw new BusinessException('Simulation interdite en production.');
        }
        $shop = self::shopCurrency();
        $cur = strtoupper((string) $method['currency']);
        $rate = self::rate($shop, $cur);
        if (!$rate) {
            throw new BusinessException("Aucun taux de change $shop → $cur saisi (avec sa source) : paiement impossible dans cette devise.");
        }
        $charge = round($amount * $rate['rate'], (int) (DB::value('SELECT decimals FROM payment_currencies WHERE code = ?', [$cur]) ?? 2));
        $phone = preg_replace('/[^0-9+]/', '', (string) ($in['payer_phone'] ?? ''));
        $id = DB::insert('payment_transactions', [
            'number' => Sequence::next('pay_transaction'), 'idempotency_key' => $key, 'cart_uuid' => strtolower($in['cart_uuid']), 'method_id' => $method['id'],
            'connector_id' => $target['type'] === 'connector' ? $row['id'] : null, 'terminal_id' => $target['type'] === 'terminal' ? $row['id'] : null,
            'driver' => $row['driver'], 'environment' => $row['environment'], 'is_test' => ($isSim || $row['environment'] === 'test') ? 1 : 0,
            'store_id' => $register['store_id'], 'register_id' => $register['id'], 'cash_session_id' => $session['id'] ?? null, 'user_id' => $user['id'],
            'customer_id' => !empty($in['customer_id']) ? (int) $in['customer_id'] : null, 'amount' => $amount, 'currency' => $shop,
            'exchange_rate' => $cur !== $shop ? $rate['rate'] : null, 'provider_amount' => $charge, 'provider_currency' => $cur, 'status' => 'pending',
            'payer_hint' => $phone !== '' ? substr($phone, 0, 4) . str_repeat('•', max(0, strlen($phone) - 6)) . substr($phone, -2) : null,
            'expires_at' => date('Y-m-d H:i:s', time() + 60 * max(1, (int) $method['expiry_minutes'])),
        ]);
        $tx = self::tx($id);
        $driver = DriverRegistry::make($row);
        $payload = ['number' => $tx['number'], 'idempotency_key' => $key, 'amount' => $charge, 'currency' => $cur,
            'description' => mb_substr((string) setting('company_name', 'POS Marwa') . ' ' . $tx['number'], 0, 120), 'payer_phone' => $phone ?: null,
            'register' => $register['name'], 'store_id' => (int) $register['store_id'], 'authorization_code' => isset($in['authorization_code']) ? mb_substr(trim((string) $in['authorization_code']), 0, 40) : null,
            'webhook_url' => self::webhookUrl($target)];
        $r = self::call($driver, $target['type'], $row, $id, null, 'initiate', fn() => $driver->initiate($payload));
        Audit::log('pay.initiate', 'payment_transaction', $id, ['number' => $tx['number'], 'amount' => $amount, 'method' => $method['label']]);
        return self::apply($id, $r, 'initiate');
    }

    private static function webhookUrl(array $target): ?string
    {
        if ($target['type'] !== 'connector') {
            return null;
        }
        $w = DB::one('SELECT id, token FROM payment_webhooks WHERE connector_id = ? AND active = 1', [$target['row']['id']]);
        return $w ? url('/pay/webhook/' . $w['id'], ['t' => $w['token']]) : null;
    }

    // ------------------------------------------------------------------ vérification des réponses

    /** @return string[] incohérences (vide = la réponse positive peut être acceptée) */
    public static function verify(array $tx, GatewayResult $r): array
    {
        $d = $r->data;
        $err = [];
        if (isset($d['amount']) && $d['amount'] !== null && $d['amount'] !== '' && abs((float) $d['amount'] - (float) $tx['provider_amount']) > 0.009) {
            $err[] = 'Montant confirmé (' . $d['amount'] . ') différent du montant demandé (' . $tx['provider_amount'] . ').';
        }
        if (!empty($d['currency']) && strtoupper((string) $d['currency']) !== strtoupper((string) $tx['provider_currency'])) {
            $err[] = 'Devise confirmée différente.';
        }
        if (!str_starts_with((string) $tx['driver'], 'terminal_') && trim((string) ($d['provider_reference'] ?? $tx['provider_reference'] ?? '')) === '') {
            $err[] = 'Référence du prestataire absente.';
        }
        if (DriverRegistry::isSimulation((string) $tx['driver']) !== !empty($d['simulation'])) {
            $err[] = 'Incohérence entre simulation et réponse réelle.';
        }
        return $err;
    }

    /** Applique un résultat de prestataire à la transaction (sans jamais rétrograder un paiement réussi). */
    public static function apply(int $id, GatewayResult $r, string $source, string $verifiedBy = 'api'): array
    {
        $tx = self::tx($id);
        $d = $r->data;
        $set = ['message' => mb_substr($r->message, 0, 255)];
        foreach (['provider_reference' => 120, 'authorization_code' => 40] as $k => $max) {
            if (!empty($d[$k]) && is_scalar($d[$k])) {
                $set[$k] = mb_substr((string) $d[$k], 0, $max);
            }
        }
        $redir = is_string($d['redirect_url'] ?? null) ? $d['redirect_url'] : '';
        if ($redir !== '' && preg_match($tx['environment'] === 'prod' ? '#^https://#i' : '#^https?://#i', $redir)) {
            $set['redirect_url'] = mb_substr($redir, 0, 500);
        }
        if (isset($d['fees']) && is_numeric($d['fees'])) {
            $set['fees'] = round((float) $d['fees'], 2);
        }
        if (!empty($d['payer_hint']) && is_string($d['payer_hint']) && empty($tx['payer_hint'])) {
            $set['payer_hint'] = mb_substr($d['payer_hint'], 0, 40);
        }
        $canPoll = DriverRegistry::supports(['driver' => $tx['driver']], 'getStatus');
        $status = match ($r->code) {
            GatewayResult::SUCCEEDED => self::verify($tx, $r) ? 'to_verify' : 'succeeded',
            GatewayResult::PENDING => 'pending',
            GatewayResult::PROCESSING => 'processing',
            GatewayResult::DECLINED => 'declined',
            GatewayResult::CANCELLED => 'cancelled',
            GatewayResult::EXPIRED => 'expired',
            GatewayResult::TO_VERIFY => 'to_verify',
            GatewayResult::TIMEOUT, GatewayResult::INVALID_RESPONSE => $source === 'initiate' ? ($canPoll ? 'processing' : 'to_verify') : null,
            GatewayResult::UNREACHABLE, GatewayResult::AUTH_FAILED, GatewayResult::CONFIG_ERROR, GatewayResult::UNSUPPORTED => $source === 'initiate' ? 'failed' : null,
            default => null,
        };
        if ($r->code === GatewayResult::SUCCEEDED && $status === 'to_verify') {
            $set['message'] = mb_substr('Réponse positive incohérente : ' . implode(' ', self::verify($tx, $r)), 0, 255);
        }
        if ($status === null) {
            DB::update('payment_transactions', ['message' => $set['message'], 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            return self::tx($id);
        }
        // Un paiement expiré ne peut évoluer que vers « réussi » (paiement tardif confirmé)
        $from = $status === 'succeeded' ? self::OPEN : ['pending', 'processing', 'to_verify'];
        if ($status === 'succeeded') {
            $known = self::knownSecrets($tx);
            $set += ['verified_by' => $verifiedBy, 'succeeded_at' => date('Y-m-d H:i:s'),
                'proof_json' => json_encode(['source' => $source, 'driver' => $tx['driver'], 'received_at' => date('c'), 'http_status' => $r->httpStatus,
                    'request' => Secrets::redact((string) $r->request, $known), 'response' => Secrets::redact((string) $r->response, $known), 'data' => Secrets::redact($d, $known)], JSON_UNESCAPED_UNICODE)];
            if (isset($d['amount']) && is_numeric($d['amount'])) {
                $set['provider_amount'] = round((float) $d['amount'], 2);
            }
            if ($tx['status'] === 'expired') {
                $set['message'] = 'Paiement confirmé APRÈS expiration : à rattacher à une vente ou à rembourser.';
            }
        }
        $sets = [];
        $vals = [];
        foreach ($set + ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')] as $k => $v) {
            $sets[] = "`$k` = ?";
            $vals[] = $v;
        }
        $vals[] = $id;
        DB::run('UPDATE payment_transactions SET ' . implode(', ', $sets) . ' WHERE id = ? AND status IN (' . implode(',', array_map(fn($s) => "'$s'", $from)) . ')', $vals);
        $after = self::tx($id);
        if ($after['status'] === 'succeeded' && $tx['status'] !== 'succeeded') {
            Audit::log('pay.succeeded', 'payment_transaction', $id, ['number' => $tx['number'], 'amount' => $tx['amount'], 'test' => (int) $tx['is_test'], 'verified_by' => $verifiedBy]);
        }
        return $after;
    }

    // ------------------------------------------------------------------ suivi, annulation, expiration

    private static function targetOf(array $tx): array
    {
        if ($tx['terminal_id']) {
            return ['type' => 'terminal', 'row' => DB::one('SELECT * FROM payment_terminals WHERE id = ?', [$tx['terminal_id']])];
        }
        return ['type' => 'connector', 'row' => DB::one('SELECT * FROM payment_connectors WHERE id = ?', [$tx['connector_id']])];
    }

    /** Vérification serveur à serveur de l'état d'une transaction ouverte ; expiration si le délai est dépassé. */
    public static function refresh(int $id): array
    {
        $tx = self::tx($id);
        if (!in_array($tx['status'], ['pending', 'processing', 'to_verify'], true)) {
            return $tx;
        }
        $t = self::targetOf($tx);
        if (!$t['row']) {
            return $tx;
        }
        if (DriverRegistry::supports($t['row'], 'getStatus')) {
            $d = DriverRegistry::make($t['row']);
            $r = self::call($d, $t['type'], $t['row'], $id, null, 'getStatus', fn() => $d->getStatus($tx));
            $tx = self::apply($id, $r, 'status');
        }
        if (in_array($tx['status'], ['pending', 'processing'], true) && $tx['expires_at'] && strtotime($tx['expires_at']) < time()) {
            $c = DriverRegistry::supports($t['row'], 'cancel') ? self::cancelAtProvider($tx, $t) : null;
            if ($c === null || in_array($c->code, [GatewayResult::CANCELLED, GatewayResult::EXPIRED], true)) {
                DB::run("UPDATE payment_transactions SET status = 'expired', message = ?, updated_at = NOW() WHERE id = ? AND status IN ('pending','processing')",
                    [$c === null ? 'Délai dépassé sans confirmation (annulation non prise en charge : un paiement tardif reste possible).' : 'Délai dépassé : demande annulée chez le prestataire.', $id]);
            } else {
                $tx = self::apply($id, $c, 'status');
            }
            $tx = self::tx($id);
        }
        return $tx;
    }

    private static function cancelAtProvider(array $tx, array $t): GatewayResult
    {
        $d = DriverRegistry::make($t['row']);
        return self::call($d, $t['type'], $t['row'], (int) $tx['id'], null, 'cancel', fn() => $d->cancel($tx));
    }

    /** Annulation par le caissier : seulement si le prestataire la confirme. */
    public static function cancel(int $id): array
    {
        $tx = self::tx($id);
        $voidable = $tx['status'] === 'succeeded' && $tx['sale_id'] === null && (int) $tx['refunded_amount'] === 0;
        if (!in_array($tx['status'], ['pending', 'processing'], true) && !$voidable) {
            throw new BusinessException('Cette transaction ne peut plus être annulée (état : ' . self::STATUSES[$tx['status']] . ').');
        }
        $t = self::targetOf($tx);
        if (!DriverRegistry::supports($t['row'], $voidable ? 'void' : 'cancel')) {
            throw new BusinessException($voidable ? 'Annulation d\'un paiement réussi non prise en charge par ce prestataire : faites un remboursement.'
                : 'Annulation non prise en charge par ce prestataire : la demande reste ouverte jusqu\'à son expiration ; vérifiez auprès du client.');
        }
        $r = self::cancelAtProvider($tx, $t);
        if ($r->code !== GatewayResult::CANCELLED) {
            throw new BusinessException('Le prestataire n\'a pas confirmé l\'annulation : ' . $r->message);
        }
        DB::run("UPDATE payment_transactions SET status = 'cancelled', message = ?, updated_at = NOW() WHERE id = ? AND status IN ('pending','processing','succeeded') AND sale_id IS NULL",
            [mb_substr($r->message, 0, 255), $id]);
        Audit::log('pay.cancel', 'payment_transaction', $id, ['number' => $tx['number']]);
        return self::tx($id);
    }

    // ------------------------------------------------------------------ rattachement aux ventes

    /**
     * Contrôle et réservation des paiements électroniques d'un panier, juste avant l'enregistrement de la vente.
     * @return string|null message de refus
     */
    public static function claimForSale(string $cartUuid, array $payments, ?int $registerId): ?string
    {
        $cart = strtolower($cartUuid);
        $test = self::setting('test_sales', '0') === '1';
        foreach ($payments as $p) {
            if (empty($p['transaction'])) {
                if (in_array($p['method'], ['card', 'mobile_money'], true) && self::setting('require_electronic', '0') === '1') {
                    return 'Les paiements par ' . method_label($p['method']) . ' doivent passer par « Paiement électronique ».';
                }
                continue;
            }
            $tx = DB::one('SELECT t.*, m.sale_method FROM payment_transactions t JOIN payment_methods m ON m.id = t.method_id WHERE t.id = ?', [(int) $p['transaction']]);
            if (!$tx) {
                return 'Transaction électronique introuvable.';
            }
            $okStatus = $tx['status'] === 'succeeded' || ($tx['status'] === 'to_verify' && $tx['driver'] === 'terminal_standalone' && $tx['authorization_code']);
            if (!$okStatus) {
                return 'Le paiement ' . $tx['number'] . ' n\'est pas confirmé (état : ' . self::STATUSES[$tx['status']] . ').';
            }
            if ((int) $tx['is_test'] === 1 && !$test) {
                return 'Le paiement ' . $tx['number'] . ' est une transaction TEST : elle ne peut pas régler une vente réelle.';
            }
            if (abs((float) $tx['amount'] - (float) $p['amount']) > 0.009 || $tx['sale_method'] !== $p['method'] || ($registerId && (int) $tx['register_id'] !== $registerId)) {
                return 'Le paiement ' . $tx['number'] . ' ne correspond pas à la ligne de règlement (montant, moyen ou caisse).';
            }
            if ($tx['sale_id'] !== null) {
                return 'Le paiement ' . $tx['number'] . ' règle déjà une autre vente.';
            }
            // Réservation atomique : une transaction ne règle que le panier pour lequel elle a été demandée
            DB::run('UPDATE payment_transactions SET cart_uuid = ? WHERE id = ? AND sale_id IS NULL AND (cart_uuid IS NULL OR cart_uuid = ?)', [$cart, $tx['id'], $cart]);
            if (DB::value('SELECT cart_uuid FROM payment_transactions WHERE id = ? AND sale_id IS NULL', [$tx['id']]) !== $cart) {
                return 'Le paiement ' . $tx['number'] . ' appartient à un autre panier ou règle déjà une autre vente.';
            }
        }
        return null;
    }

    /** Après l'enregistrement de la vente : liaison définitive. */
    public static function linkSale(string $cartUuid, int $saleId): int
    {
        $s = DB::one('SELECT customer_id FROM sales WHERE id = ?', [$saleId]);
        return DB::run("UPDATE payment_transactions SET sale_id = ?, customer_id = COALESCE(customer_id, ?), updated_at = NOW()
            WHERE cart_uuid = ? AND sale_id IS NULL AND status IN ('succeeded','to_verify')", [$saleId, $s['customer_id'] ?? null, strtolower($cartUuid)])->rowCount();
    }

    // ------------------------------------------------------------------ remboursements

    /**
     * Remboursement transmis au prestataire d'origine. Enregistré « effectué » seulement s'il est confirmé.
     * @param array|null $supervisor [username, secret] si l'utilisateur n'a pas la permission pay.refund
     */
    public static function refund(int $txId, string $amountRaw, string $reason, string $requestKey, ?array $supervisor = null): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new BusinessException('Justification obligatoire (5 caractères au moins).');
        }
        if (!preg_match('/^[0-9a-f-]{36}$/i', $requestKey)) {
            throw new BusinessException('Identifiant de demande invalide.');
        }
        $authorizedBy = Auth::id();
        if (!Auth::can('pay.refund')) {
            if (empty($supervisor['username']) || empty($supervisor['secret'])) {
                throw new BusinessException('AUTH_REQUIRED:Remboursement : autorisation d\'un responsable requise.');
            }
            $authorizedBy = Auth::verifySupervisor((string) $supervisor['username'], (string) $supervisor['secret'], 'pay.refund');
        }
        $key = hash('sha256', 'refund|' . $txId . '|' . strtolower($requestKey));
        if ($ex = DB::one('SELECT * FROM payment_refunds WHERE idempotency_key = ?', [$key])) {
            return $ex;
        }
        $tx = self::tx($txId);
        if (!in_array($tx['status'], ['succeeded', 'partially_refunded'], true)) {
            throw new BusinessException('Seul un paiement réussi peut être remboursé.');
        }
        $raw = str_replace([' ', ','], ['', '.'], $amountRaw);
        $amount = is_numeric($raw) ? round((float) $raw, 2) : 0.0;
        $pendingSum = (float) DB::value("SELECT COALESCE(SUM(amount),0) FROM payment_refunds WHERE transaction_id = ? AND status IN ('pending','to_verify')", [$txId]);
        $left = round((float) $tx['amount'] - (float) $tx['refunded_amount'] - $pendingSum, 2);
        if ($amount <= 0 || $amount > $left + 0.001) {
            throw new BusinessException('Montant à rembourser invalide (maximum ' . money($left) . ').');
        }
        $t = self::targetOf($tx);
        if (!$t['row'] || !DriverRegistry::supports($t['row'], 'refund')) {
            throw new BusinessException('Remboursement électronique non pris en charge par ce prestataire : remboursez par un autre moyen (Retours) et documentez-le. Aucun remboursement n\'a été enregistré ici.');
        }
        $charge = $tx['exchange_rate'] ? round($amount * (float) $tx['exchange_rate'], 2) : $amount;
        $rid = DB::insert('payment_refunds', ['number' => Sequence::next('pay_refund'), 'transaction_id' => $txId, 'idempotency_key' => $key, 'amount' => $amount,
            'currency' => $tx['currency'], 'reason' => mb_substr($reason, 0, 255), 'status' => 'pending', 'requested_by' => Auth::id(), 'authorized_by' => $authorizedBy]);
        $refund = DB::one('SELECT * FROM payment_refunds WHERE id = ?', [$rid]);
        $d = DriverRegistry::make($t['row']);
        $r = self::call($d, $t['type'], $t['row'], $txId, $rid, 'refund', fn() => $d->refund($tx, ['number' => $refund['number'], 'idempotency_key' => $key, 'amount' => $charge,
            'currency' => $tx['provider_currency'], 'reason' => $reason]));
        Audit::log('pay.refund_request', 'payment_transaction', $txId, ['refund' => $refund['number'], 'amount' => $amount, 'reason' => $reason, 'authorized_by' => $authorizedBy]);
        return self::applyRefund($rid, $r);
    }

    public static function applyRefund(int $rid, GatewayResult $r): array
    {
        $f = DB::one('SELECT * FROM payment_refunds WHERE id = ?', [$rid]);
        $status = match ($r->code) {
            GatewayResult::REFUNDED, GatewayResult::SUCCEEDED => 'succeeded',
            GatewayResult::PENDING, GatewayResult::PROCESSING => 'pending',
            GatewayResult::DECLINED, GatewayResult::UNREACHABLE, GatewayResult::AUTH_FAILED, GatewayResult::CONFIG_ERROR, GatewayResult::UNSUPPORTED => 'failed',
            GatewayResult::TIMEOUT, GatewayResult::INVALID_RESPONSE => 'to_verify',
            default => null,
        };
        if ($status === 'succeeded' && isset($r->data['amount']) && is_numeric($r->data['amount'])) {
            $tx = self::tx((int) $f['transaction_id']);
            $expected = $tx['exchange_rate'] ? round((float) $f['amount'] * (float) $tx['exchange_rate'], 2) : (float) $f['amount'];
            if (abs((float) $r->data['amount'] - $expected) > 0.009) {
                $status = 'to_verify';
            }
        }
        if ($status === null || !in_array($f['status'], ['pending', 'to_verify'], true)) {
            return $f;
        }
        $n = DB::run("UPDATE payment_refunds SET status = ?, provider_reference = COALESCE(?, provider_reference), message = ?, proof_json = ?, updated_at = NOW() WHERE id = ? AND status IN ('pending','to_verify')",
            [$status, isset($r->data['provider_reference']) ? mb_substr((string) $r->data['provider_reference'], 0, 120) : null, mb_substr($r->message, 0, 255),
                $status === 'succeeded' ? json_encode(['received_at' => date('c'), 'data' => Secrets::redact($r->data), 'response' => Secrets::redact((string) $r->response)], JSON_UNESCAPED_UNICODE) : null, $rid])->rowCount();
        if ($n && $status === 'succeeded') {
            DB::transaction(function () use ($f) {
                $tx = DB::one('SELECT t.*, m.sale_method FROM payment_transactions t JOIN payment_methods m ON m.id = t.method_id WHERE t.id = ? FOR UPDATE', [$f['transaction_id']]);
                $refunded = round((float) $tx['refunded_amount'] + (float) $f['amount'], 2);
                DB::run('UPDATE payment_transactions SET refunded_amount = ?, status = ?, updated_at = NOW() WHERE id = ?',
                    [$refunded, $refunded >= (float) $tx['amount'] - 0.009 ? 'refunded' : 'partially_refunded', $tx['id']]);
                // Sortie d'argent de la méthode d'origine (jamais en espèces) ; une transaction TEST n'est pas comptée
                if (!(int) $tx['is_test']) {
                    $session = $tx['register_id'] ? CashService::openSessionForRegister((int) $tx['register_id']) : null;
                    CashService::movement($session['id'] ?? null, 'refund', $tx['sale_method'], -(float) $f['amount'], 'pay_refund', (int) $f['id'], 'Remboursement ' . $f['number'] . ' (' . $tx['number'] . ')');
                }
            });
            Audit::log('pay.refunded', 'payment_transaction', (int) $f['transaction_id'], ['refund' => $f['number'], 'amount' => $f['amount']]);
        }
        return DB::one('SELECT * FROM payment_refunds WHERE id = ?', [$rid]);
    }

    public static function refreshRefund(int $rid): array
    {
        $f = DB::one('SELECT * FROM payment_refunds WHERE id = ?', [$rid]);
        if (!$f || !in_array($f['status'], ['pending', 'to_verify'], true)) {
            return $f ?? [];
        }
        $tx = self::tx((int) $f['transaction_id']);
        $t = self::targetOf($tx);
        if (!$t['row'] || !DriverRegistry::supports($t['row'], 'getRefundStatus')) {
            return $f;
        }
        $d = DriverRegistry::make($t['row']);
        return self::applyRefund($rid, self::call($d, $t['type'], $t['row'], (int) $tx['id'], $rid, 'getRefundStatus', fn() => $d->getRefundStatus($tx, $f)));
    }

    // ------------------------------------------------------------------ webhooks

    /** Notification signée d'un prestataire : dédoublonnée, puis vérifiée serveur à serveur quand c'est possible. */
    public static function webhook(array $connector, string $body, array $headers): array
    {
        $d = DriverRegistry::make($connector);
        $n = $d->parseWebhook($body, $headers);
        if (!$n || empty($n['reference'])) {
            self::log(null, null, (int) $connector['id'], null, 'webhook', 'invalid_response', 200, null, 'Notification illisible.', null, (string) Secrets::redact(mb_substr($body, 0, 20000)));
            return ['matched' => false];
        }
        $tx = DB::one('SELECT * FROM payment_transactions WHERE connector_id = ? AND (provider_reference = ? OR number = ?) ORDER BY id DESC LIMIT 1', [$connector['id'], $n['reference'], $n['reference']]);
        $dedup = hash('sha256', 'wh|' . $connector['id'] . '|' . ($n['event_id'] !== '' ? $n['event_id'] : hash('sha256', $body)));
        try {
            self::log($tx ? (int) $tx['id'] : null, null, (int) $connector['id'], null, 'webhook', $tx ? 'ok' : 'not_found', 200, null, 'Notification : ' . mb_substr((string) $n['status'], 0, 40), null, (string) Secrets::redact(mb_substr($body, 0, 20000)), $dedup);
        } catch (\PDOException) {
            return ['matched' => (bool) $tx, 'duplicate' => true]; // notification déjà traitée
        }
        if (!$tx) {
            return ['matched' => false];
        }
        if (DriverRegistry::supports($connector, 'getStatus')) {
            $after = self::refresh((int) $tx['id']); // on ne croit pas le message : on interroge le prestataire
        } else {
            $code = ['succeeded' => GatewayResult::SUCCEEDED, 'declined' => GatewayResult::DECLINED, 'cancelled' => GatewayResult::CANCELLED, 'expired' => GatewayResult::EXPIRED][$n['status']] ?? null;
            $after = $code ? self::apply((int) $tx['id'], GatewayResult::make($code, 'Notification signée du prestataire.', ['provider_reference' => $n['reference'], 'amount' => $n['amount'], 'currency' => $n['currency']], null, $body), 'webhook', 'webhook') : $tx;
        }
        return ['matched' => true, 'status' => $after['status']];
    }

    // ------------------------------------------------------------------ tâche planifiée

    /** Vérifie les transactions et remboursements en attente. */
    public static function sweep(int $limit = 50): array
    {
        $done = ['transactions' => 0, 'refunds' => 0];
        if (!self::enabled()) {
            return $done;
        }
        foreach (DB::all("SELECT id FROM payment_transactions WHERE status IN ('pending','processing') AND COALESCE(updated_at, created_at) < NOW() - INTERVAL 20 SECOND ORDER BY id LIMIT " . max(1, min(500, $limit))) as $r) {
            try {
                self::refresh((int) $r['id']);
                $done['transactions']++;
            } catch (\Throwable $e) {
                Logger::error('pay.sweep', ['id' => $r['id'], 'error' => $e->getMessage()]);
            }
        }
        foreach (DB::all("SELECT id FROM payment_refunds WHERE status IN ('pending','to_verify') ORDER BY id LIMIT " . max(1, min(500, $limit))) as $r) {
            self::refreshRefund((int) $r['id']);
            $done['refunds']++;
        }
        return $done;
    }

    // ------------------------------------------------------------------ journaux

    private static function knownSecrets(array $tx): array
    {
        return $tx['terminal_id'] ? Secrets::all('terminal', (int) $tx['terminal_id']) : ($tx['connector_id'] ? Secrets::all('connector', (int) $tx['connector_id']) : []);
    }

    public static function call(PaymentDriver $driver, string $type, array $row, ?int $txId, ?int $refundId, string $action, callable $fn): GatewayResult
    {
        $t = microtime(true);
        try {
            $r = $fn();
        } catch (\Throwable $e) {
            $r = GatewayResult::make(GatewayResult::UNREACHABLE, 'Erreur du connecteur : ' . $e->getMessage());
        }
        $known = Secrets::all($type, (int) ($row['id'] ?? 0));
        self::log($txId, $refundId, $type === 'connector' ? (int) ($row['id'] ?? 0) ?: null : null, $type === 'terminal' ? (int) ($row['id'] ?? 0) ?: null : null, $action, $r->code, $r->httpStatus,
            (int) round((microtime(true) - $t) * 1000), $r->message, $r->request !== null ? (string) Secrets::redact($r->request, $known) : null, $r->response !== null ? (string) Secrets::redact($r->response, $known) : null);
        return $r;
    }

    public static function log(?int $txId, ?int $refundId, ?int $connectorId, ?int $terminalId, string $action, string $code, ?int $http = null, ?int $ms = null, ?string $message = null, ?string $req = null, ?string $resp = null, ?string $dedup = null): void
    {
        DB::insert('payment_transaction_logs', ['transaction_id' => $txId, 'refund_id' => $refundId, 'connector_id' => $connectorId, 'terminal_id' => $terminalId,
            'action' => $action, 'result_code' => $code, 'http_status' => $http, 'duration_ms' => $ms, 'message' => $message !== null ? mb_substr($message, 0, 255) : null,
            'request_redacted' => $req !== null ? mb_substr($req, 0, 200000) : null, 'response_redacted' => $resp !== null ? mb_substr($resp, 0, 200000) : null,
            'dedup_key' => $dedup, 'user_id' => Auth::id()]);
    }
}
