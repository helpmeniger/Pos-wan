<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\Database;
use App\Core\Geo;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\DispatchService;
use App\Services\NotificationService;
use App\Services\OrderService;
use App\Services\OrderStateMachine;
use App\Services\TrackingService;

/**
 * Suivi en direct de toutes les commandes et contact des prestataires / clients
 * (notification dans l'application et sur le téléphone, SMS, appel, WhatsApp).
 */
final class LiveController extends Controller
{
    /** Personne considérée « dans l'application » si elle a été active il y a moins de 2 minutes. */
    private const IN_APP_SECONDS = 120;

    public function live(Request $r): Response
    {
        $alertMin = Settings::int('live.alert_searching_minutes', 5);
        $staleAfter = Settings::int('tracking.stale_after_seconds', 45);
        $open = "'" . implode("','", OrderService::OPEN_STATUSES) . "'";
        $rows = Database::select(
            "SELECT o.id, o.reference, o.flow, o.service_type, o.status, o.dispatch_status, o.provider_stage, o.total, o.payment_method, o.created_at, o.assigned_at,
                    o.pickup_text, o.pickup_lat, o.pickup_lng, o.dropoff_text, o.dropoff_lat, o.dropoff_lng, o.expires_at,
                    c.id c_id, c.full_name c_name, c.phone c_phone, c.last_seen_at c_seen,
                    p.id p_id, p.full_name p_name, p.phone p_phone, p.last_seen_at p_seen,
                    l.lat p_lat, l.lng p_lng, l.heading p_heading, l.updated_at p_loc_at, v.category p_cat, pp.provider_type p_type,
                    m.name merchant_name,
                    (SELECT COUNT(*) FROM dispatch_offers d WHERE d.order_id = o.id AND d.status IN ('pending','countered') AND d.expires_at > NOW()) offers_open,
                    (SELECT COUNT(*) FROM dispatch_offers d WHERE d.order_id = o.id) offers_total
               FROM orders o
               JOIN users c ON c.id = o.customer_id
               LEFT JOIN users p ON p.id = o.provider_id
               LEFT JOIN provider_profiles pp ON pp.user_id = o.provider_id
               LEFT JOIN provider_locations l ON l.provider_id = o.provider_id
               LEFT JOIN vehicles v ON v.provider_id = o.provider_id AND v.is_active = 1
               LEFT JOIN merchants m ON m.id = o.merchant_id
              WHERE o.status IN ($open)
              GROUP BY o.id
              ORDER BY o.created_at DESC LIMIT 300"
        );
        $now = time();
        $orders = [];
        $alerts = 0;
        foreach ($rows as $o) {
            $age = $now - strtotime((string) $o['created_at']);
            $waiting = $o['p_id'] === null;
            $gpsAge = $o['p_loc_at'] ? $now - strtotime((string) $o['p_loc_at']) : null;
            $flags = [];
            if ($waiting && in_array($o['status'], ['searching', 'negotiating'], true) && $age > $alertMin * 60) {
                $flags[] = 'attente';
            }
            if ($o['dispatch_status'] === 'no_provider') {
                $flags[] = 'sans_prestataire';
            }
            if (!$waiting && in_array($o['status'], OrderStateMachine::TRACKABLE, true) && ($gpsAge === null || $gpsAge > $staleAfter)) {
                $flags[] = 'gps';
            }
            if (!$waiting && $o['p_seen'] && $now - strtotime((string) $o['p_seen']) > self::IN_APP_SECONDS) {
                $flags[] = 'hors_app';
            }
            if ($o['status'] === 'pending' && $age > Settings::int('orders.merchant_accept_timeout_min', 10) * 60) {
                $flags[] = 'commerce';
            }
            $alerts += $flags ? 1 : 0;
            $orders[] = [
                'id' => (int) $o['id'], 'reference' => $o['reference'], 'flow' => $o['flow'], 'service_type' => $o['service_type'],
                'status' => $o['status'], 'status_label' => OrderStateMachine::label($o['status']), 'dispatch_status' => $o['dispatch_status'], 'provider_stage' => $o['provider_stage'],
                'total' => (int) $o['total'], 'payment_method' => $o['payment_method'], 'created_at' => $o['created_at'], 'age_seconds' => $age,
                'pickup' => ['text' => $o['pickup_text'], 'lat' => (float) $o['pickup_lat'], 'lng' => (float) $o['pickup_lng']],
                'dropoff' => ['text' => $o['dropoff_text'], 'lat' => (float) $o['dropoff_lat'], 'lng' => (float) $o['dropoff_lng']],
                'merchant' => $o['merchant_name'],
                'customer' => ['id' => (int) $o['c_id'], 'name' => $o['c_name'], 'phone' => $o['c_phone'], 'in_app' => self::inApp($o['c_seen'])],
                'provider' => $waiting ? null : [
                    'id' => (int) $o['p_id'], 'name' => $o['p_name'], 'phone' => $o['p_phone'], 'in_app' => self::inApp($o['p_seen']),
                    'last_seen_at' => $o['p_seen'], 'gps_age_seconds' => $gpsAge,
                    'position' => $o['p_lat'] !== null ? ['lat' => (float) $o['p_lat'], 'lng' => (float) $o['p_lng'], 'heading' => $o['p_heading'] !== null ? (int) $o['p_heading'] : null] : null,
                    'icon' => TrackingService::iconFor($o['p_cat'], (string) ($o['p_type'] ?? 'driver')),
                ],
                'offers_open' => (int) $o['offers_open'], 'offers_total' => (int) $o['offers_total'],
                'flags' => $flags,
            ];
        }
        $providers = Database::select(
            "SELECT p.user_id id, u.full_name name, u.phone, u.last_seen_at, p.provider_type, p.current_order_id, l.lat, l.lng, l.heading, l.updated_at, MIN(v.category) category,
                    (SELECT COUNT(*) FROM push_subscriptions s WHERE s.user_id = p.user_id) push_devices
               FROM provider_profiles p JOIN users u ON u.id = p.user_id JOIN provider_locations l ON l.provider_id = p.user_id
               LEFT JOIN vehicles v ON v.provider_id = p.user_id AND v.is_active = 1
              WHERE p.is_online = 1 AND l.updated_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
              GROUP BY p.user_id, u.full_name, u.phone, u.last_seen_at, p.provider_type, p.current_order_id, l.lat, l.lng, l.heading, l.updated_at"
        );
        $providers = array_map(fn ($p) => [
            'id' => (int) $p['id'], 'name' => $p['name'], 'phone' => $p['phone'], 'type' => $p['provider_type'], 'busy' => $p['current_order_id'] !== null,
            'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'heading' => $p['heading'] !== null ? (int) $p['heading'] : null,
            'gps_age_seconds' => $now - strtotime((string) $p['updated_at']), 'in_app' => self::inApp($p['last_seen_at']),
            'push_devices' => (int) $p['push_devices'], 'icon' => TrackingService::iconFor($p['category'], (string) $p['provider_type']),
        ], $providers);
        return $this->json([
            'orders' => $orders,
            'providers' => $providers,
            'summary' => [
                'open' => count($orders),
                'waiting' => count(array_filter($orders, fn ($o) => $o['provider'] === null && in_array($o['status'], ['searching', 'negotiating', 'accepted', 'preparing', 'ready'], true))),
                'in_progress' => count(array_filter($orders, fn ($o) => $o['provider'] !== null)),
                'alerts' => $alerts,
                'providers_online' => count($providers),
                'providers_free' => count(array_filter($providers, fn ($p) => !$p['busy'])),
            ],
            'alert_minutes' => $alertMin,
            'server_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Prestataires à contacter pour une commande en attente (les plus proches d'abord, en ligne ou non). */
    public function candidates(Request $r): Response
    {
        $o = DispatchService::fresh($r->intParam('id'));
        $type = DispatchService::providerTypeFor($o);
        $rows = Database::select(
            "SELECT p.user_id id, u.full_name name, u.phone, u.last_seen_at, p.is_online, p.current_order_id, p.rating_avg, l.lat, l.lng, l.updated_at loc_at,
                    MIN(v.category) category, (SELECT COUNT(*) FROM push_subscriptions s WHERE s.user_id = p.user_id) push_devices,
                    (SELECT d.status FROM dispatch_offers d WHERE d.order_id = :o AND d.provider_id = p.user_id LIMIT 1) offer_status
               FROM provider_profiles p JOIN users u ON u.id = p.user_id AND u.status = 'active'
               LEFT JOIN provider_locations l ON l.provider_id = p.user_id
               LEFT JOIN vehicles v ON v.provider_id = p.user_id AND v.is_active = 1
              WHERE p.provider_type = :t AND p.verification_status = 'verified'
              GROUP BY p.user_id, u.full_name, u.phone, u.last_seen_at, p.is_online, p.current_order_id, p.rating_avg, l.lat, l.lng, l.updated_at
              LIMIT 500",
            ['o' => $o['id'], 't' => $type]
        );
        $out = [];
        foreach ($rows as $p) {
            $dist = $p['lat'] !== null ? (int) round(Geo::distance((float) $o['pickup_lat'], (float) $o['pickup_lng'], (float) $p['lat'], (float) $p['lng'])) : null;
            $out[] = [
                'id' => (int) $p['id'], 'name' => $p['name'], 'phone' => $p['phone'], 'online' => (bool) $p['is_online'], 'busy' => $p['current_order_id'] !== null,
                'in_app' => self::inApp($p['last_seen_at']), 'last_seen_at' => $p['last_seen_at'], 'push_devices' => (int) $p['push_devices'],
                'distance_m' => $dist, 'gps_age_seconds' => $p['loc_at'] ? time() - strtotime((string) $p['loc_at']) : null,
                'category' => $p['category'], 'rating' => (float) $p['rating_avg'], 'offer_status' => $p['offer_status'],
            ];
        }
        usort($out, fn ($a, $b) => [$a['busy'], $a['distance_m'] === null, $a['distance_m'] ?? PHP_INT_MAX] <=> [$b['busy'], $b['distance_m'] === null, $b['distance_m'] ?? PHP_INT_MAX]);
        return $this->json(['order' => ['id' => (int) $o['id'], 'reference' => $o['reference'], 'status' => $o['status'], 'pickup_text' => $o['pickup_text'], 'provider_id' => $o['provider_id']],
            'candidates' => array_slice($out, 0, 30)]);
    }

    /**
     * Contacter un prestataire ou un client : notification dans l'application + sur le téléphone
     * (push), et SMS si demandé. Option : proposer la commande au prestataire.
     */
    public function contact(Request $r): Response
    {
        $v = $this->validate($r, ['user_id' => 'required|int', 'order_id' => 'nullable|int', 'message' => 'required|string|min:2|max:300',
            'sms' => 'nullable|bool', 'offer' => 'nullable|bool']);
        $u = Database::first("SELECT u.id, u.full_name, u.phone, r.code role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id AND u.status IN ('active','pending')", ['id' => $v['user_id']]);
        if (!$u || in_array($u['role'], ['admin', 'support', 'finance'], true)) {
            throw HttpException::notFound('Destinataire introuvable.');
        }
        $order = !empty($v['order_id']) ? DispatchService::fresh((int) $v['order_id']) : null;
        $result = ['notified' => true, 'sms' => false, 'offer' => null];
        if (!empty($v['offer'])) {
            if (!$order || !in_array($u['role'], ['driver', 'courier'], true)) {
                throw \App\Core\ValidationException::field('offer', 'Choisissez une commande et un prestataire.');
            }
            $result['offer'] = DispatchService::offerToProvider((int) $order['id'], (int) $u['id']);
        }
        $channels = !empty($v['sms']) && $u['phone'] ? ['sms'] : [];
        NotificationService::notify((int) $u['id'], 'staff_message', 'Message de l\'équipe MARWA', $v['message'],
            $order ? ['order_id' => (int) $order['id']] : [], $channels);
        $result['sms'] = (bool) $channels;
        Database::insert('staff_contacts', ['staff_id' => $this->user()['id'], 'user_id' => $u['id'], 'order_id' => $order['id'] ?? null,
            'channels' => implode(',', array_merge(['app'], $channels, !empty($v['offer']) ? ['offer'] : [])), 'message' => $v['message']]);
        Audit::log('staff.contact', 'user', (int) $u['id'], null, ['order_id' => $order['id'] ?? null, 'sms' => $result['sms'], 'offer' => !empty($v['offer'])]);
        return $this->json($result);
    }

    private static function inApp(?string $seen): bool
    {
        return $seen !== null && time() - strtotime($seen) <= self::IN_APP_SECONDS;
    }
}
