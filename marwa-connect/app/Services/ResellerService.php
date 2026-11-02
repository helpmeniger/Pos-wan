<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

/**
 * Revendeurs : grand livre du solde, attribution de stock (gros ou commission), ventes.
 *
 * Convention du solde : positif = crédit du revendeur auprès de l'opérateur ;
 * négatif = somme due par le revendeur.
 */
final class ResellerService
{
    public static function forUser(int $userId): ?array
    {
        return Database::fetch('SELECT * FROM resellers WHERE user_id = :u', ['u' => $userId]);
    }

    /** Écrit un mouvement et met à jour le solde (dans la transaction courante). */
    public static function ledger(int $resellerId, string $type, float $amount, string $description, ?int $userId, ?int $saleId = null, ?string $reference = null): float
    {
        $r = Database::fetch('SELECT balance FROM resellers WHERE id = :id FOR UPDATE', ['id' => $resellerId]);
        $balance = round((float) $r['balance'] + $amount, 2);
        Database::update('resellers', ['balance' => $balance], 'id = :id', ['id' => $resellerId]);
        Database::insert('reseller_transactions', [
            'reseller_id' => $resellerId, 'type' => $type, 'amount' => $amount, 'balance_after' => $balance,
            'reference' => $reference, 'sale_id' => $saleId, 'description' => $description, 'created_by' => $userId,
        ]);
        return $balance;
    }

    public static function topup(int $resellerId, float $amount, string $note, int $userId, string $type = 'topup'): float
    {
        if ($amount == 0.0) {
            throw new RuntimeException('Montant invalide.');
        }
        return Database::transaction(static function () use ($resellerId, $amount, $note, $userId, $type) {
            $b = self::ledger($resellerId, $type, $amount, $note ?: ($amount > 0 ? 'Réapprovisionnement' : 'Ajustement'), $userId, null, SaleService::reference('R'));
            AuditService::log('reseller.' . $type, 'reseller', $resellerId, ucfirst($type) . ' ' . money($amount), null, ['balance' => $b], $userId);
            return $b;
        });
    }

    /**
     * Attribue un stock de tickets au revendeur.
     * Modèle « gros » : les tickets sont achetés (vente canal revendeur, débit du solde).
     * Modèle « commission » : dépôt-vente, aucun débit tant qu'ils ne sont pas vendus.
     */
    public static function assignStock(int $resellerId, int $offerId, int $routerId, int $qty, int $userId): array
    {
        return Database::transaction(static function () use ($resellerId, $offerId, $routerId, $qty, $userId) {
            $res = Database::fetch('SELECT * FROM resellers WHERE id = :id FOR UPDATE', ['id' => $resellerId]);
            if (!$res || $res['status'] !== 'active') {
                throw new RuntimeException('Revendeur introuvable ou suspendu.');
            }
            if (!Database::value('SELECT 1 FROM reseller_routers WHERE reseller_id = :r AND router_id = :ro', ['r' => $resellerId, 'ro' => $routerId])) {
                throw new RuntimeException("Ce routeur n'est pas affecté à ce revendeur.");
            }
            $offer = Database::fetch('SELECT * FROM offers WHERE id = :id', ['id' => $offerId]);
            $tickets = Database::fetchAll(
                "SELECT id FROM tickets WHERE router_id = :r AND offer_id = :o AND status = 'available' AND on_router = 1 AND reseller_id IS NULL ORDER BY id LIMIT {$qty} FOR UPDATE",
                ['r' => $routerId, 'o' => $offerId]
            );
            if (count($tickets) < $qty) {
                throw new RuntimeException('Stock insuffisant : ' . count($tickets) . ' ticket(s) disponible(s).');
            }
            $ids = implode(',', array_map(static fn ($t) => (int) $t['id'], $tickets));
            $saleId = null;
            $cost = 0.0;
            if ($res['business_model'] === 'wholesale') {
                $unit = round((float) $offer['price'] * (1 - (float) $res['wholesale_discount'] / 100), 2);
                $cost = $unit * $qty;
                if ((float) $res['balance'] - $cost < -(float) $res['credit_limit']) {
                    throw new RuntimeException('Solde insuffisant : ' . money($res['balance']) . ' (plafond de crédit ' . money($res['credit_limit']) . ', coût ' . money($cost) . ').');
                }
                $saleId = Database::insert('sales', [
                    'reference' => SaleService::reference('G'), 'channel' => 'reseller', 'cashier_id' => $userId, 'reseller_id' => $resellerId,
                    'router_id' => $routerId, 'subtotal' => (float) $offer['price'] * $qty, 'discount' => (float) $offer['price'] * $qty - $cost,
                    'total' => $cost, 'payment_method_id' => Database::value("SELECT id FROM payment_methods WHERE code = 'reseller_balance'"),
                    'payment_status' => 'paid', 'status' => 'completed', 'notes' => 'Achat en gros revendeur',
                ]);
                foreach ($tickets as $t) {
                    Database::insert('sale_items', ['sale_id' => $saleId, 'ticket_id' => $t['id'], 'offer_id' => $offerId, 'unit_price' => $offer['price'], 'discount' => (float) $offer['price'] - $unit, 'total' => $unit]);
                }
                Database::query("UPDATE tickets SET reseller_id = :r, status = 'sold', sale_id = :s, sold_at = NOW() WHERE id IN ({$ids})", ['r' => $resellerId, 's' => $saleId]);
                self::ledger($resellerId, 'purchase', -$cost, "Achat de {$qty} × {$offer['name']}", $userId, $saleId);
            } else {
                Database::query("UPDATE tickets SET reseller_id = :r WHERE id IN ({$ids})", ['r' => $resellerId]);
            }
            AuditService::log('reseller.stock', 'reseller', $resellerId, "Attribution de {$qty} ticket(s) {$offer['name']}" . ($cost ? ' — ' . money($cost) : ' (dépôt-vente)'), null, null, $userId);
            return ['count' => $qty, 'cost' => $cost, 'sale_id' => $saleId];
        });
    }

    /** Reprise du stock non vendu (modèle commission). */
    public static function recallStock(int $resellerId, int $offerId, int $qty, int $userId): int
    {
        $n = Database::query("UPDATE tickets SET reseller_id = NULL WHERE reseller_id = :r AND offer_id = :o AND status = 'available' ORDER BY id DESC LIMIT {$qty}",
            ['r' => $resellerId, 'o' => $offerId])->rowCount();
        AuditService::log('reseller.recall', 'reseller', $resellerId, "Reprise de {$n} ticket(s)", null, null, $userId);
        return $n;
    }

    /**
     * Le revendeur vend un ticket à un client final.
     * Commission : vente enregistrée, le revendeur doit (prix − commission).
     * Gros : le ticket lui appartient déjà ; on note simplement le client.
     */
    public static function sellTicket(array $reseller, int $ticketId, ?string $phone, int $userId): array
    {
        return Database::transaction(static function () use ($reseller, $ticketId, $phone, $userId) {
            $t = Database::fetch('SELECT t.*, o.name AS offer_name, o.price AS offer_price FROM tickets t JOIN offers o ON o.id = t.offer_id WHERE t.id = :id AND t.reseller_id = :r FOR UPDATE',
                ['id' => $ticketId, 'r' => $reseller['id']]);
            if (!$t) {
                throw new RuntimeException('Ticket introuvable dans votre stock.');
            }
            if ($reseller['business_model'] === 'wholesale') {
                if ($t['status'] !== 'sold' || $t['customer_phone']) {
                    throw new RuntimeException('Ticket déjà remis à un client.');
                }
                Database::update('tickets', ['customer_phone' => $phone ?: '—'], 'id = :id', ['id' => $ticketId]);
                return $t;
            }
            if ($t['status'] !== 'available') {
                throw new RuntimeException('Ce ticket n\'est plus disponible.');
            }
            $price = (float) $t['offer_price'];
            $commission = round($price * (float) $reseller['commission_rate'] / 100, 2);
            $saleId = Database::insert('sales', [
                'reference' => SaleService::reference('RV'), 'channel' => 'reseller', 'cashier_id' => $userId, 'reseller_id' => $reseller['id'],
                'router_id' => $t['router_id'], 'customer_phone' => $phone ?: null, 'subtotal' => $price, 'total' => $price,
                'commission_amount' => $commission, 'payment_method_id' => Database::value("SELECT id FROM payment_methods WHERE code = 'cash'"),
                'payment_status' => 'paid', 'status' => 'completed', 'notes' => 'Vente revendeur (commission)',
            ]);
            Database::insert('sale_items', ['sale_id' => $saleId, 'ticket_id' => $ticketId, 'offer_id' => $t['offer_id'], 'unit_price' => $price, 'total' => $price]);
            Database::update('tickets', ['status' => 'sold', 'sale_id' => $saleId, 'sold_at' => date('Y-m-d H:i:s'), 'customer_phone' => $phone ?: null], 'id = :id', ['id' => $ticketId]);
            self::ledger((int) $reseller['id'], 'sale', -$price, "Vente {$t['username']} ({$t['offer_name']})", $userId, $saleId);
            if ($commission > 0) {
                self::ledger((int) $reseller['id'], 'commission', $commission, "Commission {$reseller['commission_rate']} % sur {$t['username']}", $userId, $saleId);
            }
            return $t;
        });
    }

    /** Annulation d'une vente revendeur : on rétablit le solde. */
    public static function reverseSale(array $sale, int $userId): void
    {
        $movements = Database::fetchAll('SELECT * FROM reseller_transactions WHERE sale_id = :s', ['s' => $sale['id']]);
        foreach ($movements as $m) {
            self::ledger((int) $sale['reseller_id'], 'refund', -(float) $m['amount'], 'Annulation ' . $sale['reference'], $userId, (int) $sale['id']);
        }
    }

    public static function stats(int $resellerId): array
    {
        return [
            'stock' => Database::fetchAll(
                "SELECT o.id AS offer_id, o.name, o.price, SUM(t.status = 'available') AS available,
                        SUM(t.status = 'sold' AND t.customer_phone IS NULL) AS unsold_wholesale, COUNT(*) AS total
                   FROM tickets t JOIN offers o ON o.id = t.offer_id WHERE t.reseller_id = :r GROUP BY o.id ORDER BY o.price", ['r' => $resellerId]),
            'sales_month' => (float) Database::value("SELECT COALESCE(SUM(total),0) FROM sales WHERE reseller_id = :r AND status = 'completed' AND created_at >= :d", ['r' => $resellerId, 'd' => date('Y-m-01')]),
            'sales_count' => (int) Database::value("SELECT COUNT(*) FROM sales WHERE reseller_id = :r AND status = 'completed'", ['r' => $resellerId]),
            'commission_month' => (float) Database::value("SELECT COALESCE(SUM(amount),0) FROM reseller_transactions WHERE reseller_id = :r AND type = 'commission' AND created_at >= :d", ['r' => $resellerId, 'd' => date('Y-m-01')]),
        ];
    }
}
