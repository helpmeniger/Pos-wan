<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Services\LedgerService;
use Tests\TestCase;

final class MerchantFlowTest extends TestCase
{
    public function testFoodOrderWithOptionsStockCourierAndOtp(): void
    {
        $this->isolateProviders();
        $m = $this->merchant(['track_stock' => 1]);
        $p = Database::insert('products', ['merchant_id' => $m['id'], 'name' => 'Poulet', 'price' => 3000, 'stock_qty' => 2]);
        $v1 = Database::insert('product_variants', ['product_id' => $p, 'kind' => 'variant', 'group_name' => 'Accompagnement', 'name' => 'Frites', 'price_delta' => 0]);
        $v2 = Database::insert('product_variants', ['product_id' => $p, 'kind' => 'variant', 'group_name' => 'Accompagnement', 'name' => 'Alloco', 'price_delta' => 200]);
        $tc = $this->token((int) $this->user('client')['id']);
        $tm = $this->token((int) $m['_owner']['id']);
        $courier = $this->provider('courier', 'moto', 13.5165, 2.1175);
        $tco = $this->token((int) $courier['id']);
        $base = ['service_type' => 'food', 'merchant_id' => $m['id'], 'dropoff_text' => 'Maison', 'dropoff_lat' => 13.523, 'dropoff_lng' => 2.105, 'payment_method' => 'cash'];

        $this->assertStatus(422, $this->call('POST', '/api/orders', $base + ['items' => [['product_id' => $p, 'quantity' => 1, 'option_ids' => [$v1, $v2]]]], $tc), 'Une seule variante par groupe');
        $this->assertStatus(422, $this->call('POST', '/api/orders', $base + ['items' => [['product_id' => $p, 'quantity' => 3]]], $tc), 'Stock insuffisant');
        $r = $this->call('POST', '/api/orders', $base + ['items' => [['product_id' => $p, 'quantity' => 2, 'option_ids' => [$v2]]], 'items_subtotal' => 1], $tc);
        $this->assertStatus(201, $r);
        $o = $this->data($r);
        $this->assertSame(6400, $o['items_subtotal'], '2 × (3000 + 200), le prix envoyé par le client est ignoré');
        $this->assertSame('pending', $o['status']);
        $this->assertSame(0, (int) Database::value('SELECT stock_qty FROM products WHERE id = :id', ['id' => $p]));

        $acc = $this->call('POST', "/api/merchant/orders/{$o['id']}/action", ['action' => 'accept', 'prep_time_min' => 5], $tm);
        $this->assertStatus(200, $acc);
        $this->assertSame('searching', $this->data($acc)['dispatch_status'], 'Préparation courte : livreur recherché immédiatement');
        $this->assertStatus(200, $this->call('POST', "/api/provider/requests/{$o['id']}/accept", [], $tco));
        $this->assertStatus(200, $this->call('POST', "/api/provider/orders/{$o['id']}/action", ['action' => 'en_route_pickup'], $tco));
        $this->assertStatus(409, $this->call('POST', "/api/provider/orders/{$o['id']}/action", ['action' => 'picked_up'], $tco), 'Pas de récupération avant « prête »');
        $this->call('POST', "/api/merchant/orders/{$o['id']}/action", ['action' => 'preparing'], $tm);
        $this->call('POST', "/api/merchant/orders/{$o['id']}/action", ['action' => 'ready'], $tm);
        $this->assertSame('in_delivery', $this->data($this->call('POST', "/api/provider/orders/{$o['id']}/action", ['action' => 'picked_up'], $tco))['status']);

        // Code OTP erroné puis correct
        $this->assertStatus(422, $this->call('POST', "/api/provider/orders/{$o['id']}/action", ['action' => 'delivered', 'delivery_code' => '0000'], $tco));
        $code = $this->data($this->call('GET', "/api/orders/{$o['id']}", [], $tc))['delivery_code'];
        $this->assertFalse(isset($this->data($this->call('GET', "/api/orders/{$o['id']}", [], $tco))['delivery_code']), 'Le code n\'est jamais transmis au livreur');
        $done = $this->call('POST', "/api/provider/orders/{$o['id']}/action", ['action' => 'delivered', 'delivery_code' => $code, 'amount_collected' => $o['total']], $tco);
        $this->assertStatus(200, $done);
        $this->assertSame('paid', $this->data($done)['status']);

        // Le livreur a encaissé le total : il doit à MARWA tout sauf son net
        $courierNet = (int) Database::value("SELECT partner_net FROM commissions WHERE order_id = :o AND party = 'provider'", ['o' => $o['id']]);
        $merchantNet = (int) Database::value("SELECT partner_net FROM commissions WHERE order_id = :o AND party = 'merchant'", ['o' => $o['id']]);
        $this->assertSame(-($o['total'] - $courierNet), LedgerService::partnerBalance('provider', (int) $courier['id']));
        $this->assertSame($merchantNet, LedgerService::partnerBalance('merchant', (int) $m['id']));
        $this->assertSame(6400 - 960, $merchantNet, 'Commission restaurant 15 %');
        $this->assertTrue(LedgerService::trialBalance()['balanced']);
    }

    public function testMerchantRefusalRestocksAndNotifies(): void
    {
        $m = $this->merchant(['track_stock' => 1]);
        $p = Database::insert('products', ['merchant_id' => $m['id'], 'name' => 'Jus', 'price' => 500, 'stock_qty' => 5]);
        $c = $this->user('client');
        $o = $this->data($this->call('POST', '/api/orders', ['service_type' => 'food', 'merchant_id' => $m['id'], 'items' => [['product_id' => $p, 'quantity' => 2]],
            'dropoff_text' => 'Maison', 'dropoff_lat' => 13.523, 'dropoff_lng' => 2.105], $this->token((int) $c['id'])));
        $tm = $this->token((int) $m['_owner']['id']);
        $this->assertStatus(422, $this->call('POST', "/api/merchant/orders/{$o['id']}/action", ['action' => 'refuse'], $tm), 'Motif obligatoire');
        $this->assertStatus(200, $this->call('POST', "/api/merchant/orders/{$o['id']}/action", ['action' => 'refuse', 'reason' => 'Rupture'], $tm));
        $this->assertSame(5, (int) Database::value('SELECT stock_qty FROM products WHERE id = :id', ['id' => $p]));
        $this->assertTrue((bool) Database::value("SELECT 1 FROM notifications WHERE user_id = :u AND type = 'order_refused'", ['u' => $c['id']]));
    }

    public function testClosedMerchantRejectsOrders(): void
    {
        $m = $this->merchant(['is_open' => 0]);
        $p = Database::insert('products', ['merchant_id' => $m['id'], 'name' => 'X', 'price' => 500]);
        $r = $this->call('POST', '/api/orders', ['service_type' => 'food', 'merchant_id' => $m['id'], 'items' => [['product_id' => $p, 'quantity' => 1]],
            'dropoff_text' => 'Maison', 'dropoff_lat' => 13.523, 'dropoff_lng' => 2.105], $this->token((int) $this->user('client')['id']));
        $this->assertStatus(422, $r);
    }

    public function testMessagesMaskPhoneNumbers(): void
    {
        $this->isolateProviders();
        $tc = $this->token((int) $this->user('client')['id']);
        $d = $this->provider();
        $o = $this->data($this->call('POST', '/api/orders', $this->rideBody(), $tc));
        $this->call('POST', "/api/provider/requests/{$o['id']}/accept", [], $this->token((int) $d['id']));
        $r = $this->data($this->call('POST', "/api/orders/{$o['id']}/messages", ['body' => 'Appelez-moi au 90 12 34 56 ou https://exemple.com'], $tc));
        $this->assertTrue($r['filtered']);
        $this->assertFalse(str_contains($r['body'], '90 12 34 56'));
        $this->assertFalse(str_contains($r['body'], 'https://'));
    }
}
