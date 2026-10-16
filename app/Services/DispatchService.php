<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Geo;
use App\Core\HttpException;
use App\Core\Settings;
use App\Core\Str;

/**
 * Moteur d'attribution configurable :
 *  - sélection des prestataires éligibles (vérifiés, en ligne, libres, documents valides,
 *    catégorie et capacité adaptées, position récente, dans le rayon) triés par distance
 *    (et éventuellement par note) ;
 *  - envoi par vagues successives à un groupe limité ;
 *  - attribution unique garantie par transaction SQL + verrou FOR UPDATE + mises à jour
 *    conditionnelles + index uniques (assignments.active_order_id, provider_profiles.current_order_id).
 */
final class DispatchService
{
    public static function providerTypeFor(array $order): string
    {
        return $order['flow'] === 'ride' ? 'driver' : 'courier';
    }

    public static function eligibleProviders(array $order, int $limit): array
    {
        $radiusKm = (float) Settings::get('dispatch.radius_km', 6);
        $lat = (float) $order['pickup_lat'];
        $lng = (float) $order['pickup_lng'];
        $dLat = $radiusKm / 111.0;
        $dLng = $radiusKm / (111.0 * max(0.2, cos(deg2rad($lat))));
        $category = (string) ($order['vehicle_category'] ?? '');
        $isRide = $order['flow'] === 'ride';
        $categories = $category !== '' ? [$category] : ($isRide ? PricingService::RIDE_VEHICLES : PricingService::COURIER_VEHICLES);
        [$inCat, $catParams] = Database::in($categories, 'cat');

        $rows = Database::select(
            "SELECT p.user_id, p.rating_avg, l.lat, l.lng, v.id AS vehicle_id, v.category, v.capacity
               FROM provider_profiles p
               JOIN users u ON u.id = p.user_id AND u.status = 'active'
               JOIN provider_locations l ON l.provider_id = p.user_id
               JOIN vehicles v ON v.provider_id = p.user_id AND v.is_active = 1
              WHERE p.provider_type = :ptype
                AND p.verification_status = 'verified'
                AND p.is_online = 1
                AND p.current_order_id IS NULL
                AND (p.license_expires_at IS NULL OR p.license_expires_at >= CURDATE())
                AND NOT EXISTS (SELECT 1 FROM provider_documents d WHERE d.provider_id = p.user_id AND d.status = 'approved'
                                 AND d.expires_at IS NOT NULL AND d.expires_at < CURDATE()
                                 AND d.doc_type IN ('driving_license','vehicle_registration','insurance'))
                AND (p.merchant_id IS NULL OR p.merchant_id = :mid)
                AND l.updated_at >= DATE_SUB(NOW(), INTERVAL :age SECOND)
                AND l.lat BETWEEN :lat1 AND :lat2 AND l.lng BETWEEN :lng1 AND :lng2
                AND v.category IN ($inCat)
                AND v.capacity >= :pax
                AND NOT EXISTS (SELECT 1 FROM dispatch_offers o WHERE o.order_id = :oid AND o.provider_id = p.user_id AND o.status <> 'superseded')
                AND NOT EXISTS (SELECT 1 FROM dispatch_offers o2 WHERE o2.provider_id = p.user_id AND o2.status IN ('pending','countered') AND o2.expires_at > NOW())",
            [
                'ptype' => self::providerTypeFor($order),
                'mid' => (int) ($order['merchant_id'] ?? 0),
                'age' => Settings::int('dispatch.location_max_age_s', 300),
                'lat1' => $lat - $dLat, 'lat2' => $lat + $dLat, 'lng1' => $lng - $dLng, 'lng2' => $lng + $dLng,
                'pax' => $isRide ? max(1, (int) $order['passengers']) : 1,
                'oid' => (int) $order['id'],
            ] + $catParams
        );
        $weight = (float) Settings::get('dispatch.rating_weight', 0);
        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            if (isset($seen[$r['user_id']])) {
                continue;
            }
            $seen[$r['user_id']] = true;
            $dist = Geo::distance($lat, $lng, (float) $r['lat'], (float) $r['lng']);
            if ($dist > $radiusKm * 1000) {
                continue;
            }
            // Score : distance en km, éventuellement corrigée par la note (pondération paramétrable, 0 par défaut)
            $r['distance_m'] = (int) round($dist);
            $r['score'] = $dist / 1000 - $weight * ((float) $r['rating_avg'] > 0 ? ((float) $r['rating_avg'] - 3) : 0);
            $out[] = $r;
        }
        usort($out, static fn ($a, $b) => $a['score'] <=> $b['score']);
        return array_slice($out, 0, $limit);
    }

    public static function startSearch(array $order): array
    {
        $order = self::fresh((int) $order['id']);
        Database::update('orders', ['dispatch_status' => 'searching', 'version' => (int) $order['version'] + 1], 'id = :id AND version = :v', ['id' => $order['id'], 'v' => $order['version']]);
        $order = self::fresh((int) $order['id']);
        self::sendRound($order);
        return self::fresh((int) $order['id']);
    }

    /** Envoie une nouvelle vague de propositions ; renvoie le nombre de prestataires sollicités. */
    public static function sendRound(array $order): int
    {
        $round = (int) Database::value('SELECT COALESCE(MAX(round_no),0) FROM dispatch_offers WHERE order_id = :o', ['o' => $order['id']]) + 1;
        $providers = self::eligibleProviders($order, Settings::int('dispatch.batch_size', 5));
        $ttl = Settings::int('dispatch.offer_ttl_seconds', 60);
        if ($order['pricing_mode'] === 'negotiated') {
            // En négociation, la proposition reste visible jusqu'à expiration de la demande.
            $ttl = max($ttl, Settings::int('negotiation.counter_ttl_seconds', 90) * 2);
        }
        $expires = date('Y-m-d H:i:s', time() + $ttl);
        $sent = 0;
        foreach ($providers as $p) {
            // Un prestataire dont la proposition avait été « remplacée » (commande attribuée puis libérée) peut être sollicité à nouveau.
            $n = Database::execute(
                "INSERT INTO dispatch_offers (order_id, provider_id, round_no, distance_m, expires_at) VALUES (:o, :p, :r, :d, :e)
                 ON DUPLICATE KEY UPDATE round_no = IF(status = 'superseded', VALUES(round_no), round_no), distance_m = IF(status = 'superseded', VALUES(distance_m), distance_m),
                   sent_at = IF(status = 'superseded', NOW(), sent_at), expires_at = IF(status = 'superseded', VALUES(expires_at), expires_at),
                   responded_at = IF(status = 'superseded', NULL, responded_at), status = IF(status = 'superseded', 'pending', status)",
                ['o' => $order['id'], 'p' => $p['user_id'], 'r' => $round, 'd' => $p['distance_m'], 'e' => $expires]
            );
            if ($n > 0) {
                $sent++;
                Database::execute('UPDATE provider_profiles SET offers_received = offers_received + 1 WHERE user_id = :p', ['p' => $p['user_id']]);
                $price = (int) ($order['offered_price'] ?? $order['final_price'] ?? $order['estimated_price']);
                NotificationService::notify((int) $p['user_id'], 'new_request',
                    $order['flow'] === 'ride' ? 'Nouvelle demande de course' : 'Nouvelle demande de livraison',
                    sprintf('%s — %s à %s m de vous. Répondez avant expiration.', $order['reference'], fcfa($price), number_format($p['distance_m'], 0, ',', ' ')),
                    ['order_id' => (int) $order['id'], 'expires_at' => $expires]);
            }
        }
        return $sent;
    }

    /**
     * Prestataire qui vient de se connecter (ou qui consulte ses demandes) : les commandes encore
     * sans prise en charge autour de lui lui sont proposées, même si elles ont été créées avant
     * son arrivée. Une commande déjà refusée ou laissée sans réponse par lui n'est pas reproposée.
     * Retourne le nombre de nouvelles propositions.
     */
    public static function offerOpenOrdersTo(int $providerId, int $max = 5): int
    {
        if (!filter_var(Settings::get('dispatch.offer_open_orders_on_online', true), FILTER_VALIDATE_BOOLEAN)) {
            return 0;
        }
        $p = Database::first("SELECT p.*, l.lat, l.lng, l.updated_at loc_at FROM provider_profiles p JOIN users u ON u.id = p.user_id AND u.status = 'active'
                               JOIN provider_locations l ON l.provider_id = p.user_id WHERE p.user_id = :p", ['p' => $providerId]);
        if (!$p || $p['verification_status'] !== 'verified' || !(int) $p['is_online'] || $p['current_order_id'] !== null
            || strtotime((string) $p['loc_at']) < time() - Settings::int('dispatch.location_max_age_s', 300)) {
            return 0;
        }
        $vehicles = Database::select('SELECT category, capacity FROM vehicles WHERE provider_id = :p AND is_active = 1', ['p' => $providerId]);
        if (!$vehicles) {
            return 0;
        }
        $radiusKm = (float) Settings::get('dispatch.radius_km', 6);
        $lat = (float) $p['lat'];
        $lng = (float) $p['lng'];
        $dLat = $radiusKm / 111.0;
        $dLng = $radiusKm / (111.0 * max(0.2, cos(deg2rad($lat))));
        $flowSql = $p['provider_type'] === 'driver'
            ? "o.flow = 'ride' AND o.status IN ('searching','negotiating')"
            : "((o.flow = 'courier' AND o.status IN ('searching','negotiating')) OR (o.flow = 'merchant' AND o.dispatch_status = 'searching' AND o.status IN ('accepted','preparing','ready')))";
        $orders = Database::select(
            "SELECT o.* FROM orders o
              WHERE $flowSql AND o.provider_id IS NULL
                AND (o.expires_at IS NULL OR o.expires_at > NOW())
                AND (o.dispatch_after IS NULL OR o.dispatch_after <= NOW())
                AND o.created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
                AND (o.merchant_id IS NULL OR :mid = 0 OR o.merchant_id = :mid2)
                AND o.pickup_lat BETWEEN :la1 AND :la2 AND o.pickup_lng BETWEEN :ln1 AND :ln2
                AND NOT EXISTS (SELECT 1 FROM dispatch_offers d WHERE d.order_id = o.id AND d.provider_id = :p AND d.status <> 'superseded')
              ORDER BY o.created_at LIMIT 30",
            ['mid' => (int) ($p['merchant_id'] ?? 0), 'mid2' => (int) ($p['merchant_id'] ?? 0), 'la1' => $lat - $dLat, 'la2' => $lat + $dLat, 'ln1' => $lng - $dLng, 'ln2' => $lng + $dLng, 'p' => $providerId]
        );
        $sent = 0;
        foreach ($orders as $o) {
            if ($sent >= $max) {
                break;
            }
            $dist = Geo::distance($lat, $lng, (float) $o['pickup_lat'], (float) $o['pickup_lng']);
            if ($dist > $radiusKm * 1000 || !self::vehicleFits($o, $vehicles)) {
                continue;
            }
            if (self::insertOffer($o, $providerId, (int) round($dist))) {
                $sent++;
            }
        }
        if ($sent > 0) {
            NotificationService::notify($providerId, 'new_request', $sent > 1 ? "$sent demandes en attente près de vous" : 'Une demande en attente près de vous',
                'Des clients attendent encore une prise en charge : consultez vos demandes.', []);
        }
        return $sent;
    }

    /** Proposition d'une commande à un prestataire précis (décision de l'équipe MARWA). */
    public static function offerToProvider(int $orderId, int $providerId): array
    {
        $o = self::fresh($orderId);
        if ($o['provider_id'] !== null) {
            throw HttpException::conflict('Cette commande a déjà un prestataire.', 'already_assigned');
        }
        $p = Database::first('SELECT p.*, l.lat, l.lng FROM provider_profiles p LEFT JOIN provider_locations l ON l.provider_id = p.user_id WHERE p.user_id = :p', ['p' => $providerId]);
        if (!$p || $p['verification_status'] !== 'verified') {
            throw HttpException::conflict('Ce prestataire n\'est pas validé.', 'provider_unavailable');
        }
        if ($p['provider_type'] !== self::providerTypeFor($o)) {
            throw HttpException::conflict($o['flow'] === 'ride' ? 'Une course doit être proposée à un chauffeur.' : 'Une livraison doit être proposée à un livreur.', 'wrong_type');
        }
        $dist = $p['lat'] !== null ? (int) round(Geo::distance((float) $p['lat'], (float) $p['lng'], (float) $o['pickup_lat'], (float) $o['pickup_lng'])) : null;
        Database::execute("DELETE FROM dispatch_offers WHERE order_id = :o AND provider_id = :p AND status IN ('expired','refused','superseded')", ['o' => $orderId, 'p' => $providerId]);
        $created = self::insertOffer($o, $providerId, $dist, Settings::int('dispatch.offer_ttl_seconds', 60) * 5);
        if ($o['dispatch_status'] !== 'searching') {
            Database::execute("UPDATE orders SET dispatch_status = 'searching', version = version + 1 WHERE id = :id AND provider_id IS NULL", ['id' => $orderId]);
        }
        return ['offered' => $created, 'online' => (bool) $p['is_online'], 'busy' => $p['current_order_id'] !== null];
    }

    private static function vehicleFits(array $o, array $vehicles): bool
    {
        $cats = $o['flow'] === 'ride' ? ($o['vehicle_category'] ? [$o['vehicle_category']] : PricingService::RIDE_VEHICLES)
            : ($o['vehicle_category'] ? [$o['vehicle_category']] : PricingService::COURIER_VEHICLES);
        foreach ($vehicles as $v) {
            if (in_array($v['category'], $cats, true) && ($o['flow'] !== 'ride' || (int) $v['capacity'] >= max(1, (int) $o['passengers']))) {
                return true;
            }
        }
        return false;
    }

    private static function insertOffer(array $o, int $providerId, ?int $dist, ?int $ttl = null): bool
    {
        $ttl ??= Settings::int('dispatch.offer_ttl_seconds', 60);
        if ($o['pricing_mode'] === 'negotiated') {
            $ttl = max($ttl, Settings::int('negotiation.counter_ttl_seconds', 90) * 2);
        }
        $expires = date('Y-m-d H:i:s', time() + $ttl);
        $round = (int) Database::value('SELECT COALESCE(MAX(round_no),0) FROM dispatch_offers WHERE order_id = :o', ['o' => $o['id']]) + 1;
        $n = Database::execute(
            "INSERT INTO dispatch_offers (order_id, provider_id, round_no, distance_m, expires_at) VALUES (:o, :p, :r, :d, :e)
             ON DUPLICATE KEY UPDATE round_no = IF(status = 'superseded', VALUES(round_no), round_no), sent_at = IF(status = 'superseded', NOW(), sent_at),
               expires_at = IF(status = 'superseded', VALUES(expires_at), expires_at), responded_at = IF(status = 'superseded', NULL, responded_at),
               status = IF(status = 'superseded', 'pending', status)",
            ['o' => $o['id'], 'p' => $providerId, 'r' => min(255, $round), 'd' => $dist, 'e' => $expires]
        );
        if ($n > 0) {
            Database::execute('UPDATE provider_profiles SET offers_received = offers_received + 1 WHERE user_id = :p', ['p' => $providerId]);
            $price = (int) ($o['offered_price'] ?? $o['final_price'] ?? $o['estimated_price']);
            NotificationService::notify($providerId, 'new_request', $o['flow'] === 'ride' ? 'Nouvelle demande de course' : 'Nouvelle demande de livraison',
                sprintf('%s — %s%s. Répondez avant expiration.', $o['reference'], fcfa($price), $dist !== null ? ' à ' . number_format($dist, 0, ',', ' ') . ' m de vous' : ''),
                ['order_id' => (int) $o['id'], 'expires_at' => $expires]);
        }
        return $n > 0;
    }

    /**
     * Attribution atomique. $agreedPrice = prix accepté par les deux parties
     * (null = tarif automatique / frais de livraison déjà fixés).
     */
    public static function assign(int $orderId, int $providerId, ?int $agreedPrice, string $note): array
    {
        return Database::transaction(static function () use ($orderId, $providerId, $agreedPrice, $note) {
            $order = Database::first('SELECT * FROM orders WHERE id = :id FOR UPDATE', ['id' => $orderId]);
            if (!$order) {
                throw HttpException::notFound('Commande introuvable.');
            }
            if ($order['provider_id'] !== null) {
                throw HttpException::conflict('Cette commande a déjà été attribuée à un autre prestataire.', 'already_assigned');
            }
            $open = $order['flow'] === 'merchant'
                ? ($order['dispatch_status'] === 'searching' && in_array($order['status'], ['accepted', 'preparing', 'ready'], true))
                : in_array($order['status'], ['searching', 'negotiating'], true);
            if (!$open) {
                throw HttpException::conflict('Cette commande n\'est plus disponible.', 'not_available');
            }
            $offer = Database::first(
                "SELECT * FROM dispatch_offers WHERE order_id = :o AND provider_id = :p AND status IN ('pending','countered') FOR UPDATE",
                ['o' => $orderId, 'p' => $providerId]
            );
            if (!$offer) {
                throw HttpException::forbidden('Cette demande ne vous a pas été proposée ou n\'est plus valable.');
            }
            if ($offer['status'] === 'pending' && $offer['expires_at'] < date('Y-m-d H:i:s')) {
                throw HttpException::conflict('Le délai de réponse est dépassé.', 'offer_expired');
            }
            $provider = Database::first('SELECT * FROM provider_profiles WHERE user_id = :p FOR UPDATE', ['p' => $providerId]);
            if (!$provider || $provider['verification_status'] !== 'verified' || !(int) $provider['is_online']) {
                throw HttpException::conflict('Le prestataire n\'est plus disponible.', 'provider_unavailable');
            }
            if ($provider['current_order_id'] !== null) {
                throw HttpException::conflict('Le prestataire a déjà une commande en cours.', 'provider_busy');
            }

            $price = $agreedPrice ?? (int) ($order['final_price'] ?? $order['estimated_price']);
            $extra = [
                'provider_id' => $providerId,
                'dispatch_status' => 'assigned',
                'assigned_at' => date('Y-m-d H:i:s'),
            ];
            if ($order['flow'] !== 'merchant') {
                $extra['final_price'] = $price;
                $extra['total'] = max(0, $price + (int) $order['booking_fee'] + (int) $order['service_fee'] - (int) $order['discount']);
                $updated = OrderStateMachine::transition($order, 'assigned', 'system', $providerId, $note, $extra);
            } else {
                $extra['provider_stage'] = 'assigned';
                $extra['version'] = (int) $order['version'] + 1;
                $n = Database::update('orders', $extra, 'id = :id AND provider_id IS NULL AND version = :v', ['id' => $orderId, 'v' => $order['version']]);
                if ($n !== 1) {
                    throw HttpException::conflict('Cette commande a déjà été attribuée.', 'already_assigned');
                }
                Database::insert('order_status_history', ['order_id' => $orderId, 'from_status' => $order['status'], 'to_status' => $order['status'], 'actor_id' => $providerId, 'actor_role' => 'system', 'note' => 'Livreur affecté']);
                $updated = array_merge($order, $extra);
            }
            $n = Database::execute('UPDATE provider_profiles SET current_order_id = :o WHERE user_id = :p AND current_order_id IS NULL', ['o' => $orderId, 'p' => $providerId]);
            if ($n !== 1) {
                throw HttpException::conflict('Le prestataire a déjà une commande en cours.', 'provider_busy');
            }
            Database::insert('assignments', ['order_id' => $orderId, 'provider_id' => $providerId, 'active_order_id' => $orderId, 'agreed_price' => $price]);
            Database::execute("UPDATE dispatch_offers SET status = 'accepted', responded_at = NOW() WHERE id = :id", ['id' => $offer['id']]);
            Database::execute("UPDATE dispatch_offers SET status = 'superseded' WHERE order_id = :o AND id <> :id AND status IN ('pending','countered')", ['o' => $orderId, 'id' => $offer['id']]);
            Database::execute("UPDATE fare_negotiations SET status = 'superseded' WHERE order_id = :o AND status = 'pending'", ['o' => $orderId]);
            if ($order['flow'] === 'ride') {
                $vehicle = Database::value('SELECT id FROM vehicles WHERE provider_id = :p AND is_active = 1 ORDER BY id LIMIT 1', ['p' => $providerId]);
                Database::execute('UPDATE rides SET vehicle_id = :v WHERE order_id = :o', ['v' => $vehicle, 'o' => $orderId]);
            }
            return $updated;
        });
    }

    /** Acceptation par le prestataire au prix actuellement proposé (tarif auto ou offre du client). */
    public static function acceptByProvider(int $orderId, int $providerId): array
    {
        $order = self::fresh($orderId);
        $price = $order['pricing_mode'] === 'negotiated' ? (int) $order['offered_price'] : null;
        $order = self::assign($orderId, $providerId, $price, $price ? 'Prix du client accepté par le prestataire : ' . fcfa($price) : 'Demande acceptée');
        self::afterAssign($order);
        return $order;
    }

    public static function afterAssign(array $order): void
    {
        $provider = Database::first('SELECT full_name FROM users WHERE id = :id', ['id' => $order['provider_id']]);
        $name = Str::firstName((string) ($provider['full_name'] ?? ''));
        NotificationService::order($order, 'order_assigned',
            $order['flow'] === 'ride' ? 'Chauffeur trouvé' : 'Livreur affecté',
            sprintf('%s a accepté votre commande %s.%s', $name, $order['reference'], $order['flow'] !== 'merchant' ? ' Prix convenu : ' . fcfa((int) $order['final_price']) . '.' : ''),
            $order['flow'] === 'merchant' ? ['customer', 'merchant'] : ['customer'], ['sms']);
        NotificationService::notify((int) $order['provider_id'], 'order_assigned', 'Commande attribuée',
            'La commande ' . $order['reference'] . ' vous est attribuée. Rendez-vous au point de départ.', ['order_id' => (int) $order['id']]);
        RealtimeService::pushOrder($order, 'order_updated');
    }

    public static function refuse(int $orderId, int $providerId, ?string $reason): void
    {
        $n = Database::execute(
            "UPDATE dispatch_offers SET status = 'refused', refusal_reason = :r, responded_at = NOW() WHERE order_id = :o AND provider_id = :p AND status IN ('pending','countered')",
            ['r' => $reason ? mb_substr($reason, 0, 120) : null, 'o' => $orderId, 'p' => $providerId]
        );
        if ($n !== 1) {
            throw HttpException::conflict('Aucune proposition en attente pour cette commande.', 'no_offer');
        }
        Database::execute('UPDATE provider_profiles SET offers_refused = offers_refused + 1 WHERE user_id = :p', ['p' => $providerId]);
        Database::execute("UPDATE fare_negotiations SET status = 'withdrawn' WHERE order_id = :o AND provider_id = :p AND status = 'pending'", ['o' => $orderId, 'p' => $providerId]);
        $order = self::fresh($orderId);
        if (!self::hasPendingOffers($orderId) && in_array($order['dispatch_status'], ['searching'], true)) {
            self::advanceOrFail($order);
        }
    }

    /**
     * Libère le prestataire (annulation par le prestataire ou réaffectation par l'administration)
     * et relance la recherche en excluant ce prestataire.
     */
    public static function release(int $orderId, string $reason, string $actorRole, ?int $actorId): array
    {
        $order = Database::transaction(static function () use ($orderId, $reason, $actorRole, $actorId) {
            $order = Database::first('SELECT * FROM orders WHERE id = :id FOR UPDATE', ['id' => $orderId]);
            if (!$order || !$order['provider_id']) {
                throw HttpException::conflict('Aucun prestataire affecté à cette commande.');
            }
            $allowed = $order['flow'] === 'merchant'
                ? in_array($order['status'], ['accepted', 'preparing', 'ready'], true)
                : in_array($order['status'], ['assigned', 'en_route_pickup'], true);
            if (!$allowed) {
                throw HttpException::conflict('Impossible de libérer la commande à ce stade (prise en charge déjà effectuée).', 'invalid_transition');
            }
            $providerId = (int) $order['provider_id'];
            self::endAssignment($orderId, $providerId, $reason);
            Database::execute("UPDATE dispatch_offers SET status = 'refused', refusal_reason = :r WHERE order_id = :o AND provider_id = :p",
                ['r' => mb_substr('Désistement : ' . $reason, 0, 120), 'o' => $orderId, 'p' => $providerId]);
            $back = $order['pricing_mode'] === 'negotiated' ? 'negotiating' : 'searching';
            $extra = ['provider_id' => null, 'dispatch_status' => 'searching', 'assigned_at' => null, 'provider_stage' => null];
            if ($order['flow'] !== 'merchant') {
                $extra['final_price'] = $order['pricing_mode'] === 'negotiated' ? null : $order['final_price'];
                $order = OrderStateMachine::transition($order, $back, 'system', $actorId, "Prestataire libéré ($actorRole) : $reason", $extra);
            } else {
                $extra['version'] = (int) $order['version'] + 1;
                Database::update('orders', $extra, 'id = :id', ['id' => $orderId]);
                Database::insert('order_status_history', ['order_id' => $orderId, 'from_status' => $order['status'], 'to_status' => $order['status'], 'actor_id' => $actorId, 'actor_role' => $actorRole, 'note' => 'Livreur libéré : ' . $reason]);
                $order = array_merge($order, $extra);
            }
            return $order + ['_released_provider' => $providerId];
        });
        NotificationService::order($order, 'provider_released', 'Recherche d\'un nouveau prestataire',
            'Le prestataire précédent s\'est désisté. Nous recherchons un autre prestataire pour ' . $order['reference'] . '.', ['customer', 'merchant']);
        $fresh = self::fresh($orderId);
        self::sendRound($fresh);
        return self::fresh($orderId);
    }

    public static function endAssignment(int $orderId, int $providerId, string $reason): void
    {
        Database::execute('UPDATE assignments SET active_order_id = NULL, ended_at = NOW(), end_reason = :r WHERE order_id = :o AND provider_id = :p AND active_order_id IS NOT NULL',
            ['r' => mb_substr($reason, 0, 120), 'o' => $orderId, 'p' => $providerId]);
        Database::execute('UPDATE provider_profiles SET current_order_id = NULL WHERE user_id = :p AND current_order_id = :o', ['p' => $providerId, 'o' => $orderId]);
    }

    public static function hasPendingOffers(int $orderId): bool
    {
        return (bool) Database::value("SELECT 1 FROM dispatch_offers WHERE order_id = :o AND status IN ('pending','countered') AND expires_at > NOW() LIMIT 1", ['o' => $orderId])
            || (bool) Database::value("SELECT 1 FROM fare_negotiations WHERE order_id = :o AND proposed_by = 'provider' AND status = 'pending' AND expires_at > NOW() LIMIT 1", ['o' => $orderId]);
    }

    /** Nouvelle vague ou échec de la recherche si le nombre maximal de vagues est atteint. */
    private static function advanceOrFail(array $order): void
    {
        $rounds = (int) Database::value('SELECT COALESCE(MAX(round_no),0) FROM dispatch_offers WHERE order_id = :o', ['o' => $order['id']]);
        if ($rounds < Settings::int('dispatch.max_rounds', 4) && self::sendRound($order) > 0) {
            return;
        }
        // Sans prestataire : on laisse la demande ouverte jusqu'à son expiration si elle n'a pas encore expiré.
        if ($order['expires_at'] && $order['expires_at'] > date('Y-m-d H:i:s') && $rounds < Settings::int('dispatch.max_rounds', 4)) {
            return;
        }
        self::failSearch($order);
    }

    public static function failSearch(array $order): void
    {
        if ($order['flow'] === 'merchant') {
            Database::execute("UPDATE orders SET dispatch_status = 'no_provider', version = version + 1 WHERE id = :id AND provider_id IS NULL", ['id' => $order['id']]);
            NotificationService::order($order, 'no_provider', 'Aucun livreur disponible', 'Aucun livreur n\'a accepté la commande ' . $order['reference'] . '. L\'équipe MARWA va intervenir.', ['customer', 'merchant']);
            NotificationService::staff('orders.manage', 'no_provider', 'Commande sans livreur', $order['reference'] . ' : aucun livreur disponible.', ['order_id' => (int) $order['id']]);
            return;
        }
        if (in_array($order['status'], ['searching', 'negotiating'], true)) {
            $order = OrderStateMachine::transition($order, 'expired', 'system', null, 'Aucun prestataire disponible', ['dispatch_status' => 'no_provider']);
            OrderService::afterTermination($order);
            NotificationService::order($order, 'no_provider', 'Aucun prestataire disponible',
                'Aucun prestataire n\'a pu accepter votre demande ' . $order['reference'] . '. Vous pouvez réessayer ou ajuster votre prix.', ['customer']);
            RealtimeService::pushOrder($order, 'order_updated');
        }
    }

    /**
     * Tâche périodique (appelée par bin/worker.php ou opportunément par les requêtes d'interrogation) :
     * expiration des offres, nouvelles vagues, commandes sans réponse, dispatch différé.
     */
    public static function tick(): array
    {
        $stats = ['offers_expired' => 0, 'rounds' => 0, 'orders_expired' => 0, 'deferred_dispatch' => 0, 'merchant_timeouts' => 0];
        $lock = Database::value("SELECT GET_LOCK('marwa_dispatch_tick', 0)");
        if ((int) $lock !== 1) {
            return $stats;
        }
        try {
            $expired = Database::select("SELECT id, order_id, provider_id FROM dispatch_offers WHERE status = 'pending' AND expires_at <= NOW()");
            foreach ($expired as $o) {
                Database::execute("UPDATE dispatch_offers SET status = 'expired' WHERE id = :id AND status = 'pending'", ['id' => $o['id']]);
                Database::execute('UPDATE provider_profiles SET offers_expired = offers_expired + 1 WHERE user_id = :p', ['p' => $o['provider_id']]);
                $stats['offers_expired']++;
            }
            Database::execute("UPDATE fare_negotiations SET status = 'expired' WHERE status = 'pending' AND expires_at <= NOW()");
            Database::execute("UPDATE dispatch_offers d JOIN orders o ON o.id = d.order_id SET d.status = 'expired'
                                WHERE d.status = 'countered' AND d.expires_at <= NOW() AND o.provider_id IS NULL");

            // Commandes expirées (délai global dépassé)
            foreach (Database::select("SELECT * FROM orders WHERE status IN ('searching','negotiating') AND expires_at IS NOT NULL AND expires_at <= NOW()") as $order) {
                try {
                    self::failSearch($order);
                    $stats['orders_expired']++;
                } catch (HttpException) {
                }
            }
            // Recherche active sans proposition en cours : vague suivante
            foreach (Database::select("SELECT * FROM orders WHERE dispatch_status = 'searching' AND provider_id IS NULL
                                        AND (dispatch_after IS NULL OR dispatch_after <= NOW())
                                        AND status IN ('searching','negotiating','accepted','preparing','ready')") as $order) {
                if (!self::hasPendingOffers((int) $order['id'])) {
                    self::advanceOrFail($order);
                    $stats['rounds']++;
                }
            }
            // Dispatch différé (commandes programmées, temps de préparation)
            foreach (Database::select("SELECT * FROM orders WHERE dispatch_status = 'none' AND provider_id IS NULL AND dispatch_after IS NOT NULL AND dispatch_after <= NOW()
                                        AND status IN ('searching','negotiating','accepted','preparing','ready')") as $order) {
                self::startSearch($order);
                $stats['deferred_dispatch']++;
            }
            // Commerces qui n'ont pas répondu
            $timeout = Settings::int('orders.merchant_accept_timeout_min', 10);
            foreach (Database::select("SELECT * FROM orders WHERE status = 'pending' AND flow = 'merchant' AND updated_at <= DATE_SUB(NOW(), INTERVAL :m MINUTE)", ['m' => $timeout]) as $order) {
                try {
                    $order = OrderStateMachine::transition($order, 'expired', 'system', null, 'Le commerce n\'a pas répondu à temps');
                    OrderService::afterTermination($order);
                    NotificationService::order($order, 'order_expired', 'Commande expirée', 'Le commerce n\'a pas confirmé la commande ' . $order['reference'] . ' à temps. Elle est annulée sans frais.', ['customer', 'merchant']);
                    $stats['merchant_timeouts']++;
                } catch (HttpException) {
                }
            }
        } finally {
            Database::value("SELECT RELEASE_LOCK('marwa_dispatch_tick')");
        }
        return $stats;
    }

    public static function fresh(int $orderId): array
    {
        $o = Database::first('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
        if (!$o) {
            throw HttpException::notFound('Commande introuvable.');
        }
        return $o;
    }
}
