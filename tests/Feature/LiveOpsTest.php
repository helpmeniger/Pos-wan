<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Services\NotificationService;
use Tests\TestCase;

/** Commandes en attente proposées au prestataire qui se connecte, suivi en direct, contact, notifications. */
final class LiveOpsTest extends TestCase
{
    public function testDriverComingOnlineSeesWaitingTaxiOrders(): void
    {
        $this->isolateProviders();
        $client = $this->user('client');
        $tc = $this->token((int) $client['id']);
        // Aucun chauffeur en ligne : la demande reste sans prise en charge
        $o = $this->data($this->call('POST', '/api/orders', $this->rideBody(), $tc));
        $this->assertSame(0, (int) Database::value('SELECT COUNT(*) FROM dispatch_offers WHERE order_id = :o', ['o' => $o['id']]));

        // Un chauffeur se connecte ensuite à proximité : la demande lui est proposée
        $driver = $this->provider('driver', 'taxi', 13.5150, 2.1100, false);
        $td = $this->token((int) $driver['id']);
        $r = $this->call('POST', '/api/provider/online', ['online' => true, 'lat' => 13.5150, 'lng' => 2.1100], $td);
        $this->assertStatus(200, $r);
        $this->assertSame(1, $this->data($r)['waiting_orders']);
        $reqs = $this->data($this->call('GET', '/api/provider/requests', [], $td));
        $this->assertSame([(int) $o['id']], array_map(fn ($x) => (int) $x['id'], $reqs));
        $this->assertStatus(200, $this->call('POST', "/api/provider/requests/{$o['id']}/accept", [], $td), 'Acceptée depuis la liste');

        // Un chauffeur trop loin ou qui a refusé ne la reçoit pas
        $far = $this->provider('driver', 'taxi', 13.90, 2.50, false);
        $this->assertSame(0, $this->data($this->call('POST', '/api/provider/online', ['online' => true, 'lat' => 13.90, 'lng' => 2.50], $this->token((int) $far['id'])))['waiting_orders']);
    }

    public function testAdminLiveBoardContactAndOffer(): void
    {
        $this->isolateProviders();
        $admin = $this->user('admin');
        $ta = $this->token((int) $admin['id']);
        $client = $this->user('client');
        $o = $this->data($this->call('POST', '/api/orders', $this->rideBody(), $this->token((int) $client['id'])));
        Database::execute('UPDATE orders SET created_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE id = :id', ['id' => $o['id']]);

        $live = $this->data($this->call('GET', '/api/admin/live', [], $ta));
        $row = array_values(array_filter($live['orders'], fn ($x) => $x['id'] === (int) $o['id']))[0];
        $this->assertTrue(in_array('attente', $row['flags'], true), 'Alerte attente longue');
        $this->assertTrue($row['provider'] === null && $live['summary']['waiting'] >= 1);

        // Chauffeur hors ligne mais validé : l'admin le trouve, le notifie et lui propose la course
        $driver = $this->provider('driver', 'taxi', 13.5160, 2.1110, false);
        $cands = $this->data($this->call('GET', "/api/admin/live/orders/{$o['id']}/candidates", [], $ta))['candidates'];
        $this->assertTrue(in_array((int) $driver['id'], array_column($cands, 'id'), true));
        $r = $this->call('POST', '/api/admin/live/contact', ['user_id' => $driver['id'], 'order_id' => $o['id'], 'message' => 'Un client vous attend, ouvrez MARWA.', 'offer' => true], $ta);
        $this->assertStatus(200, $r);
        $this->assertTrue($this->data($r)['offer']['offered'] === true);
        $this->assertSame('Message de l\'équipe MARWA', Database::value("SELECT title FROM notifications WHERE user_id = :u AND type = 'staff_message' ORDER BY id DESC LIMIT 1", ['u' => $driver['id']]));
        $this->assertSame(1, (int) Database::value('SELECT COUNT(*) FROM staff_contacts WHERE user_id = :u', ['u' => $driver['id']]));
        // Le chauffeur passe en ligne : la course proposée l'attend
        $td = $this->token((int) $driver['id']);
        $this->call('POST', '/api/provider/online', ['online' => true, 'lat' => 13.5160, 'lng' => 2.1110], $td);
        $ids = array_map(fn ($x) => (int) $x['id'], $this->data($this->call('GET', '/api/provider/requests', [], $td)));
        $this->assertTrue(in_array((int) $o['id'], $ids, true));

        // Personnel : contact impossible ; client : réservé à orders.manage
        $this->assertStatus(404, $this->call('POST', '/api/admin/live/contact', ['user_id' => $admin['id'], 'message' => 'test'], $ta));
        $this->assertStatus(403, $this->call('GET', '/api/admin/live', [], $this->token((int) $client['id'])));
    }

    public function testDeleteOwnNotificationOnly(): void
    {
        $a = $this->user('client');
        $b = $this->user('client');
        $id = NotificationService::notify((int) $a['id'], 'test', 'Essai', 'Corps');
        $this->assertStatus(404, $this->call('DELETE', "/api/notifications/$id", [], $this->token((int) $b['id'])), 'Notification d\'un autre utilisateur');
        $this->assertStatus(200, $this->call('DELETE', "/api/notifications/$id", [], $this->token((int) $a['id'])));
        $this->assertSame(0, (int) Database::value('SELECT COUNT(*) FROM notifications WHERE id = :id', ['id' => $id]));
    }

    public function testCriticalNotificationFallsBackToSmsWhenUnreachable(): void
    {
        $u = $this->user('driver');
        Database::execute('UPDATE users SET last_seen_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = :id', ['id' => $u['id']]);
        $sent = [];
        \App\Services\Notification\SmsSender::useGateway(new class($sent) implements \App\Services\Notification\SmsGateway {
            public function __construct(private array &$box) {}
            public function name(): string { return 'test'; }
            public function send(string $phone, string $message): void { $this->box[] = $phone; }
        });
        try {
            NotificationService::notify((int) $u['id'], 'new_request', 'Nouvelle demande', 'Course TX-1');
            NotificationService::notify((int) $u['id'], 'new_request', 'Nouvelle demande', 'Course TX-2');
            NotificationService::notify((int) $u['id'], 'payment_confirmed', 'Paiement', 'OK');
            $this->assertSame(1, count($sent), 'Un seul SMS (limite 1 / 10 min, alertes critiques uniquement)');
            Database::execute('UPDATE users SET last_seen_at = NOW() WHERE id = :id', ['id' => $u['id']]);
            $other = $this->user('driver');
            Database::execute('UPDATE users SET last_seen_at = NOW() WHERE id = :id', ['id' => $other['id']]);
            NotificationService::notify((int) $other['id'], 'new_request', 'Nouvelle demande', 'Course TX-3');
            $this->assertSame(1, count($sent), 'Pas de SMS si la personne est dans l\'application');
        } finally {
            \App\Services\Notification\SmsSender::useGateway(null);
        }
    }
}
