<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/** Dettes fournisseurs : grand livre (factures, paiements, retours). */
final class SupplierService
{
    public static function ledger(int $supplierId, string $type, float $amount, ?string $method, ?string $refType, ?int $refId, ?string $note, ?string $due = null): float
    {
        $s = DB::one('SELECT balance FROM suppliers WHERE id = ? FOR UPDATE', [$supplierId]);
        if (!$s) {
            throw new BusinessException('Fournisseur introuvable.');
        }
        $new = round((float) $s['balance'] + $amount, 2);
        DB::run('UPDATE suppliers SET balance = ? WHERE id = ?', [$new, $supplierId]);
        DB::insert('supplier_transactions', [
            'supplier_id' => $supplierId, 'type' => $type, 'amount' => round($amount, 2), 'balance_after' => $new,
            'method' => $method, 'ref_type' => $refType, 'ref_id' => $refId, 'due_date' => $due, 'note' => $note, 'user_id' => Auth::id(),
        ]);
        return $new;
    }

    public static function pay(int $supplierId, float $amount, string $method, ?string $note, bool $fromRegister): void
    {
        if ($amount <= 0) {
            throw new BusinessException('Montant invalide.');
        }
        DB::transaction(function () use ($supplierId, $amount, $method, $note, $fromRegister) {
            $session = null;
            if ($method === 'cash' && $fromRegister) {
                $session = CashService::currentSession();
                if (!$session) {
                    throw new BusinessException('Aucune session de caisse ouverte pour sortir les espèces.');
                }
                if (CashService::expectedCash((int) $session['id']) < $amount) {
                    throw new BusinessException('Espèces insuffisantes dans la caisse.');
                }
            }
            self::ledger($supplierId, 'payment', -$amount, $method, null, null, $note ?: 'Paiement fournisseur');
            CashService::movement($session['id'] ?? null, 'supplier_payment', $method, -$amount, 'supplier', $supplierId, $note ?: 'Paiement fournisseur');
            Audit::log('supplier.payment', 'supplier', $supplierId, ['amount' => $amount, 'method' => $method]);
        });
    }
}
