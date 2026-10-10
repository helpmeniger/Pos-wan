<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * Module restaurant : commandes en salle, à emporter et en livraison,
 * envoi en cuisine (tickets par poste : cuisine, bar…), annulations tracées,
 * transferts / fusions de tables, addition et paiement (total ou partiel).
 *
 * Cycle d'une ligne : new (saisie) → sent (envoyée) → preparing → ready → served,
 * ou cancelled. Le paiement passe par SaleService (stock, caisse, crédit, ticket)
 * avec les prix figés au moment de la commande.
 */
final class RestaurantService
{
    public const COURSES = [0 => 'Boisson', 1 => 'Entrée', 2 => 'Plat', 3 => 'Dessert'];
    public const TYPES = ['dine_in' => 'Sur place', 'takeaway' => 'À emporter', 'delivery' => 'Livraison'];

    /** Postes de préparation configurés sur les catégories (+ cuisine par défaut). */
    public static function stations(): array
    {
        $s = array_column(DB::all("SELECT DISTINCT kitchen_station FROM categories WHERE kitchen_station IS NOT NULL AND kitchen_station <> ''"), 'kitchen_station');
        return array_values(array_unique(array_merge(['cuisine'], $s)));
    }

    private static function lockOrder(int $orderId, bool $mustBeOpen = true): array
    {
        $o = DB::one('SELECT * FROM restaurant_orders WHERE id = ? FOR UPDATE', [$orderId]);
        if (!$o) {
            throw new BusinessException('Commande introuvable.');
        }
        if ($mustBeOpen && !in_array($o['status'], ['open', 'billed'], true)) {
            throw new BusinessException('Cette commande est déjà clôturée.');
        }
        return $o;
    }

    /** Ouvre une commande (table libre obligatoire pour « sur place »). */
    public static function open(?int $tableId, string $type, int $covers, array $customer = []): int
    {
        if (!isset(self::TYPES[$type])) {
            throw new BusinessException('Type de commande invalide.');
        }
        if ($type === 'dine_in' && !$tableId) {
            throw new BusinessException('Choisissez une table.');
        }
        if ($type === 'delivery' && trim((string) ($customer['phone'] ?? '')) === '') {
            throw new BusinessException('Le téléphone du client est obligatoire pour une livraison.');
        }
        return DB::transaction(function () use ($tableId, $type, $covers, $customer) {
            $user = Auth::user();
            $storeId = (int) ($user['store_id'] ?: DB::value('SELECT id FROM stores ORDER BY id LIMIT 1'));
            if ($tableId) {
                $t = DB::one('SELECT t.*, a.store_id FROM restaurant_tables t JOIN restaurant_areas a ON a.id = t.area_id WHERE t.id = ? AND t.active = 1 FOR UPDATE', [$tableId]);
                if (!$t) {
                    throw new BusinessException('Table introuvable.');
                }
                if (DB::value("SELECT id FROM restaurant_orders WHERE table_id = ? AND status IN ('open','billed')", [$tableId])) {
                    throw new BusinessException('Cette table est déjà occupée.');
                }
                $storeId = (int) ($t['store_id'] ?: $storeId);
            }
            $id = DB::insert('restaurant_orders', [
                'number' => Sequence::next('restaurant_order'), 'store_id' => $storeId, 'table_id' => $type === 'dine_in' ? $tableId : null,
                'type' => $type, 'covers' => max(1, min(200, $covers)), 'waiter_id' => (int) $user['id'],
                'customer_id' => !empty($customer['id']) ? (int) $customer['id'] : null,
                'customer_name' => mb_substr(trim((string) ($customer['name'] ?? '')), 0, 150) ?: null,
                'customer_phone' => mb_substr(trim((string) ($customer['phone'] ?? '')), 0, 50) ?: null,
                'delivery_address' => mb_substr(trim((string) ($customer['address'] ?? '')), 0, 255) ?: null,
            ]);
            Audit::log('restaurant.open', 'restaurant_order', $id, ['table' => $tableId, 'type' => $type]);
            return $id;
        });
    }

    public static function addItem(int $orderId, int $productId, float $qty, ?string $note = null, ?int $course = null): int
    {
        if ($qty <= 0 || $qty > 1000) {
            throw new BusinessException('Quantité invalide.');
        }
        return DB::transaction(function () use ($orderId, $productId, $qty, $note, $course) {
            $o = self::lockOrder($orderId);
            $p = DB::one("SELECT p.*, c.kitchen_station, pc.kitchen_station AS parent_station, u.allow_decimal FROM products p LEFT JOIN categories c ON c.id = p.category_id
                          LEFT JOIN categories pc ON pc.id = c.parent_id LEFT JOIN units u ON u.id = p.unit_id WHERE p.id = ? AND p.status = 'active' AND p.sellable = 1", [$productId]);
            if (!$p) {
                throw new BusinessException('Article introuvable ou non vendable.');
            }
            if ((int) $p['sold_by_weight'] !== 1 && (int) $p['allow_decimal'] !== 1 && abs($qty - round($qty)) > 0.0001) {
                throw new BusinessException('Quantité entière obligatoire pour « ' . $p['name'] . ' ».');
            }
            $station = $p['kitchen_station'] ?: ($p['parent_station'] ?: null);
            $note = $note !== null ? mb_substr(trim($note), 0, 255) : null;
            // Regroupement avec une ligne identique non encore envoyée
            $same = DB::one("SELECT id FROM restaurant_order_items WHERE order_id = ? AND product_id = ? AND status = 'new' AND COALESCE(note,'') = ? FOR UPDATE", [$orderId, $productId, (string) $note]);
            if ($same) {
                DB::run('UPDATE restaurant_order_items SET quantity = quantity + ? WHERE id = ?', [$qty, $same['id']]);
                $id = (int) $same['id'];
            } else {
                $id = DB::insert('restaurant_order_items', [
                    'order_id' => $orderId, 'product_id' => $productId, 'name' => mb_substr($p['name'], 0, 200), 'quantity' => $qty,
                    'unit_price' => SaleService::listPrice($p, $qty, false), 'note' => $note ?: null,
                    'course' => $course !== null && isset(self::COURSES[$course]) ? $course : ($station === 'bar' || $station === null ? 0 : 2),
                    'station' => $station, 'created_by' => Auth::id(),
                ]);
            }
            if ($o['status'] === 'billed') {
                DB::run("UPDATE restaurant_orders SET status = 'open' WHERE id = ?", [$orderId]);
            }
            return $id;
        });
    }

    /** Modifie une ligne non encore envoyée (quantité, note, service). Quantité 0 = suppression. */
    public static function updateItem(int $itemId, float $qty, ?string $note, ?int $course): void
    {
        DB::transaction(function () use ($itemId, $qty, $note, $course) {
            $it = DB::one('SELECT * FROM restaurant_order_items WHERE id = ? FOR UPDATE', [$itemId]);
            if (!$it) {
                throw new BusinessException('Ligne introuvable.');
            }
            self::lockOrder((int) $it['order_id']);
            if ($it['status'] !== 'new') {
                throw new BusinessException('Ligne déjà envoyée en cuisine : utilisez « Annuler » (tracé).');
            }
            if ($qty <= 0) {
                DB::run('DELETE FROM restaurant_order_items WHERE id = ?', [$itemId]);
                return;
            }
            DB::update('restaurant_order_items', [
                'quantity' => min(1000, $qty), 'note' => $note !== null ? (mb_substr(trim($note), 0, 255) ?: null) : $it['note'],
                'course' => $course !== null && isset(self::COURSES[$course]) ? $course : $it['course'],
            ], 'id = ?', [$itemId]);
        });
    }

    /**
     * Envoi en cuisine : un ticket par poste (cuisine, bar…) pour les lignes nouvelles.
     * Les articles sans poste (ex. boissons en bouteille) passent directement à « servi ».
     * @return int[] identifiants des tickets créés
     */
    public static function send(int $orderId): array
    {
        return DB::transaction(function () use ($orderId) {
            $o = self::lockOrder($orderId);
            $items = DB::all("SELECT * FROM restaurant_order_items WHERE order_id = ? AND status = 'new' FOR UPDATE", [$orderId]);
            if (!$items) {
                throw new BusinessException('Aucune nouvelle ligne à envoyer.');
            }
            $byStation = [];
            foreach ($items as $it) {
                $byStation[$it['station'] ?: ''][] = $it;
            }
            $tickets = [];
            $now = date('Y-m-d H:i:s');
            foreach ($byStation as $station => $list) {
                if ($station === '') {
                    DB::run("UPDATE restaurant_order_items SET status = 'served', sent_at = ? WHERE id IN (" . implode(',', array_map('intval', array_column($list, 'id'))) . ')', [$now]);
                    continue;
                }
                $tid = DB::insert('kitchen_tickets', ['number' => Sequence::next('kitchen_ticket'), 'order_id' => $orderId, 'station' => $station, 'user_id' => Auth::id()]);
                DB::run("UPDATE restaurant_order_items SET status = 'sent', sent_at = ?, kitchen_ticket_id = ? WHERE id IN (" . implode(',', array_map('intval', array_column($list, 'id'))) . ')', [$now, $tid]);
                $tickets[] = $tid;
            }
            if ($o['status'] === 'billed') {
                DB::run("UPDATE restaurant_orders SET status = 'open' WHERE id = ?", [$orderId]);
            }
            Audit::log('restaurant.send', 'restaurant_order', $orderId, ['tickets' => $tickets, 'lines' => count($items)]);
            return $tickets;
        });
    }

    /**
     * Annulation d'une ligne. Ligne non envoyée : simple retrait. Ligne envoyée :
     * permission restaurant.cancel_item (ou superviseur), motif, ticket d'annulation
     * pour la cuisine et, si le plat était déjà préparé, déclaration de perte.
     * @return int|null ticket d'annulation créé
     */
    public static function cancelItem(int $itemId, float $qty, string $reason, bool $waste, ?int $authorizedBy = null): ?int
    {
        return DB::transaction(function () use ($itemId, $qty, $reason, $waste, $authorizedBy) {
            $it = DB::one('SELECT * FROM restaurant_order_items WHERE id = ? FOR UPDATE', [$itemId]);
            if (!$it || $it['status'] === 'cancelled') {
                throw new BusinessException('Ligne introuvable ou déjà annulée.');
            }
            $o = self::lockOrder((int) $it['order_id']);
            $available = (float) $it['quantity'] - (float) $it['paid_qty'];
            if ($qty <= 0 || $qty > $available + 0.0005) {
                throw new BusinessException('Quantité à annuler invalide (déjà payée ou supérieure à la commande).');
            }
            if ($it['status'] === 'new') {
                self::updateItem($itemId, (float) $it['quantity'] - $qty, null, null);
                return null;
            }
            if (trim($reason) === '') {
                throw new BusinessException('Le motif d\'annulation est obligatoire.');
            }
            if (!Auth::can('restaurant.cancel_item') && !$authorizedBy) {
                throw new BusinessException('AUTH_REQUIRED:Annulation d\'un plat déjà envoyé en cuisine');
            }
            // Ligne annulée séparée si annulation partielle
            if ($qty < (float) $it['quantity'] - 0.0005) {
                DB::run('UPDATE restaurant_order_items SET quantity = quantity - ? WHERE id = ?', [$qty, $itemId]);
                $cancelId = DB::insert('restaurant_order_items', array_merge(array_intersect_key($it, array_flip(['order_id', 'product_id', 'name', 'unit_price', 'note', 'course', 'station', 'kitchen_ticket_id', 'created_by', 'sent_at'])), ['quantity' => $qty, 'status' => 'cancelled']));
            } else {
                $cancelId = $itemId;
                DB::run("UPDATE restaurant_order_items SET status = 'cancelled' WHERE id = ?", [$itemId]);
            }
            DB::update('restaurant_order_items', ['cancelled_by' => $authorizedBy ?? Auth::id(), 'cancel_reason' => mb_substr($reason, 0, 255), 'wasted' => $waste ? 1 : 0], 'id = ?', [$cancelId]);
            $ticket = null;
            if ($it['station']) {
                $ticket = DB::insert('kitchen_tickets', ['number' => Sequence::next('kitchen_ticket'), 'order_id' => $o['id'], 'station' => $it['station'], 'type' => 'cancel', 'user_id' => Auth::id()]);
                DB::insert('kitchen_ticket_cancellations', ['kitchen_ticket_id' => $ticket, 'order_item_id' => $cancelId, 'quantity' => $qty]);
            }
            if ($waste) {
                self::declareWaste((int) $it['product_id'], $qty, (int) $o['store_id'], $o['number'] . ' : ' . $it['name'] . ' — ' . $reason);
            }
            Audit::log('restaurant.cancel_item', 'restaurant_order', (int) $o['id'], ['item' => $it['name'], 'qty' => $qty, 'reason' => $reason, 'waste' => $waste, 'authorized_by' => $authorizedBy]);
            return $ticket;
        });
    }

    /** Perte : sortie des ingrédients (fiche technique) ou de l'article lui-même. */
    private static function declareWaste(int $productId, float $qty, int $storeId, string $reason): void
    {
        $wh = (int) (DB::value('SELECT warehouse_id FROM cash_registers WHERE store_id = ? AND active = 1 ORDER BY id LIMIT 1', [$storeId])
            ?: DB::value('SELECT id FROM warehouses WHERE active = 1 ORDER BY id LIMIT 1'));
        $recipe = DB::all('SELECT ingredient_id, quantity FROM product_recipes WHERE product_id = ?', [$productId]);
        if ($recipe) {
            foreach ($recipe as $r) {
                $need = round((float) $r['quantity'] * $qty, 3);
                if ($need > 0) {
                    StockService::issue((int) $r['ingredient_id'], $wh, $need, 'kitchen_waste', 'restaurant', null, $reason);
                }
            }
        } elseif ((int) DB::value('SELECT track_stock FROM products WHERE id = ?', [$productId]) === 1) {
            StockService::issue($productId, $wh, $qty, 'kitchen_waste', 'restaurant', null, $reason);
        }
    }

    /** Écran cuisine : faire avancer un ticket (pending → preparing → ready → served). */
    public static function ticketStatus(int $ticketId, string $status): void
    {
        $order = ['pending' => 0, 'preparing' => 1, 'ready' => 2, 'served' => 3];
        if (!isset($order[$status])) {
            throw new BusinessException('Statut invalide.');
        }
        DB::transaction(function () use ($ticketId, $status, $order) {
            $t = DB::one('SELECT * FROM kitchen_tickets WHERE id = ? FOR UPDATE', [$ticketId]);
            if (!$t) {
                throw new BusinessException('Ticket introuvable.');
            }
            $now = date('Y-m-d H:i:s');
            $col = ['preparing' => 'started_at', 'ready' => 'ready_at', 'served' => 'served_at'][$status] ?? null;
            $data = ['status' => $status];
            if ($col) {
                $data[$col] = $now;
            }
            // Retour arrière autorisé (« rappeler » un ticket), dates conservées
            if ($order[$status] < $order[$t['status']]) {
                $data = ['status' => $status];
            }
            DB::update('kitchen_tickets', $data, 'id = ?', [$ticketId]);
            if ($t['type'] === 'order') {
                $itemStatus = ['pending' => 'sent', 'preparing' => 'preparing', 'ready' => 'ready', 'served' => 'served'][$status];
                DB::run("UPDATE restaurant_order_items SET status = ? WHERE kitchen_ticket_id = ? AND status <> 'cancelled'", [$itemStatus, $ticketId]);
            }
        });
    }

    /** Le serveur confirme avoir servi les plats prêts de la commande. */
    public static function serveReady(int $orderId): int
    {
        return DB::transaction(function () use ($orderId) {
            self::lockOrder($orderId);
            $n = DB::run("UPDATE restaurant_order_items SET status = 'served' WHERE order_id = ? AND status = 'ready'", [$orderId])->rowCount();
            DB::run("UPDATE kitchen_tickets SET status = 'served', served_at = NOW() WHERE order_id = ? AND status = 'ready'", [$orderId]);
            return $n;
        });
    }

    public static function update(int $orderId, array $d): void
    {
        DB::transaction(function () use ($orderId, $d) {
            self::lockOrder($orderId);
            $data = [];
            if (isset($d['covers'])) {
                $data['covers'] = max(1, min(200, (int) $d['covers']));
            }
            foreach (['note' => 255, 'customer_name' => 150, 'customer_phone' => 50, 'delivery_address' => 255] as $k => $max) {
                if (array_key_exists($k, $d)) {
                    $data[$k] = mb_substr(trim((string) $d[$k]), 0, $max) ?: null;
                }
            }
            if (array_key_exists('customer_id', $d)) {
                $cid = (int) $d['customer_id'];
                if ($cid && !DB::value('SELECT 1 FROM customers WHERE id = ? AND active = 1', [$cid])) {
                    throw new BusinessException('Client introuvable.');
                }
                $data['customer_id'] = $cid ?: null;
            }
            if ($data) {
                DB::update('restaurant_orders', $data, 'id = ?', [$orderId]);
            }
        });
    }

    public static function transfer(int $orderId, int $tableId): void
    {
        DB::transaction(function () use ($orderId, $tableId) {
            $o = self::lockOrder($orderId);
            if (!DB::value('SELECT 1 FROM restaurant_tables WHERE id = ? AND active = 1', [$tableId])) {
                throw new BusinessException('Table introuvable.');
            }
            if (DB::value("SELECT id FROM restaurant_orders WHERE table_id = ? AND status IN ('open','billed') AND id <> ?", [$tableId, $orderId])) {
                throw new BusinessException('La table de destination est occupée : utilisez « Fusionner ».');
            }
            DB::update('restaurant_orders', ['table_id' => $tableId, 'type' => 'dine_in'], 'id = ?', [$orderId]);
            Audit::log('restaurant.transfer', 'restaurant_order', $orderId, ['from' => $o['table_id'], 'to' => $tableId]);
        });
    }

    /** Fusion : les lignes non payées de la commande source rejoignent la cible. */
    public static function merge(int $sourceId, int $targetId): void
    {
        if ($sourceId === $targetId) {
            throw new BusinessException('Choisissez une autre commande.');
        }
        DB::transaction(function () use ($sourceId, $targetId) {
            [$a, $b] = $sourceId < $targetId ? [$sourceId, $targetId] : [$targetId, $sourceId];
            self::lockOrder($a);
            self::lockOrder($b);
            $src = DB::one('SELECT * FROM restaurant_orders WHERE id = ?', [$sourceId]);
            $dst = DB::one('SELECT * FROM restaurant_orders WHERE id = ?', [$targetId]);
            if ((float) DB::value('SELECT COALESCE(SUM(paid_qty),0) FROM restaurant_order_items WHERE order_id = ?', [$sourceId]) > 0) {
                throw new BusinessException('La commande source a déjà été payée en partie : fusion impossible.');
            }
            DB::run('UPDATE restaurant_order_items SET order_id = ? WHERE order_id = ?', [$targetId, $sourceId]);
            DB::run('UPDATE kitchen_tickets SET order_id = ? WHERE order_id = ?', [$targetId, $sourceId]);
            DB::update('restaurant_orders', ['covers' => (int) $dst['covers'] + (int) $src['covers'], 'status' => 'open'], 'id = ?', [$targetId]);
            DB::update('restaurant_orders', ['status' => 'cancelled', 'cancel_reason' => 'Fusionnée avec ' . $dst['number'], 'closed_at' => date('Y-m-d H:i:s')], 'id = ?', [$sourceId]);
            Audit::log('restaurant.merge', 'restaurant_order', $targetId, ['source' => $src['number']]);
        });
    }

    /** Addition (pré-note) : passe la commande au statut « addition demandée ». */
    public static function bill(int $orderId): void
    {
        DB::transaction(function () use ($orderId) {
            self::lockOrder($orderId);
            DB::run("UPDATE restaurant_orders SET status = 'billed', bill_count = bill_count + 1 WHERE id = ?", [$orderId]);
        });
    }

    /**
     * Paiement total ou partiel (addition séparée).
     * @param array<int, float> $quantities [order_item_id => quantité payée]
     * @param array $payment données de paiement au format SaleService (payments, customer_id, remise, superviseur, client_uuid)
     */
    public static function pay(int $orderId, array $quantities, array $payment): array
    {
        return DB::transaction(function () use ($orderId, $quantities, $payment) {
            $o = self::lockOrder($orderId);
            $lines = [];
            $selected = [];
            foreach (DB::all("SELECT * FROM restaurant_order_items WHERE order_id = ? AND status <> 'cancelled' FOR UPDATE", [$orderId]) as $it) {
                $q = round((float) ($quantities[$it['id']] ?? 0), 3);
                if ($q <= 0) {
                    continue;
                }
                $left = (float) $it['quantity'] - (float) $it['paid_qty'];
                if ($q > $left + 0.0005) {
                    throw new BusinessException('Quantité à payer supérieure au reste dû pour « ' . $it['name'] . ' ».');
                }
                $lines[] = ['product_id' => (int) $it['product_id'], 'quantity' => $q, 'unit_price' => (float) $it['unit_price']];
                $selected[(int) $it['id']] = $q;
            }
            if (!$lines) {
                throw new BusinessException('Sélectionnez au moins un article à payer.');
            }
            $label = $o['table_id'] ? 'Table ' . DB::value('SELECT name FROM restaurant_tables WHERE id = ?', [$o['table_id']]) : self::TYPES[$o['type']];
            $data = $payment;
            $data['items'] = $lines;
            $data['customer_id'] = !empty($payment['customer_id']) ? (int) $payment['customer_id'] : $o['customer_id'];
            $data['note'] = $o['number'] . ' · ' . $label . ($o['customer_name'] ? ' · ' . $o['customer_name'] : '');
            $r = SaleService::create($data, ['trusted_prices' => true, 'restaurant_order_id' => $orderId]);
            if (!$r['duplicate']) {
                foreach ($selected as $id => $q) {
                    DB::run('UPDATE restaurant_order_items SET paid_qty = paid_qty + ? WHERE id = ?', [$q, $id]);
                }
            }
            $remaining = (float) DB::value("SELECT COALESCE(SUM(quantity - paid_qty),0) FROM restaurant_order_items WHERE order_id = ? AND status <> 'cancelled'", [$orderId]);
            if ($remaining <= 0.0005) {
                DB::update('restaurant_orders', ['status' => 'paid', 'closed_at' => date('Y-m-d H:i:s')], 'id = ?', [$orderId]);
            }
            return $r + ['order_closed' => $remaining <= 0.0005];
        });
    }

    public static function cancelOrder(int $orderId, string $reason, ?int $authorizedBy = null): void
    {
        if (trim($reason) === '') {
            throw new BusinessException('Le motif est obligatoire.');
        }
        DB::transaction(function () use ($orderId, $reason, $authorizedBy) {
            self::lockOrder($orderId);
            if ((float) DB::value('SELECT COALESCE(SUM(paid_qty),0) FROM restaurant_order_items WHERE order_id = ?', [$orderId]) > 0) {
                throw new BusinessException('Commande payée en partie : annulez les lignes restantes une à une.');
            }
            foreach (DB::all("SELECT * FROM restaurant_order_items WHERE order_id = ? AND status <> 'cancelled'", [$orderId]) as $it) {
                if ($it['status'] === 'new') {
                    DB::run('DELETE FROM restaurant_order_items WHERE id = ?', [$it['id']]);
                } else {
                    self::cancelItem((int) $it['id'], (float) $it['quantity'], $reason, false, $authorizedBy);
                }
            }
            DB::update('restaurant_orders', ['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 255), 'closed_at' => date('Y-m-d H:i:s')], 'id = ?', [$orderId]);
            Audit::log('restaurant.cancel_order', 'restaurant_order', $orderId, ['reason' => $reason]);
        });
    }

    // ------------------------------------------------------------------ lecture

    public static function order(int $orderId): ?array
    {
        $o = DB::one('SELECT o.*, t.name AS table_name, a.name AS area_name, u.full_name AS waiter, c.name AS customer FROM restaurant_orders o
                LEFT JOIN restaurant_tables t ON t.id = o.table_id LEFT JOIN restaurant_areas a ON a.id = t.area_id
                JOIN users u ON u.id = o.waiter_id LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = ?', [$orderId]);
        if (!$o) {
            return null;
        }
        $o['items'] = DB::all('SELECT i.*, kt.number AS ticket_number FROM restaurant_order_items i LEFT JOIN kitchen_tickets kt ON kt.id = i.kitchen_ticket_id WHERE i.order_id = ? ORDER BY i.course, i.id', [$orderId]);
        $o['total'] = 0.0;
        $o['due'] = 0.0;
        foreach ($o['items'] as $i) {
            if ($i['status'] === 'cancelled') {
                continue;
            }
            $o['total'] += (float) $i['quantity'] * (float) $i['unit_price'];
            $o['due'] += ((float) $i['quantity'] - (float) $i['paid_qty']) * (float) $i['unit_price'];
        }
        $o['paid'] = (float) DB::value("SELECT COALESCE(SUM(total),0) FROM sales WHERE restaurant_order_id = ? AND status <> 'cancelled'", [$orderId]);
        $o['sales'] = DB::all('SELECT id, number, total, status, created_at FROM sales WHERE restaurant_order_id = ? ORDER BY id', [$orderId]);
        return $o;
    }

    /** Plan de salle : tables + commande en cours. */
    public static function floor(?int $areaId = null): array
    {
        $sql = "SELECT t.*, a.name AS area_name, o.id AS order_id, o.number, o.status AS order_status, o.covers, o.opened_at, u.full_name AS waiter,
                (SELECT COALESCE(SUM((i.quantity - i.paid_qty) * i.unit_price),0) FROM restaurant_order_items i WHERE i.order_id = o.id AND i.status <> 'cancelled') AS due,
                (SELECT COUNT(*) FROM restaurant_order_items i WHERE i.order_id = o.id AND i.status = 'ready') AS ready,
                (SELECT COUNT(*) FROM restaurant_order_items i WHERE i.order_id = o.id AND i.status = 'new') AS unsent
                FROM restaurant_tables t JOIN restaurant_areas a ON a.id = t.area_id
                LEFT JOIN restaurant_orders o ON o.table_id = t.id AND o.status IN ('open','billed')
                LEFT JOIN users u ON u.id = o.waiter_id
                WHERE t.active = 1 AND a.active = 1" . ($areaId ? ' AND t.area_id = ' . (int) $areaId : '') . ' ORDER BY a.sort_order, a.name, t.sort_order, t.name';
        return DB::all($sql);
    }

    /** Commandes sans table en cours (à emporter, livraison). */
    public static function openWithoutTable(): array
    {
        return DB::all("SELECT o.*, u.full_name AS waiter,
                (SELECT COALESCE(SUM((i.quantity - i.paid_qty) * i.unit_price),0) FROM restaurant_order_items i WHERE i.order_id = o.id AND i.status <> 'cancelled') AS due,
                (SELECT COUNT(*) FROM restaurant_order_items i WHERE i.order_id = o.id AND i.status = 'ready') AS ready
                FROM restaurant_orders o JOIN users u ON u.id = o.waiter_id WHERE o.table_id IS NULL AND o.status IN ('open','billed') ORDER BY o.id");
    }

    /** Flux de l'écran cuisine pour un poste. */
    public static function kitchenFeed(string $station): array
    {
        $tickets = DB::all("SELECT kt.*, o.number AS order_number, o.type AS order_type, o.covers, o.customer_name, t.name AS table_name, u.full_name AS waiter,
                TIMESTAMPDIFF(SECOND, kt.created_at, NOW()) AS age
                FROM kitchen_tickets kt JOIN restaurant_orders o ON o.id = kt.order_id LEFT JOIN restaurant_tables t ON t.id = o.table_id JOIN users u ON u.id = kt.user_id
                WHERE kt.station = ? AND (kt.status IN ('pending','preparing','ready') OR (kt.status = 'served' AND kt.served_at > NOW() - INTERVAL 15 MINUTE))
                ORDER BY kt.status = 'served', kt.id LIMIT 80", [$station]);
        foreach ($tickets as &$t) {
            if ($t['type'] === 'cancel') {
                $t['items'] = DB::all('SELECT i.name, c.quantity, i.note, i.cancel_reason, i.course FROM kitchen_ticket_cancellations c JOIN restaurant_order_items i ON i.id = c.order_item_id WHERE c.kitchen_ticket_id = ?', [$t['id']]);
            } else {
                $t['items'] = DB::all('SELECT i.name, i.quantity, i.note, i.status, i.course FROM restaurant_order_items i WHERE i.kitchen_ticket_id = ? ORDER BY i.course, i.id', [$t['id']]);
            }
        }
        return $tickets;
    }
}
