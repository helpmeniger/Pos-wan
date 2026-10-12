<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Services\LedgerService;
use Tests\TestCase;

/** Remboursements, versements, reversements, dépenses et rapports issus des données réelles. */
final class FinanceTest extends TestCase
{
    private function paidRide(): array
    {
        $this->isolateProviders();
        $tc = $this->token((int) $this->user('client')['id']);
        $d = $this->provider();
        $td = $this->token((int) $d['id']);
        $o = $this->data($this->call('POST', '/api/orders', $this->rideBody(), $tc));
        $this->call('POST', "/api/provider/requests/{$o['id']}/accept", [], $td);
        foreach (['en_route_pickup', 'arrived_pickup', 'in_progress'] as $s) {
            $this->call('POST', "/api/provider/orders/{$o['id']}/action", ['action' => $s], $td);
        }
        $done = $this->data($this->call('POST', "/api/provider/orders/{$o['id']}/action", ['action' => 'completed', 'amount_collected' => $o['estimated_price']], $td));
        return [$done, $d, $td, $tc];
    }

    public function testRefundIsBalancedAndCapped(): void
    {
        [$o, $d] = $this->paidRide();
        $admin = $this->token((int) $this->user('admin')['id']);
        $support = $this->token((int) $this->user('support')['id']);
        $this->assertStatus(422, $this->call('POST', '/api/admin/refunds', ['order_id' => $o['id'], 'amount' => $o['total'] + 1, 'reason' => 'Trop', 'borne_by' => 'marwa'], $admin));
        // L'assistance demande, la finance (admin) approuve
        $req = $this->call('POST', '/api/admin/refunds', ['order_id' => $o['id'], 'amount' => 500, 'reason' => 'Retard important', 'borne_by' => 'provider'], $support);
        $this->assertStatus(201, $req);
        $rid = $this->data($req)['id'];
        $this->assertSame('requested', $this->data($req)['status']);
        $this->assertSame('refund_pending', Database::value('SELECT status FROM orders WHERE id = :id', ['id' => $o['id']]));
        $before = LedgerService::partnerBalance('provider', (int) $d['id']);
        $this->assertStatus(403, $this->call('POST', "/api/admin/refunds/$rid/review", ['decision' => 'approve'], $support));
        $this->assertStatus(200, $this->call('POST', "/api/admin/refunds/$rid/review", ['decision' => 'approve'], $admin));
        $this->assertSame($before - 500, LedgerService::partnerBalance('provider', (int) $d['id']), 'Remboursement à la charge du chauffeur');
        $this->assertSame('paid', Database::value('SELECT status FROM orders WHERE id = :id', ['id' => $o['id']]), 'Remboursement partiel : commande reste réglée');
        $this->assertSame('partially_refunded', Database::value('SELECT payment_status FROM orders WHERE id = :id', ['id' => $o['id']]));
        $this->assertTrue((bool) Database::value("SELECT 1 FROM audit_logs WHERE action = 'refund.completed' AND entity_id = :id", ['id' => $rid]));
        $this->assertTrue(LedgerService::trialBalance()['balanced']);
    }

    public function testPayoutAndRemittanceFlow(): void
    {
        [$o, $d, $td] = $this->paidRide();
        $admin = $this->token((int) $this->user('admin')['id']);
        $owed = -LedgerService::partnerBalance('provider', (int) $d['id']);
        $this->assertTrue($owed > 0, 'Commission due après une course payée en espèces');
        $this->assertStatus(422, $this->call('POST', '/api/provider/payouts', ['amount' => 1000, 'method' => 'mobile_money'], $td), 'Pas de retrait sans solde disponible');
        // Le chauffeur reverse la commission en agence
        $r = $this->call('POST', '/api/admin/remittances', ['partner_type' => 'provider', 'owner_id' => $d['id'], 'amount' => $owed, 'method' => 'cash', 'reference' => 'RECU-001'], $admin);
        $this->assertStatus(201, $r);
        $this->assertSame(0, LedgerService::partnerBalance('provider', (int) $d['id']));
        // Crédit (ex. course payée électroniquement) puis retrait validé avec justificatif
        LedgerService::post('test', 'Gain électronique', [[LedgerService::account('1100'), 3000, 0], [LedgerService::partnerAccount('provider', (int) $d['id']), 0, 3000]]);
        $po = $this->call('POST', '/api/provider/payouts', ['amount' => 2000, 'method' => 'mobile_money', 'destination' => '+22790000000'], $td);
        $this->assertStatus(201, $po);
        $this->assertStatus(422, $this->call('POST', '/api/provider/payouts', ['amount' => 1500, 'method' => 'mobile_money'], $td), 'Le montant en attente est déduit du disponible');
        $pid = $this->data($po)['id'];
        $this->assertStatus(422, $this->call('POST', "/api/admin/payouts/$pid/review", ['decision' => 'pay'], $admin), 'Justificatif obligatoire');
        $this->assertStatus(200, $this->call('POST', "/api/admin/payouts/$pid/review", ['decision' => 'pay', 'reference' => 'MM-TX-123'], $admin));
        $this->assertSame(1000, LedgerService::partnerBalance('provider', (int) $d['id']));
        $this->assertTrue(LedgerService::trialBalance()['balanced']);
    }

    public function testExpensesAndDashboardUseRealData(): void
    {
        [$o] = $this->paidRide();
        $admin = $this->token((int) $this->user('admin')['id']);
        $before = $this->data($this->call('GET', '/api/admin/dashboard?from=' . date('Y-m-d') . '&to=' . date('Y-m-d'), [], $admin))['finance'];
        $this->assertStatus(201, $this->call('POST', '/api/admin/expenses', ['category' => 'Marketing', 'description' => 'Affiches', 'amount' => 10000, 'expense_date' => date('d/m/Y')], $admin));
        $after = $this->data($this->call('GET', '/api/admin/dashboard?from=' . date('Y-m-d') . '&to=' . date('Y-m-d'), [], $admin))['finance'];
        $this->assertSame($before['operating_expenses'] + 10000, $after['operating_expenses']);
        $this->assertSame($before['profit_estimate'] - 10000, $after['profit_estimate']);
        $this->assertSame($after['margin'] - $after['operating_expenses'], $after['profit_estimate']);
        $this->assertTrue($after['gross_revenue'] >= $o['total']);
        $this->assertTrue($after['gross_revenue'] > $after['marwa_revenue'], 'Le CA brut n\'est jamais confondu avec le revenu MARWA');
        $gmvSql = (int) Database::value("SELECT COALESCE(SUM(total),0) FROM orders WHERE settled_at >= CURDATE() AND status IN ('paid','disputed','refund_pending','refunded')");
        $this->assertSame($gmvSql, $after['gross_revenue']);
    }

    public function testReportExportsAreValidFiles(): void
    {
        $this->paidRide();
        $finance = $this->token((int) $this->user('finance')['id']);
        $support = $this->token((int) $this->user('support')['id']);
        $q = '?from=' . date('Y-m-d') . '&to=' . date('Y-m-d');
        foreach (['period', 'city', 'driver', 'courier', 'merchant', 'payment_method', 'commissions', 'cash', 'unpaid', 'refunds', 'payouts', 'expenses'] as $type) {
            $r = $this->call('GET', "/api/admin/reports/$type$q", [], $finance);
            $this->assertStatus(200, $r, $type);
        }
        $rows = $this->data($this->call('GET', "/api/admin/reports/commissions$q", [], $finance))['rows'];
        $this->assertTrue(count($rows) >= 1);
        $csv = $this->call('GET', "/api/admin/reports/period$q&format=csv", [], $finance);
        $this->assertSame("\xEF\xBB\xBF", substr($csv->body, 0, 3));
        $xlsx = $this->call('GET', "/api/admin/reports/period$q&format=xlsx", [], $finance);
        $this->assertSame("PK", substr($xlsx->body, 0, 2));
        $pdf = $this->call('GET', "/api/admin/reports/commissions$q&format=pdf", [], $finance);
        $this->assertSame('%PDF-1.4', substr($pdf->body, 0, 8));
        $this->assertTrue(str_contains($pdf->body, '%%EOF'));
        $this->assertStatus(403, $this->call('GET', "/api/admin/reports/period$q", [], $support));
    }

    public function testCommissionRuleChangeIsAudited(): void
    {
        $admin = $this->user('admin');
        $t = $this->token((int) $admin['id']);
        $r = $this->call('POST', '/api/admin/commissions', ['name' => 'Contrat X', 'applies_to' => 'provider', 'model' => 'percent', 'percent_bp' => 800, 'fixed_amount' => 0, 'is_active' => true, 'provider_type' => 'driver'], $t);
        $this->assertStatus(201, $r);
        $id = $this->data($r)['id'];
        $this->assertTrue((bool) Database::value("SELECT 1 FROM audit_logs WHERE action = 'commission.created' AND entity_id = :id AND user_id = :u", ['id' => $id, 'u' => $admin['id']]));
        $this->assertThrows(fn () => Database::execute('DELETE FROM audit_logs WHERE entity_id = :id', ['id' => $id]), \PDOException::class, 'Journal d\'audit immuable');
        Database::execute('UPDATE commission_rules SET is_active = 0 WHERE id = :id', ['id' => $id]);
    }
}
