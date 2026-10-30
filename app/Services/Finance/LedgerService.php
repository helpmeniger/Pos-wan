<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Core\Database;
use App\Support\References;
use App\Support\Uuid;

/**
 * Écritures du grand livre en partie double. Un journal par événement, idempotent
 * (clé unique), équilibré (vérifié avant écriture), jamais modifié ensuite.
 */
class LedgerService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param list<array{account: string, debit?: int, credit?: int, user_id?: ?int}> $lines montants en francs
     * @return int|null identifiant du journal, null si l'événement était déjà comptabilisé
     */
    public function post(string $eventType, string $sourceType, int $sourceId, string $idempotencyKey, array $lines, ?string $description = null, ?int $actorId = null): ?int
    {
        $debit = array_sum(array_map(fn ($l) => (int) ($l['debit'] ?? 0), $lines));
        $credit = array_sum(array_map(fn ($l) => (int) ($l['credit'] ?? 0), $lines));
        if ($debit !== $credit || $debit <= 0) {
            throw new \LogicException("Écriture déséquilibrée ({$debit} / {$credit})");
        }
        return $this->db->transaction(function (Database $db) use ($eventType, $sourceType, $sourceId, $idempotencyKey, $lines, $description, $actorId) {
            if ($db->fetchValue('SELECT id FROM financial_ledger_journals WHERE idempotency_key = ?', [$idempotencyKey]) !== null) {
                return null;
            }
            $journalId = $db->insert('financial_ledger_journals', [
                'uuid' => Uuid::v4(), 'reference' => References::next($db, 'J'), 'event_type' => $eventType,
                'source_type' => $sourceType, 'source_id' => $sourceId, 'idempotency_key' => $idempotencyKey,
                'description' => $description, 'created_by' => $actorId,
            ]);
            foreach ($lines as $l) {
                if (((int) ($l['debit'] ?? 0)) === 0 && ((int) ($l['credit'] ?? 0)) === 0) {
                    continue;
                }
                $accountId = $db->fetchValue('SELECT id FROM financial_ledger_accounts WHERE code = ?', [$l['account']])
                    ?? throw new \InvalidArgumentException("Compte inconnu : {$l['account']}");
                $db->insert('financial_ledger_entries', [
                    'journal_id' => $journalId, 'account_id' => (int) $accountId, 'user_id' => $l['user_id'] ?? null,
                    'debit' => ((int) ($l['debit'] ?? 0)) . '.00', 'credit' => ((int) ($l['credit'] ?? 0)) . '.00',
                ]);
            }
            return $journalId;
        });
    }

    /** Solde d'un compte (sens normal), éventuellement pour un utilisateur. */
    public function balance(string $accountCode, ?int $userId = null): int
    {
        $sql = "SELECT a.normal_balance, COALESCE(SUM(e.debit), 0) d, COALESCE(SUM(e.credit), 0) c
                FROM financial_ledger_accounts a LEFT JOIN financial_ledger_entries e ON e.account_id = a.id"
            . ($userId !== null ? ' AND e.user_id = ' . (int) $userId : '') . ' WHERE a.code = ? GROUP BY a.id, a.normal_balance';
        $r = $this->db->fetch($sql, [$accountCode]);
        if ($r === null) {
            return 0;
        }
        $v = $r['normal_balance'] === 'DEBIT' ? (float) $r['d'] - (float) $r['c'] : (float) $r['c'] - (float) $r['d'];
        return (int) round($v);
    }
}
