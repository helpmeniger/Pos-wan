<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Services\LedgerService;
use App\Services\Payment\DemoGateway;
use App\Services\PaymentService;
use Tests\TestCase;

final class PaymentTest extends TestCase
{
    private function foodOrder(string $method = 'demo_mobile_money'): array
    {
        $m = $this->merchant();
        $p = Database::insert('products', ['merchant_id' => $m['id'], 'name' => 'Riz', 'price' => 2000]);
        $client = $this->user('client');
        $tc = $this->token((int) $client['id']);
        $r = $this->call('POST', '/api/orders', ['service_type' => 'food', 'merchant_id' => $m['id'], 'items' => [['product_id' => $p, 'quantity' => 2]],
            'dropoff_text' => 'Maison', 'dropoff_lat' => 13.523, 'dropoff_lng' => 2.105, 'payment_method' => $method], $tc);
        $this->assertStatus(201, $r);
        return [$this->data($r), $tc, $m, $client];
    }

    public function testElectronicPaymentIsConfirmedOnlyByValidSignedWebhook(): void
    {
        [$o, $tc] = $this->foodOrder();
        $this->assertSame('created', $o['status'], 'Commande non transmise au commerce avant paiement');
        $this->assertSame(4000, $o['items_subtotal'], 'Prix recalculé côté serveur');

        $a = $this->data($this->call('POST', "/api/orders/{$o['id']}/pay", [], $tc, ['Idempotency-Key' => 'k-test-0000001']));
        $b = $this->data($this->call('POST', "/api/orders/{$o['id']}/pay", [], $tc, ['Idempotency-Key' => 'k-test-0000001']));
        $this->assertSame($a['payment_id'], $b['payment_id'], 'Clé d\'idempotence : même paiement');
        $c = $this->data($this->call('POST', "/api/orders/{$o['id']}/pay", [], $tc, ['Idempotency-Key' => 'k-test-0000002']));
        $this->assertSame($a['payment_id'], $c['payment_id'], 'Une seule intention de paiement active par commande');

        $ext = Database::value('SELECT external_ref FROM payments WHERE id = :id', ['id' => $a['payment_id']]);
        [$body, $sig] = DemoGateway::buildWebhook((string) $ext, $o['total'], true);
        // Signature falsifiée (ex. capture d'écran ou requête forgée) : refusée
        $forged = $this->call('POST', '/api/webhooks/payments/demo', json_decode($body, true), null, ['X-Marwa-Signature' => str_repeat('0', 64)]);
        $this->assertStatus(400, $forged);
        $this->assertSame('pending', Database::value('SELECT status FROM payments WHERE id = :id', ['id' => $a['payment_id']]));
        // Montant altéré : refusé
        [$body2, $sig2] = DemoGateway::buildWebhook((string) $ext, 1, true);
        $res = PaymentService::handleWebhook('demo', $body2, ['x-marwa-signature' => $sig2]);
        $this->assertSame('amount_mismatch', $res['status']);
        Database::execute("UPDATE payments SET status = 'pending', failure_reason = NULL WHERE id = :id", ['id' => $a['payment_id']]);
        // Notification authentique
        $ok = PaymentService::handleWebhook('demo', $body, ['x-marwa-signature' => $sig]);
        $this->assertSame('confirmed', $ok['status']);
        $replay = PaymentService::handleWebhook('demo', $body, ['x-marwa-signature' => $sig]);
        $this->assertSame('duplicate', $replay['status'], 'Rejeu d\'une notification sans effet');
        $order = Database::first('SELECT * FROM orders WHERE id = :id', ['id' => $o['id']]);
        $this->assertSame('pending', $order['status'], 'Transmise au commerce après paiement');
        $this->assertSame('paid', $order['payment_status']);
        $this->assertSame(1, (int) Database::value("SELECT COUNT(*) FROM payments WHERE order_id = :o AND status = 'confirmed'", ['o' => $o['id']]));
        $this->assertStatus(409, $this->call('POST', "/api/orders/{$o['id']}/pay", [], $tc, ['Idempotency-Key' => 'k-test-0000003']), 'Double paiement refusé');
        $this->assertTrue(LedgerService::trialBalance()['balanced']);
    }

    public function testCancellationAfterPrepaymentRefundsAutomatically(): void
    {
        [$o, $tc] = $this->foodOrder();
        $a = $this->data($this->call('POST', "/api/orders/{$o['id']}/pay", [], $tc, ['Idempotency-Key' => 'k-cancel-000001']));
        $ext = Database::value('SELECT external_ref FROM payments WHERE id = :id', ['id' => $a['payment_id']]);
        [$body, $sig] = DemoGateway::buildWebhook((string) $ext, $o['total'], true);
        PaymentService::handleWebhook('demo', $body, ['x-marwa-signature' => $sig]);
        $this->assertStatus(200, $this->call('POST', "/api/orders/{$o['id']}/cancel", [], $tc));
        $this->assertSame('refunded', Database::value('SELECT payment_status FROM orders WHERE id = :id', ['id' => $o['id']]));
        $this->assertSame((int) $o['total'], (int) Database::value("SELECT amount FROM refunds WHERE order_id = :o AND status = 'completed'", ['o' => $o['id']]));
        $net2300 = (int) Database::value('SELECT COALESCE(SUM(e.credit) - SUM(e.debit),0) FROM ledger_entries e JOIN ledger_transactions t ON t.id = e.transaction_id WHERE e.account_id = :a AND t.order_id = :o',
            ['a' => LedgerService::account('2300'), 'o' => $o['id']]);
        $this->assertSame(0, $net2300, 'Plus aucune somme détenue pour le client');
    }

    public function testFailedPaymentIsRecorded(): void
    {
        [$o, $tc] = $this->foodOrder();
        $a = $this->data($this->call('POST', "/api/orders/{$o['id']}/pay", [], $tc, ['Idempotency-Key' => 'k-fail-0000001']));
        $ext = Database::value('SELECT external_ref FROM payments WHERE id = :id', ['id' => $a['payment_id']]);
        [$body, $sig] = DemoGateway::buildWebhook((string) $ext, $o['total'], false);
        $this->assertSame('failed', PaymentService::handleWebhook('demo', $body, ['x-marwa-signature' => $sig])['status']);
        $this->assertSame('failed', Database::value('SELECT payment_status FROM orders WHERE id = :id', ['id' => $o['id']]));
    }

    public function testBusinessAccountRespectsLimits(): void
    {
        $this->isolateProviders();
        $owner = $this->user('business');
        $emp = $this->user('client');
        $biz = Database::insert('businesses', ['owner_user_id' => $owner['id'], 'name' => 'SARL Test', 'status' => 'verified', 'postpaid_enabled' => 1]);
        Database::insert('business_members', ['business_id' => $biz, 'user_id' => $emp['id'], 'member_role' => 'employee', 'monthly_limit' => 500]);
        $te = $this->token((int) $emp['id']);
        $r = $this->call('POST', '/api/orders', $this->rideBody(['payment_method' => 'business_account', 'business_id' => $biz]), $te);
        $this->assertStatus(422, $r, 'Plafond mensuel du collaborateur dépassé');
        Database::execute('UPDATE business_members SET monthly_limit = NULL WHERE business_id = :b', ['b' => $biz]);
        $d = $this->provider();
        $o = $this->data($this->call('POST', '/api/orders', $this->rideBody(['payment_method' => 'business_account', 'business_id' => $biz]), $te));
        $td = $this->token((int) $d['id']);
        $this->call('POST', "/api/provider/requests/{$o['id']}/accept", [], $td);
        foreach (['en_route_pickup', 'arrived_pickup', 'in_progress', 'completed'] as $s) {
            $this->call('POST', "/api/provider/orders/{$o['id']}/action", ['action' => $s], $td);
        }
        $this->assertSame('paid', Database::value('SELECT status FROM orders WHERE id = :id', ['id' => $o['id']]));
        $this->assertSame(-$o['total'], LedgerService::partnerBalance('business', $biz), 'Créance de l\'entreprise');
        $this->assertSame($o['total'] - (int) round($o['total'] * 0.1), LedgerService::partnerBalance('provider', (int) $d['id']), 'MARWA doit le net au chauffeur');
        // Un non-membre ne peut pas utiliser le compte
        $this->assertStatus(403, $this->call('POST', '/api/orders', $this->rideBody(['payment_method' => 'business_account', 'business_id' => $biz]), $this->token((int) $this->user('client')['id'])));
    }
}
