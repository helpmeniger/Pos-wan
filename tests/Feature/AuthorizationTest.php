<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** Contrôle d'accès côté serveur : rôles, permissions, propriété des données. */
final class AuthorizationTest extends TestCase
{
    public function testUnauthenticatedRequestsAreRejected(): void
    {
        $this->assertStatus(401, $this->call('GET', '/api/orders'));
        $this->assertStatus(401, $this->call('GET', '/api/admin/dashboard'));
    }

    public function testRolesAreEnforcedPerRoute(): void
    {
        $client = $this->token((int) $this->user('client')['id']);
        $driver = $this->token((int) $this->provider()['id']);
        $support = $this->token((int) $this->user('support')['id']);
        $finance = $this->token((int) $this->user('finance')['id']);
        $this->assertStatus(403, $this->call('GET', '/api/admin/dashboard', [], $client));
        $this->assertStatus(403, $this->call('GET', '/api/provider/requests', [], $client));
        $this->assertStatus(403, $this->call('POST', '/api/orders', $this->rideBody(), $driver), 'Un chauffeur ne commande pas via ce rôle');
        $this->assertStatus(403, $this->call('GET', '/api/merchant/finance', [], $driver));
        $this->assertStatus(403, $this->call('GET', '/api/admin/payments', [], $support), 'L\'assistance n\'a pas accès aux finances');
        $this->assertStatus(200, $this->call('GET', '/api/admin/complaints', [], $support));
        $this->assertStatus(200, $this->call('GET', '/api/admin/payments', [], $finance));
        $this->assertStatus(403, $this->call('POST', '/api/admin/commissions', ['name' => 'x'], $finance), 'La finance ne modifie pas les commissions');
        $this->assertStatus(403, $this->call('POST', '/api/admin/settings', ['settings' => []], $support));
    }

    public function testCustomerCannotSeeAnotherCustomersOrder(): void
    {
        $this->isolateProviders();
        $a = $this->user('client');
        $b = $this->user('client');
        $ta = $this->token((int) $a['id']);
        $tb = $this->token((int) $b['id']);
        $r = $this->call('POST', '/api/orders', $this->rideBody(), $ta);
        $this->assertStatus(201, $r);
        $id = $this->data($r)['id'];
        $this->assertStatus(200, $this->call('GET', "/api/orders/$id", [], $ta));
        $this->assertStatus(403, $this->call('GET', "/api/orders/$id", [], $tb));
        $this->assertStatus(403, $this->call('GET', "/api/orders/$id/tracking", [], $tb));
        $this->assertStatus(403, $this->call('POST', "/api/orders/$id/cancel", [], $tb));
        $this->assertStatus(403, $this->call('GET', "/api/orders/$id/messages", [], $tb));
        // Un chauffeur non sollicité n'y a pas accès non plus
        $stranger = $this->token((int) $this->provider('driver', 'taxi', 13.9, 2.5)['id']);
        $this->assertStatus(403, $this->call('GET', "/api/orders/$id", [], $stranger));
        $this->assertStatus(403, $this->call('POST', "/api/provider/orders/$id/action", ['action' => 'en_route_pickup'], $stranger));
    }

    public function testMerchantCannotActOnAnotherMerchantsOrders(): void
    {
        $m1 = $this->merchant();
        $m2 = $this->merchant();
        $p = \App\Core\Database::insert('products', ['merchant_id' => $m1['id'], 'name' => 'Plat', 'price' => 1000]);
        $c = $this->token((int) $this->user('client')['id']);
        $r = $this->call('POST', '/api/orders', ['service_type' => 'food', 'merchant_id' => $m1['id'], 'items' => [['product_id' => $p, 'quantity' => 1]],
            'dropoff_text' => 'Maison', 'dropoff_lat' => 13.523, 'dropoff_lng' => 2.105, 'payment_method' => 'cash'], $c);
        $this->assertStatus(201, $r);
        $id = $this->data($r)['id'];
        $t2 = $this->token((int) $m2['_owner']['id']);
        $this->assertStatus(403, $this->call('POST', "/api/merchant/orders/$id/action", ['action' => 'accept', 'prep_time_min' => 10], $t2));
        $list = $this->data($this->call('GET', '/api/merchant/orders', [], $t2));
        $this->assertSame([], array_values(array_filter($list, fn ($o) => $o['id'] === $id)));
    }

    public function testPartnerFinanceIsScopedToOwner(): void
    {
        $d1 = $this->provider('driver', 'taxi', 13.9, 2.5, false);
        $d2 = $this->provider('driver', 'taxi', 13.9, 2.5, false);
        $acc = \App\Services\LedgerService::partnerAccount('provider', (int) $d1['id']);
        \App\Services\LedgerService::post('test', 'Gain d1', [[\App\Services\LedgerService::account('1000'), 5000, 0], [$acc, 0, 5000]]);
        $e2 = $this->data($this->call('GET', '/api/provider/earnings', [], $this->token((int) $d2['id'])));
        $this->assertSame(0, $e2['summary']['balance'], 'Le solde d\'un autre prestataire n\'est jamais visible');
        $this->assertSame([], $e2['statement']);
        $e1 = $this->data($this->call('GET', '/api/provider/earnings', [], $this->token((int) $d1['id'])));
        $this->assertSame(5000, $e1['summary']['balance']);
    }

    public function testFilesAreProtected(): void
    {
        $u = $this->user('client');
        $fid = \App\Core\Database::insert('files', ['owner_id' => $u['id'], 'purpose' => 'document', 'path' => 'x/none.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1, 'visibility' => 'private']);
        $other = $this->token((int) $this->user('client')['id']);
        $this->assertStatus(404, $this->call('GET', "/fichiers/$fid", [], $other));
    }
}
