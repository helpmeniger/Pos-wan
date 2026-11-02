<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Services\MikroTik\RouterService;
use RuntimeException;
use Throwable;

/**
 * Caisse et ventes. Toutes les opérations sont transactionnelles ; les tickets sont
 * verrouillés (SELECT … FOR UPDATE) pour qu'un même ticket ne soit jamais vendu deux fois.
 */
final class SaleService
{
    public static function openSession(int $userId): ?array
    {
        return Database::fetch("SELECT * FROM cash_sessions WHERE user_id = :u AND status = 'open' ORDER BY id DESC LIMIT 1", ['u' => $userId]);
    }

    public static function openCash(int $userId, float $amount, ?int $registerId = null): int
    {
        if (self::openSession($userId)) {
            throw new RuntimeException('Une caisse est déjà ouverte.');
        }
        $id = Database::insert('cash_sessions', ['user_id' => $userId, 'register_id' => $registerId, 'opening_amount' => max(0, $amount)]);
        AuditService::log('cash.open', 'cash_session', $id, 'Ouverture de caisse : ' . money($amount), null, null, $userId);
        return $id;
    }

    /** Montant d'espèces attendu dans la caisse. */
    public static function expectedCash(array $session): float
    {
        $cash = (float) Database::value(
            "SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN sales s ON s.id = p.sale_id JOIN payment_methods pm ON pm.id = p.payment_method_id
              WHERE s.cash_session_id = :cs AND pm.type = 'cash' AND p.status = 'confirmed'",
            ['cs' => $session['id']]
        );
        $refunds = (float) Database::value(
            "SELECT COALESCE(SUM(r.amount),0) FROM refunds r JOIN sales s ON s.id = r.sale_id JOIN payment_methods pm ON pm.id = s.payment_method_id
              WHERE s.cash_session_id = :cs AND pm.type = 'cash' AND r.status = 'completed'",
            ['cs' => $session['id']]
        );
        return (float) $session['opening_amount'] + $cash - $refunds;
    }

    public static function closeCash(array $session, float $declared, string $notes, int $userId): array
    {
        $expected = self::expectedCash($session);
        $diff = round($declared - $expected, 2);
        Database::update('cash_sessions', [
            'status' => 'closed', 'closed_at' => date('Y-m-d H:i:s'), 'closed_by' => $userId,
            'expected_amount' => $expected, 'declared_amount' => $declared, 'difference' => $diff, 'notes' => $notes ?: null,
        ], 'id = :id', ['id' => $session['id']]);
        AuditService::log('cash.close', 'cash_session', (int) $session['id'], 'Clôture de caisse', null, compact('expected', 'declared', 'diff'), $userId);
        $threshold = NotificationService::threshold('cash_gap', 0);
        if ($threshold !== null && abs($diff) > $threshold) {
            NotificationService::notify('cash_gap', 'Écart de caisse', sprintf('Session #%d : écart de %s (attendu %s, déclaré %s).', $session['id'], money($diff), money($expected), money($declared)),
                'danger', '/pos/sessions', 'sales.view', 'cash_gap:' . $session['id']);
        }
        return compact('expected', 'declared', 'diff');
    }

    public static function reference(string $prefix = 'V'): string
    {
        return $prefix . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    /**
     * Vente de tickets en caisse.
     * @param array{offer_id:int,router_id:int,quantity:int,payment_method_id:int,discount?:float,customer_name?:string,
     *              customer_phone?:string,payment_reference?:string,idempotency_key?:string,notes?:string} $d
     */
    public static function sell(array $d, int $cashierId, bool $canDiscount = false): int
    {
        $qty = (int) $d['quantity'];
        if ($qty < 1 || $qty > 100) {
            throw new RuntimeException('Quantité invalide (1 à 100).');
        }
        if (!empty($d['idempotency_key'])) {
            $existing = Database::value('SELECT id FROM sales WHERE idempotency_key = :k', ['k' => $d['idempotency_key']]);
            if ($existing) {
                return (int) $existing; // Double soumission : on renvoie la vente déjà créée.
            }
        }
        $pm = Database::fetch('SELECT * FROM payment_methods WHERE id = :id AND is_active = 1 AND available_pos = 1', ['id' => (int) $d['payment_method_id']]);
        if (!$pm) {
            throw new RuntimeException('Moyen de paiement indisponible.');
        }
        $ref = trim((string) ($d['payment_reference'] ?? ''));
        if ($pm['type'] !== 'cash' && $ref === '') {
            throw new RuntimeException('Saisissez la référence de la transaction ' . $pm['name'] . ' (reçue par SMS).');
        }
        $session = self::openSession($cashierId);
        if (!$session && setting('sales.require_open_cash_session', true)) {
            throw new RuntimeException('Ouvrez la caisse avant de vendre.');
        }
        $offer = Database::fetch('SELECT * FROM offers WHERE id = :id', ['id' => (int) $d['offer_id']]);
        if (!$offer) {
            throw new RuntimeException('Offre introuvable.');
        }
        $subtotal = (float) $offer['price'] * $qty;
        $discount = $canDiscount ? max(0.0, (float) ($d['discount'] ?? 0)) : 0.0;
        $maxPct = (float) setting('sales.max_discount_percent', 10);
        if ($discount > $subtotal * $maxPct / 100) {
            throw new RuntimeException("Remise supérieure au maximum autorisé ({$maxPct} %).");
        }
        $total = $subtotal - $discount;

        return Database::transaction(static function () use ($d, $qty, $pm, $ref, $session, $offer, $subtotal, $discount, $total, $cashierId) {
            $tickets = Database::fetchAll(
                "SELECT id, price FROM tickets WHERE router_id = :r AND offer_id = :o AND status = 'available' AND on_router = 1 AND reseller_id IS NULL
                  ORDER BY id LIMIT {$qty} FOR UPDATE",
                ['r' => (int) $d['router_id'], 'o' => (int) $offer['id']]
            );
            if (count($tickets) < $qty) {
                throw new RuntimeException('Stock insuffisant : ' . count($tickets) . ' ticket(s) disponible(s) sur ce routeur. Générez des tickets.');
            }
            $saleId = Database::insert('sales', [
                'reference' => self::reference(), 'channel' => 'physical', 'cash_session_id' => $session['id'] ?? null,
                'cashier_id' => $cashierId, 'router_id' => (int) $d['router_id'],
                'customer_name' => $d['customer_name'] ?: null, 'customer_phone' => $d['customer_phone'] ?: null,
                'subtotal' => $subtotal, 'discount' => $discount, 'total' => $total,
                'payment_method_id' => $pm['id'], 'payment_status' => 'paid', 'status' => 'completed',
                'idempotency_key' => $d['idempotency_key'] ?: null, 'notes' => $d['notes'] ?? null,
            ]);
            $perTicketDiscount = $qty ? $discount / $qty : 0;
            foreach ($tickets as $t) {
                Database::insert('sale_items', [
                    'sale_id' => $saleId, 'ticket_id' => $t['id'], 'offer_id' => $offer['id'],
                    'unit_price' => $offer['price'], 'discount' => $perTicketDiscount, 'total' => (float) $offer['price'] - $perTicketDiscount,
                ]);
            }
            $ids = implode(',', array_map(static fn ($t) => (int) $t['id'], $tickets));
            Database::query("UPDATE tickets SET status = 'sold', sale_id = :s, sold_at = NOW(), customer_phone = :p, version = version + 1 WHERE id IN ({$ids})",
                ['s' => $saleId, 'p' => $d['customer_phone'] ?: null]);
            Database::insert('payments', [
                'sale_id' => $saleId, 'payment_method_id' => $pm['id'], 'provider' => $pm['provider'] ?? $pm['code'],
                'provider_reference' => $pm['type'] === 'cash' ? null : $ref, 'amount' => $total, 'phone' => $d['customer_phone'] ?: null,
                'status' => 'confirmed', 'confirmed_at' => date('Y-m-d H:i:s'), 'confirmed_via' => $pm['type'] === 'cash' ? 'cash' : 'manual', 'created_by' => $cashierId,
            ]);
            AuditService::log('sale.create', 'sale', $saleId, "Vente de {$qty} ticket(s) {$offer['name']} — " . money($total), null, null, $cashierId);
            return $saleId;
        });
    }

    /** Annulation : uniquement si aucun ticket n'a été utilisé. Les tickets redeviennent disponibles. */
    public static function cancel(int $saleId, string $reason, int $userId): void
    {
        Database::transaction(static function () use ($saleId, $reason, $userId) {
            $sale = Database::fetch('SELECT * FROM sales WHERE id = :id FOR UPDATE', ['id' => $saleId]);
            if (!$sale || $sale['status'] !== 'completed') {
                throw new RuntimeException('Seule une vente terminée peut être annulée.');
            }
            $used = (int) Database::value("SELECT COUNT(*) FROM tickets t JOIN sale_items si ON si.ticket_id = t.id WHERE si.sale_id = :s AND (t.first_used_at IS NOT NULL OR t.status NOT IN ('sold'))", ['s' => $saleId]);
            if ($used > 0) {
                throw new RuntimeException("Annulation impossible : {$used} ticket(s) déjà utilisé(s). Utilisez le remboursement.");
            }
            // Achat en gros annulé : les tickets reviennent au stock de l'opérateur.
            $releaseReseller = $sale['channel'] === 'reseller' && str_starts_with($sale['reference'], 'G') ? ', t.reseller_id = NULL' : '';
            Database::query("UPDATE tickets t JOIN sale_items si ON si.ticket_id = t.id SET t.status = 'available', t.sale_id = NULL, t.sold_at = NULL, t.customer_phone = NULL{$releaseReseller} WHERE si.sale_id = :s", ['s' => $saleId]);
            Database::query('DELETE FROM sale_items WHERE sale_id = :s', ['s' => $saleId]);
            Database::update('sales', ['status' => 'cancelled', 'payment_status' => 'refunded', 'cancelled_by' => $userId, 'cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => $reason], 'id = :id', ['id' => $saleId]);
            Database::query("UPDATE payments SET status = 'cancelled' WHERE sale_id = :s", ['s' => $saleId]);
            if ($sale['reseller_id']) {
                ResellerService::reverseSale($sale, $userId);
            }
            AuditService::log('sale.cancel', 'sale', $saleId, "Annulation de la vente {$sale['reference']} : {$reason}", null, null, $userId);
        });
    }

    /** Remboursement : les tickets sont annulés et désactivés sur le routeur. */
    public static function refund(int $saleId, float $amount, string $reason, int $userId): void
    {
        $tickets = [];
        Database::transaction(static function () use ($saleId, $amount, $reason, $userId, &$tickets) {
            $sale = Database::fetch('SELECT * FROM sales WHERE id = :id FOR UPDATE', ['id' => $saleId]);
            if (!$sale || $sale['status'] !== 'completed') {
                throw new RuntimeException('Vente non remboursable.');
            }
            $already = (float) Database::value("SELECT COALESCE(SUM(amount),0) FROM refunds WHERE sale_id = :s AND status = 'completed'", ['s' => $saleId]);
            if ($amount <= 0 || $amount + $already > (float) $sale['total'] + 0.001) {
                throw new RuntimeException('Montant de remboursement invalide.');
            }
            Database::insert('refunds', ['sale_id' => $saleId, 'amount' => $amount, 'reason' => $reason, 'status' => 'completed',
                'requested_by' => $userId, 'approved_by' => $userId, 'approved_at' => date('Y-m-d H:i:s')]);
            $full = abs($amount + $already - (float) $sale['total']) < 0.01;
            Database::update('sales', ['payment_status' => $full ? 'refunded' : 'partially_refunded', 'status' => $full ? 'refunded' : 'completed'], 'id = :id', ['id' => $saleId]);
            if ($full) {
                $tickets = Database::fetchAll('SELECT t.* FROM tickets t JOIN sale_items si ON si.ticket_id = t.id WHERE si.sale_id = :s', ['s' => $saleId]);
                Database::query("UPDATE tickets t JOIN sale_items si ON si.ticket_id = t.id SET t.status = 'cancelled' WHERE si.sale_id = :s", ['s' => $saleId]);
            }
            AuditService::log('sale.refund', 'sale', $saleId, "Remboursement de " . money($amount) . " sur {$sale['reference']} : {$reason}", null, null, $userId);
        });
        foreach ($tickets as $t) {
            if ($t['on_router']) {
                try {
                    RouterService::setUserDisabled(RouterService::find((int) $t['router_id']), $t['username'], true);
                } catch (Throwable) {
                }
            }
        }
    }

    public static function saleTickets(int $saleId): array
    {
        return Database::fetchAll(
            'SELECT t.*, o.name AS offer_name, o.validity_value, o.validity_unit, o.time_limit_seconds AS o_time, o.data_limit_bytes AS o_data, r.name AS router_name, r.wifi_ssid, r.hotspot_dns_name
               FROM sale_items si JOIN tickets t ON t.id = si.ticket_id JOIN offers o ON o.id = t.offer_id JOIN routers r ON r.id = t.router_id
              WHERE si.sale_id = :s ORDER BY t.id', ['s' => $saleId]);
    }
}
