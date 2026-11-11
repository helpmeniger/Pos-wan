<?php
declare(strict_types=1);

namespace Addons\Boutique;

use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;

/** Commandes en ligne dans POS Marwa : vérification, validation, encaissement, livraison ; réglages. */
final class ShopAdminController extends Controller
{
    public function index(): void
    {
        $f = (string) Request::query('f', 'todo');
        $where = ['1=1'];
        $params = [];
        if ($f === 'todo') {
            $where[] = "o.status IN ('new','confirmed','paid')";
        } elseif (isset(ShopService::STATUSES[$f])) {
            $where[] = 'o.status = ?';
            $params[] = $f;
        }
        if (($q = trim((string) Request::query('q', ''))) !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $where[] = '(o.number LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $p = paginate('FROM shop_orders o WHERE ' . implode(' AND ', $where), $params,
            'o.*, (SELECT COUNT(*) FROM shop_order_items i WHERE i.order_id = o.id) AS nb_lines',
            "FIELD(o.status, 'new', 'confirmed', 'paid', 'delivered', 'cancelled'), o.id DESC");
        $today = date('Y-m-d');
        $stats = ['today' => (int) DB::value('SELECT COUNT(*) FROM shop_orders WHERE DATE(created_at) = ?', [$today]),
            'sales_today' => (float) DB::value("SELECT COALESCE(SUM(total),0) FROM shop_orders WHERE status IN ('paid','delivered') AND DATE(paid_at) = ?", [$today])];
        view('boutique::admin_index', ['title' => 'Commandes en ligne', 'p' => $p, 'f' => $f, 'q' => $q, 'counts' => ShopService::counts(), 'stats' => $stats,
            'statuses' => ShopService::STATUSES, 'shopUrl' => $this->shopUrl()]);
    }

    private function shopUrl(): string
    {
        $https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('/boutique');
    }

    public function show(int $id): void
    {
        $o = ShopService::order($id) ?? throw new HttpException(404);
        $u = $this->user();
        view('boutique::admin_show', ['title' => 'Commande ' . $o['number'], 'o' => $o, 'check' => ShopService::check($o), 'statuses' => ShopService::STATUSES,
            'session' => \App\Services\CashService::currentSession($u), 'hasRegister' => (bool) $u['register_id'], 'methods' => payment_methods(),
            'providers' => function_exists('mobile_money_providers') ? mobile_money_providers() : []]);
    }

    public function confirm(int $id): void
    {
        $this->attempt(fn () => ShopService::confirm($id), '/boutique-admin/' . $id);
        flash('success', 'Commande validée. Préparez les articles puis encaissez.');
        redirect('/boutique-admin/' . $id);
    }

    public function pay(int $id): void
    {
        // « mobile_money|Airtel Money » : mode et opérateur choisis ensemble
        [$method, $provider] = array_pad(explode('|', Request::str('method', 'cash'), 2), 2, null);
        $r = $this->attempt(fn () => ShopService::pay($id, $method, Request::num('tendered'), Request::str('reference') ?: null, $provider ? mobile_money_provider($provider) : null), '/boutique-admin/' . $id);
        flash('success', 'Commande encaissée : vente ' . $r['number'] . ' créée, stock mis à jour.' . ($r['change'] > 0 ? ' Monnaie à rendre : ' . money($r['change']) . '.' : ''));
        redirect('/boutique-admin/' . $id);
    }

    public function deliver(int $id): void
    {
        $this->attempt(fn () => ShopService::deliver($id), '/boutique-admin/' . $id);
        flash('success', 'Commande remise au client.');
        redirect('/boutique-admin/' . $id);
    }

    public function cancel(int $id): void
    {
        $this->attempt(fn () => ShopService::cancel($id, Request::str('reason')), '/boutique-admin/' . $id);
        flash('success', 'Commande annulée : les quantités réservées sont de nouveau disponibles en ligne.');
        redirect('/boutique-admin/' . $id);
    }

    /** Alerte « nouvelle commande » (badge et son dans l'application). */
    public function feed(): void
    {
        header('Cache-Control: no-store');
        $c = ShopService::counts();
        json_response(['ok' => true, 'new' => $c['new'], 'last' => (int) DB::value("SELECT COALESCE(MAX(id),0) FROM shop_orders WHERE status = 'new'")]);
    }

    // ------------------------------------------------------------------ réglages

    private const KEYS = ['enabled' => 'bool', 'title' => 'text', 'tagline' => 'text', 'color' => 'color', 'register_id' => 'register', 'delivery' => 'bool',
        'delivery_fee' => 'number', 'min_order' => 'number', 'stock_display' => ['badge', 'qty', 'none'], 'require_open' => 'bool', 'out_of_stock' => ['show', 'hide'], 'whatsapp' => 'text'];

    /** Clients inscrits sur la boutique en ligne. */
    public function accounts(): void
    {
        $p = paginate("FROM shop_accounts a", [], "a.id, a.name, a.phone, a.email, a.address, a.created_at, a.last_login_at,
            (SELECT COUNT(*) FROM shop_orders o WHERE o.account_id = a.id) AS orders,
            (SELECT COALESCE(SUM(o.total), 0) FROM shop_orders o WHERE o.account_id = a.id AND o.status IN ('paid','delivered')) AS spent,
            (SELECT MAX(o.created_at) FROM shop_orders o WHERE o.account_id = a.id) AS last_order", 'a.id DESC', 40);
        view('boutique::admin_accounts', ['title' => 'Clients de la boutique en ligne', 'p' => $p]);
    }

    public function settings(): void
    {
        $q = trim((string) Request::query('q', ''));
        $params = [];
        $where = "p.status = 'active' AND p.sellable = 1";
        if ($q !== '') {
            $where .= ' AND (p.name LIKE ? OR p.reference LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            array_push($params, $like, $like);
        }
        $p = paginate('FROM products p LEFT JOIN shop_products sp ON sp.product_id = p.id LEFT JOIN categories c ON c.id = p.category_id WHERE ' . $where, $params,
            'p.id, p.name, p.reference, p.image, p.sale_price, p.promo_price, p.description, p.requires_prescription, p.is_controlled, c.name AS category, COALESCE(sp.hidden,0) AS hidden, COALESCE(sp.featured,0) AS featured, sp.web_description',
            'COALESCE(sp.featured,0) DESC, p.name', 30);
        $online = (int) DB::value("SELECT COUNT(*) FROM products p LEFT JOIN shop_products sp ON sp.product_id = p.id WHERE p.status = 'active' AND p.sellable = 1 AND COALESCE(sp.hidden,0) = 0");
        view('boutique::admin_settings', ['title' => 'Boutique en ligne : réglages', 'p' => $p, 'q' => $q, 'online' => $online, 'shopUrl' => $this->shopUrl(),
            'registers' => DB::all('SELECT r.id, r.name, s.name AS store, w.name AS warehouse FROM cash_registers r JOIN stores s ON s.id = r.store_id JOIN warehouses w ON w.id = r.warehouse_id WHERE r.active = 1 ORDER BY r.id'),
            'current' => ShopService::register(), 'scripts' => ['vendor/qrcode/qrcode.js', 'js/qr.js']]);
    }

    public function settingsSave(): void
    {
        foreach (self::KEYS as $k => $type) {
            $v = Request::str($k);
            if ($type === 'bool') {
                $v = Request::input($k) === '1' ? '1' : '0';
            } elseif ($type === 'number') {
                $v = (string) max(0, round((float) str_replace([' ', ','], ['', '.'], $v), 2));
            } elseif ($type === 'color') {
                $v = preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : '#0d6efd';
            } elseif ($type === 'register') {
                $v = DB::value('SELECT id FROM cash_registers WHERE id = ? AND active = 1', [(int) $v]) ? (string) (int) $v : '';
            } elseif (is_array($type)) {
                $v = in_array($v, $type, true) ? $v : $type[0];
            } else {
                $v = mb_substr($v, 0, 200);
            }
            setting_set('shop_' . $k, $v);
        }
        Audit::log('shop.settings');
        flash('success', 'Réglages de la boutique en ligne enregistrés.');
        redirect('/boutique-admin/reglages');
    }

    /** Article : visible en ligne, à la une, texte en ligne. */
    public function product(int $id): void
    {
        if (!DB::value('SELECT 1 FROM products WHERE id = ?', [$id])) {
            throw new HttpException(404);
        }
        $row = ['hidden' => Request::input('online') === '1' ? 0 : 1, 'featured' => Request::input('featured') === '1' ? 1 : 0,
            'web_description' => trim(mb_substr(Request::str('web_description'), 0, 2000)) ?: null];
        DB::run('INSERT INTO shop_products (product_id, hidden, featured, web_description) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE hidden = VALUES(hidden), featured = VALUES(featured), web_description = VALUES(web_description)',
            [$id, $row['hidden'], $row['featured'], $row['web_description']]);
        Audit::log('shop.product', 'product', $id, $row);
        if (Request::wantsJson()) {
            json_response(['ok' => true]);
        }
        flash('success', 'Article mis à jour.');
        redirect('/boutique-admin/reglages', array_filter(['q' => Request::str('q'), 'page' => Request::str('page')]));
    }
}
