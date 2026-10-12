<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Str;

/** Remboursements, versements, reversements de commissions, dépenses et abonnements. */
final class FinanceService
{
    // ------------------------------------------------------------------
    // Remboursements
    // ------------------------------------------------------------------

    public static function refundable(array $order): int
    {
        $paid = (int) Database::value("SELECT COALESCE(SUM(COALESCE(verified_amount, declared_amount, amount)),0) FROM payments WHERE order_id = :o AND status IN ('declared','confirmed','partially_refunded')", ['o' => $order['id']]);
        $refunded = (int) Database::value("SELECT COALESCE(SUM(amount),0) FROM refunds WHERE order_id = :o AND status IN ('approved','completed')", ['o' => $order['id']]);
        return max(0, $paid - $refunded);
    }

    /** Demande (support) ou exécution directe (refunds.manage) d'un remboursement. */
    public static function requestRefund(array $order, int $amount, string $reason, string $borneBy, int $userId, bool $execute, ?int $complaintId = null): array
    {
        if (!in_array($order['status'], ['paid', 'disputed', 'refund_pending'], true)) {
            throw HttpException::conflict('Seule une commande réglée ou contestée peut être remboursée.', 'invalid_transition');
        }
        if ($amount <= 0 || $amount > self::refundable($order)) {
            throw \App\Core\ValidationException::field('amount', 'Montant supérieur au montant remboursable (' . fcfa(self::refundable($order)) . ').');
        }
        if (!in_array($borneBy, ['marwa', 'provider', 'merchant'], true)
            || ($borneBy === 'provider' && !$order['provider_id']) || ($borneBy === 'merchant' && !$order['merchant_id'])) {
            throw \App\Core\ValidationException::field('borne_by', 'Partie prenant en charge le remboursement invalide.');
        }
        $id = Database::transaction(static function () use ($order, $amount, $reason, $borneBy, $userId, $complaintId) {
            $o = Database::first('SELECT * FROM orders WHERE id = :id FOR UPDATE', ['id' => $order['id']]);
            if ($o['status'] !== 'refund_pending') {
                OrderStateMachine::transition($o, 'refund_pending', 'admin', $userId, 'Remboursement demandé : ' . $reason);
            }
            return Database::insert('refunds', [
                'order_id' => $order['id'],
                'payment_id' => Database::value("SELECT id FROM payments WHERE order_id = :o AND status IN ('declared','confirmed','partially_refunded') ORDER BY id LIMIT 1", ['o' => $order['id']]),
                'amount' => $amount, 'reason' => mb_substr($reason, 0, 255), 'borne_by' => $borneBy,
                'requested_by' => $userId, 'complaint_id' => $complaintId,
            ]);
        });
        Audit::log('refund.requested', 'refund', $id, null, ['order' => $order['reference'], 'amount' => $amount, 'borne_by' => $borneBy]);
        return $execute ? self::approveRefund($id, $userId) : Database::first('SELECT * FROM refunds WHERE id = :id', ['id' => $id]);
    }

    public static function approveRefund(int $refundId, int $adminId): array
    {
        $refund = Database::transaction(static function () use ($refundId, $adminId) {
            $r = Database::first('SELECT * FROM refunds WHERE id = :id FOR UPDATE', ['id' => $refundId]);
            if (!$r || $r['status'] !== 'requested') {
                throw HttpException::conflict('Remboursement introuvable ou déjà traité.');
            }
            $order = Database::first('SELECT * FROM orders WHERE id = :id FOR UPDATE', ['id' => $r['order_id']]);
            $payment = $r['payment_id'] ? Database::first('SELECT * FROM payments WHERE id = :id', ['id' => $r['payment_id']]) : null;
            $electronic = $payment && $payment['collector_type'] === 'gateway';
            $source = $electronic ? LedgerService::account('1100') : LedgerService::account('1000');
            if ($payment && $payment['collector_type'] === 'business') {
                $source = LedgerService::partnerAccount('business', (int) $order['business_id']); // avoir sur la créance entreprise
            }
            $debit = match ($r['borne_by']) {
                'provider' => LedgerService::partnerAccount('provider', (int) $order['provider_id']),
                'merchant' => LedgerService::partnerAccount('merchant', (int) $order['merchant_id']),
                default => LedgerService::account('5300'),
            };
            $amount = (int) $r['amount'];
            $txn = LedgerService::post('refund', 'Remboursement ' . $order['reference'] . ' — ' . $r['reason'], [
                [$debit, $amount, 0, 'Remboursement pris en charge (' . $r['borne_by'] . ')'],
                [$source, 0, $amount, $electronic ? 'Restitution via le prestataire de paiement' : ($payment && $payment['collector_type'] === 'business' ? 'Avoir sur compte entreprise' : 'Restitution en espèces par MARWA')],
            ], (int) $order['id'], 'refund:' . $refundId, $adminId);
            Database::update('refunds', ['status' => 'completed', 'approved_by' => $adminId, 'ledger_txn_id' => $txn['id'], 'completed_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $refundId]);
            $remaining = self::refundable($order);
            $full = $remaining === 0;
            if ($payment) {
                Database::update('payments', ['status' => $full ? 'refunded' : 'partially_refunded'], 'id = :id', ['id' => $payment['id']]);
            }
            Database::execute('UPDATE orders SET payment_status = :s WHERE id = :id', ['s' => $full ? 'refunded' : 'partially_refunded', 'id' => $order['id']]);
            $order = Database::first('SELECT * FROM orders WHERE id = :id', ['id' => $order['id']]);
            if ($order['status'] === 'refund_pending') {
                $order = OrderStateMachine::transition($order, $full ? 'refunded' : 'paid', 'admin', $adminId, 'Remboursement de ' . fcfa($amount));
            }
            return Database::first('SELECT * FROM refunds WHERE id = :id', ['id' => $refundId]) + ['_order' => $order];
        });
        Audit::log('refund.completed', 'refund', $refundId, null, ['amount' => $refund['amount'], 'borne_by' => $refund['borne_by']], $adminId);
        NotificationService::order($refund['_order'], 'refund', 'Remboursement effectué', 'Un remboursement de ' . fcfa((int) $refund['amount']) . ' a été accordé pour ' . $refund['_order']['reference'] . '.', ['customer']);
        unset($refund['_order']);
        return $refund;
    }

    public static function rejectRefund(int $refundId, int $adminId, string $note): void
    {
        Database::transaction(static function () use ($refundId, $adminId, $note) {
            $r = Database::first("SELECT * FROM refunds WHERE id = :id AND status = 'requested' FOR UPDATE", ['id' => $refundId]);
            if (!$r) {
                throw HttpException::conflict('Remboursement introuvable ou déjà traité.');
            }
            Database::update('refunds', ['status' => 'rejected', 'approved_by' => $adminId], 'id = :id', ['id' => $refundId]);
            $order = Database::first('SELECT * FROM orders WHERE id = :id FOR UPDATE', ['id' => $r['order_id']]);
            if ($order['status'] === 'refund_pending') {
                OrderStateMachine::transition($order, 'paid', 'admin', $adminId, 'Remboursement refusé : ' . $note);
            }
        });
        Audit::log('refund.rejected', 'refund', $refundId, null, ['note' => $note], $adminId);
    }

    // ------------------------------------------------------------------
    // Partenaires : soldes, retraits, reversements
    // ------------------------------------------------------------------

    /** @return array{type: string, owner_id: int} */
    public static function partnerOf(array $user): array
    {
        if (in_array($user['role'], ['driver', 'courier'], true)) {
            return ['type' => 'provider', 'owner_id' => (int) $user['id']];
        }
        if ($user['role'] === 'merchant') {
            $mid = Database::value('SELECT id FROM merchants WHERE owner_user_id = :u ORDER BY id LIMIT 1', ['u' => $user['id']]);
            if ($mid) {
                return ['type' => 'merchant', 'owner_id' => (int) $mid];
            }
        }
        throw HttpException::forbidden('Fonction réservée aux partenaires.');
    }

    public static function partnerSummary(string $type, int $ownerId): array
    {
        $balance = LedgerService::partnerBalance($type, $ownerId);
        $userId = $type === 'provider' ? $ownerId : (int) Database::value('SELECT owner_user_id FROM merchants WHERE id = :id', ['id' => $ownerId]);
        $pending = (int) Database::value("SELECT COALESCE(SUM(amount),0) FROM payouts WHERE partner_user_id = :u AND partner_type = :t AND direction = 'to_partner' AND status IN ('requested','approved')", ['u' => $userId, 't' => $type]);
        $col = $type === 'provider' ? 'provider_id' : 'merchant_id';
        $earn = Database::first(
            "SELECT COUNT(*) AS orders, COALESCE(SUM(c.base_amount),0) AS gross, COALESCE(SUM(c.commission),0) AS commission, COALESCE(SUM(c.partner_net),0) AS net
               FROM commissions c JOIN orders o ON o.id = c.order_id WHERE c.party = :p AND o.$col = :id",
            ['p' => $type, 'id' => $ownerId]
        );
        $cashCollected = (int) Database::value(
            "SELECT COALESCE(SUM(COALESCE(declared_amount, amount)),0) FROM payments WHERE method = 'cash' AND collector_type = :t AND collector_id = :id",
            ['t' => $type, 'id' => $ownerId]
        );
        return [
            'balance' => $balance,                              // > 0 : MARWA doit au partenaire
            'owed_to_partner' => max(0, $balance),
            'owed_to_marwa' => max(0, -$balance),               // commissions sur espèces à reverser
            'pending_payouts' => $pending,
            'available' => max(0, $balance - $pending),
            'orders' => (int) $earn['orders'],
            'gross' => (int) $earn['gross'],
            'commission' => (int) $earn['commission'],
            'net' => (int) $earn['net'],
            'cash_collected' => $cashCollected,
        ];
    }

    public static function requestPayout(array $user, int $amount, string $method, ?string $destination): array
    {
        $p = self::partnerOf($user);
        if ($amount < 500) {
            throw \App\Core\ValidationException::field('amount', 'Montant minimum de retrait : 500 FCFA.');
        }
        return Database::transaction(static function () use ($user, $p, $amount, $method, $destination) {
            // Verrou sur le compte du partenaire pour éviter deux demandes simultanées
            $acc = LedgerService::partnerAccount($p['type'], $p['owner_id']);
            Database::value('SELECT id FROM ledger_accounts WHERE id = :id FOR UPDATE', ['id' => $acc]);
            $summary = self::partnerSummary($p['type'], $p['owner_id']);
            if ($amount > $summary['available']) {
                throw \App\Core\ValidationException::field('amount', 'Montant supérieur au solde disponible (' . fcfa($summary['available']) . ').');
            }
            $id = Database::insert('payouts', [
                'uuid' => Str::uuid(), 'direction' => 'to_partner', 'partner_type' => $p['type'], 'partner_user_id' => $user['id'],
                'amount' => $amount, 'method' => $method, 'destination' => $destination,
            ]);
            NotificationService::staff('payouts.manage', 'payout_requested', 'Demande de retrait', $user['full_name'] . ' demande ' . fcfa($amount) . '.', ['payout_id' => $id]);
            return Database::first('SELECT * FROM payouts WHERE id = :id', ['id' => $id]);
        });
    }

    /** Validation (approved), paiement effectif (paid, avec justificatif) ou rejet par la finance. */
    public static function reviewPayout(int $payoutId, string $decision, int $adminId, ?string $reference, ?string $note): array
    {
        $payout = Database::transaction(static function () use ($payoutId, $decision, $adminId, $reference, $note) {
            $po = Database::first('SELECT * FROM payouts WHERE id = :id FOR UPDATE', ['id' => $payoutId]);
            if (!$po || $po['direction'] !== 'to_partner') {
                throw HttpException::notFound();
            }
            $allowed = ['approve' => ['requested'], 'pay' => ['requested', 'approved'], 'reject' => ['requested', 'approved']][$decision] ?? [];
            if (!in_array($po['status'], $allowed, true)) {
                throw HttpException::conflict('Action impossible sur ce versement (statut : ' . $po['status'] . ').');
            }
            $data = ['reviewed_by' => $adminId, 'reviewed_at' => date('Y-m-d H:i:s'), 'note' => $note];
            if ($decision === 'approve') {
                $data['status'] = 'approved';
            } elseif ($decision === 'reject') {
                $data['status'] = 'rejected';
            } else {
                if (!$reference) {
                    throw \App\Core\ValidationException::field('reference', 'Indiquez la référence du justificatif de paiement.');
                }
                $ownerId = $po['partner_type'] === 'provider' ? (int) $po['partner_user_id'] : (int) Database::value('SELECT id FROM merchants WHERE owner_user_id = :u ORDER BY id LIMIT 1', ['u' => $po['partner_user_id']]);
                $acc = LedgerService::partnerAccount($po['partner_type'], $ownerId);
                if (LedgerService::balance($acc) < (int) $po['amount']) {
                    throw HttpException::conflict('Le solde du partenaire est insuffisant pour ce versement.');
                }
                $txn = LedgerService::post('payout', 'Versement au partenaire ' . $po['uuid'], [
                    [$acc, (int) $po['amount'], 0, 'Versement effectué (' . $po['method'] . ')'],
                    [LedgerService::account('1000'), 0, (int) $po['amount'], 'Sortie de trésorerie réf. ' . $reference],
                ], null, 'payout:' . $payoutId, $adminId);
                $data += ['status' => 'paid', 'reference' => $reference, 'paid_at' => date('Y-m-d H:i:s'), 'ledger_txn_id' => $txn['id']];
            }
            Database::update('payouts', $data, 'id = :id', ['id' => $payoutId]);
            return Database::first('SELECT * FROM payouts WHERE id = :id', ['id' => $payoutId]);
        });
        Audit::log('payout.' . $decision, 'payout', $payoutId, null, ['amount' => $payout['amount'], 'reference' => $reference], $adminId);
        $label = ['approve' => 'approuvée', 'pay' => 'payée', 'reject' => 'rejetée'][$decision];
        NotificationService::notify((int) $payout['partner_user_id'], 'payout_' . $decision, 'Demande de retrait ' . $label,
            'Votre demande de ' . fcfa((int) $payout['amount']) . ' a été ' . $label . ($reference ? ' (réf. ' . $reference . ')' : '') . '.', ['payout_id' => $payoutId]);
        return $payout;
    }

    /** Un partenaire remet à MARWA les commissions encaissées en espèces. */
    public static function recordRemittance(string $partnerType, int $ownerId, int $amount, string $method, string $reference, int $adminId): array
    {
        if ($amount <= 0) {
            throw \App\Core\ValidationException::field('amount', 'Montant invalide.');
        }
        $userId = $partnerType === 'provider' ? $ownerId : (int) Database::value('SELECT owner_user_id FROM merchants WHERE id = :id', ['id' => $ownerId]);
        if (!$userId) {
            throw HttpException::notFound('Partenaire introuvable.');
        }
        $id = Database::transaction(static function () use ($partnerType, $ownerId, $amount, $method, $reference, $adminId, $userId) {
            $id = Database::insert('payouts', [
                'uuid' => Str::uuid(), 'direction' => 'from_partner', 'partner_type' => $partnerType, 'partner_user_id' => $userId,
                'amount' => $amount, 'method' => $method, 'status' => 'paid', 'reference' => $reference,
                'reviewed_by' => $adminId, 'reviewed_at' => date('Y-m-d H:i:s'), 'paid_at' => date('Y-m-d H:i:s'),
            ]);
            $txn = LedgerService::post('remittance', 'Reversement de commissions par le partenaire', [
                [LedgerService::account('1000'), $amount, 0, 'Encaissement réf. ' . $reference],
                [LedgerService::partnerAccount($partnerType, $ownerId), 0, $amount, 'Commissions reversées à MARWA'],
            ], null, 'remittance:' . $id, $adminId);
            Database::update('payouts', ['ledger_txn_id' => $txn['id']], 'id = :id', ['id' => $id]);
            return $id;
        });
        Audit::log('remittance.recorded', 'payout', $id, null, ['partner' => "$partnerType:$ownerId", 'amount' => $amount, 'reference' => $reference], $adminId);
        NotificationService::notify($userId, 'remittance', 'Reversement enregistré', 'MARWA a bien reçu ' . fcfa($amount) . ' (réf. ' . $reference . ').');
        return Database::first('SELECT * FROM payouts WHERE id = :id', ['id' => $id]);
    }

    /** Règlement d'une créance entreprise (facture payée). */
    public static function recordBusinessPayment(int $businessId, int $amount, string $reference, int $adminId): void
    {
        LedgerService::post('business_payment', 'Règlement entreprise réf. ' . $reference, [
            [LedgerService::account('1000'), $amount, 0, 'Encaissement entreprise'],
            [LedgerService::partnerAccount('business', $businessId), 0, $amount, 'Règlement de la créance'],
        ], null, 'business-payment:' . $businessId . ':' . $reference, $adminId);
        Audit::log('business.payment_recorded', 'business', $businessId, null, ['amount' => $amount, 'reference' => $reference], $adminId);
    }

    // ------------------------------------------------------------------
    // Dépenses et abonnements
    // ------------------------------------------------------------------

    public static function recordExpense(array $v, int $adminId): array
    {
        $id = Database::transaction(static function () use ($v, $adminId) {
            $id = Database::insert('expenses', [
                'category' => $v['category'], 'description' => $v['description'], 'amount' => $v['amount'],
                'expense_date' => $v['expense_date'], 'city_id' => $v['city_id'] ?? null, 'paid_from' => $v['paid_from'] ?? 'cash', 'created_by' => $adminId,
            ]);
            $txn = LedgerService::post('expense', 'Dépense : ' . $v['category'] . ' — ' . $v['description'], [
                [LedgerService::account('5200'), (int) $v['amount'], 0, $v['category']],
                [LedgerService::account('1000'), 0, (int) $v['amount'], 'Paiement ' . ($v['paid_from'] ?? 'cash')],
            ], null, 'expense:' . $id, $adminId, $v['expense_date'] . ' 12:00:00');
            Database::update('expenses', ['ledger_txn_id' => $txn['id']], 'id = :id', ['id' => $id]);
            return $id;
        });
        Audit::log('expense.recorded', 'expense', $id, null, $v, $adminId);
        return Database::first('SELECT * FROM expenses WHERE id = :id', ['id' => $id]);
    }

    public static function billSubscription(string $partnerType, int $ownerId, string $plan, int $amount, string $start, string $end, int $adminId): array
    {
        $userId = match ($partnerType) {
            'provider' => $ownerId,
            'merchant' => (int) Database::value('SELECT owner_user_id FROM merchants WHERE id = :id', ['id' => $ownerId]),
            'business' => (int) Database::value('SELECT owner_user_id FROM businesses WHERE id = :id', ['id' => $ownerId]),
            default => 0,
        };
        if (!$userId) {
            throw HttpException::notFound('Partenaire introuvable.');
        }
        $id = Database::transaction(static function () use ($partnerType, $ownerId, $plan, $amount, $start, $end, $adminId, $userId) {
            $id = Database::insert('subscriptions', ['partner_type' => $partnerType, 'partner_user_id' => $userId, 'plan_name' => $plan, 'amount' => $amount,
                'period_start' => $start, 'period_end' => $end, 'status' => 'billed']);
            $txn = LedgerService::post('subscription', 'Abonnement ' . $plan . ' du ' . $start . ' au ' . $end, [
                [LedgerService::partnerAccount($partnerType, $ownerId), $amount, 0, 'Abonnement ' . $plan],
                [LedgerService::account('4300'), 0, $amount, 'Abonnement professionnel'],
            ], null, 'subscription:' . $id, $adminId);
            Database::update('subscriptions', ['ledger_txn_id' => $txn['id']], 'id = :id', ['id' => $id]);
            return $id;
        });
        Audit::log('subscription.billed', 'subscription', $id, null, compact('partnerType', 'ownerId', 'plan', 'amount'), $adminId);
        return Database::first('SELECT * FROM subscriptions WHERE id = :id', ['id' => $id]);
    }

    /** Écriture d'ajustement manuelle (corrections) — jamais de suppression. */
    public static function adjustment(int $debitAccount, int $creditAccount, int $amount, string $description, int $adminId): int
    {
        $txn = LedgerService::post('adjustment', 'Ajustement : ' . $description, [
            [$debitAccount, $amount, 0, $description], [$creditAccount, 0, $amount, $description],
        ], null, null, $adminId);
        Audit::log('ledger.adjustment', 'ledger_transaction', $txn['id'], null, compact('debitAccount', 'creditAccount', 'amount', 'description'), $adminId);
        return $txn['id'];
    }
}
