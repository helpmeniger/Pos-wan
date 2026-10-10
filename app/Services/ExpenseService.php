<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/** Dépenses et recettes diverses. Correction = contrepassation (jamais de suppression). */
final class ExpenseService
{
    public static function create(string $type, int $categoryId, float $amount, string $method, string $date, string $description, bool $fromRegister): int
    {
        if ($amount <= 0) {
            throw new BusinessException('Montant invalide.');
        }
        if (!in_array($type, ['expense', 'income'], true)) {
            throw new BusinessException('Type invalide.');
        }
        return DB::transaction(function () use ($type, $categoryId, $amount, $method, $date, $description, $fromRegister) {
            $cat = DB::one('SELECT * FROM expense_categories WHERE id = ?', [$categoryId]);
            if (!$cat) {
                throw new BusinessException('Catégorie introuvable.');
            }
            $session = null;
            if ($fromRegister && $method === 'cash') {
                $session = CashService::currentSession();
                if (!$session) {
                    throw new BusinessException('Aucune session de caisse ouverte.');
                }
                if ($type === 'expense' && CashService::expectedCash((int) $session['id']) < $amount) {
                    throw new BusinessException('Espèces insuffisantes dans la caisse.');
                }
            }
            $user = Auth::user();
            $number = Sequence::next('expense');
            $id = DB::insert('expenses', [
                'number' => $number, 'type' => $type, 'category_id' => $categoryId, 'store_id' => $user['store_id'] ?? null,
                'amount' => round($amount, 2), 'method' => $method, 'expense_date' => $date, 'description' => mb_substr($description, 0, 255),
                'cash_session_id' => $session['id'] ?? null, 'user_id' => Auth::id(),
            ]);
            CashService::movement($session['id'] ?? null, $type, $method, $type === 'expense' ? -$amount : $amount, 'expense', $id, $description);
            Audit::log('expense.create', 'expense', $id, ['type' => $type, 'amount' => $amount]);
            return $id;
        });
    }

    public static function reverse(int $id, string $reason): void
    {
        if (trim($reason) === '') {
            throw new BusinessException('Le motif est obligatoire.');
        }
        DB::transaction(function () use ($id, $reason) {
            $e = DB::one('SELECT * FROM expenses WHERE id = ? FOR UPDATE', [$id]);
            if (!$e || $e['status'] !== 'valid') {
                throw new BusinessException('Opération introuvable ou déjà contrepassée.');
            }
            $session = $e['method'] === 'cash' && $e['cash_session_id'] ? CashService::currentSession() : null;
            $amount = (float) $e['amount'];
            CashService::movement($session['id'] ?? null, $e['type'] . '_reversal', $e['method'], $e['type'] === 'expense' ? $amount : -$amount, 'expense', $id, 'Contrepassation : ' . $reason);
            DB::update('expenses', ['status' => 'reversed', 'reversed_by' => Auth::id()], 'id = ?', [$id]);
            Audit::log('expense.reverse', 'expense', $id, ['reason' => $reason, 'amount' => $amount]);
        });
    }
}
