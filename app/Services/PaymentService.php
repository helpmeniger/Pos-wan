<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Settings;
use App\Core\Str;
use App\Services\Payment\DemoGateway;
use App\Services\Payment\PaymentGateway;

/**
 * Paiements et règlement comptable des commandes.
 *
 * Principes comptables (grand livre en partie double) :
 *  - le « collecteur » est celui qui détient l'argent payé par le client :
 *    prestataire ou commerce (espèces), compte 2300 (paiement électronique reçu d'avance),
 *    compte entreprise (paiement différé) ;
 *  - au règlement, le collecteur est débité du montant encaissé, chaque partenaire est
 *    crédité de son net, MARWA de ses commissions et frais. Ainsi un chauffeur payé en espèces
 *    voit son compte débiteur de la commission : c'est la commission due à MARWA.
 */
final class PaymentService
{
    public const ELECTRONIC = ['demo_mobile_money', 'mobile_money', 'card'];

    public static function demoEnabled(): bool
    {
        return (bool) Config::get('services.payments.demo_enabled', false);
    }

    public static function gateway(string $method): PaymentGateway
    {
        return match ($method) {
            'demo_mobile_money' => new DemoGateway(),
            default => throw new HttpException(422, 'Aucun prestataire de paiement réel n\'est encore configuré pour ce moyen de paiement.', 'gateway_not_configured'),
        };
    }

    // ------------------------------------------------------------------
    // Fin de commande
    // ------------------------------------------------------------------

    public static function onOrderFinished(array $order, string $actorRole, int $actorId, ?int $amountCollected): array
    {
        if ($order['payment_method'] === 'business_account') {
            return self::chargeBusiness($order);
        }
        if (in_array($order['payment_method'], self::ELECTRONIC, true)) {
            if ($order['payment_status'] === 'paid') {
                return self::settle($order, LedgerService::account('2300'), 'Paiement électronique reçu d\'avance');
            }
            NotificationService::order($order, 'payment_due', 'Paiement en attente', 'Merci de régler ' . fcfa((int) $order['total']) . ' pour ' . $order['reference'] . ' depuis l\'application.', ['customer']);
            return $order;
        }
        if ($amountCollected !== null) {
            return self::declareCash($order, $actorRole === 'merchant' ? 'merchant' : 'provider', $actorId, $amountCollected);
        }
        return $order;
    }

    /** Espèces : le collecteur déclare le montant reçu ; l'écart éventuel est signalé à la finance. */
    public static function declareCash(array $order, string $collectorType, int $collectorUserId, int $amount): array
    {
        if ($order['payment_method'] !== 'cash') {
            throw HttpException::conflict('Cette commande n\'est pas payée en espèces.');
        }
        if (!in_array($order['status'], ['completed', 'delivered', 'returned'], true)) {
            throw HttpException::conflict('Le paiement ne peut être déclaré qu\'une fois la commande terminée.', 'invalid_transition');
        }
        if ($amount < 0 || $amount > 10000000) {
            throw \App\Core\ValidationException::field('amount_collected', 'Montant invalide.');
        }
        if ($collectorType === 'provider' && (int) $order['provider_id'] !== $collectorUserId) {
            throw HttpException::forbidden();
        }
        $merchant = $order['merchant_id'] ? Database::first('SELECT * FROM merchants WHERE id = :id', ['id' => $order['merchant_id']]) : null;
        if ($collectorType === 'merchant' && (!$merchant || (int) $merchant['owner_user_id'] !== $collectorUserId)) {
            throw HttpException::forbidden();
        }
        $created = Database::transaction(static function () use ($order, $collectorType, $collectorUserId, $amount, $merchant) {
            $exists = Database::first('SELECT * FROM payments WHERE idempotency_key = :k', ['k' => 'cash:' . $order['id']]);
            if ($exists) {
                return false;
            }
            Database::insert('payments', [
                'uuid' => Str::uuid(),
                'order_id' => $order['id'],
                'payer_user_id' => $order['customer_id'],
                'method' => 'cash',
                'gateway' => 'cash',
                'amount' => (int) $order['total'],
                'declared_amount' => $amount,
                'status' => 'declared',
                'collector_type' => $collectorType,
                'collector_id' => $collectorType === 'merchant' ? (int) $merchant['id'] : $collectorUserId,
                'idempotency_key' => 'cash:' . $order['id'],
                'confirmed_at' => date('Y-m-d H:i:s'),
            ]);
            Database::execute("UPDATE orders SET payment_status = 'paid', version = version + 1 WHERE id = :id", ['id' => $order['id']]);
            return true;
        });
        if (!$created) {
            throw HttpException::conflict('Le paiement de cette commande a déjà été enregistré.', 'duplicate_payment');
        }
        if ($amount !== (int) $order['total'] && abs($amount - (int) $order['total']) > Settings::int('finance.cash_discrepancy_tolerance', 0)) {
            NotificationService::staff('payments.manage', 'cash_discrepancy', 'Écart de caisse',
                sprintf('%s : %s déclaré pour %s attendu.', $order['reference'], fcfa($amount), fcfa((int) $order['total'])), ['order_id' => (int) $order['id']]);
        }
        $order = DispatchService::fresh((int) $order['id']);
        $collector = $collectorType === 'merchant'
            ? LedgerService::partnerAccount('merchant', (int) $merchant['id'])
            : LedgerService::partnerAccount('provider', $collectorUserId);
        return self::settle($order, $collector, 'Encaissement en espèces par le ' . ($collectorType === 'merchant' ? 'commerce' : 'prestataire'));
    }

    public static function chargeBusiness(array $order): array
    {
        $created = Database::transaction(static function () use ($order) {
            if (Database::value('SELECT 1 FROM payments WHERE idempotency_key = :k', ['k' => 'business:' . $order['id']])) {
                return false;
            }
            Database::insert('payments', [
                'uuid' => Str::uuid(), 'order_id' => $order['id'], 'payer_user_id' => $order['customer_id'],
                'method' => 'business_account', 'gateway' => 'business', 'amount' => (int) $order['total'],
                'verified_amount' => (int) $order['total'], 'status' => 'confirmed', 'collector_type' => 'business',
                'collector_id' => $order['business_id'], 'idempotency_key' => 'business:' . $order['id'], 'confirmed_at' => date('Y-m-d H:i:s'),
            ]);
            Database::execute("UPDATE orders SET payment_status = 'paid', version = version + 1 WHERE id = :id", ['id' => $order['id']]);
            return true;
        });
        $order = DispatchService::fresh((int) $order['id']);
        if (!$created && $order['settled_at']) {
            return $order;
        }
        return self::settle($order, LedgerService::partnerAccount('business', (int) $order['business_id']), 'Facturation sur compte entreprise');
    }

    /**
     * Règlement comptable : commissions + écriture équilibrée + statut « Paiement confirmé ».
     * Idempotent (clé « settlement:{id} »).
     */
    public static function settle(array $order, int $collectorAccountId, string $label): array
    {
        return Database::transaction(static function () use ($order, $collectorAccountId, $label) {
            $order = Database::first('SELECT * FROM orders WHERE id = :id FOR UPDATE', ['id' => $order['id']]);
            if ($order['settled_at']) {
                return $order;
            }
            $b = CommissionService::breakdown($order);
            $lines = [];
            $lines[] = [$collectorAccountId, $b['collected'], 0, $label];
            if ($b['promo_expense'] > 0) {
                $lines[] = [LedgerService::account('5000'), $b['promo_expense'], 0, 'Réduction promotionnelle financée par MARWA'];
            }
            foreach ($b['parties'] as $p) {
                $acc = LedgerService::partnerAccount($p['party'], (int) $p['owner_id']);
                $net = (int) $p['partner_net'];
                $lines[] = $net >= 0 ? [$acc, 0, $net, 'Montant dû au ' . ($p['party'] === 'merchant' ? 'commerce' : 'prestataire')] : [$acc, -$net, 0, 'Solde négatif du partenaire'];
                Database::execute(
                    'INSERT INTO commissions (order_id, party, party_user_id, rule_id, model, base_amount, percent_bp, fixed_amount, commission, vat_amount, partner_net)
                     VALUES (:o, :p, :u, :r, :m, :b, :bp, :f, :c, :v, :n)
                     ON DUPLICATE KEY UPDATE commission = VALUES(commission)',
                    ['o' => $order['id'], 'p' => $p['party'], 'u' => $p['user_id'], 'r' => $p['rule_id'], 'm' => $p['model'], 'b' => $p['base_amount'],
                     'bp' => $p['percent_bp'], 'f' => $p['fixed_amount'], 'c' => $p['commission'], 'v' => $p['vat_amount'], 'n' => $p['partner_net']]
                );
            }
            if ($b['commission'] - $b['vat'] > 0) {
                $lines[] = [LedgerService::account('4000'), 0, $b['commission'] - $b['vat'], 'Commission MARWA'];
            }
            if ($b['vat'] > 0) {
                $lines[] = [LedgerService::account('2400'), 0, $b['vat'], 'TVA sur commission'];
            }
            if ($b['fees'] > 0) {
                $lines[] = [LedgerService::account('4100'), 0, $b['fees'], 'Frais de service / réservation'];
            }
            // Cas limite : commande gratuite (100 % de réduction) et aucune ligne non nulle
            if ($b['collected'] + $b['promo_expense'] > 0) {
                LedgerService::post('settlement', 'Règlement ' . $order['reference'], $lines, (int) $order['id'], 'settlement:' . $order['id']);
            }
            $o = OrderStateMachine::transition($order, 'paid', 'system', null, $label, ['settled_at' => date('Y-m-d H:i:s'), 'payment_status' => 'paid']);
            self::issueReceipt($o);
            return $o;
        });
    }

    private static function issueReceipt(array $order): void
    {
        $number = 'REC-' . date('Ym') . '-' . str_pad((string) $order['id'], 6, '0', STR_PAD_LEFT);
        Database::execute(
            "INSERT IGNORE INTO invoices (number, kind, order_id, business_id, user_id, amount, status, paid_at) VALUES (:n, 'receipt', :o, :b, :u, :a, 'paid', NOW())",
            ['n' => $number, 'o' => $order['id'], 'b' => $order['business_id'], 'u' => $order['customer_id'], 'a' => $order['total']]
        );
        NotificationService::order($order, 'payment_confirmed', 'Paiement confirmé', 'Paiement de ' . fcfa((int) $order['total']) . ' confirmé pour ' . $order['reference'] . '. Votre reçu est disponible.', ['customer', 'provider', 'merchant']);
        RealtimeService::pushOrder($order, 'order_updated');
    }

    // ------------------------------------------------------------------
    // Paiement électronique (passerelle interchangeable)
    // ------------------------------------------------------------------

    public static function initiate(array $order, int $userId, string $idempotencyKey): array
    {
        if (!in_array($order['payment_method'], self::ELECTRONIC, true)) {
            throw HttpException::conflict('Cette commande n\'utilise pas le paiement électronique.');
        }
        if ($order['payment_status'] === 'paid') {
            throw HttpException::conflict('Cette commande est déjà payée.', 'duplicate_payment');
        }
        $payable = $order['flow'] === 'merchant' ? $order['status'] === 'created' : in_array($order['status'], ['completed', 'delivered', 'returned'], true);
        if (!$payable) {
            throw HttpException::conflict('Le paiement n\'est pas encore possible pour cette commande.', 'not_payable');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{8,80}$/', $idempotencyKey)) {
            throw \App\Core\ValidationException::field('idempotency_key', 'Clé d\'idempotence invalide.');
        }
        $existing = Database::first('SELECT * FROM payments WHERE idempotency_key = :k', ['k' => $idempotencyKey]);
        if ($existing) {
            if ((int) $existing['order_id'] !== (int) $order['id']) {
                throw HttpException::conflict('Clé d\'idempotence déjà utilisée.', 'idempotency_conflict');
            }
            return $existing + ['redirect_url' => url('paiement/demo/' . $existing['uuid'])];
        }
        // Une seule intention de paiement active par commande : évite les doubles paiements.
        $active = Database::first("SELECT * FROM payments WHERE order_id = :o AND status IN ('pending','confirmed')", ['o' => $order['id']]);
        if ($active) {
            if ($active['status'] === 'confirmed') {
                throw HttpException::conflict('Cette commande est déjà payée.', 'duplicate_payment');
            }
            return $active + ['redirect_url' => url('paiement/demo/' . $active['uuid'])];
        }
        $gw = self::gateway($order['payment_method']);
        $payment = ['uuid' => Str::uuid()];
        $init = $gw->initiate($payment, $order);
        $id = Database::insert('payments', [
            'uuid' => $payment['uuid'], 'order_id' => $order['id'], 'payer_user_id' => $userId,
            'method' => $order['payment_method'], 'gateway' => $gw->code(), 'amount' => (int) $order['total'],
            'status' => 'pending', 'collector_type' => 'gateway', 'idempotency_key' => $idempotencyKey,
            'external_ref' => $init['external_ref'],
        ]);
        Database::execute("UPDATE orders SET payment_status = 'pending' WHERE id = :id AND payment_status = 'unpaid'", ['id' => $order['id']]);
        return Database::first('SELECT * FROM payments WHERE id = :id', ['id' => $id]) + ['redirect_url' => $init['redirect_url'], 'instructions' => $init['instructions']];
    }

    /** Traitement d'une notification de paiement : signature, idempotence, montant, comptabilisation. */
    public static function handleWebhook(string $gatewayCode, string $rawBody, array $headers): array
    {
        $gw = match ($gatewayCode) {
            'demo' => new DemoGateway(),
            default => throw HttpException::notFound(),
        };
        if ($gatewayCode === 'demo' && !self::demoEnabled()) {
            throw HttpException::notFound();
        }
        $valid = $gw->verifyWebhook($rawBody, $headers);
        $data = $valid ? $gw->parseWebhook($rawBody) : ['event_id' => 'invalid-' . Str::token(8), 'external_ref' => null];
        $ins = Database::execute(
            'INSERT IGNORE INTO payment_webhooks (gateway, event_id, external_ref, signature_valid, payload) VALUES (:g, :e, :r, :v, :p)',
            ['g' => $gatewayCode, 'e' => mb_substr($data['event_id'] ?: 'missing-' . Str::token(6), 0, 120), 'r' => $data['external_ref'], 'v' => $valid ? 1 : 0, 'p' => mb_substr($rawBody, 0, 20000)]
        );
        if (!$valid) {
            Logger::warning('Webhook de paiement à signature invalide', ['gateway' => $gatewayCode]);
            throw new HttpException(400, 'Signature invalide.', 'invalid_signature');
        }
        if ($ins === 0) {
            return ['status' => 'duplicate'];
        }
        $result = Database::transaction(static function () use ($gatewayCode, $data) {
            $payment = Database::first('SELECT * FROM payments WHERE gateway = :g AND external_ref = :r FOR UPDATE', ['g' => $gatewayCode, 'r' => $data['external_ref']]);
            if (!$payment) {
                return ['status' => 'unknown_payment'];
            }
            if ($payment['status'] !== 'pending') {
                return ['status' => 'already_' . $payment['status']];
            }
            if ($data['status'] !== 'succeeded') {
                Database::update('payments', ['status' => 'failed', 'failure_reason' => 'Refusé par le prestataire de paiement'], 'id = :id', ['id' => $payment['id']]);
                Database::execute("UPDATE orders SET payment_status = 'failed' WHERE id = :id", ['id' => $payment['order_id']]);
                return ['status' => 'failed', 'payment_id' => (int) $payment['id']];
            }
            if ((int) $data['amount'] !== (int) $payment['amount']) {
                Database::update('payments', ['status' => 'failed', 'failure_reason' => 'Montant confirmé différent du montant attendu'], 'id = :id', ['id' => $payment['id']]);
                NotificationService::staff('payments.manage', 'payment_mismatch', 'Montant de paiement incohérent', 'Paiement ' . $payment['uuid'] . ' : montant reçu ' . fcfa($data['amount']), []);
                return ['status' => 'amount_mismatch'];
            }
            Database::update('payments', ['status' => 'confirmed', 'verified_amount' => $data['amount'], 'fee_amount' => $data['fee'], 'confirmed_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $payment['id']]);
            Database::execute("UPDATE orders SET payment_status = 'paid', version = version + 1 WHERE id = :id", ['id' => $payment['order_id']]);
            $lines = [[LedgerService::account('1100'), (int) $data['amount'], 0, 'Fonds reçus chez le prestataire de paiement'],
                      [LedgerService::account('2300'), 0, (int) $data['amount'], 'Paiement client reçu d\'avance']];
            if ($data['fee'] > 0) {
                $lines[] = [LedgerService::account('5100'), (int) $data['fee'], 0, 'Frais de paiement'];
                $lines[] = [LedgerService::account('1100'), 0, (int) $data['fee'], 'Frais prélevés par le prestataire'];
            }
            LedgerService::post('payment', 'Paiement électronique ' . $payment['uuid'], $lines, (int) $payment['order_id'], 'payment:' . $payment['id']);
            return ['status' => 'confirmed', 'payment_id' => (int) $payment['id'], 'order_id' => (int) $payment['order_id']];
        });
        if (($result['status'] ?? '') === 'confirmed') {
            $order = DispatchService::fresh((int) $result['order_id']);
            if ($order['flow'] === 'merchant' && $order['status'] === 'created') {
                OrderService::submitToMerchant($order);
            } elseif (in_array($order['status'], ['completed', 'delivered', 'returned'], true)) {
                self::settle($order, LedgerService::account('2300'), 'Paiement électronique confirmé');
            } elseif (in_array($order['status'], ['cancelled', 'expired', 'refused'], true)) {
                self::refundPrepaidOnCancel($order);
            }
        } elseif (($result['status'] ?? '') === 'failed') {
            $order = DispatchService::fresh((int) Database::value('SELECT order_id FROM payments WHERE id = :id', ['id' => $result['payment_id']]));
            NotificationService::order($order, 'payment_failed', 'Paiement refusé', 'Le paiement de ' . $order['reference'] . ' a échoué. Vous pouvez réessayer.', ['customer']);
        }
        return $result;
    }

    /** Commande annulée après un paiement électronique confirmé : restitution intégrale au client. */
    public static function refundPrepaidOnCancel(array $order): void
    {
        $p = Database::first("SELECT * FROM payments WHERE order_id = :o AND status = 'confirmed' AND collector_type = 'gateway'", ['o' => $order['id']]);
        if (!$p || $order['settled_at']) {
            return;
        }
        $txn = LedgerService::post('refund', 'Remboursement automatique (annulation) ' . $order['reference'], [
            [LedgerService::account('2300'), (int) $p['amount'], 0, 'Annulation du paiement reçu d\'avance'],
            [LedgerService::account('1100'), 0, (int) $p['amount'], 'Restitution au client via le prestataire de paiement'],
        ], (int) $order['id'], 'cancel-refund:' . $p['id']);
        if ($txn['created']) {
            Database::insert('refunds', ['order_id' => $order['id'], 'payment_id' => $p['id'], 'amount' => $p['amount'], 'reason' => 'Annulation de la commande',
                'borne_by' => 'marwa', 'status' => 'completed', 'requested_by' => $order['customer_id'], 'ledger_txn_id' => $txn['id'], 'completed_at' => date('Y-m-d H:i:s')]);
            Database::update('payments', ['status' => 'refunded'], 'id = :id', ['id' => $p['id']]);
            Database::execute("UPDATE orders SET payment_status = 'refunded' WHERE id = :id", ['id' => $order['id']]);
            NotificationService::order($order, 'refund', 'Remboursement', 'Votre paiement de ' . fcfa((int) $p['amount']) . ' pour ' . $order['reference'] . ' est remboursé.', ['customer']);
        }
    }

    /** Vérification d'un encaissement en espèces par la finance (rapprochement). */
    public static function verifyCash(int $paymentId, int $verifiedAmount, bool $absorbDifference, int $adminId): array
    {
        return Database::transaction(static function () use ($paymentId, $verifiedAmount, $absorbDifference, $adminId) {
            $p = Database::first("SELECT * FROM payments WHERE id = :id AND method = 'cash' FOR UPDATE", ['id' => $paymentId]);
            if (!$p || $p['status'] !== 'declared') {
                throw HttpException::conflict('Paiement introuvable ou déjà vérifié.');
            }
            $diff = (int) $p['amount'] - $verifiedAmount;
            if ($diff !== 0 && $absorbDifference) {
                // MARWA prend l'écart à sa charge : le collecteur ne le doit plus.
                $acc = LedgerService::partnerAccount($p['collector_type'] === 'merchant' ? 'merchant' : 'provider', (int) $p['collector_id']);
                $lines = $diff > 0
                    ? [[LedgerService::account('5400'), $diff, 0, 'Écart de caisse pris en charge'], [$acc, 0, $diff, 'Annulation de l\'écart de caisse']]
                    : [[$acc, -$diff, 0, 'Excédent de caisse'], [LedgerService::account('5400'), 0, -$diff, 'Excédent de caisse']];
                LedgerService::post('adjustment', 'Rapprochement espèces paiement #' . $paymentId, $lines, (int) $p['order_id'], 'cash-verify:' . $paymentId, $adminId);
            }
            Database::update('payments', ['status' => 'confirmed', 'verified_amount' => $verifiedAmount, 'verified_by' => $adminId, 'verified_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $paymentId]);
            \App\Core\Audit::log('payment.cash_verified', 'payment', $paymentId, ['declared' => $p['declared_amount'], 'expected' => $p['amount']], ['verified' => $verifiedAmount, 'absorbed' => $absorbDifference]);
            return Database::first('SELECT * FROM payments WHERE id = :id', ['id' => $paymentId]);
        });
    }
}
