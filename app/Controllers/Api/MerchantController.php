<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\DispatchService;
use App\Services\FinanceService;
use App\Services\LedgerService;
use App\Services\MerchantHours;
use App\Services\OrderPolicy;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\UploadService;

/** Espace restaurant / commerçant. */
final class MerchantController extends Controller
{
    private function merchant(): array
    {
        $m = Database::first('SELECT * FROM merchants WHERE owner_user_id = :u ORDER BY id LIMIT 1', ['u' => $this->user()['id']]);
        if (!$m) {
            throw HttpException::forbidden('Aucun commerce associé à ce compte.');
        }
        return $m;
    }

    private function product(int $id, array $m): array
    {
        $p = Database::first('SELECT * FROM products WHERE id = :id AND merchant_id = :m AND deleted_at IS NULL', ['id' => $id, 'm' => $m['id']]);
        if (!$p) {
            throw HttpException::notFound('Produit introuvable.');
        }
        return $p;
    }

    public function store(Request $r): Response
    {
        $m = $this->merchant();
        return $this->json([
            'store' => $m + ['open_now' => MerchantHours::isOpenNow($m), 'logo_url' => $m['logo_file_id'] ? url('fichiers/' . $m['logo_file_id']) : null],
            'hours' => Database::select('SELECT id, weekday, open_time, close_time FROM merchant_hours WHERE merchant_id = :m ORDER BY weekday, open_time', ['m' => $m['id']]),
            'closures' => Database::select('SELECT id, closed_on, reason FROM merchant_closures WHERE merchant_id = :m AND closed_on >= CURDATE() ORDER BY closed_on', ['m' => $m['id']]),
            'stats' => Database::first("SELECT SUM(status = 'pending') pending, SUM(status IN ('accepted','preparing','ready')) in_preparation,
                                               SUM(created_at >= CURDATE()) today, COALESCE(SUM(CASE WHEN settled_at >= CURDATE() THEN items_subtotal END),0) sales_today
                                          FROM orders WHERE merchant_id = :m", ['m' => $m['id']]),
        ]);
    }

    public function updateStore(Request $r): Response
    {
        $m = $this->merchant();
        $v = $this->validate($r, [
            'name' => 'required|string|max:150', 'cuisine' => 'nullable|string|max:80', 'description' => 'nullable|string|max:500', 'phone' => 'nullable|phone',
            'address_text' => 'required|string|max:255', 'lat' => 'required|lat', 'lng' => 'required|lng',
            'prep_time_min' => 'required|int|between:1,240', 'delivery_mode' => 'required|in:marwa,own', 'delivery_radius_km' => 'required|numeric|between:0.5,50',
            'min_order' => 'nullable|int|min:0', 'track_stock' => 'nullable|bool', 'is_open' => 'nullable|bool', 'rccm' => 'nullable|string|max:60', 'tax_id' => 'nullable|string|max:60',
            'hours' => 'nullable|array|max:28', 'closures' => 'nullable|array|max:60',
        ]);
        Database::transaction(function () use ($m, $v) {
            $data = $v;
            unset($data['hours'], $data['closures']);
            $data['track_stock'] = !empty($v['track_stock']) ? 1 : 0;
            $data['is_open'] = !empty($v['is_open']) ? 1 : 0;
            $data['min_order'] = $v['min_order'] ?? 0;
            // Changement d'emplacement d'un commerce validé : contrôle par l'administration
            if ($m['status'] === 'verified' && (abs((float) $m['lat'] - $v['lat']) > 0.005 || abs((float) $m['lng'] - $v['lng']) > 0.005)) {
                $data['status'] = 'pending';
            }
            Database::update('merchants', $data, 'id = :id', ['id' => $m['id']]);
            if (isset($v['hours'])) {
                Database::execute('DELETE FROM merchant_hours WHERE merchant_id = :m', ['m' => $m['id']]);
                foreach ($v['hours'] as $h) {
                    $hv = \App\Core\Validator::make((array) $h, ['weekday' => 'required|int|between:1,7', 'open_time' => 'required|time', 'close_time' => 'required|time']);
                    Database::insert('merchant_hours', $hv + ['merchant_id' => $m['id']]);
                }
            }
            if (isset($v['closures'])) {
                Database::execute('DELETE FROM merchant_closures WHERE merchant_id = :m AND closed_on >= CURDATE()', ['m' => $m['id']]);
                foreach ($v['closures'] as $c) {
                    $cv = \App\Core\Validator::make((array) $c, ['closed_on' => 'required|date', 'reason' => 'nullable|string|max:120']);
                    Database::execute('INSERT IGNORE INTO merchant_closures (merchant_id, closed_on, reason) VALUES (:m, :d, :r)', ['m' => $m['id'], 'd' => $cv['closed_on'], 'r' => $cv['reason'] ?? null]);
                }
            }
        });
        return $this->ok('Paramètres enregistrés.');
    }

    public function toggleOpen(Request $r): Response
    {
        $m = $this->merchant();
        $v = $this->validate($r, ['is_open' => 'required|bool']);
        if ($v['is_open'] && $m['status'] !== 'verified') {
            throw HttpException::conflict('Votre commerce doit être validé par MARWA avant d\'ouvrir.');
        }
        Database::update('merchants', ['is_open' => $v['is_open'] ? 1 : 0], 'id = :id', ['id' => $m['id']]);
        return $this->json(['is_open' => $v['is_open']]);
    }

    public function logo(Request $r): Response
    {
        $m = $this->merchant();
        $f = UploadService::store($r->file('file'), (int) $this->user()['id'], 'logo', true);
        Database::update('merchants', ['logo_file_id' => $f['id']], 'id = :id', ['id' => $m['id']]);
        return $this->json(['logo_url' => url('fichiers/' . $f['id'])]);
    }

    // ------------------------------------------------------------------ Catalogue

    public function catalog(Request $r): Response
    {
        $m = $this->merchant();
        $products = Database::select('SELECT * FROM products WHERE merchant_id = :m AND deleted_at IS NULL ORDER BY sort_order, name', ['m' => $m['id']]);
        $opts = [];
        if ($products) {
            [$in, $p] = Database::in(array_column($products, 'id'), 'p');
            foreach (Database::select("SELECT * FROM product_variants WHERE product_id IN ($in) ORDER BY group_name, id", $p) as $o) {
                $opts[$o['product_id']][] = $o;
            }
        }
        foreach ($products as &$p) {
            $p['options'] = $opts[$p['id']] ?? [];
            $p['photo_url'] = $p['photo_file_id'] ? url('fichiers/' . $p['photo_file_id']) : null;
        }
        return $this->json([
            'categories' => Database::select('SELECT * FROM product_categories WHERE merchant_id = :m ORDER BY sort_order, name', ['m' => $m['id']]),
            'products' => $products,
        ]);
    }

    public function saveCategory(Request $r): Response
    {
        $m = $this->merchant();
        $v = $this->validate($r, ['name' => 'required|string|max:80', 'sort_order' => 'nullable|int|between:-1000,1000']);
        $id = $r->param('id');
        if ($id) {
            Database::update('product_categories', $v, 'id = :id AND merchant_id = :m', ['id' => (int) $id, 'm' => $m['id']]);
        } else {
            if (Database::value('SELECT 1 FROM product_categories WHERE merchant_id = :m AND name = :n', ['m' => $m['id'], 'n' => $v['name']])) {
                throw \App\Core\ValidationException::field('name', 'Cette catégorie existe déjà.');
            }
            $id = Database::insert('product_categories', $v + ['merchant_id' => $m['id']]);
        }
        return $this->json(['id' => (int) $id]);
    }

    public function deleteCategory(Request $r): Response
    {
        $m = $this->merchant();
        Database::execute('DELETE FROM product_categories WHERE id = :id AND merchant_id = :m', ['id' => $r->intParam('id'), 'm' => $m['id']]);
        return $this->ok('Catégorie supprimée (les produits sont conservés sans catégorie).');
    }

    private const PRODUCT_RULES = ['name' => 'required|string|max:120', 'description' => 'nullable|string|max:500', 'category_id' => 'nullable|int',
        'price' => 'required|int|between:1,10000000', 'promo_price' => 'nullable|int|min:1', 'is_available' => 'nullable|bool',
        'stock_qty' => 'nullable|int|min:0', 'sort_order' => 'nullable|int|between:-1000,1000'];

    public function saveProduct(Request $r): Response
    {
        $m = $this->merchant();
        $v = $this->validate($r, self::PRODUCT_RULES);
        if (!empty($v['category_id']) && !Database::value('SELECT 1 FROM product_categories WHERE id = :c AND merchant_id = :m', ['c' => $v['category_id'], 'm' => $m['id']])) {
            throw \App\Core\ValidationException::field('category_id', 'Catégorie invalide.');
        }
        if (!empty($v['promo_price']) && $v['promo_price'] >= $v['price']) {
            throw \App\Core\ValidationException::field('promo_price', 'Le prix promotionnel doit être inférieur au prix normal.');
        }
        $v['is_available'] = !array_key_exists('is_available', $v) || $v['is_available'] ? 1 : 0;
        $id = $r->param('id');
        if ($id) {
            $this->product((int) $id, $m);
            Database::update('products', $v, 'id = :id', ['id' => (int) $id]);
        } else {
            $id = Database::insert('products', $v + ['merchant_id' => $m['id']]);
        }
        return $this->json(['id' => (int) $id]);
    }

    public function availability(Request $r): Response
    {
        $m = $this->merchant();
        $p = $this->product($r->intParam('id'), $m);
        $v = $this->validate($r, ['is_available' => 'required|bool']);
        Database::update('products', ['is_available' => $v['is_available'] ? 1 : 0], 'id = :id', ['id' => $p['id']]);
        return $this->ok($v['is_available'] ? 'Produit disponible.' : 'Produit marqué en rupture.');
    }

    public function deleteProduct(Request $r): Response
    {
        $m = $this->merchant();
        $p = $this->product($r->intParam('id'), $m);
        Database::update('products', ['deleted_at' => date('Y-m-d H:i:s'), 'is_available' => 0], 'id = :id', ['id' => $p['id']]);
        return $this->ok('Produit supprimé.');
    }

    public function productPhoto(Request $r): Response
    {
        $m = $this->merchant();
        $p = $this->product($r->intParam('id'), $m);
        $f = UploadService::store($r->file('file'), (int) $this->user()['id'], 'product', true);
        Database::update('products', ['photo_file_id' => $f['id']], 'id = :id', ['id' => $p['id']]);
        return $this->json(['photo_url' => url('fichiers/' . $f['id'])]);
    }

    public function saveOption(Request $r): Response
    {
        $m = $this->merchant();
        $p = $this->product($r->intParam('id'), $m);
        $v = $this->validate($r, ['kind' => 'required|in:variant,extra', 'group_name' => 'required|string|max:60', 'name' => 'required|string|max:80',
            'price_delta' => 'required|int|between:-1000000,1000000', 'is_available' => 'nullable|bool']);
        $v['is_available'] = !array_key_exists('is_available', $v) || $v['is_available'] ? 1 : 0;
        $id = Database::insert('product_variants', $v + ['product_id' => $p['id']]);
        return $this->json(['id' => $id], 201);
    }

    public function deleteOption(Request $r): Response
    {
        $m = $this->merchant();
        Database::execute('DELETE v FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = :id AND p.merchant_id = :m', ['id' => $r->intParam('oid'), 'm' => $m['id']]);
        return $this->ok('Option supprimée.');
    }

    /** Import CSV : nom;categorie;prix;description;prix_promo (séparateur ; ou ,). */
    public function import(Request $r): Response
    {
        $m = $this->merchant();
        $file = $r->file('file');
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 1048576) {
            throw \App\Core\ValidationException::field('file', 'Fichier CSV requis (1 Mo max.).');
        }
        $content = (string) file_get_contents($file['tmp_name']);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $lines = preg_split('/\R/', trim((string) $content));
        $sep = substr_count($lines[0] ?? '', ';') >= substr_count($lines[0] ?? '', ',') ? ';' : ',';
        $created = 0;
        $errors = [];
        Database::transaction(function () use ($lines, $sep, $m, &$created, &$errors) {
            foreach ($lines as $i => $line) {
                $cols = str_getcsv($line, $sep);
                if ($i === 0 && !is_numeric(str_replace([' ', "\u{202F}"], '', $cols[2] ?? ''))) {
                    continue; // ligne d'en-tête
                }
                $name = trim($cols[0] ?? '');
                $price = (int) preg_replace('/\D/', '', $cols[2] ?? '');
                if ($name === '' || $price <= 0 || mb_strlen($name) > 120) {
                    $errors[] = 'Ligne ' . ($i + 1) . ' ignorée (nom ou prix invalide).';
                    continue;
                }
                $catId = null;
                $cat = trim($cols[1] ?? '');
                if ($cat !== '') {
                    $catId = Database::value('SELECT id FROM product_categories WHERE merchant_id = :m AND name = :n', ['m' => $m['id'], 'n' => mb_substr($cat, 0, 80)])
                        ?: Database::insert('product_categories', ['merchant_id' => $m['id'], 'name' => mb_substr($cat, 0, 80)]);
                }
                $promo = (int) preg_replace('/\D/', '', $cols[4] ?? '');
                Database::insert('products', ['merchant_id' => $m['id'], 'category_id' => $catId, 'name' => $name, 'price' => $price,
                    'description' => mb_substr(trim($cols[3] ?? ''), 0, 500) ?: null, 'promo_price' => $promo > 0 && $promo < $price ? $promo : null]);
                $created++;
            }
        });
        return $this->json(['created' => $created, 'errors' => $errors]);
    }

    // ------------------------------------------------------------------ Commandes

    public function orders(Request $r): Response
    {
        $m = $this->merchant();
        DispatchService::tick();
        $filter = (string) $r->input('filter', 'active');
        $where = match ($filter) {
            'active' => "status IN ('pending','accepted','preparing','ready','picked_up','in_delivery','delivery_failed','returning')",
            'done' => "status IN ('delivered','paid','returned','disputed','refund_pending','refunded')",
            'cancelled' => "status IN ('cancelled','refused','expired')",
            default => "status <> 'created'",
        };
        [$limit, $offset] = $this->page($r, 50, 100);
        $rows = Database::select("SELECT * FROM orders WHERE merchant_id = :m AND $where ORDER BY id DESC LIMIT $limit OFFSET $offset", ['m' => $m['id']]);
        return $this->json(array_map(static fn ($o) => OrderPolicy::present($o, 'merchant'), $rows));
    }

    public function orderAction(Request $r): Response
    {
        $m = $this->merchant();
        $u = $this->user();
        $order = OrderPolicy::load($r->intParam('id'), $u, ['merchant']);
        $action = (string) $r->input('action', '');
        if ($action === 'declare_cash') {
            $v = $this->validate($r, ['amount_collected' => 'required|int|min:0']);
            $o = PaymentService::declareCash($order, 'merchant', (int) $u['id'], $v['amount_collected']);
        } else {
            $o = OrderService::merchantAction($order, $m, (int) $u['id'], $action, $r->all());
        }
        return $this->json(OrderPolicy::present(DispatchService::fresh((int) $o['id']), 'merchant'));
    }

    // ------------------------------------------------------------------ Finances & rapports

    public function finance(Request $r): Response
    {
        $m = $this->merchant();
        $acc = LedgerService::partnerAccount('merchant', (int) $m['id']);
        return $this->json([
            'summary' => FinanceService::partnerSummary('merchant', (int) $m['id']),
            'statement' => LedgerService::statement($acc, 100),
            'payouts' => Database::select('SELECT id, direction, amount, method, destination, status, reference, requested_at, paid_at FROM payouts WHERE partner_user_id = :u ORDER BY id DESC LIMIT 50', ['u' => $this->user()['id']]),
        ]);
    }

    public function report(Request $r): Response
    {
        $m = $this->merchant();
        $f = \App\Services\ReportService::filters($r->query);
        $rows = Database::select("SELECT DATE(o.settled_at) d, COUNT(*) orders, SUM(o.items_subtotal) sales, COALESCE(SUM(c.commission),0) commission, COALESCE(SUM(c.partner_net),0) net
                                   FROM orders o LEFT JOIN commissions c ON c.order_id = o.id AND c.party = 'merchant'
                                  WHERE o.merchant_id = :m AND o.settled_at BETWEEN :f AND :t GROUP BY DATE(o.settled_at) ORDER BY d", ['m' => $m['id'], 'f' => $f['_from'], 't' => $f['_to']]);
        $top = Database::select("SELECT i.name, SUM(i.quantity) qty, SUM(i.line_total) revenue FROM order_items i JOIN orders o ON o.id = i.order_id
                                  WHERE o.merchant_id = :m AND o.settled_at BETWEEN :f AND :t GROUP BY i.name ORDER BY revenue DESC LIMIT 10", ['m' => $m['id'], 'f' => $f['_from'], 't' => $f['_to']]);
        $cancel = Database::first("SELECT SUM(status = 'refused') refused, SUM(status = 'cancelled') cancelled, SUM(status = 'expired') expired FROM orders WHERE merchant_id = :m AND created_at BETWEEN :f AND :t",
            ['m' => $m['id'], 'f' => $f['_from'], 't' => $f['_to']]);
        $report = ['title' => 'Ventes — ' . $m['name'], 'subtitle' => 'Du ' . date('d/m/Y', strtotime($f['from'])) . ' au ' . date('d/m/Y', strtotime($f['to'])),
            'columns' => ['label' => 'Jour', 'orders' => 'Commandes', 'sales' => 'Ventes', 'commission' => 'Commission MARWA', 'net' => 'Net commerce'],
            'rows' => array_map(static fn ($x) => ['label' => date('d/m/Y', strtotime($x['d'])), 'orders' => (int) $x['orders'], 'sales' => (int) $x['sales'], 'commission' => (int) $x['commission'], 'net' => (int) $x['net']], $rows)];
        $report['totals'] = $report['rows'] ? ['label' => 'TOTAL', 'orders' => array_sum(array_column($report['rows'], 'orders')), 'sales' => array_sum(array_column($report['rows'], 'sales')),
            'commission' => array_sum(array_column($report['rows'], 'commission')), 'net' => array_sum(array_column($report['rows'], 'net'))] : [];
        $format = (string) $r->input('format', 'json');
        if ($format !== 'json') {
            return ExportResponder::respond($report, $format, 'ventes-' . $f['from'] . '-' . $f['to']);
        }
        return $this->json($report + ['top_products' => $top, 'cancellations' => array_map('intval', $cancel ?? [])]);
    }

    public function requestPayout(Request $r): Response
    {
        $v = $this->validate($r, ['amount' => 'required|int|min:1', 'method' => 'required|in:cash,mobile_money,bank_transfer', 'destination' => 'nullable|string|max:120']);
        return $this->json(FinanceService::requestPayout($this->user(), $v['amount'], $v['method'], $v['destination'] ?? null), 201);
    }

    /** Livreurs : livreurs internes rattachés + livreurs MARWA ayant livré récemment (sans position GPS). */
    public function couriers(Request $r): Response
    {
        $m = $this->merchant();
        return $this->json([
            'internal' => Database::select("SELECT u.id, u.full_name, p.verification_status, p.is_online, p.current_order_id FROM provider_profiles p JOIN users u ON u.id = p.user_id WHERE p.merchant_id = :m", ['m' => $m['id']]),
            'recent' => Database::select("SELECT u.full_name, COUNT(*) deliveries, MAX(o.completed_at) last_delivery, p.rating_avg FROM orders o JOIN users u ON u.id = o.provider_id JOIN provider_profiles p ON p.user_id = u.id
                                           WHERE o.merchant_id = :m AND o.completed_at IS NOT NULL GROUP BY u.id, u.full_name, p.rating_avg ORDER BY last_delivery DESC LIMIT 20", ['m' => $m['id']]),
        ]);
    }

    public function linkCourier(Request $r): Response
    {
        $m = $this->merchant();
        $v = $this->validate($r, ['phone' => 'required|phone']);
        $c = Database::first("SELECT p.user_id, p.merchant_id FROM users u JOIN provider_profiles p ON p.user_id = u.id WHERE u.phone = :p AND p.provider_type = 'courier'", ['p' => $v['phone']]);
        if (!$c) {
            throw HttpException::notFound('Aucun livreur MARWA avec ce numéro.');
        }
        if ($c['merchant_id'] && (int) $c['merchant_id'] !== (int) $m['id']) {
            throw HttpException::conflict('Ce livreur est déjà rattaché à un autre commerce.');
        }
        Database::update('provider_profiles', ['merchant_id' => $m['id']], 'user_id = :u', ['u' => $c['user_id']]);
        \App\Services\NotificationService::notify((int) $c['user_id'], 'merchant_link', 'Rattachement à un commerce', 'Vous êtes désormais livreur interne de ' . $m['name'] . '.');
        return $this->ok('Livreur rattaché.');
    }

    public function unlinkCourier(Request $r): Response
    {
        $m = $this->merchant();
        Database::execute('UPDATE provider_profiles SET merchant_id = NULL WHERE user_id = :u AND merchant_id = :m', ['u' => $r->intParam('id'), 'm' => $m['id']]);
        return $this->ok('Livreur détaché.');
    }
}
