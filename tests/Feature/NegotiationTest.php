<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use Tests\TestCase;

final class NegotiationTest extends TestCase
{
    public function testNegotiatedPriceRequiresAgreementFromBothParties(): void
    {
        $this->isolateProviders();
        $tc = $this->token((int) $this->user('client')['id']);
        $d1 = $this->provider('driver', 'taxi', 13.5140, 2.1099);
        $d2 = $this->provider('driver', 'taxi', 13.5150, 2.1110);
        $t1 = $this->token((int) $d1['id']);
        $t2 = $this->token((int) $d2['id']);

        // Prix hors bornes refusé
        $this->assertStatus(422, $this->call('POST', '/api/orders', $this->rideBody(['pricing_mode' => 'negotiated', 'offered_price' => 50]), $tc));
        $r = $this->call('POST', '/api/orders', $this->rideBody(['pricing_mode' => 'negotiated', 'offered_price' => 1500]), $tc);
        $this->assertStatus(201, $r);
        $o = $this->data($r);
        $this->assertSame('negotiating', $o['status']);
        $this->assertSame(null, $o['final_price'], 'Aucun prix final tant que les deux parties ne sont pas d\'accord');

        // Contre-proposition du chauffeur 1, valable 60 s
        $c = $this->call('POST', "/api/provider/requests/{$o['id']}/counter", ['amount' => 1900, 'ttl_seconds' => 60], $t1);
        $this->assertStatus(201, $c);
        $nid = $this->data($c)['id'];
        $this->assertSame('negotiating', Database::value('SELECT status FROM orders WHERE id = :id', ['id' => $o['id']]));

        // Le client voit la proposition et l'accepte
        $view = $this->data($this->call('GET', "/api/orders/{$o['id']}", [], $tc));
        $this->assertSame(1900, array_values(array_filter($view['negotiations'], fn ($n) => $n['proposed_by'] === 'provider'))[0]['amount']);
        $acc = $this->call('POST', "/api/orders/{$o['id']}/negotiations/$nid/accept", [], $tc);
        $this->assertStatus(200, $acc);
        $this->assertSame(1900, $this->data($acc)['final_price']);
        $this->assertSame((int) $d1['id'], (int) Database::value('SELECT provider_id FROM orders WHERE id = :id', ['id' => $o['id']]));

        // Le second chauffeur ne peut plus accepter
        $this->assertStatus(409, $this->call('POST', "/api/provider/requests/{$o['id']}/accept", [], $t2));
    }

    public function testCounterLimitsAndExpiry(): void
    {
        $this->isolateProviders();
        $tc = $this->token((int) $this->user('client')['id']);
        $d = $this->provider();
        $td = $this->token((int) $d['id']);
        $o = $this->data($this->call('POST', '/api/orders', $this->rideBody(['pricing_mode' => 'negotiated', 'offered_price' => 1500]), $tc));
        for ($i = 0; $i < 3; $i++) {
            $this->assertStatus(201, $this->call('POST', "/api/provider/requests/{$o['id']}/counter", ['amount' => 1600 + $i * 50], $td));
        }
        $this->assertStatus(429, $this->call('POST', "/api/provider/requests/{$o['id']}/counter", ['amount' => 1800], $td), 'Anti-spam : 3 contre-propositions max.');
        $last = Database::value("SELECT id FROM fare_negotiations WHERE order_id = :o AND proposed_by = 'provider' AND status = 'pending'", ['o' => $o['id']]);
        Database::execute('UPDATE fare_negotiations SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = :id', ['id' => $last]);
        $this->assertStatus(409, $this->call('POST', "/api/orders/{$o['id']}/negotiations/$last/accept", [], $tc), 'Proposition expirée');
    }

    public function testProviderAcceptsCustomerPrice(): void
    {
        $this->isolateProviders();
        $tc = $this->token((int) $this->user('client')['id']);
        $d = $this->provider();
        $o = $this->data($this->call('POST', '/api/orders', $this->rideBody(['pricing_mode' => 'negotiated', 'offered_price' => 1500]), $tc));
        $this->assertStatus(200, $this->call('POST', "/api/orders/{$o['id']}/offer", ['amount' => 1600], $tc));
        $acc = $this->data($this->call('POST', "/api/provider/requests/{$o['id']}/accept", [], $this->token((int) $d['id'])));
        $this->assertSame(1600, $acc['final_price'], 'Le prix accepté est la dernière offre du client');
    }
}
