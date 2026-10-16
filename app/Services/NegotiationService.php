<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Settings;

/**
 * Négociation du prix (mode 2) : le client propose un prix, les prestataires sollicités
 * acceptent, refusent ou font une contre-proposition à durée limitée ; la commande n'est
 * attribuée qu'après confirmation explicite du prix par les deux parties.
 */
final class NegotiationService
{
    public static function bounds(array $order): array
    {
        $rule = Database::first('SELECT min_offer_pct, max_offer_pct FROM pricing_rules WHERE id = :id', ['id' => $order['pricing_rule_id']]) ?? ['min_offer_pct' => 60, 'max_offer_pct' => 300];
        $est = (int) $order['estimated_price'];
        return [
            'min' => PricingService::round($est * (int) $rule['min_offer_pct'] / 100, 50),
            'max' => PricingService::round($est * (int) $rule['max_offer_pct'] / 100, 50),
        ];
    }

    public static function assertAmount(array $order, int $amount): void
    {
        $b = self::bounds($order);
        if ($amount < $b['min'] || $amount > $b['max']) {
            throw new HttpException(422, sprintf('Le prix doit être compris entre %s et %s.', fcfa($b['min']), fcfa($b['max'])), 'price_out_of_bounds');
        }
    }

    /** Le client modifie son prix proposé (nombre de modifications limité). */
    public static function customerUpdateOffer(array $order, int $amount): array
    {
        if ($order['status'] !== 'negotiating') {
            throw HttpException::conflict('Le prix ne peut plus être modifié.', 'invalid_transition');
        }
        self::assertAmount($order, $amount);
        $count = (int) Database::value("SELECT COUNT(*) FROM fare_negotiations WHERE order_id = :o AND proposed_by = 'customer'", ['o' => $order['id']]);
        if ($count >= Settings::int('negotiation.max_customer_updates', 5) + 1) {
            throw HttpException::tooMany('Nombre maximal de modifications du prix atteint pour cette demande.');
        }
        Database::transaction(static function () use ($order, $amount) {
            Database::execute("UPDATE fare_negotiations SET status = 'superseded' WHERE order_id = :o AND proposed_by = 'customer' AND status = 'pending'", ['o' => $order['id']]);
            Database::insert('fare_negotiations', ['order_id' => $order['id'], 'proposed_by' => 'customer', 'amount' => $amount, 'expires_at' => $order['expires_at'] ?? date('Y-m-d H:i:s', time() + 600)]);
            Database::execute('UPDATE orders SET offered_price = :p, version = version + 1 WHERE id = :id', ['p' => $amount, 'id' => $order['id']]);
            Database::insert('order_status_history', ['order_id' => $order['id'], 'from_status' => 'negotiating', 'to_status' => 'negotiating', 'actor_id' => $order['customer_id'], 'actor_role' => 'customer', 'note' => 'Nouveau prix proposé : ' . fcfa($amount)]);
        });
        $order = DispatchService::fresh((int) $order['id']);
        foreach (Database::select("SELECT provider_id FROM dispatch_offers WHERE order_id = :o AND status IN ('pending','countered')", ['o' => $order['id']]) as $p) {
            RealtimeService::push((int) $p['provider_id'], 'offer_updated', (int) $order['id'], ['order_id' => (int) $order['id'], 'offered_price' => $amount]);
        }
        // Le prix a changé : on peut solliciter de nouveaux prestataires
        if (!DispatchService::hasPendingOffers((int) $order['id'])) {
            DispatchService::sendRound($order);
        }
        return $order;
    }

    /** Contre-proposition d'un prestataire sollicité, valable $ttl secondes. */
    public static function providerCounter(array $order, int $providerId, int $amount, ?int $ttl = null): array
    {
        if ($order['status'] !== 'negotiating') {
            throw HttpException::conflict('Cette demande n\'accepte plus de contre-proposition.', 'invalid_transition');
        }
        $offer = Database::first("SELECT * FROM dispatch_offers WHERE order_id = :o AND provider_id = :p AND status IN ('pending','countered')", ['o' => $order['id'], 'p' => $providerId]);
        if (!$offer) {
            throw HttpException::forbidden('Cette demande ne vous a pas été proposée.');
        }
        self::assertAmount($order, $amount);
        $count = (int) Database::value("SELECT COUNT(*) FROM fare_negotiations WHERE order_id = :o AND provider_id = :p AND proposed_by = 'provider'", ['o' => $order['id'], 'p' => $providerId]);
        if ($count >= Settings::int('negotiation.max_counters_per_provider', 3)) {
            throw HttpException::tooMany('Nombre maximal de contre-propositions atteint pour cette demande.');
        }
        $ttl = max(30, min(300, $ttl ?? Settings::int('negotiation.counter_ttl_seconds', 90)));
        $expires = date('Y-m-d H:i:s', time() + $ttl);
        $id = Database::transaction(static function () use ($order, $providerId, $amount, $expires) {
            Database::execute("UPDATE fare_negotiations SET status = 'superseded' WHERE order_id = :o AND provider_id = :p AND status = 'pending'", ['o' => $order['id'], 'p' => $providerId]);
            $id = Database::insert('fare_negotiations', ['order_id' => $order['id'], 'provider_id' => $providerId, 'proposed_by' => 'provider', 'amount' => $amount, 'expires_at' => $expires]);
            Database::execute("UPDATE dispatch_offers SET status = 'countered', expires_at = GREATEST(expires_at, :e), responded_at = NOW() WHERE order_id = :o AND provider_id = :p",
                ['e' => $expires, 'o' => $order['id'], 'p' => $providerId]);
            return $id;
        });
        NotificationService::notify((int) $order['customer_id'], 'counter_offer', 'Contre-proposition reçue',
            sprintf('Un prestataire propose %s pour %s (valable %d s).', fcfa($amount), $order['reference'], $ttl ?? 90), ['order_id' => (int) $order['id'], 'negotiation_id' => $id]);
        return Database::first('SELECT * FROM fare_negotiations WHERE id = :id', ['id' => $id]);
    }

    /** Le client accepte une contre-proposition : attribution au prix convenu. */
    public static function customerAccept(array $order, int $negotiationId): array
    {
        $neg = Database::first("SELECT * FROM fare_negotiations WHERE id = :id AND order_id = :o AND proposed_by = 'provider'", ['id' => $negotiationId, 'o' => $order['id']]);
        if (!$neg || $neg['status'] !== 'pending') {
            throw HttpException::conflict('Cette contre-proposition n\'est plus disponible.', 'negotiation_unavailable');
        }
        if ($neg['expires_at'] < date('Y-m-d H:i:s')) {
            Database::execute("UPDATE fare_negotiations SET status = 'expired' WHERE id = :id", ['id' => $negotiationId]);
            throw HttpException::conflict('Cette contre-proposition a expiré.', 'negotiation_expired');
        }
        $assigned = DispatchService::assign((int) $order['id'], (int) $neg['provider_id'], (int) $neg['amount'],
            'Contre-proposition acceptée par le client : ' . fcfa((int) $neg['amount']));
        Database::execute("UPDATE fare_negotiations SET status = 'accepted', responded_at = NOW() WHERE id = :id", ['id' => $negotiationId]);
        DispatchService::afterAssign($assigned);
        return $assigned;
    }

    public static function customerReject(array $order, int $negotiationId): void
    {
        $neg = Database::first("SELECT * FROM fare_negotiations WHERE id = :id AND order_id = :o AND proposed_by = 'provider' AND status = 'pending'", ['id' => $negotiationId, 'o' => $order['id']]);
        if (!$neg) {
            throw HttpException::conflict('Cette contre-proposition n\'est plus disponible.', 'negotiation_unavailable');
        }
        Database::execute("UPDATE fare_negotiations SET status = 'rejected', responded_at = NOW() WHERE id = :id", ['id' => $negotiationId]);
        Database::execute("UPDATE dispatch_offers SET status = 'refused', refusal_reason = 'Contre-proposition refusée par le client' WHERE order_id = :o AND provider_id = :p AND status = 'countered'",
            ['o' => $order['id'], 'p' => $neg['provider_id']]);
        RealtimeService::push((int) $neg['provider_id'], 'counter_rejected', (int) $order['id'], ['order_id' => (int) $order['id']]);
    }

    public static function forOrder(int $orderId): array
    {
        return Database::select(
            "SELECT f.id, f.provider_id, f.proposed_by, f.amount, f.status, f.expires_at, f.created_at,
                    u.full_name, p.rating_avg, p.rating_count, v.category AS vehicle_category, v.make, v.model, v.color,
                    d.distance_m
               FROM fare_negotiations f
               LEFT JOIN users u ON u.id = f.provider_id
               LEFT JOIN provider_profiles p ON p.user_id = f.provider_id
               LEFT JOIN vehicles v ON v.provider_id = f.provider_id AND v.is_active = 1
               LEFT JOIN dispatch_offers d ON d.order_id = f.order_id AND d.provider_id = f.provider_id
              WHERE f.order_id = :o ORDER BY f.id DESC",
            ['o' => $orderId]
        );
    }
}
