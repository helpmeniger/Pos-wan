<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Request;
use App\Services\CashService;
use App\Services\SaleService;

final class PosController extends Controller
{
    public function index(): void
    {
        $u = $this->user();
        $register = $u['register_id'] ? DB::one('SELECT r.*, s.name AS store_name, w.name AS warehouse_name FROM cash_registers r JOIN stores s ON s.id = r.store_id JOIN warehouses w ON w.id = r.warehouse_id WHERE r.id = ?', [$u['register_id']]) : null;
        $session = $register ? CashService::openSessionForRegister((int) $register['id']) : null;
        $categories = DB::all('SELECT id, name, color, icon, image FROM categories WHERE active = 1 AND parent_id IS NULL ORDER BY sort_order, name');
        $shortcuts = json_decode((string) setting('pos_shortcuts', ''), true);
        $config = [
            'csrf' => csrf_token(),
            'userId' => (int) $u['id'],
            'userName' => $u['full_name'],
            'register' => $register ? $register['name'] : null,
            'sessionOpen' => (bool) $session,
            'requireSession' => setting('require_cash_session', '1') === '1',
            'methods' => payment_methods(),
            'maxDiscount' => (float) setting('max_discount_percent', '10'),
            'features' => ['wholesale' => feature('wholesale'), 'pharmacy' => feature('pharmacy'), 'lots' => feature('lots'), 'packaging' => feature('packaging')],
            'perms' => [
                'discount' => can('pos.discount'), 'price' => can('pos.price_override'), 'credit' => can('pos.credit_sale'),
                'wholesale' => can('pos.wholesale'), 'cancel' => can('pos.cancel_sale'), 'customers' => can('customers.manage'),
                'cash' => can('cash.open_close'), 'returns' => can('returns.create'),
            ],
            'shortcuts' => is_array($shortcuts) ? $shortcuts : self::defaultShortcuts(),
            'autoPrint' => setting('pos_auto_print', '1') === '1',
        ];
        view('pos/index', ['u' => $u, 'register' => $register, 'session' => $session, 'categories' => $categories, 'config' => $config], null);
    }

    public static function defaultShortcuts(): array
    {
        return ['search' => 'F2', 'customer' => 'F4', 'quantity' => 'F6', 'discount' => 'F7', 'hold' => 'F8', 'pay' => 'F9', 'cashExact' => 'F10', 'clear' => 'F12'];
    }

    /** Fiche article au format caisse (prix effectif calculé comme côté serveur). */
    private function present(array $p): array
    {
        $packs = [];
        if (feature('packaging')) {
            $packs = DB::all('SELECT id, name, factor, sale_price, barcode FROM product_packagings WHERE product_id = ? ORDER BY factor', [$p['id']]);
        }
        $promo = SaleService::promoActive($p);
        return [
            'id' => (int) $p['id'],
            'name' => $p['name'] . ($p['variant_size'] || $p['variant_color'] ? ' · ' . trim(($p['variant_size'] ?? '') . ' ' . ($p['variant_color'] ?? '')) : ''),
            'reference' => $p['reference'],
            'barcode' => $p['barcode'],
            'price' => (float) ($promo ? $p['promo_price'] : $p['sale_price']),
            'regular' => (float) $p['sale_price'],
            'promo' => $promo,
            'wholesale' => $p['wholesale_price'] !== null ? (float) $p['wholesale_price'] : null,
            'wholesaleMin' => $p['wholesale_min_qty'] !== null ? (float) $p['wholesale_min_qty'] : null,
            'stock' => (float) ($p['stock'] ?? 0),
            'track' => (int) $p['track_stock'] === 1,
            'decimal' => (int) $p['sold_by_weight'] === 1 || (int) ($p['allow_decimal'] ?? 0) === 1,
            'unit' => $p['unit_short'] ?? '',
            'image' => upload_url($p['image']),
            'color' => $p['cat_color'] ?? '#5d6d7e',
            'rx' => (int) $p['requires_prescription'] === 1 && feature('pharmacy'),
            'tax' => (float) $p['tax_rate'],
            'packs' => array_map(fn($k) => ['id' => (int) $k['id'], 'name' => $k['name'], 'factor' => (float) $k['factor'], 'price' => (float) $k['sale_price'], 'barcode' => $k['barcode']], $packs),
        ];
    }

    private function baseQuery(): string
    {
        $wh = $this->defaultWarehouseId();
        return "SELECT p.*, u.short_name AS unit_short, u.allow_decimal, c.color AS cat_color, COALESCE(sl.quantity,0) AS stock
                FROM products p LEFT JOIN units u ON u.id = p.unit_id LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN stock_levels sl ON sl.product_id = p.id AND sl.warehouse_id = " . (int) $wh . "
                WHERE p.status = 'active' AND p.sellable = 1";
    }

    public function products(): void
    {
        $sql = $this->baseQuery();
        $params = [];
        $cat = (int) Request::query('category', 0);
        $q = trim((string) Request::query('q', ''));
        if ($cat > 0) {
            $sql .= ' AND (p.category_id = ? OR p.category_id IN (SELECT id FROM categories WHERE parent_id = ?))';
            $params[] = $cat;
            $params[] = $cat;
        }
        if ($q !== '') {
            $sql .= ' AND (p.name LIKE ? OR p.reference LIKE ? OR p.barcode = ? OR p.active_ingredient LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            array_push($params, $like, $like, $q, $like);
        }
        $page = max(1, (int) Request::query('page', 1));
        $per = 60;
        $sql .= ' ORDER BY p.name LIMIT ' . ($per + 1) . ' OFFSET ' . (($page - 1) * $per);
        $rows = DB::all($sql, $params);
        $more = count($rows) > $per;
        json_response(['ok' => true, 'items' => array_map([$this, 'present'], array_slice($rows, 0, $per)), 'more' => $more]);
    }

    /** Recherche exacte (douchette code-barres) : code-barres article, code-barres conditionnement ou référence. */
    public function lookup(): void
    {
        $code = trim((string) Request::query('code', ''));
        if ($code === '') {
            json_response(['ok' => false, 'error' => 'Code vide.'], 422);
        }
        $p = DB::one($this->baseQuery() . ' AND (p.barcode = ? OR p.reference = ?) LIMIT 1', [$code, $code]);
        $packId = null;
        if (!$p && feature('packaging')) {
            $pack = DB::one('SELECT id, product_id FROM product_packagings WHERE barcode = ?', [$code]);
            if ($pack) {
                $p = DB::one($this->baseQuery() . ' AND p.id = ?', [$pack['product_id']]);
                $packId = (int) $pack['id'];
            }
        }
        if (!$p) {
            json_response(['ok' => false, 'error' => 'Aucun article pour le code « ' . $code . ' ».'], 404);
        }
        json_response(['ok' => true, 'item' => $this->present($p), 'packaging_id' => $packId]);
    }

    public function customers(): void
    {
        $q = trim((string) Request::query('q', ''));
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $rows = DB::all('SELECT id, name, phone, balance, credit_limit, loyalty_points FROM customers WHERE active = 1 AND (name LIKE ? OR phone LIKE ?) ORDER BY name LIMIT 20', [$like, $like]);
        json_response(['ok' => true, 'items' => $rows]);
    }

    public function quickCustomer(): void
    {
        $this->jsonAttempt(function () {
            $name = Request::str('name');
            $phone = Request::str('phone');
            if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
                throw new BusinessException('Nom du client invalide.');
            }
            if ($phone !== '' && !preg_match('/^[+0-9 ()\-.]{6,30}$/', $phone)) {
                throw new BusinessException('Téléphone invalide.');
            }
            $id = DB::insert('customers', ['name' => $name, 'phone' => $phone ?: null]);
            Audit::log('customer.create', 'customer', $id, ['source' => 'pos']);
            return ['customer' => DB::one('SELECT id, name, phone, balance, credit_limit, loyalty_points FROM customers WHERE id = ?', [$id])];
        });
    }

    public function checkout(): void
    {
        $this->jsonAttempt(function () {
            $data = Request::json();
            $r = SaleService::create($data);
            // Ticket sur l'imprimante de la caisse (si configurée et demandé) : la vente reste valide même si l'impression échoue
            $print = ['printed' => 0, 'errors' => []];
            if (!$r['duplicate'] && !empty($data['print_ticket'])) {
                $print = \App\Services\PrintService::printReceipt($r['sale_id']);
            }
            return $r + ['ticket_url' => url('/sales/' . $r['sale_id'] . '/ticket', ['auto' => 1]), 'invoice_url' => url('/sales/' . $r['sale_id'] . '/invoice'),
                'delivery_url' => url('/sales/' . $r['sale_id'] . '/delivery'), 'printed_server' => $print['printed'], 'print_errors' => $print['errors']];
        });
    }

    public function hold(): void
    {
        $this->jsonAttempt(function () {
            $u = $this->user();
            $cart = Request::input('cart');
            if (!is_array($cart) || empty($cart['lines'])) {
                throw new BusinessException('Panier vide.');
            }
            $json = json_encode($cart, JSON_UNESCAPED_UNICODE);
            if (strlen((string) $json) > 200000) {
                throw new BusinessException('Panier trop volumineux.');
            }
            $label = mb_substr(Request::str('label') ?: 'Vente ' . date('H:i'), 0, 100);
            $id = DB::insert('held_sales', ['user_id' => $u['id'], 'register_id' => $u['register_id'], 'label' => $label, 'cart_json' => $json]);
            return ['id' => $id];
        });
    }

    public function heldList(): void
    {
        $u = $this->user();
        $rows = DB::all('SELECT h.id, h.label, h.created_at, h.cart_json, us.full_name FROM held_sales h JOIN users us ON us.id = h.user_id WHERE h.register_id <=> ? ORDER BY h.id DESC LIMIT 50', [$u['register_id']]);
        foreach ($rows as &$r) {
            $c = json_decode($r['cart_json'], true) ?: [];
            $r['lines'] = count($c['lines'] ?? []);
            unset($r['cart_json']);
        }
        json_response(['ok' => true, 'items' => $rows]);
    }

    public function resume(int $id): void
    {
        $u = $this->user();
        $row = DB::one('SELECT * FROM held_sales WHERE id = ? AND register_id <=> ?', [$id, $u['register_id']]);
        if (!$row) {
            json_response(['ok' => false, 'error' => 'Vente en attente introuvable.'], 404);
        }
        DB::run('DELETE FROM held_sales WHERE id = ?', [$id]);
        json_response(['ok' => true, 'cart' => json_decode($row['cart_json'], true)]);
    }

    public function recent(): void
    {
        $u = $this->user();
        $rows = DB::all('SELECT s.id, s.number, s.total, s.status, s.created_at, c.name AS customer FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 15', [$u['id']]);
        json_response(['ok' => true, 'items' => $rows]);
    }
}
