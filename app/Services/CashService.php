<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * Sessions de caisse : ouverture avec fond de caisse, mouvements, clôture
 * avec comptage réel et calcul de l'écart. Une seule session ouverte par caisse
 * (garanti par un index unique en base).
 */
final class CashService
{
    public static function openSessionForRegister(int $registerId): ?array
    {
        return DB::one("SELECT * FROM cash_sessions WHERE register_id = ? AND status = 'open'", [$registerId]);
    }

    /** Session ouverte de l'utilisateur courant (sur sa caisse). */
    public static function currentSession(?array $user = null): ?array
    {
        $user ??= Auth::user();
        if (!$user || !$user['register_id']) {
            return null;
        }
        return self::openSessionForRegister((int) $user['register_id']);
    }

    public static function open(int $registerId, float $amount): int
    {
        if ($amount < 0) {
            throw new BusinessException('Le fond de caisse ne peut pas être négatif.');
        }
        return DB::transaction(function () use ($registerId, $amount) {
            $reg = DB::one('SELECT * FROM cash_registers WHERE id = ? AND active = 1 FOR UPDATE', [$registerId]);
            if (!$reg) {
                throw new BusinessException('Caisse introuvable ou inactive.');
            }
            if (self::openSessionForRegister($registerId)) {
                throw new BusinessException('Une session est déjà ouverte sur cette caisse.');
            }
            $id = DB::insert('cash_sessions', ['register_id' => $registerId, 'user_id' => Auth::id(), 'opening_amount' => $amount]);
            Audit::log('cash.open', 'cash_session', $id, ['register' => $registerId, 'opening' => $amount]);
            return $id;
        });
    }

    public static function movement(?int $sessionId, string $type, string $method, float $amount, ?string $refType = null, ?int $refId = null, ?string $reason = null): int
    {
        return DB::insert('cash_movements', [
            'cash_session_id' => $sessionId, 'type' => $type, 'method' => $method, 'amount' => round($amount, 2),
            'ref_type' => $refType, 'ref_id' => $refId, 'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null, 'user_id' => Auth::id(),
        ]);
    }

    /** Entrée / sortie manuelle d'espèces (apport, retrait, dépôt banque). */
    public static function manual(int $sessionId, string $direction, float $amount, string $reason): int
    {
        if ($amount <= 0) {
            throw new BusinessException('Montant invalide.');
        }
        if (trim($reason) === '') {
            throw new BusinessException('Le motif est obligatoire.');
        }
        return DB::transaction(function () use ($sessionId, $direction, $amount, $reason) {
            $s = DB::one("SELECT * FROM cash_sessions WHERE id = ? AND status = 'open' FOR UPDATE", [$sessionId]);
            if (!$s) {
                throw new BusinessException('Aucune session de caisse ouverte.');
            }
            $signed = $direction === 'in' ? $amount : -$amount;
            if ($signed < 0 && self::expectedCash($sessionId) + $signed < -0.001) {
                throw new BusinessException('Montant supérieur aux espèces théoriquement présentes en caisse.');
            }
            $id = self::movement($sessionId, $direction === 'in' ? 'cash_in' : 'cash_out', 'cash', $signed, null, null, $reason);
            Audit::log('cash.' . ($direction === 'in' ? 'in' : 'out'), 'cash_session', $sessionId, ['amount' => $amount, 'reason' => $reason]);
            return $id;
        });
    }

    /** Espèces théoriques = fond + mouvements en espèces. */
    public static function expectedCash(int $sessionId): float
    {
        $s = DB::one('SELECT opening_amount FROM cash_sessions WHERE id = ?', [$sessionId]);
        $mv = (float) DB::value("SELECT COALESCE(SUM(amount),0) FROM cash_movements WHERE cash_session_id = ? AND method = 'cash'", [$sessionId]);
        return round((float) $s['opening_amount'] + $mv, 2);
    }

    public static function summary(int $sessionId): array
    {
        $byMethod = DB::all('SELECT method, SUM(amount) AS total FROM cash_movements WHERE cash_session_id = ? GROUP BY method', [$sessionId]);
        $byType = DB::all('SELECT type, method, SUM(amount) AS total, COUNT(*) AS n FROM cash_movements WHERE cash_session_id = ? GROUP BY type, method ORDER BY type', [$sessionId]);
        $sales = DB::one("SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS total, COALESCE(SUM(credit_amount),0) AS credit, COALESCE(SUM(discount_total),0) AS discounts FROM sales WHERE cash_session_id = ? AND status <> 'cancelled'", [$sessionId]);
        $cancelled = (int) DB::value("SELECT COUNT(*) FROM sales WHERE cash_session_id = ? AND status = 'cancelled'", [$sessionId]);
        return ['by_method' => $byMethod, 'by_type' => $byType, 'sales' => $sales, 'cancelled' => $cancelled, 'expected_cash' => self::expectedCash($sessionId)];
    }

    public static function close(int $sessionId, float $counted, ?string $note): array
    {
        if ($counted < 0) {
            throw new BusinessException('Montant compté invalide.');
        }
        return DB::transaction(function () use ($sessionId, $counted, $note) {
            $s = DB::one("SELECT * FROM cash_sessions WHERE id = ? AND status = 'open' FOR UPDATE", [$sessionId]);
            if (!$s) {
                throw new BusinessException('Session introuvable ou déjà clôturée.');
            }
            $expected = self::expectedCash($sessionId);
            $diff = round($counted - $expected, 2);
            if (abs($diff) >= 1 && trim((string) $note) === '') {
                throw new BusinessException(sprintf('Écart de %s constaté : un commentaire est obligatoire pour clôturer.', money($diff)));
            }
            DB::update('cash_sessions', [
                'status' => 'closed', 'expected_amount' => $expected, 'counted_amount' => $counted, 'difference' => $diff,
                'closed_at' => date('Y-m-d H:i:s'), 'closed_by' => Auth::id(), 'closing_note' => $note,
            ], 'id = ?', [$sessionId]);
            Audit::log('cash.close', 'cash_session', $sessionId, ['expected' => $expected, 'counted' => $counted, 'difference' => $diff]);
            return ['expected' => $expected, 'counted' => $counted, 'difference' => $diff];
        });
    }
}
