<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Services\NavigationService;
use App\Services\Routing\RouteResult;
use App\Services\Routing\RoutingProvider;
use App\Services\Routing\RoutingService;
use Tests\TestCase;

/** Taxis à proximité (vie privée) et navigation intégrée. */
final class NearbyNavigationTest extends TestCase
{
    public function testNearbyShowsOnlyFreeOnlineProvidersWithoutIdentity(): void
    {
        $this->isolateProviders();
        $client = $this->user('client');
        $free = $this->provider('driver', 'taxi', 13.5150, 2.1100);
        $busy = $this->provider('driver', 'taxi', 13.5152, 2.1102);
        $off = $this->provider('driver', 'taxi', 13.5148, 2.1098, false);
        $far = $this->provider('driver', 'taxi', 13.80, 2.40);
        Database::execute('UPDATE provider_profiles SET current_order_id = NULL WHERE user_id = :u', ['u' => $busy['id']]);
        $o = Database::insert('orders', $this->minimalOrder((int) $client['id']));
        Database::execute('UPDATE provider_profiles SET current_order_id = :o WHERE user_id = :u', ['o' => $o, 'u' => $busy['id']]);

        $this->assertStatus(401, $this->call('GET', '/api/nearby?lat=13.5137&lng=2.1098'), 'Réservé aux utilisateurs connectés');
        $r = $this->call('GET', '/api/nearby?lat=13.5137&lng=2.1098&service=taxi', [], $this->token((int) $client['id']));
        $this->assertStatus(200, $r);
        $d = $this->data($r);
        $this->assertSame(1, $d['count'], 'Seul le chauffeur libre, en ligne et proche est visible');
        $v = $d['vehicles'][0];
        $this->assertSame(['k', 'lat', 'lng', 'heading', 'icon', 'category'], array_keys($v), 'Aucune donnée personnelle');
        $this->assertSame('car', $v['icon']);
        $shift = \App\Core\Geo::distance(13.5150, 2.1100, $v['lat'], $v['lng']);
        $this->assertTrue($shift >= 35 && $shift <= 120, "Position volontairement approximative (décalage {$shift} m)");
        $this->assertFalse(str_contains(json_encode($d), (string) $free['id']) && strlen((string) $free['id']) > 3, 'Identifiant non exposé');
        unset($off, $far);
    }

    public function testNavigationStepsAndFrenchInstructions(): void
    {
        $this->assertSame('Tournez à droite sur Rue 12', NavigationService::instruction(['type' => 'turn', 'modifier' => 'right', 'name' => 'Rue 12']));
        $this->assertSame('Au rond-point, prenez la 2e sortie sur Boulevard Mali Béro', NavigationService::instruction(['type' => 'roundabout', 'exit' => 2, 'name' => 'Boulevard Mali Béro']));
        $this->assertSame('Vous êtes arrivé (sur votre gauche)', NavigationService::instruction(['type' => 'arrive', 'modifier' => 'left']));
        $this->assertSame('Faites demi-tour', NavigationService::instruction(['type' => 'turn', 'modifier' => 'uturn', 'name' => '']));

        $this->isolateProviders();
        $client = $this->user('client');
        $driver = $this->provider('driver', 'taxi', 13.5150, 2.1100);
        $tc = $this->token((int) $client['id']);
        $td = $this->token((int) $driver['id']);
        $o = $this->data($this->call('POST', '/api/orders', $this->rideBody(), $tc));
        $this->assertStatus(200, $this->call('POST', "/api/provider/requests/{$o['id']}/accept", [], $td));

        RoutingService::fake(new class implements RoutingProvider {
            public function route(float $fromLat, float $fromLng, float $toLat, float $toLng, string $profile = 'car'): RouteResult
            {
                return new RouteResult(500, 90, [[$fromLat, $fromLng], [$toLat, $fromLng], [$toLat, $toLng]], 'osrm', false, [
                    ['type' => 'depart', 'modifier' => null, 'name' => 'Rue A', 'distance_m' => 300, 'duration_s' => 50, 'location' => [$fromLat, $fromLng]],
                    ['type' => 'turn', 'modifier' => 'left', 'name' => 'Rue B', 'distance_m' => 200, 'duration_s' => 40, 'location' => [$toLat, $fromLng]],
                    ['type' => 'arrive', 'modifier' => null, 'name' => '', 'distance_m' => 0, 'duration_s' => 0, 'location' => [$toLat, $toLng]],
                ]);
            }
        });
        try {
            $r = $this->call('GET', "/api/provider/orders/{$o['id']}/navigation?lat=13.5150&lng=2.1100", [], $td);
            $this->assertStatus(200, $r);
            $n = $this->data($r);
            $this->assertSame('pickup', $n['target']['kind']);
            $this->assertSame('Tournez à gauche sur Rue B', $n['steps'][1]['instruction']);
            $this->assertSame(3, count($n['geometry']));
            // Un autre chauffeur ne peut pas obtenir l'itinéraire de cette commande
            $other = $this->provider('driver', 'taxi', 13.6, 2.2);
            $this->assertStatus(403, $this->call('GET', "/api/provider/orders/{$o['id']}/navigation?lat=13.5&lng=2.1", [], $this->token((int) $other['id'])));
        } finally {
            RoutingService::fake(null);
        }
    }

    private function minimalOrder(int $customerId): array
    {
        return ['uuid' => \App\Core\Str::uuid(), 'reference' => 'TST-' . random_int(10000, 99999), 'customer_id' => $customerId, 'flow' => 'ride', 'service_type' => 'taxi',
            'status' => 'assigned', 'pickup_text' => 'A', 'pickup_lat' => 13.51, 'pickup_lng' => 2.11, 'dropoff_text' => 'B', 'dropoff_lat' => 13.52, 'dropoff_lng' => 2.12,
            'payment_method' => 'cash', 'total' => 1000];
    }
}
