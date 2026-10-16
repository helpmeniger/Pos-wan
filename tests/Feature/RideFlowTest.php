<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Services\LedgerService;
use Tests\TestCase;

/** Parcours complet d'une course : création → attribution → GPS → fin → espèces → commission → grand livre. */
final class RideFlowTest extends TestCase
{
    public function testFullCashRideLifecycle(): void
    {
        $this->isolateProviders();
        $client = $this->user('client');
        $driver = $this->provider('driver', 'taxi', 13.5150, 2.1100);
        $tc = $this->token((int) $client['id']);
        $td = $this->token((int) $driver['id']);

        $r = $this->call('POST', '/api/orders', $this->rideBody(), $tc);
        $this->assertStatus(201, $r);
        $o = $this->data($r);
        $this->assertSame('searching', $o['status']);
        $price = $o['estimated_price'];
        $this->assertTrue($price > 0);

        // Le chauffeur reçoit la demande (sans données personnelles du client)
        $reqs = $this->data($this->call('GET', '/api/provider/requests', [], $td));
        $this->assertSame(1, count($reqs));
        $this->assertFalse(isset($reqs[0]['customer']), 'Aucune identité client avant affectation');
        $this->assertSame(round(13.5137, 3), $reqs[0]['pickup']['lat'], 'Position de départ arrondie pour un prestataire non affecté');

        $acc = $this->call('POST', "/api/provider/requests/{$o['id']}/accept", [], $td);
        $this->assertStatus(200, $acc);
        $this->assertSame('assigned', $this->data($acc)['status']);
        $this->assertSame($price, $this->data($acc)['final_price']);

        foreach (['en_route_pickup', 'arrived_pickup', 'in_progress'] as $step) {
            $this->assertStatus(200, $this->call('POST', "/api/provider/orders/{$o['id']}/action", ['action' => $step], $td), $step);
        }
        // Position GPS réelle envoyée par l'appareil, visible du client
        Database::execute('UPDATE provider_locations SET updated_at = DATE_SUB(NOW(), INTERVAL 10 SECOND) WHERE provider_id = :p', ['p' => $driver['id']]);
        $this->assertStatus(200, $this->call('POST', '/api/provider/location', ['lat' => 13.5050, 'lng' => 2.1300, 'heading' => 120, 'speed' => 8.5, 'accuracy' => 12], $td));
        $t = $this->data($this->call('GET', "/api/orders/{$o['id']}/tracking", [], $tc));
        $this->assertSame(13.505, $t['position']['lat']);
        $this->assertSame(120, $t['position']['heading']);
        $this->assertSame('car', $t['vehicle']['icon']);
        $this->assertSame('dropoff', $t['eta']['target']);
        $this->assertFalse($t['position']['stale']);

        // Fin de course avec déclaration des espèces encaissées
        $done = $this->call('POST', "/api/provider/orders/{$o['id']}/action", ['action' => 'completed', 'amount_collected' => $price], $td);
        $this->assertStatus(200, $done);
        $this->assertSame('paid', $this->data($done)['status']);
        $this->assertSame(null, Database::value('SELECT current_order_id FROM provider_profiles WHERE user_id = :u', ['u' => $driver['id']]), 'Prestataire libéré');

        // Une course terminée ne peut plus être modifiée librement
        $this->assertStatus(409, $this->call('POST', "/api/provider/orders/{$o['id']}/action", ['action' => 'in_progress'], $td));
        $this->assertStatus(409, $this->call('POST', "/api/orders/{$o['id']}/cancel", ['reason' => 'trop tard'], $tc));

        // Commission 10 % : le chauffeur doit la commission à MARWA (espèces)
        $commission = (int) round($price * 0.1);
        $this->assertSame($commission, (int) Database::value("SELECT commission FROM commissions WHERE order_id = :o AND party = 'provider'", ['o' => $o['id']]));
        $this->assertSame(-$commission, LedgerService::partnerBalance('provider', (int) $driver['id']));
        $this->assertTrue(LedgerService::trialBalance()['balanced']);
        $this->assertTrue((bool) Database::value("SELECT 1 FROM invoices WHERE kind = 'receipt' AND order_id = :o", ['o' => $o['id']]));
        $hist = array_column(Database::select('SELECT to_status FROM order_status_history WHERE order_id = :o ORDER BY id', ['o' => $o['id']]), 'to_status');
        $this->assertSame(['created', 'searching', 'assigned', 'en_route_pickup', 'arrived_pickup', 'in_progress', 'completed', 'paid'], $hist);

        // Évaluation unique
        $this->assertStatus(200, $this->call('POST', "/api/orders/{$o['id']}/rate", ['provider_score' => 5, 'provider_comment' => 'Très bien'], $tc));
        $this->assertSame('5.00', (string) Database::value('SELECT rating_avg FROM provider_profiles WHERE user_id = :u', ['u' => $driver['id']]));
    }

    public function testDuplicateCashDeclarationIsRejected(): void
    {
        $this->isolateProviders();
        $tc = $this->token((int) $this->user('client')['id']);
        $driver = $this->provider();
        $td = $this->token((int) $driver['id']);
        $o = $this->data($this->call('POST', '/api/orders', $this->rideBody(), $tc));
        $this->call('POST', "/api/provider/requests/{$o['id']}/accept", [], $td);
        foreach (['en_route_pickup', 'arrived_pickup', 'in_progress', 'completed'] as $s) {
            $this->call('POST', "/api/provider/orders/{$o['id']}/action", ['action' => $s], $td);
        }
        $this->assertStatus(200, $this->call('POST', "/api/provider/orders/{$o['id']}/cash", ['amount_collected' => 1000], $td));
        $this->assertStatus(409, $this->call('POST', "/api/provider/orders/{$o['id']}/cash", ['amount_collected' => 1000], $td), 'Pas de double encaissement');
        $this->assertSame(1, (int) Database::value('SELECT COUNT(*) FROM payments WHERE order_id = :o', ['o' => $o['id']]));
        // Écart de caisse signalé : déclaré ≠ attendu
        $p = Database::first('SELECT * FROM payments WHERE order_id = :o', ['o' => $o['id']]);
        $this->assertSame('declared', $p['status']);
        $this->assertTrue((int) $p['declared_amount'] !== (int) $p['amount']);
    }

    public function testProviderReleaseTriggersReassignment(): void
    {
        $this->isolateProviders();
        $tc = $this->token((int) $this->user('client')['id']);
        $d1 = $this->provider('driver', 'taxi', 13.5140, 2.1099);
        $d2 = $this->provider('driver', 'taxi', 13.5160, 2.1120);
        $o = $this->data($this->call('POST', '/api/orders', $this->rideBody(), $tc));
        $this->assertStatus(200, $this->call('POST', "/api/provider/requests/{$o['id']}/accept", [], $this->token((int) $d1['id'])));
        $this->assertStatus(422, $this->call('POST', "/api/provider/orders/{$o['id']}/action", ['action' => 'release'], $this->token((int) $d1['id'])), 'Motif obligatoire');
        $this->assertStatus(200, $this->call('POST', "/api/provider/orders/{$o['id']}/action", ['action' => 'release', 'reason' => 'Panne'], $this->token((int) $d1['id'])));
        $fresh = Database::first('SELECT * FROM orders WHERE id = :id', ['id' => $o['id']]);
        $this->assertSame('searching', $fresh['status']);
        $this->assertSame(null, $fresh['provider_id']);
        $this->assertSame('pending', Database::value('SELECT status FROM dispatch_offers WHERE order_id = :o AND provider_id = :p', ['o' => $o['id'], 'p' => $d2['id']]), 'Le second chauffeur est sollicité');
        $this->assertStatus(200, $this->call('POST', "/api/provider/requests/{$o['id']}/accept", [], $this->token((int) $d2['id'])));
    }

    public function testCustomerCancellationAndAntiSpam(): void
    {
        $this->isolateProviders();
        $c = $this->user('client');
        $tc = $this->token((int) $c['id']);
        $a = $this->data($this->call('POST', '/api/orders', $this->rideBody(), $tc));
        $this->data($this->call('POST', '/api/orders', $this->rideBody(), $tc));
        $this->assertStatus(429, $this->call('POST', '/api/orders', $this->rideBody(), $tc), 'Nombre de demandes simultanées limité');
        $this->assertStatus(200, $this->call('POST', "/api/orders/{$a['id']}/cancel", [], $tc));
        $this->assertSame('cancelled', Database::value('SELECT status FROM orders WHERE id = :id', ['id' => $a['id']]));
    }

    public function testNoProviderExpiresRequest(): void
    {
        $this->isolateProviders();
        $tc = $this->token((int) $this->user('client')['id']);
        $o = $this->data($this->call('POST', '/api/orders', $this->rideBody(), $tc));
        Database::execute('UPDATE orders SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = :id', ['id' => $o['id']]);
        \App\Services\DispatchService::tick();
        $this->assertSame('expired', Database::value('SELECT status FROM orders WHERE id = :id', ['id' => $o['id']]));
    }
}
