<?php
declare(strict_types=1);

namespace Addons\Boutique;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Request;
use App\Services\CashService;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\Sequence;

/**
 * Boutique en ligne : catalogue public, commandes des clients, traitement en caisse.
 *
 * Stock : celui de l'entrepôt de la « caisse de la boutique en ligne » (Réglages), diminué des
 * quantités déjà commandées en ligne et pas encore encaissées (réservées). Le stock réel ne
 * baisse qu'à l'encaissement : une vente normale est alors créée dans la caisse du caissier
 * (mêmes règles que la caisse : lots, coûts, mouvements de caisse, rapports).
 */
final class ShopService
{
    public const STATUSES = [
        'new' => ['Nouvelle', 'warning', 'bi-bell'],
        'confirmed' => ['Validée', 'info', 'bi-check2-circle'],
        'paid' => ['Encaissée', 'primary', 'bi-cash-coin'],
        'delivered' => ['Livrée / retirée', 'success', 'bi-bag-check'],
        'cancelled' => ['Annulée', 'secondary', 'bi-x-circle'],
    ];
    /** Statuts dont les quantités sont réservées (commandées, pas encore sorties du stock). */
    private const RESERVING = ['new', 'confirmed'];
    public const MAX_LINES = 50;

    // ------------------------------------------------------------------ réglages

    public static function setting(string $key, string $default = ''): string
    {
        $v = setting('shop_' . $key);
        return $v === null || $v === '' ? $default : (string) $v;
    }

    public static function enabled(): bool
    {
        return self::setting('enabled', '1') === '1';
    }

    public static function title(): string
    {
        return self::setting('title') ?: (string) (setting('company_name') ?: 'Notre boutique');
    }

    /** Caisse de la boutique en ligne (son entrepôt fournit le stock affiché). */
    public static function register(): ?array
    {
        $id = (int) self::setting('register_id', '0');
        $r = $id ? DB::one('SELECT * FROM cash_registers WHERE id = ? AND active = 1', [$id]) : null;
        return $r ?: DB::one('SELECT * FROM cash_registers WHERE active = 1 ORDER BY id LIMIT 1');
    }

    public static function warehouseId(): int
    {
        return (int) (self::register()['warehouse_id'] ?? DB::value('SELECT id FROM warehouses WHERE active = 1 ORDER BY id LIMIT 1'));
    }

    /** Commandes acceptées ? (option : seulement quand la caisse de la boutique est ouverte) */
    public static function isOpen(): bool
    {
        if (!self::enabled()) {
            return false;
        }
        if (self::setting('require_open', '0') !== '1') {
            return true;
        }
        $r = self::register();
        return $r && CashService::openSessionForRegister((int) $r['id']) !== null;
    }

    // ------------------------------------------------------------------ catalogue

    /** Articles visibles en ligne (actifs, vendables, hors restaurant et ordonnance). */
    private static function baseSql(): string
    {
        return "FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN categories pc ON pc.id = c.parent_id
            LEFT JOIN shop_products sp ON sp.product_id = p.id
            WHERE p.status = 'active' AND p.sellable = 1 AND COALESCE(sp.hidden, 0) = 0
              AND p.requires_prescription = 0 AND p.is_controlled = 0
              AND " . ProductService::CHANNEL_SQL . " <> 'restaurant'
              AND (p.sale_price > 0 OR p.promo_price > 0)";
    }

    /** Quantités réservées par les commandes en ligne non encaissées. @return array<int,float> */
    public static function reserved(?array $productIds = null): array
    {
        $sql = 'SELECT i.product_id, SUM(i.quantity) q FROM shop_order_items i JOIN shop_orders o ON o.id = i.order_id WHERE o.status IN (\'' . implode("','", self::RESERVING) . '\')';
        $params = [];
        if ($productIds !== null) {
            if (!$productIds) {
                return [];
            }
            $sql .= ' AND i.product_id IN (' . implode(',', array_fill(0, count($productIds), '?')) . ')';
            $params = array_values($productIds);
        }
        return array_map('floatval', array_column(DB::all($sql . ' GROUP BY i.product_id', $params), 'q', 'product_id'));
    }

    /**
     * Quantité disponible en ligne. null = illimitée (article sans suivi de stock ou fabriqué
     * à partir d'une fiche technique).
     */
    private static function available(array $p, array $reserved): ?float
    {
        if ((int) $p['track_stock'] !== 1 || (int) $p['has_recipe'] === 1) {
            return null;
        }
        return max(0.0, round((float) $p['stock'] - ($reserved[(int) $p['id']] ?? 0.0), 3));
    }

    /** Catalogue public complet (une seule requête, mis en forme pour la page). */
    public static function catalog(): array
    {
        $wh = self::warehouseId();
        $rows = DB::all('SELECT p.id, p.name, p.description, p.image, p.sale_price, p.promo_price, p.promo_start, p.promo_end, p.track_stock, p.sold_by_weight, p.unit_id,
                p.category_id, COALESCE(pc.id, c.id) AS cat_root, COALESCE(sp.featured, 0) AS featured, sp.web_description,
                COALESCE((SELECT quantity FROM stock_levels WHERE product_id = p.id AND warehouse_id = ?), 0) AS stock,
                EXISTS (SELECT 1 FROM product_recipes r WHERE r.product_id = p.id) AS has_recipe,
                (SELECT allow_decimal FROM units u WHERE u.id = p.unit_id) AS allow_decimal,
                (SELECT short_name FROM units u WHERE u.id = p.unit_id) AS unit
            ' . self::baseSql() . ' ORDER BY COALESCE(sp.featured, 0) DESC, p.name LIMIT 2000', [$wh]);
        $reserved = self::reserved();
        $hideOut = self::setting('out_of_stock', 'show') === 'hide';
        $out = [];
        foreach ($rows as $p) {
            $avail = self::available($p, $reserved);
            if ($hideOut && $avail !== null && $avail <= 0) {
                continue;
            }
            $price = SaleService::listPrice($p, 1, false);
            $out[] = [
                'id' => (int) $p['id'], 'name' => $p['name'],
                'desc' => trim((string) ($p['web_description'] ?: $p['description'] ?: '')),
                'img' => upload_url($p['image']), 'price' => $price,
                'old' => $price < (float) $p['sale_price'] - 0.009 ? (float) $p['sale_price'] : null,
                'cat' => $p['category_id'] ? (int) $p['category_id'] : null, 'root' => $p['cat_root'] ? (int) $p['cat_root'] : null,
                'featured' => (int) $p['featured'] === 1, 'avail' => $avail,
                'decimal' => (int) $p['sold_by_weight'] === 1 || (int) $p['allow_decimal'] === 1, 'unit' => (string) ($p['unit'] ?? ''),
            ];
        }
        return $out;
    }

    /** Catégories qui contiennent au moins un article en ligne. */
    public static function categories(array $catalog): array
    {
        $ids = array_unique(array_filter(array_merge(array_column($catalog, 'cat'), array_column($catalog, 'root'))));
        if (!$ids) {
            return [];
        }
        return DB::all('SELECT id, parent_id, name, color, icon FROM categories WHERE active = 1 AND id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY sort_order, name');
    }

    /** Disponibilité actuelle de quelques articles (rafraîchissement du panier). */
    public static function stockFor(array $ids): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 200);
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (self::catalog() as $p) {
            if (in_array($p['id'], $ids, true)) {
                $out[$p['id']] = ['price' => $p['price'], 'avail' => $p['avail'], 'name' => $p['name']];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ commande du client

    private static function cleanPhone(string $phone): string
    {
        return preg_replace('/[^\d+]/', '', $phone) ?? '';
    }

    /**
     * Commande passée depuis la boutique. Prix et disponibilités recalculés côté serveur ;
     * les quantités sont réservées jusqu'à l'encaissement ou l'annulation.
     * @return array{id:int, number:string, token:string, total:float}
     */
    public static function placeOrder(array $d): array
    {
        if (!self::isOpen()) {
            throw new BusinessException('La boutique en ligne ne prend pas de commande pour le moment. Réessayez plus tard ou appelez-nous.');
        }
        if (trim((string) ($d['website'] ?? '')) !== '') {
            throw new BusinessException('Commande refusée.'); // champ piège rempli par un robot
        }
        $name = trim(mb_substr((string) ($d['name'] ?? ''), 0, 150));
        $phone = trim(mb_substr((string) ($d['phone'] ?? ''), 0, 50));
        if (mb_strlen($name) < 2) {
            throw new BusinessException('Indiquez votre nom.');
        }
        if (strlen(preg_replace('/\D/', '', $phone)) < 8) {
            throw new BusinessException('Indiquez un numéro de téléphone valide (8 chiffres au moins).');
        }
        $email = trim((string) ($d['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BusinessException('Adresse e-mail invalide.');
        }
        $delivery = ($d['delivery'] ?? 'pickup') === 'delivery' ? 'delivery' : 'pickup';
        if ($delivery === 'delivery' && self::setting('delivery', '1') !== '1') {
            $delivery = 'pickup';
        }
        $address = trim(mb_substr((string) ($d['address'] ?? ''), 0, 255));
        if ($delivery === 'delivery' && mb_strlen($address) < 4) {
            throw new BusinessException('Indiquez l\'adresse de livraison (quartier, repère).');
        }
        // Anti-abus : pas plus de 6 commandes par heure depuis la même adresse IP
        $ip = Request::ip();
        if ((int) DB::value('SELECT COUNT(*) FROM shop_orders WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$ip]) >= 6) {
            throw new BusinessException('Trop de commandes en peu de temps depuis cette connexion. Appelez-nous pour finaliser votre commande.');
        }
        $items = [];
        foreach ((array) ($d['items'] ?? []) as $raw) {
            $pid = (int) ($raw['id'] ?? 0);
            $q = round((float) ($raw['qty'] ?? 0), 3);
            if ($pid > 0 && $q > 0) {
                $items[$pid] = ($items[$pid] ?? 0) + $q;
            }
        }
        if (!$items) {
            throw new BusinessException('Votre panier est vide.');
        }
        if (count($items) > self::MAX_LINES) {
            throw new BusinessException('Trop d\'articles différents dans une seule commande (' . self::MAX_LINES . ' au maximum).');
        }
        return DB::transaction(function () use ($items, $name, $phone, $email, $delivery, $address, $d, $ip) {
            // Verrou par article : deux clients ne peuvent pas réserver la dernière pièce en même temps
            $ids = array_keys($items);
            DB::all('SELECT id FROM products WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE', $ids);
            $catalog = [];
            foreach (self::catalog() as $p) {
                $catalog[$p['id']] = $p;
            }
            $lines = [];
            $subtotal = 0.0;
            foreach ($items as $pid => $q) {
                $p = $catalog[$pid] ?? null;
                if (!$p) {
                    throw new BusinessException('Un article de votre panier n\'est plus disponible en ligne. Retirez-le puis validez de nouveau.');
                }
                if (!$p['decimal'] && abs($q - round($q)) > 0.0001) {
                    throw new BusinessException('« ' . $p['name'] . ' » se commande en quantité entière.');
                }
                if ($q > 1000) {
                    throw new BusinessException('Quantité trop importante pour « ' . $p['name'] . ' » : appelez-nous.');
                }
                if ($p['avail'] !== null && $q > $p['avail'] + 0.0005 && setting('allow_negative_stock') !== '1') {
                    throw new BusinessException($p['avail'] > 0
                        ? sprintf('Il ne reste que %s « %s » : diminuez la quantité.', rtrim(rtrim(number_format($p['avail'], 3, ',', ' '), '0'), ','), $p['name'])
                        : '« ' . $p['name'] . ' » est en rupture de stock : retirez-le du panier.');
                }
                $total = round($p['price'] * $q, 2);
                $lines[] = ['product_id' => $pid, 'product_name' => mb_substr($p['name'], 0, 200), 'unit_price' => $p['price'], 'quantity' => $q, 'line_total' => $total];
                $subtotal += $total;
            }
            $subtotal = round($subtotal, 2);
            $min = (float) self::setting('min_order', '0');
            if ($min > 0 && $subtotal < $min) {
                throw new BusinessException('Montant minimum de commande : ' . money($min) . '.');
            }
            $fee = $delivery === 'delivery' ? round(max(0.0, (float) self::setting('delivery_fee', '0')), 2) : 0.0;
            $number = Sequence::next('shop_order');
            $token = bin2hex(random_bytes(16));
            $id = DB::insert('shop_orders', [
                'number' => $number, 'token' => $token, 'customer_name' => $name, 'phone' => $phone, 'email' => $email ?: null,
                'delivery' => $delivery, 'address' => $delivery === 'delivery' ? $address : null,
                'note' => trim(mb_substr((string) ($d['note'] ?? ''), 0, 500)) ?: null,
                'subtotal' => $subtotal, 'delivery_fee' => $fee, 'total' => round($subtotal + $fee, 2),
                'warehouse_id' => self::warehouseId(), 'ip' => $ip,
            ]);
            foreach ($lines as $l) {
                DB::insert('shop_order_items', $l + ['order_id' => $id]);
            }
            self::log($id, 'new', 'Commande passée en ligne', null);
            Audit::log('shop.order', 'shop_order', $id, ['number' => $number, 'total' => $subtotal + $fee], null);
            return ['id' => $id, 'number' => $number, 'token' => $token, 'total' => round($subtotal + $fee, 2)];
        });
    }

    /** Commande vue par le client (lien secret du reçu). */
    public static function forCustomer(int $id, string $token): ?array
    {
        $o = DB::one('SELECT * FROM shop_orders WHERE id = ?', [$id]);
        if (!$o || !preg_match('/^[a-f0-9]{32}$/', $token) || !hash_equals((string) $o['token'], $token)) {
            return null;
        }
        $o['items'] = self::items($id);
        $o['log'] = DB::all('SELECT status, created_at FROM shop_order_log WHERE order_id = ? ORDER BY id', [$id]);
        return $o;
    }

    public static function items(int $orderId): array
    {
        return DB::all('SELECT * FROM shop_order_items WHERE order_id = ? ORDER BY id', [$orderId]);
    }

    private static function log(int $orderId, string $status, ?string $note, ?int $userId = -1): void
    {
        DB::insert('shop_order_log', ['order_id' => $orderId, 'status' => $status, 'note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'user_id' => $userId === -1 ? Auth::id() : $userId]);
    }

    // ------------------------------------------------------------------ traitement en caisse

    public static function order(int $id): ?array
    {
        $o = DB::one('SELECT o.*, s.number AS sale_number FROM shop_orders o LEFT JOIN sales s ON s.id = o.sale_id WHERE o.id = ?', [$id]);
        if ($o) {
            $o['items'] = self::items($id);
            $o['log'] = DB::all('SELECT l.*, u.full_name FROM shop_order_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.order_id = ? ORDER BY l.id', [$id]);
        }
        return $o;
    }

    /**
     * Vérification : stock réel de l'entrepôt de la caisse de l'utilisateur (là où l'article
     * sortira à l'encaissement) pour chaque ligne.
     */
    public static function check(array $o): array
    {
        $u = Auth::user();
        $wh = $u && $u['register_id'] ? (int) DB::value('SELECT warehouse_id FROM cash_registers WHERE id = ?', [$u['register_id']]) : self::warehouseId();
        $out = [];
        foreach ($o['items'] as $i) {
            $p = DB::one('SELECT id, track_stock, status, (SELECT quantity FROM stock_levels WHERE product_id = products.id AND warehouse_id = ?) AS stock,
                EXISTS (SELECT 1 FROM product_recipes r WHERE r.product_id = products.id) AS has_recipe FROM products WHERE id = ?', [$wh, $i['product_id']]);
            $tracked = $p && (int) $p['track_stock'] === 1 && !(int) $p['has_recipe'];
            $stock = $tracked ? (float) $p['stock'] : null;
            $out[(int) $i['id']] = ['stock' => $stock, 'ok' => $p && $p['status'] === 'active' && ($stock === null || $stock + 0.0005 >= (float) $i['quantity'])];
        }
        return ['warehouse' => (string) DB::value('SELECT name FROM warehouses WHERE id = ?', [$wh]), 'lines' => $out, 'ok' => !in_array(false, array_column($out, 'ok'), true)];
    }

    public static function confirm(int $id): void
    {
        DB::transaction(function () use ($id) {
            $o = DB::one('SELECT * FROM shop_orders WHERE id = ? FOR UPDATE', [$id]) ?? throw new BusinessException('Commande introuvable.');
            if ($o['status'] !== 'new') {
                throw new BusinessException('Seule une nouvelle commande peut être validée.');
            }
            DB::update('shop_orders', ['status' => 'confirmed', 'confirmed_at' => date('Y-m-d H:i:s'), 'confirmed_by' => Auth::id()], 'id = ?', [$id]);
            self::log($id, 'confirmed', 'Commande vérifiée et validée');
            Audit::log('shop.confirm', 'shop_order', $id);
        });
    }

    /**
     * Encaissement : vente créée dans la caisse de l'utilisateur (stock déstocké, mouvement de
     * caisse). Idempotent : un double clic ne crée jamais deux ventes.
     */
    public static function pay(int $id, string $method, float $tendered, ?string $reference, ?string $provider = null): array
    {
        $o = DB::one('SELECT * FROM shop_orders WHERE id = ?', [$id]) ?? throw new BusinessException('Commande introuvable.');
        if (!in_array($o['status'], ['new', 'confirmed'], true)) {
            throw new BusinessException($o['status'] === 'paid' || $o['status'] === 'delivered' ? 'Cette commande est déjà encaissée.' : 'Cette commande est annulée.');
        }
        if (!isset(payment_methods()[$method])) {
            throw new BusinessException('Mode de paiement non autorisé.');
        }
        $total = (float) $o['total'];
        $amount = $method === 'cash' ? max($tendered, 0.0) : $total;
        if ($amount + 0.009 < $total) {
            throw new BusinessException('Montant reçu insuffisant : ' . money($total) . ' à encaisser.');
        }
        if (!$o['sale_uuid']) {
            $uuid = sprintf('%04x%04x-%04x-4%03x-%04x-%04x%04x%04x', random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xfff),
                random_int(0x8000, 0xbfff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff));
            DB::run('UPDATE shop_orders SET sale_uuid = ? WHERE id = ? AND sale_uuid IS NULL', [$uuid, $id]);
            $o['sale_uuid'] = DB::value('SELECT sale_uuid FROM shop_orders WHERE id = ?', [$id]);
        }
        $items = array_map(fn ($i) => ['product_id' => (int) $i['product_id'], 'quantity' => (float) $i['quantity'], 'unit_price' => (float) $i['unit_price']], self::items($id));
        $note = 'Commande en ligne ' . $o['number'] . ($o['delivery'] === 'delivery' ? ' (livraison)' : '');
        $customerId = self::customerFor($o);
        // Frais de livraison : recette de caisse à part (la vente ne contient que les articles)
        $fee = (float) $o['delivery_fee'];
        if ($fee > 0) {
            $note .= ' · livraison ' . money($fee);
        }
        $payments = [['method' => $method, 'amount' => round($method === 'cash' ? $amount - $fee : (float) $o['subtotal'], 2), 'reference' => $reference, 'provider' => $provider]];
        $sale = SaleService::create(['client_uuid' => $o['sale_uuid'], 'items' => $items, 'payments' => $payments, 'customer_id' => $customerId, 'note' => $note], ['trusted_prices' => true]);
        if ($fee > 0 && empty($sale['duplicate'])) {
            // Frais de livraison : recette de caisse rattachée à la commande
            $session = CashService::currentSession();
            CashService::movement($session['id'] ?? null, 'shop_delivery', $method, $fee, 'shop_order', $id, 'Livraison ' . $o['number']);
        }
        DB::transaction(function () use ($id, $sale, $customerId) {
            DB::update('shop_orders', ['status' => 'paid', 'sale_id' => $sale['sale_id'], 'customer_id' => $customerId, 'paid_at' => date('Y-m-d H:i:s'), 'paid_by' => Auth::id()],
                "id = ? AND status IN ('new','confirmed')", [$id]);
            self::log($id, 'paid', 'Encaissée : vente ' . $sale['number']);
        });
        Audit::log('shop.pay', 'shop_order', $id, ['sale' => $sale['number'], 'method' => $method]);
        return $sale + ['change' => max(0.0, round($amount - $total, 2))];
    }

    /** Client de la caisse correspondant au téléphone (fiche existante uniquement). */
    private static function customerFor(array $o): ?int
    {
        $digits = preg_replace('/\D/', '', (string) $o['phone']);
        if (strlen((string) $digits) < 8) {
            return null;
        }
        $id = DB::value("SELECT id FROM customers WHERE active = 1 AND REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', '') LIKE ? ORDER BY id LIMIT 1", ['%' . substr((string) $digits, -8)]);
        return $id ? (int) $id : null;
    }

    public static function deliver(int $id): void
    {
        DB::transaction(function () use ($id) {
            $o = DB::one('SELECT * FROM shop_orders WHERE id = ? FOR UPDATE', [$id]) ?? throw new BusinessException('Commande introuvable.');
            if ($o['status'] !== 'paid') {
                throw new BusinessException($o['status'] === 'delivered' ? 'Commande déjà livrée.' : 'Encaissez la commande avant de la livrer.');
            }
            DB::update('shop_orders', ['status' => 'delivered', 'delivered_at' => date('Y-m-d H:i:s'), 'delivered_by' => Auth::id()], 'id = ?', [$id]);
            self::log($id, 'delivered', $o['delivery'] === 'delivery' ? 'Livrée au client' : 'Retirée par le client');
            Audit::log('shop.deliver', 'shop_order', $id);
        });
    }

    public static function cancel(int $id, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new BusinessException('Indiquez le motif de l\'annulation.');
        }
        DB::transaction(function () use ($id, $reason) {
            $o = DB::one('SELECT * FROM shop_orders WHERE id = ? FOR UPDATE', [$id]) ?? throw new BusinessException('Commande introuvable.');
            if (!in_array($o['status'], ['new', 'confirmed'], true)) {
                throw new BusinessException($o['status'] === 'cancelled' ? 'Commande déjà annulée.' : 'Commande encaissée : annulez ou faites un retour sur la vente ' . ($o['sale_id'] ? 'correspondante' : '') . ' (Ventes).');
            }
            DB::update('shop_orders', ['status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s'), 'cancelled_by' => Auth::id(), 'cancel_reason' => mb_substr($reason, 0, 255)], 'id = ?', [$id]);
            self::log($id, 'cancelled', $reason);
            Audit::log('shop.cancel', 'shop_order', $id, ['reason' => $reason]);
        });
    }

    /** Compteurs pour le tableau des commandes et l'alerte « nouvelle commande ». */
    public static function counts(): array
    {
        $c = array_map('intval', array_column(DB::all('SELECT status, COUNT(*) n FROM shop_orders GROUP BY status'), 'n', 'status'));
        return $c + array_fill_keys(array_keys(self::STATUSES), 0);
    }
}
