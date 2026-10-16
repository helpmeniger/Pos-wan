<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Str;
use LogicException;

/**
 * Grand livre interne en partie double. Les écritures sont équilibrées, immuables
 * (déclencheurs SQL) et traçables ; une correction passe par une contre-passation
 * ou une écriture d'ajustement.
 */
final class LedgerService
{
    private static array $codeCache = [];

    public static function account(string $code): int
    {
        if (!isset(self::$codeCache[$code])) {
            $id = Database::value('SELECT id FROM ledger_accounts WHERE code = :c', ['c' => $code]);
            if (!$id) {
                throw new LogicException("Compte de grand livre introuvable : $code");
            }
            self::$codeCache[$code] = (int) $id;
        }
        return self::$codeCache[$code];
    }

    public static function flushCache(): void
    {
        self::$codeCache = [];
    }

    /** Compte de tiers (prestataire, commerce, entreprise), créé à la demande. */
    public static function partnerAccount(string $ownerType, int $ownerId): int
    {
        $id = Database::value('SELECT id FROM ledger_accounts WHERE owner_type = :t AND owner_id = :o', ['t' => $ownerType, 'o' => $ownerId]);
        if ($id) {
            return (int) $id;
        }
        $name = match ($ownerType) {
            'provider' => 'Prestataire ' . (Database::value('SELECT full_name FROM users WHERE id = :i', ['i' => $ownerId]) ?? '#' . $ownerId),
            'merchant' => 'Commerce ' . (Database::value('SELECT name FROM merchants WHERE id = :i', ['i' => $ownerId]) ?? '#' . $ownerId),
            'business' => 'Entreprise ' . (Database::value('SELECT name FROM businesses WHERE id = :i', ['i' => $ownerId]) ?? '#' . $ownerId),
            default => throw new LogicException('Type de tiers inconnu'),
        };
        $prefix = ['provider' => 'P', 'merchant' => 'M', 'business' => 'B'][$ownerType];
        Database::execute(
            'INSERT IGNORE INTO ledger_accounts (code, name, type, owner_type, owner_id) VALUES (:c, :n, :ty, :t, :o)',
            ['c' => $prefix . '-' . $ownerId, 'n' => mb_substr($name, 0, 150), 'ty' => $ownerType === 'business' ? 'asset' : 'liability', 't' => $ownerType, 'o' => $ownerId]
        );
        return (int) Database::value('SELECT id FROM ledger_accounts WHERE owner_type = :t AND owner_id = :o', ['t' => $ownerType, 'o' => $ownerId]);
    }

    /**
     * Enregistre une transaction équilibrée.
     * @param array<int, array{0:int,1:int,2:int,3?:string}> $lines [compte, débit, crédit, libellé]
     * @return array{id:int, created:bool}
     */
    public static function post(string $kind, string $description, array $lines, ?int $orderId = null, ?string $dedupeKey = null, ?int $userId = null, ?string $occurredAt = null, ?int $reversesId = null): array
    {
        $lines = array_values(array_filter($lines, static fn ($l) => (int) $l[1] !== 0 || (int) $l[2] !== 0));
        $debit = 0;
        $credit = 0;
        foreach ($lines as $l) {
            if ((int) $l[1] < 0 || (int) $l[2] < 0 || ((int) $l[1] > 0 && (int) $l[2] > 0)) {
                throw new LogicException('Ligne d\'écriture invalide (montant négatif ou double sens).');
            }
            $debit += (int) $l[1];
            $credit += (int) $l[2];
        }
        if ($debit !== $credit || $debit === 0) {
            throw new LogicException("Écriture déséquilibrée : débit $debit ≠ crédit $credit.");
        }
        return Database::transaction(static function () use ($kind, $description, $lines, $orderId, $dedupeKey, $userId, $occurredAt, $reversesId) {
            if ($dedupeKey !== null) {
                $existing = Database::value('SELECT id FROM ledger_transactions WHERE dedupe_key = :k', ['k' => $dedupeKey]);
                if ($existing) {
                    return ['id' => (int) $existing, 'created' => false];
                }
            }
            $at = $occurredAt ?? date('Y-m-d H:i:s');
            $txnId = Database::insert('ledger_transactions', [
                'uuid' => Str::uuid(),
                'kind' => $kind,
                'dedupe_key' => $dedupeKey,
                'order_id' => $orderId,
                'description' => mb_substr($description, 0, 255),
                'reverses_id' => $reversesId,
                'created_by' => $userId,
                'occurred_at' => $at,
            ]);
            foreach ($lines as $l) {
                Database::insert('ledger_entries', [
                    'transaction_id' => $txnId,
                    'account_id' => (int) $l[0],
                    'debit' => (int) $l[1],
                    'credit' => (int) $l[2],
                    'memo' => isset($l[3]) ? mb_substr((string) $l[3], 0, 190) : null,
                    'occurred_at' => $at,
                ]);
            }
            return ['id' => $txnId, 'created' => true];
        });
    }

    /** Contre-passation intégrale d'une transaction (jamais de suppression). */
    public static function reverse(int $txnId, string $reason, ?int $userId): int
    {
        $entries = Database::select('SELECT account_id, debit, credit, memo FROM ledger_entries WHERE transaction_id = :t', ['t' => $txnId]);
        $txn = Database::first('SELECT * FROM ledger_transactions WHERE id = :t', ['t' => $txnId]);
        if (!$txn || !$entries) {
            throw new LogicException('Transaction introuvable.');
        }
        $lines = array_map(static fn ($e) => [(int) $e['account_id'], (int) $e['credit'], (int) $e['debit'], 'Contre-passation : ' . $e['memo']], $entries);
        return self::post('adjustment', 'Contre-passation #' . $txnId . ' — ' . $reason, $lines, $txn['order_id'] ? (int) $txn['order_id'] : null, 'reverse:' . $txnId, $userId, null, $txnId)['id'];
    }

    /** Solde créditeur (crédit − débit). Pour un tiers : > 0 = MARWA lui doit. */
    public static function balance(int $accountId, ?string $until = null): int
    {
        $sql = 'SELECT COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) FROM ledger_entries WHERE account_id = :a';
        $p = ['a' => $accountId];
        if ($until) {
            $sql .= ' AND occurred_at <= :u';
            $p['u'] = $until;
        }
        return (int) Database::value($sql, $p);
    }

    public static function partnerBalance(string $ownerType, int $ownerId): int
    {
        $id = Database::value('SELECT id FROM ledger_accounts WHERE owner_type = :t AND owner_id = :o', ['t' => $ownerType, 'o' => $ownerId]);
        return $id ? self::balance((int) $id) : 0;
    }

    /** Contrôle global : la somme des débits doit égaler la somme des crédits. */
    public static function trialBalance(): array
    {
        $r = Database::first('SELECT COALESCE(SUM(debit),0) AS d, COALESCE(SUM(credit),0) AS c FROM ledger_entries');
        $unbalanced = Database::select('SELECT transaction_id, SUM(debit) d, SUM(credit) c FROM ledger_entries GROUP BY transaction_id HAVING d <> c');
        return ['debit' => (int) $r['d'], 'credit' => (int) $r['c'], 'balanced' => (int) $r['d'] === (int) $r['c'] && !$unbalanced, 'unbalanced_transactions' => $unbalanced];
    }

    public static function statement(int $accountId, int $limit = 200): array
    {
        return Database::select(
            'SELECT e.id, e.debit, e.credit, e.memo, e.occurred_at, t.kind, t.description, t.order_id, o.reference AS order_reference
               FROM ledger_entries e JOIN ledger_transactions t ON t.id = e.transaction_id
               LEFT JOIN orders o ON o.id = t.order_id
              WHERE e.account_id = :a ORDER BY e.id DESC LIMIT ' . max(1, min(1000, $limit)),
            ['a' => $accountId]
        );
    }
}
