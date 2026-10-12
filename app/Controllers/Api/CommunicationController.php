<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Str;
use App\Services\NotificationService;
use App\Services\OrderPolicy;
use App\Services\RealtimeService;

/** Notifications, flux temps réel, réclamations et alertes d'urgence. */
final class CommunicationController extends Controller
{
    public function notifications(Request $r): Response
    {
        $u = $this->user();
        return $this->json([
            'unread' => (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND read_at IS NULL', ['u' => $u['id']]),
            'items' => Database::select('SELECT id, type, title, body, data, read_at, created_at FROM notifications WHERE user_id = :u ORDER BY id DESC LIMIT 50', ['u' => $u['id']]),
        ]);
    }

    public function markRead(Request $r): Response
    {
        $ids = array_filter(array_map('intval', (array) $r->input('ids', [])));
        if ($ids) {
            [$in, $p] = Database::in($ids, 'n');
            Database::execute("UPDATE notifications SET read_at = NOW() WHERE user_id = :u AND read_at IS NULL AND id IN ($in)", $p + ['u' => $this->user()['id']]);
        } else {
            Database::execute('UPDATE notifications SET read_at = NOW() WHERE user_id = :u AND read_at IS NULL', ['u' => $this->user()['id']]);
        }
        return $this->ok('Notifications lues.');
    }

    /** Interrogation incrémentale : renvoie uniquement les événements de l'utilisateur connecté. */
    public function events(Request $r): Response
    {
        $u = $this->user();
        $since = (int) $r->input('since', 0);
        if ($since <= 0) {
            return $this->json(['cursor' => RealtimeService::latestId((int) $u['id']), 'events' => []]);
        }
        $events = RealtimeService::since((int) $u['id'], $since);
        return $this->json(['cursor' => $events ? (int) end($events)['id'] : $since, 'events' => $events, 'server_time' => date('Y-m-d H:i:s')]);
    }

    public function complaints(Request $r): Response
    {
        return $this->json(Database::select('SELECT c.id, c.reference, c.category, c.subject, c.status, c.created_at, c.resolution, o.reference order_reference
                                              FROM complaints c LEFT JOIN orders o ON o.id = c.order_id WHERE c.user_id = :u ORDER BY c.id DESC LIMIT 50', ['u' => $this->user()['id']]));
    }

    public function createComplaint(Request $r): Response
    {
        $u = $this->user();
        $v = $this->validate($r, ['category' => 'required|in:ride,order,payment,lost_item,safety,account,report_user,other', 'order_id' => 'nullable|int',
            'subject' => 'required|string|min:3|max:150', 'description' => 'required|string|min:10|max:4000']);
        RateLimiter::enforce('complaint:' . $u['id'], 5, 3600, 'Vous avez déjà envoyé plusieurs réclamations. Notre équipe vous répondra rapidement.');
        if (!empty($v['order_id'])) {
            OrderPolicy::load((int) $v['order_id'], $u, ['customer', 'provider', 'merchant', 'business']);
        }
        $priority = in_array($v['category'], ['safety'], true) ? 'urgent' : (in_array($v['category'], ['payment', 'lost_item'], true) ? 'high' : 'normal');
        $id = Database::insert('complaints', ['reference' => Str::reference('RC'), 'user_id' => $u['id'], 'order_id' => $v['order_id'] ?? null, 'category' => $v['category'],
            'subject' => $v['subject'], 'description' => $v['description'], 'priority' => $priority]);
        // Une contestation de paiement / de commande réglée place la commande en litige.
        if (!empty($v['order_id']) && in_array($v['category'], ['payment', 'order', 'ride'], true)) {
            $o = Database::first('SELECT * FROM orders WHERE id = :id', ['id' => $v['order_id']]);
            if ($o && (int) $o['customer_id'] === (int) $u['id'] && \App\Services\OrderStateMachine::can($o['flow'], $o['status'], 'disputed', 'customer')) {
                \App\Services\OrderStateMachine::transition($o, 'disputed', 'customer', (int) $u['id'], 'Réclamation ' . $v['subject']);
            }
        }
        NotificationService::staff('complaints.manage', 'complaint', 'Nouvelle réclamation', $v['subject'], ['complaint_id' => $id]);
        return $this->json(Database::first('SELECT id, reference, status FROM complaints WHERE id = :id', ['id' => $id]), 201);
    }

    public function complaint(Request $r): Response
    {
        $c = Database::first('SELECT * FROM complaints WHERE id = :id AND user_id = :u', ['id' => $r->intParam('id'), 'u' => $this->user()['id']]);
        if (!$c) {
            throw HttpException::notFound();
        }
        $c['messages'] = Database::select('SELECT m.body, m.created_at, (m.author_id = :u) mine FROM complaint_messages m WHERE m.complaint_id = :c AND m.is_internal = 0 ORDER BY m.id',
            ['c' => $c['id'], 'u' => $this->user()['id']]);
        return $this->json($c);
    }

    public function complaintMessage(Request $r): Response
    {
        $u = $this->user();
        $c = Database::first('SELECT * FROM complaints WHERE id = :id AND user_id = :u', ['id' => $r->intParam('id'), 'u' => $u['id']]);
        if (!$c) {
            throw HttpException::notFound();
        }
        $v = $this->validate($r, ['body' => 'required|string|min:1|max:2000']);
        RateLimiter::enforce('complaint_msg:' . $u['id'], 20, 3600);
        Database::insert('complaint_messages', ['complaint_id' => $c['id'], 'author_id' => $u['id'], 'body' => $v['body']]);
        if ($c['status'] === 'resolved') {
            Database::update('complaints', ['status' => 'in_progress'], 'id = :id', ['id' => $c['id']]);
        }
        return $this->ok('Message envoyé.');
    }

    /** Bouton d'urgence : alerte l'équipe MARWA. N'appelle PAS les secours (aucune intégration réelle). */
    public function emergency(Request $r): Response
    {
        $u = $this->user();
        $v = $this->validate($r, ['order_id' => 'nullable|int', 'lat' => 'nullable|lat', 'lng' => 'nullable|lng', 'message' => 'nullable|string|max:255']);
        RateLimiter::enforce('sos:' . $u['id'], 5, 600);
        if (!empty($v['order_id'])) {
            OrderPolicy::load((int) $v['order_id'], $u, ['customer', 'provider', 'merchant']);
        }
        $id = Database::insert('emergency_alerts', ['user_id' => $u['id'], 'order_id' => $v['order_id'] ?? null, 'lat' => $v['lat'] ?? null, 'lng' => $v['lng'] ?? null, 'message' => $v['message'] ?? null]);
        NotificationService::staff('complaints.manage', 'emergency', 'ALERTE DE SÉCURITÉ', $u['full_name'] . ' a déclenché le bouton d\'urgence.', ['alert_id' => $id, 'order_id' => $v['order_id'] ?? null]);
        return $this->json(['alert_id' => $id, 'message' => Settings::get('emergency.message'), 'numbers' => Settings::get('emergency.numbers', [])], 201);
    }
}
