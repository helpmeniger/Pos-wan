<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Geo;
use App\Core\HttpException;
use App\Core\Str;

/**
 * Contrôle d'accès aux commandes et minimisation des données selon le rôle de la personne
 * qui consulte : chacun ne voit que ce dont il a besoin.
 */
final class OrderPolicy
{
    /** Rôle de l'utilisateur vis-à-vis de la commande, ou null s'il n'a aucun droit. */
    public static function viewerRole(array $order, array $user): ?string
    {
        $uid = (int) $user['id'];
        if ((int) $order['customer_id'] === $uid) {
            return 'customer';
        }
        if ($order['provider_id'] !== null && (int) $order['provider_id'] === $uid) {
            return 'provider';
        }
        if ($order['merchant_id'] && (int) Database::value('SELECT owner_user_id FROM merchants WHERE id = :id', ['id' => $order['merchant_id']]) === $uid) {
            return 'merchant';
        }
        if ($order['business_id'] && BusinessService::managedBy($uid) && (int) BusinessService::managedBy($uid)['id'] === (int) $order['business_id']) {
            return 'business';
        }
        if (Auth::can('orders.view') && (int) Auth::id() === $uid) {
            return 'admin';
        }
        if (Database::value("SELECT 1 FROM dispatch_offers WHERE order_id = :o AND provider_id = :p AND status IN ('pending','countered')", ['o' => $order['id'], 'p' => $uid])) {
            return 'candidate';
        }
        return null;
    }

    public static function load(int $orderId, array $user, array $allowedRoles = ['customer', 'provider', 'merchant', 'business', 'admin', 'candidate']): array
    {
        $order = Database::first('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
        if (!$order) {
            throw HttpException::notFound('Commande introuvable.');
        }
        $role = self::viewerRole($order, $user);
        if ($role === null || !in_array($role, $allowedRoles, true)) {
            throw HttpException::forbidden('Vous n\'avez pas accès à cette commande.');
        }
        $order['_viewer'] = $role;
        return $order;
    }

    /** Sérialisation adaptée au rôle (données personnelles masquées si non nécessaires). */
    public static function present(array $order, string $viewer, bool $detailed = true): array
    {
        $out = [
            'id' => (int) $order['id'],
            'reference' => $order['reference'],
            'service_type' => $order['service_type'],
            'flow' => $order['flow'],
            'status' => $order['status'],
            'status_label' => OrderStateMachine::label($order['status']),
            'dispatch_status' => $order['dispatch_status'],
            'provider_stage' => $order['provider_stage'],
            'vehicle_category' => $order['vehicle_category'],
            'pricing_mode' => $order['pricing_mode'],
            'estimated_price' => (int) $order['estimated_price'],
            'offered_price' => $order['offered_price'] !== null ? (int) $order['offered_price'] : null,
            'final_price' => $order['final_price'] !== null ? (int) $order['final_price'] : null,
            'items_subtotal' => (int) $order['items_subtotal'],
            'delivery_fee' => (int) $order['delivery_fee'],
            'booking_fee' => (int) $order['booking_fee'],
            'service_fee' => (int) $order['service_fee'],
            'discount' => (int) $order['discount'],
            'total' => (int) $order['total'],
            'payment_method' => $order['payment_method'],
            'payment_status' => $order['payment_status'],
            'distance_m' => $order['distance_m'] !== null ? (int) $order['distance_m'] : null,
            'duration_s' => $order['duration_s'] !== null ? (int) $order['duration_s'] : null,
            'route_source' => $order['route_source'],
            'passengers' => (int) $order['passengers'],
            'scheduled_at' => $order['scheduled_at'],
            'expires_at' => $order['expires_at'],
            'created_at' => $order['created_at'],
            'completed_at' => $order['completed_at'],
            'cancel_reason' => $order['cancel_reason'],
            'rated' => $order['rated_at'] !== null,
            'viewer' => $viewer,
            'pickup' => ['text' => $order['pickup_text'], 'lat' => (float) $order['pickup_lat'], 'lng' => (float) $order['pickup_lng'], 'notes' => $order['pickup_notes']],
            'dropoff' => ['text' => $order['dropoff_text'], 'lat' => (float) $order['dropoff_lat'], 'lng' => (float) $order['dropoff_lng'], 'notes' => $order['dropoff_notes']],
            'notes' => $order['notes'],
        ];
        if ($viewer === 'candidate') {
            // Prestataire sollicité mais non affecté : position approximative, aucune donnée personnelle.
            $out['pickup'] = ['text' => self::area($order['pickup_text']), 'lat' => Geo::blur((float) $order['pickup_lat']), 'lng' => Geo::blur((float) $order['pickup_lng']), 'notes' => null];
            $out['dropoff'] = ['text' => self::area($order['dropoff_text']), 'lat' => Geo::blur((float) $order['dropoff_lat']), 'lng' => Geo::blur((float) $order['dropoff_lng']), 'notes' => null];
            $out['notes'] = null;
            $out['total'] = null;
        }
        if (in_array($viewer, ['provider', 'candidate'], true) && $order['flow'] !== 'merchant') {
            $price = (int) ($order['final_price'] ?? $order['offered_price'] ?? $order['estimated_price']);
            $out['earnings_preview'] = CommissionService::preview($order['service_type'], $order['flow'] === 'ride' ? 'driver' : 'courier', $price, Auth::id(), $order['city_id'] ? (int) $order['city_id'] : null);
        }
        if (in_array($viewer, ['provider', 'candidate'], true) && $order['flow'] === 'merchant') {
            $out['earnings_preview'] = CommissionService::preview($order['service_type'], 'courier', (int) $order['delivery_fee'], Auth::id());
            $out['total'] = $order['payment_method'] === 'cash' ? (int) $order['total'] : null; // montant à encaisser
        }
        if ($viewer === 'merchant') {
            unset($out['earnings_preview']);
        }
        if (!$detailed) {
            return $out;
        }

        $customer = Database::first('SELECT full_name FROM users WHERE id = :id', ['id' => $order['customer_id']]);
        if (in_array($viewer, ['provider', 'merchant', 'admin', 'business'], true)) {
            $out['customer'] = ['name' => $viewer === 'admin' || $viewer === 'business' ? $customer['full_name'] : Str::firstName((string) $customer['full_name'])];
        }
        if ($order['provider_id'] && in_array($viewer, ['customer', 'merchant', 'admin', 'business', 'provider'], true)) {
            $p = Database::first(
                'SELECT u.full_name, u.photo_file_id, p.rating_avg, p.rating_count, p.provider_type, v.category, v.make, v.model, v.color, v.plate
                   FROM users u JOIN provider_profiles p ON p.user_id = u.id LEFT JOIN vehicles v ON v.provider_id = u.id AND v.is_active = 1
                  WHERE u.id = :id ORDER BY v.id LIMIT 1',
                ['id' => $order['provider_id']]
            );
            $out['provider'] = [
                'name' => $viewer === 'admin' ? $p['full_name'] : Str::firstName((string) $p['full_name']),
                'photo_url' => $p['photo_file_id'] ? url('fichiers/' . $p['photo_file_id']) : null,
                'rating' => (float) $p['rating_avg'],
                'rating_count' => (int) $p['rating_count'],
                'type' => $p['provider_type'],
                'vehicle' => ['category' => $p['category'], 'label' => trim($p['make'] . ' ' . $p['model']), 'color' => $p['color'], 'plate' => $p['plate'],
                    'icon' => TrackingService::iconFor($p['category'], $p['provider_type'])],
            ];
        }
        if ($order['merchant_id']) {
            $m = Database::first('SELECT id, name, address_text, lat, lng, delivery_mode, logo_file_id FROM merchants WHERE id = :id', ['id' => $order['merchant_id']]);
            $out['merchant'] = ['id' => (int) $m['id'], 'name' => $m['name'], 'address' => $m['address_text'], 'delivery_mode' => $m['delivery_mode'],
                'logo_url' => $m['logo_file_id'] ? url('fichiers/' . $m['logo_file_id']) : null];
            $out['items'] = array_map(static fn ($i) => ['name' => $i['name'], 'quantity' => (int) $i['quantity'], 'unit_price' => (int) $i['unit_price'],
                'line_total' => (int) $i['line_total'], 'options' => $i['options'] ? json_decode($i['options'], true) : []],
                Database::select('SELECT * FROM order_items WHERE order_id = :o', ['o' => $order['id']]));
            $out['prep_time_min'] = $order['prep_time_min'] !== null ? (int) $order['prep_time_min'] : null;
        }
        $d = Database::first('SELECT * FROM deliveries WHERE order_id = :o', ['o' => $order['id']]);
        if ($d && $viewer !== 'candidate') {
            $out['delivery'] = [
                'recipient_name' => $d['recipient_name'],
                'package_type' => $d['package_type'],
                'description' => $d['description'],
                'weight_kg' => $d['weight_kg'] !== null ? (float) $d['weight_kg'] : null,
                'dimensions' => $d['dimensions'],
                'instructions' => $d['instructions'],
                'proof_type' => $d['proof_type'],
                'failed_reason' => $d['failed_reason'],
                'delivered_with_proof' => $d['otp_verified_at'] !== null || $d['proof_file_id'] !== null || $d['proof_signature'] !== null,
            ];
            // Numéros visibles uniquement par le client et le livreur affecté (nécessaires à la remise)
            if (in_array($viewer, ['customer', 'provider', 'admin'], true)) {
                $out['delivery']['recipient_phone'] = $d['recipient_phone'];
                $out['delivery']['sender_name'] = $d['sender_name'];
                $out['delivery']['sender_phone'] = $viewer === 'provider' && $order['flow'] === 'merchant' ? null : $d['sender_phone'];
            }
        } elseif ($d && $viewer === 'candidate') {
            $out['delivery'] = ['package_type' => $d['package_type'], 'weight_kg' => $d['weight_kg'] !== null ? (float) $d['weight_kg'] : null];
        }
        if ($viewer === 'customer' && $order['delivery_code'] && !in_array($order['status'], ['delivered', 'paid'], true)) {
            $out['delivery_code'] = $order['delivery_code'];
        }
        if (in_array($viewer, ['customer', 'admin', 'provider', 'merchant', 'business'], true)) {
            $out['history'] = Database::select('SELECT to_status, actor_role, note, created_at FROM order_status_history WHERE order_id = :o ORDER BY id', ['o' => $order['id']]);
            foreach ($out['history'] as &$h) {
                $h['label'] = OrderStateMachine::label($h['to_status']);
            }
            unset($h);
        }
        if ($viewer === 'customer' && $order['status'] === 'negotiating') {
            $out['negotiations'] = array_map(static fn ($n) => [
                'id' => (int) $n['id'], 'amount' => (int) $n['amount'], 'status' => $n['status'], 'expires_at' => $n['expires_at'], 'proposed_by' => $n['proposed_by'],
                'provider' => $n['provider_id'] ? ['name' => Str::firstName((string) $n['full_name']), 'rating' => (float) $n['rating_avg'], 'rating_count' => (int) $n['rating_count'],
                    'vehicle' => trim($n['make'] . ' ' . $n['model'] . ' ' . $n['color']), 'distance_m' => $n['distance_m'] !== null ? (int) $n['distance_m'] : null] : null,
            ], NegotiationService::forOrder((int) $order['id']));
            $out['offer_bounds'] = NegotiationService::bounds($order);
        }
        if ($viewer === 'candidate' || $viewer === 'provider') {
            $offer = Database::first('SELECT status, expires_at, distance_m FROM dispatch_offers WHERE order_id = :o AND provider_id = :p', ['o' => $order['id'], 'p' => Auth::id()]);
            $out['offer'] = $offer;
            if ($order['pricing_mode'] === 'negotiated') {
                $out['my_counters'] = Database::select("SELECT id, amount, status, expires_at FROM fare_negotiations WHERE order_id = :o AND provider_id = :p ORDER BY id DESC", ['o' => $order['id'], 'p' => Auth::id()]);
                $out['offer_bounds'] = NegotiationService::bounds($order);
            }
        }
        if (in_array($viewer, ['admin'], true)) {
            $out['commissions'] = Database::select('SELECT party, model, base_amount, percent_bp, fixed_amount, commission, vat_amount, partner_net FROM commissions WHERE order_id = :o', ['o' => $order['id']]);
            $out['payments'] = Database::select('SELECT id, method, gateway, amount, declared_amount, verified_amount, fee_amount, status, collector_type, external_ref, created_at FROM payments WHERE order_id = :o', ['o' => $order['id']]);
        }
        if (in_array($viewer, ['customer', 'business', 'provider', 'merchant'], true)) {
            $c = Database::first('SELECT party, commission, partner_net FROM commissions WHERE order_id = :o AND party = :p',
                ['o' => $order['id'], 'p' => $viewer === 'merchant' ? 'merchant' : 'provider']);
            if ($c && in_array($viewer, ['provider', 'merchant'], true)) {
                $out['settlement'] = ['commission' => (int) $c['commission'], 'net' => (int) $c['partner_net']];
            }
            $inv = Database::value("SELECT number FROM invoices WHERE kind = 'receipt' AND order_id = :o", ['o' => $order['id']]);
            if ($inv && in_array($viewer, ['customer', 'business'], true)) {
                $out['receipt_number'] = $inv;
            }
        }
        $out['actions'] = self::actionsFor($order, $viewer);
        return $out;
    }

    private static function area(string $text): string
    {
        // Ne conserve que la partie la plus générale d'une adresse (ex. quartier)
        $parts = array_map('trim', explode(',', $text));
        return count($parts) > 1 ? implode(', ', array_slice($parts, -2)) : 'Secteur ' . mb_substr($parts[0], 0, 30);
    }

    public static function actionsFor(array $order, string $viewer): array
    {
        $role = ['customer' => 'customer', 'provider' => 'provider', 'merchant' => 'merchant', 'admin' => 'admin'][$viewer] ?? null;
        $next = $role ? OrderStateMachine::next($order['flow'], $order['status'], $role) : [];
        if ($viewer === 'merchant') {
            $own = (string) Database::value('SELECT delivery_mode FROM merchants WHERE id = :id', ['id' => $order['merchant_id']]) === 'own';
            $next = array_values(array_filter($next, static fn ($s) => $own || !in_array($s, ['picked_up', 'in_delivery', 'delivered', 'delivery_failed', 'returning', 'returned'], true)));
        }
        if ($viewer === 'provider' && $order['flow'] === 'merchant' && in_array($order['status'], ['accepted', 'preparing', 'ready'], true)) {
            $stage = $order['provider_stage'];
            if ($stage === 'assigned') {
                $next[] = 'en_route_pickup';
            } elseif ($stage === 'en_route_pickup') {
                $next[] = 'arrived_pickup';
            }
            $next[] = 'release';
        }
        if ($viewer === 'provider' && in_array($order['status'], ['assigned', 'en_route_pickup'], true)) {
            $next[] = 'release';
        }
        if ($viewer === 'provider' && $order['payment_method'] === 'cash' && in_array($order['status'], ['completed', 'delivered', 'returned'], true) && $order['payment_status'] === 'unpaid') {
            $next[] = 'declare_cash';
        }
        if ($viewer === 'customer' && in_array($order['payment_method'], PaymentService::ELECTRONIC, true) && $order['payment_status'] !== 'paid'
            && (($order['flow'] === 'merchant' && $order['status'] === 'created') || in_array($order['status'], ['completed', 'delivered', 'returned'], true))) {
            $next[] = 'pay';
        }
        if ($viewer === 'customer' && in_array($order['status'], ['completed', 'delivered', 'paid', 'returned'], true) && $order['rated_at'] === null) {
            $next[] = 'rate';
        }
        return array_values(array_unique($next));
    }
}
