<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Geo;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Settings;
use App\Core\Str;
use App\Core\Validator;
use DateTimeImmutable;

/** Création et cycle de vie des commandes (courses, livraisons, commandes commerce). */
final class OrderService
{
    public const OPEN_STATUSES = ['created', 'searching', 'negotiating', 'pending', 'accepted', 'preparing', 'ready', 'assigned',
        'en_route_pickup', 'arrived_pickup', 'in_progress', 'picked_up', 'in_delivery', 'delivery_failed', 'returning'];

    // ------------------------------------------------------------------
    // Création
    // ------------------------------------------------------------------

    public static function create(array $user, array $input): array
    {
        $type = (string) ($input['service_type'] ?? '');
        $flow = PricingService::flowFor($type);
        RateLimiter::enforce('order_create:' . $user['id'], 12, 3600, 'Trop de commandes créées en peu de temps. Réessayez plus tard.');
        $open = (int) Database::value('SELECT COUNT(*) FROM orders WHERE customer_id = :u AND status IN (' . implode(',', array_map(fn ($s) => "'$s'", self::OPEN_STATUSES)) . ')', ['u' => $user['id']]);
        if ($open >= Settings::int('orders.max_open_per_customer', 2)) {
            throw new HttpException(429, 'Vous avez déjà des commandes en cours. Terminez-les ou annulez-les avant d\'en créer une nouvelle.', 'too_many_open_orders');
        }
        return match ($flow) {
            'ride', 'courier' => self::createTransport($user, $input, $flow),
            'merchant' => self::createMerchantOrder($user, $input),
        };
    }

    private static function paymentFields(array $user, array $v, int $amount): array
    {
        $method = $v['payment_method'] ?? 'cash';
        $allowed = (array) Settings::get('payments.methods', ['cash']);
        if (!in_array($method, $allowed, true)) {
            throw new HttpException(422, 'Moyen de paiement non disponible.', 'payment_method_unavailable');
        }
        if ($method === 'demo_mobile_money' && !PaymentService::demoEnabled()) {
            throw new HttpException(422, 'Le paiement électronique de démonstration est désactivé.', 'payment_method_unavailable');
        }
        $businessId = $v['business_id'] ?? null;
        if ($method === 'business_account') {
            if (!$businessId) {
                throw new HttpException(422, 'Choisissez le compte entreprise à débiter.', 'business_required');
            }
            BusinessService::assertCanCharge((int) $user['id'], (int) $businessId, $amount);
        } elseif ($businessId && !BusinessService::membership((int) $user['id'], (int) $businessId)) {
            throw HttpException::forbidden('Vous n\'êtes pas membre de cette entreprise.');
        }
        return ['payment_method' => $method, 'business_id' => $businessId];
    }

    private static function createTransport(array $user, array $input, string $flow): array
    {
        $isRide = $flow === 'ride';
        $rules = [
            'service_type' => 'required|in:' . implode(',', $isRide ? PricingService::RIDE_SERVICES : PricingService::COURIER_SERVICES),
            'vehicle_category' => 'nullable|in:' . implode(',', $isRide ? PricingService::RIDE_VEHICLES : PricingService::COURIER_VEHICLES),
            'pickup_text' => 'required|string|max:255', 'pickup_lat' => 'required|lat', 'pickup_lng' => 'required|lng', 'pickup_notes' => 'nullable|string|max:255',
            'dropoff_text' => 'required|string|max:255', 'dropoff_lat' => 'required|lat', 'dropoff_lng' => 'required|lng', 'dropoff_notes' => 'nullable|string|max:255',
            'pricing_mode' => 'required|in:auto,negotiated',
            'offered_price' => 'nullable|int|min:1|max:10000000',
            'payment_method' => 'nullable|in:cash,demo_mobile_money,business_account',
            'business_id' => 'nullable|int',
            'promo_code' => 'nullable|string|max:30',
            'scheduled_at' => 'nullable|datetime',
            'notes' => 'nullable|string|max:500',
            'passengers' => 'nullable|int|between:1,15',
            'share_tracking' => 'nullable|bool',
        ];
        if (!$isRide) {
            $rules += [
                'sender_name' => 'nullable|string|max:120', 'sender_phone' => 'nullable|phone',
                'recipient_name' => 'required|string|max:120', 'recipient_phone' => 'required|phone',
                'package_type' => 'required|in:petit_colis,colis_moyen,gros_colis,documents,courses,repas,autre',
                'description' => 'required|string|max:500', 'weight_kg' => 'nullable|numeric|between:0,500',
                'dimensions' => 'nullable|string|max:60', 'declared_value' => 'nullable|int|min:0|max:100000000',
                'instructions' => 'nullable|string|max:500', 'proof_type' => 'nullable|in:otp,photo,signature',
                'cash_payer' => 'nullable|in:customer,recipient', 'accept_rules' => 'required|bool',
            ];
        }
        $v = Validator::make($input, $rules);
        if (!$isRide && !$v['accept_rules']) {
            throw \App\Core\ValidationException::field('accept_rules', 'Vous devez confirmer que l\'envoi ne contient aucun objet interdit.');
        }
        if (Geo::distance($v['pickup_lat'], $v['pickup_lng'], $v['dropoff_lat'], $v['dropoff_lng']) < 100) {
            throw \App\Core\ValidationException::field('dropoff_text', 'Le départ et la destination sont trop proches.');
        }
        $category = $v['vehicle_category'] ?? ($isRide ? 'taxi' : 'moto');
        $est = PricingService::estimate($v['service_type'], $category, $v['pickup_lat'], $v['pickup_lng'], $v['dropoff_lat'], $v['dropoff_lng']);

        $mode = $v['pricing_mode'];
        $fare = $est['fare'];
        if ($mode === 'negotiated') {
            if (empty($v['offered_price'])) {
                throw \App\Core\ValidationException::field('offered_price', 'Indiquez le prix que vous proposez.');
            }
            if ($v['offered_price'] < $est['min_offer'] || $v['offered_price'] > $est['max_offer']) {
                throw \App\Core\ValidationException::field('offered_price', sprintf('Proposez un prix entre %s et %s.', fcfa($est['min_offer']), fcfa($est['max_offer'])));
            }
            $fare = (int) $v['offered_price'];
        }
        $discount = 0;
        $promo = null;
        if (!empty($v['promo_code'])) {
            $ev = PromotionService::evaluate($v['promo_code'], (int) $user['id'], $v['service_type'], $fare);
            $promo = $ev['promotion'];
            $discount = $ev['discount'];
        }
        $total = max(0, $fare + $est['booking_fee'] + $est['service_fee'] - $discount);
        $pay = self::paymentFields($user, $v, $total);

        $now = time();
        $dispatchAfter = null;
        $expires = $now + max(600, Settings::int('dispatch.offer_ttl_seconds', 60) * Settings::int('dispatch.max_rounds', 4) + 120);
        if (!empty($v['scheduled_at'])) {
            $at = strtotime($v['scheduled_at']);
            if ($at < $now + 600 || $at > $now + 30 * 86400) {
                throw \App\Core\ValidationException::field('scheduled_at', 'La date programmée doit être comprise entre 10 minutes et 30 jours.');
            }
            $dispatchAfter = date('Y-m-d H:i:s', $at - 900);
            $expires = $at + 900;
        }

        $order = Database::transaction(static function () use ($user, $v, $est, $mode, $fare, $discount, $promo, $total, $pay, $category, $flow, $dispatchAfter, $expires, $isRide) {
            $id = Database::insert('orders', [
                'uuid' => Str::uuid(),
                'reference' => self::newReference($v['service_type']),
                'service_type' => $v['service_type'],
                'flow' => $flow,
                'status' => 'created',
                'customer_id' => (int) $user['id'],
                'business_id' => $pay['business_id'],
                'city_id' => $est['city_id'],
                'zone_id' => $est['zone_id'],
                'pickup_text' => $v['pickup_text'], 'pickup_lat' => $v['pickup_lat'], 'pickup_lng' => $v['pickup_lng'], 'pickup_notes' => $v['pickup_notes'] ?? null,
                'dropoff_text' => $v['dropoff_text'], 'dropoff_lat' => $v['dropoff_lat'], 'dropoff_lng' => $v['dropoff_lng'], 'dropoff_notes' => $v['dropoff_notes'] ?? null,
                'distance_m' => $est['distance_m'], 'duration_s' => $est['duration_s'], 'route_source' => $est['route_source'],
                'route_geometry' => json_encode($est['geometry']),
                'vehicle_category' => $category,
                'pricing_mode' => $mode,
                'pricing_rule_id' => $est['rule_id'],
                'estimated_price' => $est['fare'],
                'offered_price' => $mode === 'negotiated' ? $fare : null,
                'final_price' => $mode === 'auto' ? $est['fare'] : null,
                'booking_fee' => $est['booking_fee'],
                'service_fee' => $est['service_fee'],
                'discount' => $discount,
                'promotion_id' => $promo['id'] ?? null,
                'total' => $total,
                'payment_method' => $pay['payment_method'],
                'cash_payer' => $v['cash_payer'] ?? 'customer',
                'passengers' => $v['passengers'] ?? 1,
                'share_tracking' => ($v['share_tracking'] ?? true) ? 1 : 0,
                'delivery_code' => $isRide ? null : Str::digits(4),
                'notes' => $v['notes'] ?? null,
                'scheduled_at' => $v['scheduled_at'] ?? null,
                'dispatch_after' => $dispatchAfter,
                'expires_at' => date('Y-m-d H:i:s', $expires),
            ]);
            if ($isRide) {
                Database::insert('rides', ['order_id' => $id]);
            } else {
                Database::insert('deliveries', [
                    'order_id' => $id,
                    'sender_name' => $v['sender_name'] ?? $user['full_name'],
                    'sender_phone' => $v['sender_phone'] ?? $user['phone'],
                    'recipient_name' => $v['recipient_name'], 'recipient_phone' => $v['recipient_phone'],
                    'package_type' => $v['package_type'], 'description' => $v['description'],
                    'weight_kg' => $v['weight_kg'] ?? null, 'dimensions' => $v['dimensions'] ?? null,
                    'declared_value' => $v['declared_value'] ?? null, 'instructions' => $v['instructions'] ?? null,
                    'proof_type' => $v['proof_type'] ?? 'otp',
                ]);
            }
            Database::insert('order_status_history', ['order_id' => $id, 'from_status' => null, 'to_status' => 'created', 'actor_id' => $user['id'], 'actor_role' => 'customer', 'note' => null]);
            if ($promo) {
                PromotionService::redeem((int) $promo['id'], (int) $user['id'], $id, $discount);
            }
            if ($mode === 'negotiated') {
                Database::insert('fare_negotiations', ['order_id' => $id, 'proposed_by' => 'customer', 'amount' => $fare, 'expires_at' => date('Y-m-d H:i:s', $expires)]);
            }
            $order = DispatchService::fresh($id);
            return OrderStateMachine::transition($order, $mode === 'negotiated' ? 'negotiating' : 'searching', 'system', null,
                $mode === 'negotiated' ? 'Prix proposé par le client : ' . fcfa($fare) : 'Tarif automatique : ' . fcfa($fare));
        });
        if ($dispatchAfter === null) {
            $order = DispatchService::startSearch($order);
        }
        return $order;
    }

    private static function createMerchantOrder(array $user, array $input): array
    {
        $v = Validator::make($input, [
            'service_type' => 'required|in:food,shop',
            'merchant_id' => 'required|int',
            'items' => 'required|array|max:50',
            'dropoff_text' => 'required|string|max:255', 'dropoff_lat' => 'required|lat', 'dropoff_lng' => 'required|lng', 'dropoff_notes' => 'nullable|string|max:255',
            'recipient_name' => 'nullable|string|max:120', 'recipient_phone' => 'nullable|phone',
            'notes' => 'nullable|string|max:500',
            'payment_method' => 'nullable|in:cash,demo_mobile_money,business_account',
            'business_id' => 'nullable|int',
            'promo_code' => 'nullable|string|max:30',
        ]);
        $merchant = Database::first('SELECT * FROM merchants WHERE id = :id', ['id' => $v['merchant_id']]);
        if (!$merchant || $merchant['status'] !== 'verified') {
            throw HttpException::notFound('Commerce introuvable.');
        }
        if (!MerchantHours::isOpenNow($merchant)) {
            throw new HttpException(422, 'Ce commerce est actuellement fermé.', 'merchant_closed');
        }
        $distance = Geo::distance((float) $merchant['lat'], (float) $merchant['lng'], $v['dropoff_lat'], $v['dropoff_lng']);
        if ($distance > (float) $merchant['delivery_radius_km'] * 1000) {
            throw new HttpException(422, 'Votre adresse est hors de la zone de livraison de ce commerce.', 'out_of_delivery_area');
        }
        $est = PricingService::estimate($v['service_type'], null, (float) $merchant['lat'], (float) $merchant['lng'], $v['dropoff_lat'], $v['dropoff_lng']);

        return Database::transaction(static function () use ($user, $v, $merchant, $est) {
            [$lines, $subtotal] = self::priceItems($merchant, $v['items']);
            if ($subtotal < (int) $merchant['min_order']) {
                throw new HttpException(422, 'Montant minimum de commande : ' . fcfa((int) $merchant['min_order']) . '.', 'min_order');
            }
            $discount = 0;
            $promo = null;
            if (!empty($v['promo_code'])) {
                $ev = PromotionService::evaluate($v['promo_code'], (int) $user['id'], $v['service_type'], $subtotal, (int) $merchant['id']);
                $promo = $ev['promotion'];
                $discount = $ev['discount'];
            }
            $deliveryFee = $est['fare'];
            $total = max(0, $subtotal + $deliveryFee + $est['service_fee'] + $est['booking_fee'] - $discount);
            $pay = self::paymentFields($user, $v, $total);
            $id = Database::insert('orders', [
                'uuid' => Str::uuid(),
                'reference' => self::newReference($v['service_type']),
                'service_type' => $v['service_type'],
                'flow' => 'merchant',
                'status' => 'created',
                'customer_id' => (int) $user['id'],
                'business_id' => $pay['business_id'],
                'merchant_id' => (int) $merchant['id'],
                'city_id' => $est['city_id'],
                'zone_id' => $est['zone_id'],
                'pickup_text' => $merchant['name'] . ' — ' . $merchant['address_text'],
                'pickup_lat' => $merchant['lat'], 'pickup_lng' => $merchant['lng'],
                'dropoff_text' => $v['dropoff_text'], 'dropoff_lat' => $v['dropoff_lat'], 'dropoff_lng' => $v['dropoff_lng'], 'dropoff_notes' => $v['dropoff_notes'] ?? null,
                'distance_m' => $est['distance_m'], 'duration_s' => $est['duration_s'], 'route_source' => $est['route_source'],
                'route_geometry' => json_encode($est['geometry']),
                'pricing_mode' => 'catalog',
                'pricing_rule_id' => $est['rule_id'],
                'estimated_price' => $deliveryFee,
                'final_price' => $deliveryFee,
                'items_subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'booking_fee' => $est['booking_fee'],
                'service_fee' => $est['service_fee'],
                'discount' => $discount,
                'promotion_id' => $promo['id'] ?? null,
                'total' => $total,
                'payment_method' => $pay['payment_method'],
                'delivery_code' => Str::digits(4),
                'notes' => $v['notes'] ?? null,
                'expires_at' => date('Y-m-d H:i:s', time() + 3 * 3600),
            ]);
            foreach ($lines as $l) {
                Database::insert('order_items', $l + ['order_id' => $id]);
            }
            Database::insert('deliveries', [
                'order_id' => $id,
                'sender_name' => $merchant['name'],
                'sender_phone' => $merchant['phone'],
                'recipient_name' => $v['recipient_name'] ?? $user['full_name'],
                'recipient_phone' => $v['recipient_phone'] ?? ($user['phone'] ?? ''),
                'package_type' => 'repas',
                'description' => count($lines) . ' article(s)',
                'instructions' => $v['dropoff_notes'] ?? null,
                'proof_type' => 'otp',
            ]);
            Database::insert('order_status_history', ['order_id' => $id, 'from_status' => null, 'to_status' => 'created', 'actor_id' => $user['id'], 'actor_role' => 'customer', 'note' => null]);
            if ($promo) {
                PromotionService::redeem((int) $promo['id'], (int) $user['id'], $id, $discount);
            }
            $order = DispatchService::fresh($id);
            // Paiement électronique : la commande n'est transmise au commerce qu'après confirmation serveur du paiement.
            if (!in_array($order['payment_method'], ['demo_mobile_money', 'mobile_money', 'card'], true)) {
                $order = self::submitToMerchant($order);
            }
            return $order;
        });
    }

    public static function submitToMerchant(array $order): array
    {
        $order = OrderStateMachine::transition($order, 'pending', 'system', null, 'Commande transmise au commerce');
        NotificationService::order($order, 'new_order', 'Nouvelle commande', 'Commande ' . $order['reference'] . ' de ' . fcfa((int) $order['items_subtotal']) . ' à confirmer.', ['merchant']);
        RealtimeService::pushOrder($order, 'order_updated');
        return $order;
    }

    /** Recalcule les prix côté serveur (le client n'envoie que des identifiants et quantités). */
    public static function priceItems(array $merchant, array $items): array
    {
        $lines = [];
        $subtotal = 0;
        foreach ($items as $i => $item) {
            $pid = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 0);
            if ($pid <= 0 || $qty < 1 || $qty > 50) {
                throw \App\Core\ValidationException::field("items.$i", 'Article ou quantité invalide.');
            }
            $lock = (int) $merchant['track_stock'] ? ' FOR UPDATE' : '';
            $p = Database::first('SELECT * FROM products WHERE id = :id AND merchant_id = :m AND deleted_at IS NULL' . $lock, ['id' => $pid, 'm' => $merchant['id']]);
            if (!$p || !(int) $p['is_available']) {
                throw new HttpException(422, 'Un article n\'est plus disponible : ' . ($p['name'] ?? '#' . $pid) . '.', 'product_unavailable');
            }
            if ((int) $merchant['track_stock'] && $p['stock_qty'] !== null) {
                if ((int) $p['stock_qty'] < $qty) {
                    throw new HttpException(422, 'Stock insuffisant pour « ' . $p['name'] . ' ».', 'out_of_stock');
                }
                Database::execute('UPDATE products SET stock_qty = stock_qty - :q WHERE id = :id', ['q' => $qty, 'id' => $pid]);
            }
            $unit = (int) ($p['promo_price'] ?? $p['price']);
            $chosen = [];
            $groups = [];
            foreach ((array) ($item['option_ids'] ?? []) as $oid) {
                $o = Database::first('SELECT * FROM product_variants WHERE id = :id AND product_id = :p AND is_available = 1', ['id' => (int) $oid, 'p' => $pid]);
                if (!$o) {
                    throw new HttpException(422, 'Option invalide pour « ' . $p['name'] . ' ».', 'invalid_option');
                }
                if ($o['kind'] === 'variant') {
                    if (isset($groups[$o['group_name']])) {
                        throw new HttpException(422, 'Une seule variante « ' . $o['group_name'] . ' » est autorisée.', 'invalid_option');
                    }
                    $groups[$o['group_name']] = true;
                }
                $unit += (int) $o['price_delta'];
                $chosen[] = ['id' => (int) $o['id'], 'group' => $o['group_name'], 'name' => $o['name'], 'price_delta' => (int) $o['price_delta']];
            }
            $unit = max(0, $unit);
            $lines[] = [
                'product_id' => $pid,
                'name' => $p['name'],
                'unit_price' => $unit,
                'quantity' => $qty,
                'options' => $chosen ? json_encode($chosen, JSON_UNESCAPED_UNICODE) : null,
                'line_total' => $unit * $qty,
            ];
            $subtotal += $unit * $qty;
        }
        return [$lines, $subtotal];
    }

    public static function newReference(string $serviceType): string
    {
        $prefix = ['taxi' => 'TX', 'pro' => 'PR', 'food' => 'RP', 'shop' => 'CM', 'parcel' => 'CL', 'document' => 'DC', 'errand' => 'CS', 'urgent' => 'UR'][$serviceType] ?? 'MW';
        for ($i = 0; $i < 5; $i++) {
            $ref = Str::reference($prefix);
            if (!Database::value('SELECT 1 FROM orders WHERE reference = :r', ['r' => $ref])) {
                return $ref;
            }
        }
        return Str::reference($prefix, 10);
    }

    // ------------------------------------------------------------------
    // Annulation
    // ------------------------------------------------------------------

    public static function cancel(array $order, string $actorRole, int $actorId, ?string $reason): array
    {
        $reason = $reason !== null ? trim($reason) : null;
        if ($actorRole === 'provider') {
            if (in_array($order['status'], ['assigned', 'en_route_pickup', 'accepted', 'preparing', 'ready'], true)) {
                if (!$reason) {
                    throw \App\Core\ValidationException::field('reason', 'Indiquez le motif de l\'annulation.');
                }
                return DispatchService::release((int) $order['id'], $reason, 'provider', $actorId);
            }
            if ($order['status'] !== 'arrived_pickup' || !$reason) {
                throw HttpException::conflict('Annulation impossible à ce stade.', 'invalid_transition');
            }
        }
        if ($actorRole === 'customer' && $order['assigned_at'] && !$reason
            && time() - strtotime((string) $order['assigned_at']) > Settings::int('orders.customer_free_cancel_seconds', 120)) {
            throw \App\Core\ValidationException::field('reason', 'Indiquez le motif de l\'annulation.');
        }
        if ($actorRole === 'merchant' && !$reason) {
            throw \App\Core\ValidationException::field('reason', 'Indiquez le motif de l\'annulation.');
        }
        $order = Database::transaction(static function () use ($order, $actorRole, $actorId, $reason) {
            $fresh = Database::first('SELECT * FROM orders WHERE id = :id FOR UPDATE', ['id' => $order['id']]);
            $o = OrderStateMachine::transition($fresh, 'cancelled', $actorRole, $actorId, $reason, [
                'cancel_reason' => $reason ? mb_substr($reason, 0, 255) : null,
                'cancelled_by' => $actorId,
                'dispatch_status' => $fresh['provider_id'] ? 'assigned' : ($fresh['dispatch_status'] === 'searching' ? 'none' : $fresh['dispatch_status']),
            ]);
            self::afterTermination($o);
            return $o;
        });
        $who = ['customer' => 'le client', 'provider' => 'le prestataire', 'merchant' => 'le commerce', 'admin' => 'l\'administration', 'system' => 'le système'][$actorRole] ?? '';
        NotificationService::order($order, 'order_cancelled', 'Commande annulée', 'La commande ' . $order['reference'] . ' a été annulée par ' . $who . ($reason ? ' : ' . $reason : '.'), ['customer', 'provider', 'merchant']);
        RealtimeService::pushOrder($order, 'order_updated');
        return $order;
    }

    /** Nettoyage après annulation, expiration ou refus. */
    public static function afterTermination(array $order): void
    {
        if ($order['provider_id']) {
            DispatchService::endAssignment((int) $order['id'], (int) $order['provider_id'], 'Commande ' . $order['status']);
        }
        Database::execute("UPDATE dispatch_offers SET status = 'superseded' WHERE order_id = :o AND status IN ('pending','countered')", ['o' => $order['id']]);
        Database::execute("UPDATE fare_negotiations SET status = 'superseded' WHERE order_id = :o AND status = 'pending'", ['o' => $order['id']]);
        if ($order['flow'] === 'merchant' && !in_array($order['status'], ['delivered', 'paid'], true)) {
            $merchant = Database::first('SELECT track_stock FROM merchants WHERE id = :id', ['id' => $order['merchant_id']]);
            if ($merchant && (int) $merchant['track_stock']) {
                foreach (Database::select('SELECT product_id, quantity FROM order_items WHERE order_id = :o AND product_id IS NOT NULL', ['o' => $order['id']]) as $it) {
                    Database::execute('UPDATE products SET stock_qty = stock_qty + :q WHERE id = :id AND stock_qty IS NOT NULL', ['q' => $it['quantity'], 'id' => $it['product_id']]);
                }
            }
        }
        PromotionService::release((int) $order['id']);
        PaymentService::refundPrepaidOnCancel($order);
    }

    // ------------------------------------------------------------------
    // Actions du prestataire
    // ------------------------------------------------------------------

    public static function providerAction(array $order, int $providerId, string $action, array $input): array
    {
        if ((int) $order['provider_id'] !== $providerId) {
            throw HttpException::forbidden('Cette commande ne vous est pas attribuée.');
        }
        // Flux commerce : étapes du livreur pendant la préparation (sans changer le statut de la commande)
        if ($order['flow'] === 'merchant' && in_array($action, ['en_route_pickup', 'arrived_pickup'], true)) {
            if (!in_array($order['status'], ['accepted', 'preparing', 'ready'], true)) {
                throw HttpException::conflict('Étape impossible à ce stade.', 'invalid_transition');
            }
            Database::execute('UPDATE orders SET provider_stage = :s, version = version + 1 WHERE id = :id', ['s' => $action, 'id' => $order['id']]);
            Database::insert('order_status_history', ['order_id' => $order['id'], 'from_status' => $order['status'], 'to_status' => $order['status'], 'actor_id' => $providerId, 'actor_role' => 'provider',
                'note' => $action === 'en_route_pickup' ? 'Livreur en route vers le commerce' : 'Livreur arrivé au commerce']);
            $order = DispatchService::fresh((int) $order['id']);
            RealtimeService::pushOrder($order, 'order_updated');
            if ($action === 'arrived_pickup') {
                NotificationService::order($order, 'courier_arrived', 'Livreur arrivé', 'Le livreur est arrivé pour récupérer la commande ' . $order['reference'] . '.', ['merchant']);
            }
            return $order;
        }

        return self::advance($order, $action, 'provider', $providerId, $input);
    }

    /** Transition commune prestataire / commerce (livraison propre) avec preuves et effets de bord. */
    public static function advance(array $order, string $to, string $role, int $actorId, array $input): array
    {
        $extraNote = null;
        if ($to === 'delivered') {
            self::verifyProof($order, $input);
        }
        if ($to === 'delivery_failed') {
            $reason = trim((string) ($input['reason'] ?? ''));
            if ($reason === '') {
                throw \App\Core\ValidationException::field('reason', 'Indiquez la raison de l\'échec de livraison.');
            }
            Database::execute('UPDATE deliveries SET failed_reason = :r, attempts = attempts + 1 WHERE order_id = :o', ['r' => mb_substr($reason, 0, 255), 'o' => $order['id']]);
            $extraNote = $reason;
        }
        if ($to === 'cancelled') {
            return self::cancel($order, $role, $actorId, (string) ($input['reason'] ?? ''));
        }
        $order = Database::transaction(static function () use ($order, $to, $role, $actorId, $extraNote) {
            $fresh = Database::first('SELECT * FROM orders WHERE id = :id FOR UPDATE', ['id' => $order['id']]);
            $o = OrderStateMachine::transition($fresh, $to, $role, $actorId, $extraNote, $to === 'picked_up' ? ['provider_stage' => 'picked_up'] : []);
            if ($o['flow'] === 'ride') {
                $col = ['arrived_pickup' => 'arrived_at', 'in_progress' => 'started_at', 'completed' => 'ended_at'][$to] ?? null;
                if ($col) {
                    Database::execute("UPDATE rides SET $col = NOW() WHERE order_id = :o", ['o' => $o['id']]);
                }
            }
            // La récupération enchaîne directement sur la livraison.
            if ($to === 'picked_up') {
                $o = OrderStateMachine::transition($o, 'in_delivery', $role, $actorId, null);
            }
            if (in_array($o['status'], ['completed', 'delivered', 'returned'], true) && $o['provider_id']) {
                DispatchService::endAssignment((int) $o['id'], (int) $o['provider_id'], 'Commande terminée');
            }
            return $o;
        });

        self::notifyProgress($order);
        RealtimeService::pushOrder($order, 'order_updated');

        if (in_array($order['status'], ['completed', 'delivered', 'returned'], true)) {
            $order = PaymentService::onOrderFinished($order, $role, $actorId, isset($input['amount_collected']) ? (int) $input['amount_collected'] : null);
        }
        return $order;
    }

    private static function verifyProof(array $order, array $input): void
    {
        $d = Database::first('SELECT * FROM deliveries WHERE order_id = :o', ['o' => $order['id']]);
        if (!$d) {
            return;
        }
        switch ($d['proof_type']) {
            case 'otp':
                if ((int) $d['otp_attempts'] >= 5) {
                    throw HttpException::tooMany('Trop de codes erronés. Contactez l\'assistance MARWA.');
                }
                $code = preg_replace('/\D/', '', (string) ($input['delivery_code'] ?? ''));
                if (!hash_equals((string) $order['delivery_code'], (string) $code)) {
                    Database::execute('UPDATE deliveries SET otp_attempts = otp_attempts + 1 WHERE order_id = :o', ['o' => $order['id']]);
                    throw \App\Core\ValidationException::field('delivery_code', 'Code de livraison incorrect. Demandez le code au destinataire.');
                }
                Database::execute('UPDATE deliveries SET otp_verified_at = NOW() WHERE order_id = :o', ['o' => $order['id']]);
                break;
            case 'photo':
                $fid = (int) ($input['proof_file_id'] ?? 0);
                $ok = $fid && Database::value("SELECT 1 FROM files WHERE id = :id AND owner_id = :u AND purpose = 'delivery_proof'", ['id' => $fid, 'u' => $order['provider_id'] ?? 0]);
                if (!$ok) {
                    throw \App\Core\ValidationException::field('proof_file_id', 'Ajoutez une photo de la remise du colis.');
                }
                Database::execute('UPDATE deliveries SET proof_file_id = :f WHERE order_id = :o', ['f' => $fid, 'o' => $order['id']]);
                break;
            case 'signature':
                $sig = (string) ($input['signature'] ?? '');
                if (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $sig) || strlen($sig) > 300000 || strlen($sig) < 200) {
                    throw \App\Core\ValidationException::field('signature', 'La signature du destinataire est requise.');
                }
                Database::execute('UPDATE deliveries SET proof_signature = :s WHERE order_id = :o', ['s' => $sig, 'o' => $order['id']]);
                break;
        }
    }

    private static function notifyProgress(array $order): void
    {
        $ride = $order['flow'] === 'ride';
        $msg = match ($order['status']) {
            'en_route_pickup' => [$ride ? 'Chauffeur en route' : 'Livreur en route', $ride ? 'Votre chauffeur se dirige vers vous.' : 'Le livreur se dirige vers le point de collecte.'],
            'arrived_pickup' => [$ride ? 'Votre chauffeur est arrivé' : 'Livreur arrivé', $ride ? 'Votre chauffeur vous attend au point de prise en charge.' : 'Le livreur est arrivé au point de collecte.'],
            'in_progress' => ['Course démarrée', 'Bon voyage ! Vous pouvez suivre le trajet sur la carte.'],
            'in_delivery' => ['Livraison en cours', 'Votre commande est en route. Préparez le code de livraison.'],
            'completed' => ['Course terminée', 'Merci d\'avoir voyagé avec MARWA. Pensez à noter votre chauffeur.'],
            'delivered' => ['Commande livrée', 'Votre commande ' . $order['reference'] . ' a été livrée.'],
            'delivery_failed' => ['Livraison échouée', 'Le livreur n\'a pas pu effectuer la livraison. Il vous contactera via la messagerie.'],
            'returning', 'returned' => ['Retour à l\'expéditeur', 'Le colis ' . $order['reference'] . ' est retourné à l\'expéditeur.'],
            default => null,
        };
        if ($msg) {
            $channels = in_array($order['status'], ['arrived_pickup', 'delivered'], true) ? ['sms'] : [];
            NotificationService::order($order, 'order_' . $order['status'], $msg[0], $msg[1], ['customer', 'merchant'], $channels);
        }
    }

    // ------------------------------------------------------------------
    // Actions du commerce
    // ------------------------------------------------------------------

    public static function merchantAction(array $order, array $merchant, int $userId, string $action, array $input): array
    {
        if ((int) $order['merchant_id'] !== (int) $merchant['id']) {
            throw HttpException::forbidden();
        }
        switch ($action) {
            case 'accept':
                $prep = (int) ($input['prep_time_min'] ?? $merchant['prep_time_min']);
                if ($prep < 1 || $prep > 240) {
                    throw \App\Core\ValidationException::field('prep_time_min', 'Temps de préparation entre 1 et 240 minutes.');
                }
                $lead = 10;
                $dispatchAt = time() + max(0, ($prep - $lead) * 60);
                $order = OrderStateMachine::transition($order, 'accepted', 'merchant', $userId, 'Préparation estimée : ' . $prep . ' min', [
                    'prep_time_min' => $prep,
                    'dispatch_after' => $merchant['delivery_mode'] === 'marwa' ? date('Y-m-d H:i:s', $dispatchAt) : null,
                ]);
                NotificationService::order($order, 'order_accepted', 'Commande acceptée', $merchant['name'] . ' prépare votre commande (environ ' . $prep . ' min).', ['customer']);
                if ($merchant['delivery_mode'] === 'marwa' && $dispatchAt <= time()) {
                    $order = DispatchService::startSearch($order);
                }
                break;
            case 'refuse':
                $reason = trim((string) ($input['reason'] ?? ''));
                if ($reason === '') {
                    throw \App\Core\ValidationException::field('reason', 'Indiquez le motif du refus.');
                }
                $order = Database::transaction(static function () use ($order, $userId, $reason) {
                    $o = OrderStateMachine::transition($order, 'refused', 'merchant', $userId, $reason, ['cancel_reason' => mb_substr($reason, 0, 255)]);
                    self::afterTermination($o);
                    return $o;
                });
                NotificationService::order($order, 'order_refused', 'Commande refusée', $merchant['name'] . ' ne peut pas honorer la commande : ' . $reason, ['customer'], ['sms']);
                break;
            case 'preparing':
            case 'ready':
                $order = OrderStateMachine::transition($order, $action, 'merchant', $userId);
                if ($action === 'ready') {
                    NotificationService::order($order, 'order_ready', 'Commande prête', 'La commande ' . $order['reference'] . ' est prête.', ['customer', 'provider']);
                    if ($merchant['delivery_mode'] === 'marwa' && !$order['provider_id'] && $order['dispatch_status'] === 'none') {
                        $order = DispatchService::startSearch($order);
                    }
                }
                break;
            case 'cancel':
                return self::cancel($order, 'merchant', $userId, (string) ($input['reason'] ?? ''));
            case 'picked_up':
            case 'delivered':
            case 'delivery_failed':
            case 'returning':
            case 'returned':
                if ($merchant['delivery_mode'] !== 'own' || $order['provider_id']) {
                    throw HttpException::forbidden('Cette étape est réalisée par le livreur MARWA.');
                }
                return self::advance($order, $action, 'merchant', $userId, $input);
            default:
                throw HttpException::badRequest('Action inconnue.');
        }
        RealtimeService::pushOrder($order, 'order_updated');
        return $order;
    }
}
