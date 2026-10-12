<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database;
use App\Services\LedgerService;
use LogicException;
use PDOException;
use Tests\TestCase;

final class LedgerTest extends TestCase
{
    public function testBalancedTransactionIsPosted(): void
    {
        $cash = LedgerService::account('1000');
        $rev = LedgerService::account('4000');
        $t = LedgerService::post('test', 'Écriture test', [[$cash, 500, 0], [$rev, 0, 500]]);
        $this->assertTrue($t['created']);
        $this->assertSame(2, (int) Database::value('SELECT COUNT(*) FROM ledger_entries WHERE transaction_id = :t', ['t' => $t['id']]));
    }

    public function testUnbalancedTransactionIsRefused(): void
    {
        $this->assertThrows(fn () => LedgerService::post('test', 'Déséquilibre', [[LedgerService::account('1000'), 500, 0], [LedgerService::account('4000'), 0, 400]]), LogicException::class);
        $this->assertThrows(fn () => LedgerService::post('test', 'Négatif', [[LedgerService::account('1000'), -5, 0], [LedgerService::account('4000'), 0, -5]]), LogicException::class);
    }

    public function testDedupeKeyMakesPostingIdempotent(): void
    {
        $lines = [[LedgerService::account('1000'), 100, 0], [LedgerService::account('4000'), 0, 100]];
        $a = LedgerService::post('test', 'Idempotent', $lines, null, 'test:dedupe:1');
        $b = LedgerService::post('test', 'Idempotent', $lines, null, 'test:dedupe:1');
        $this->assertSame($a['id'], $b['id']);
        $this->assertFalse($b['created']);
    }

    public function testEntriesAreImmutable(): void
    {
        $t = LedgerService::post('test', 'Immuable', [[LedgerService::account('1000'), 100, 0], [LedgerService::account('4000'), 0, 100]]);
        $this->assertThrows(fn () => Database::execute('UPDATE ledger_entries SET debit = 1 WHERE transaction_id = :t', ['t' => $t['id']]), PDOException::class);
        $this->assertThrows(fn () => Database::execute('DELETE FROM ledger_entries WHERE transaction_id = :t', ['t' => $t['id']]), PDOException::class);
        $this->assertThrows(fn () => Database::execute('DELETE FROM ledger_transactions WHERE id = :t', ['t' => $t['id']]), PDOException::class);
    }

    public function testReversalRestoresBalances(): void
    {
        $p = $this->provider('driver', 'taxi', 13.6, 2.2, false);
        $acc = LedgerService::partnerAccount('provider', (int) $p['id']);
        $t = LedgerService::post('test', 'Crédit partenaire', [[LedgerService::account('1000'), 1000, 0], [$acc, 0, 1000]]);
        $this->assertSame(1000, LedgerService::balance($acc));
        LedgerService::reverse($t['id'], 'erreur de saisie', null);
        $this->assertSame(0, LedgerService::balance($acc));
        $this->assertTrue(LedgerService::trialBalance()['balanced']);
    }
}
