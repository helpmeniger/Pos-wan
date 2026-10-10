<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/** Comptes clients : chaque variation de solde passe par le grand livre. */
final class CustomerService
{
    public static ?int $lastTxId = null;

    public static function ledger(int $customerId, string $type, float $amount, ?string $method, ?string $refType, ?int $refId, ?string $note, ?string $due = null, ?int $sessionId = null): float
    {
        $c = DB::one('SELECT balance FROM customers WHERE id = ? FOR UPDATE', [$customerId]);
        if (!$c) {
            throw new BusinessException('Client introuvable.');
        }
        $new = round((float) $c['balance'] + $amount, 2);
        DB::run('UPDATE customers SET balance = ? WHERE id = ?', [$new, $customerId]);
        self::$lastTxId = DB::insert('customer_transactions', [
            'customer_id' => $customerId, 'type' => $type, 'amount' => round($amount, 2), 'balance_after' => $new,
            'method' => $method, 'ref_type' => $refType, 'ref_id' => $refId, 'due_date' => $due, 'note' => $note,
            'cash_session_id' => $sessionId, 'user_id' => Auth::id(),
        ]);
        return $new;
    }

    /** Règlement d'un client (diminue sa dette) ou acompte (crée un avoir). */
    public static function payment(int $customerId, float $amount, string $method, ?string $note, string $type = 'payment'): int
    {
        if ($amount <= 0) {
            throw new BusinessException('Montant invalide.');
        }
        if (!isset(payment_methods()[$method])) {
            throw new BusinessException('Mode de paiement non autorisé.');
        }
        return DB::transaction(function () use ($customerId, $amount, $method, $note, $type) {
            $session = CashService::currentSession();
            if ($method === 'cash' && !$session) {
                throw new BusinessException('Ouvrez une session de caisse pour encaisser des espèces.');
            }
            self::ledger($customerId, $type, -$amount, $method, null, null, $note ?: ($type === 'deposit' ? 'Acompte' : 'Règlement'), null, $session['id'] ?? null);
            $txId = (int) self::$lastTxId;
            CashService::movement($session['id'] ?? null, 'customer_payment', $method, $amount, 'customer', $customerId, $note ?: 'Règlement client');
            Audit::log('customer.payment', 'customer', $customerId, ['amount' => $amount, 'method' => $method, 'type' => $type]);
            return $txId;
        });
    }
}
