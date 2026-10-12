<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

/** Comptes entreprise : collaborateurs, plafonds, paiement différé. */
final class BusinessService
{
    /** Entreprise gérée par l'utilisateur (propriétaire ou gestionnaire), sinon null. */
    public static function managedBy(int $userId): ?array
    {
        return Database::first(
            "SELECT b.*, m.member_role FROM businesses b JOIN business_members m ON m.business_id = b.id
              WHERE m.user_id = :u AND m.status = 'active' AND m.member_role IN ('owner','manager') ORDER BY b.id LIMIT 1",
            ['u' => $userId]
        );
    }

    public static function membership(int $userId, int $businessId): ?array
    {
        return Database::first("SELECT * FROM business_members WHERE user_id = :u AND business_id = :b AND status = 'active'", ['u' => $userId, 'b' => $businessId]);
    }

    public static function monthSpend(int $businessId, ?int $userId = null): int
    {
        $sql = "SELECT COALESCE(SUM(total),0) FROM orders WHERE business_id = :b AND payment_method = 'business_account'
                 AND status NOT IN ('cancelled','expired','refused','refunded') AND created_at >= :m";
        $p = ['b' => $businessId, 'm' => date('Y-m-01 00:00:00')];
        if ($userId) {
            $sql .= ' AND customer_id = :u';
            $p['u'] = $userId;
        }
        return (int) Database::value($sql, $p);
    }

    /** Vérifie autorisation, plafond mensuel du collaborateur, budget et encours de l'entreprise. */
    public static function assertCanCharge(int $userId, int $businessId, int $amount): array
    {
        $b = Database::first('SELECT * FROM businesses WHERE id = :id', ['id' => $businessId]);
        $m = self::membership($userId, $businessId);
        if (!$b || !$m) {
            throw HttpException::forbidden('Vous n\'êtes pas autorisé à commander pour cette entreprise.');
        }
        if ($b['status'] !== 'verified' || !(int) $b['postpaid_enabled']) {
            throw new HttpException(422, 'Le paiement sur compte entreprise n\'est pas activé pour cette entreprise.', 'business_not_enabled');
        }
        if (!(int) $m['can_order']) {
            throw HttpException::forbidden('Votre compte collaborateur n\'est pas autorisé à passer des commandes.');
        }
        if ($m['monthly_limit'] !== null && self::monthSpend($businessId, $userId) + $amount > (int) $m['monthly_limit']) {
            throw new HttpException(422, 'Plafond mensuel de dépenses atteint pour votre compte collaborateur.', 'spending_limit');
        }
        if ($b['monthly_budget'] !== null && self::monthSpend($businessId) + $amount > (int) $b['monthly_budget']) {
            throw new HttpException(422, 'Budget mensuel de l\'entreprise atteint.', 'spending_limit');
        }
        $receivable = -LedgerService::partnerBalance('business', $businessId); // débiteur = dû par l'entreprise
        if ((int) $b['credit_limit'] > 0 && $receivable + $amount > (int) $b['credit_limit']) {
            throw new HttpException(422, 'Encours autorisé de l\'entreprise dépassé. Un règlement est nécessaire.', 'credit_limit');
        }
        return $b;
    }
}
