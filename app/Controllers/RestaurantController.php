<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\ProductService;
use App\Services\RestaurantService as R;

final class RestaurantController extends Controller
{
    private function guardFeature(): void
    {
        if (!feature('restaurant')) {
            throw new HttpException(404, 'Le module restaurant n\'est pas activé (Paramètres → Type d\'activité et modules).');
        }
    }

    private function orderOr404(int $id): array
    {
        $this->guardFeature();
        return R::order($id) ?? throw new HttpException(404);
    }

    /** Commande au format JSON pour l'écran de prise de commande. */
    private function orderJson(int $id): array
    {
        $o = R::order($id);
        $o['items'] = array_map(fn($i) => [
            'id' => (int) $i['id'], 'name' => $i['name'], 'qty' => (float) $i['quantity'], 'paid' => (float) $i['paid_qty'],
            'price' => (float) $i['unit_price'], 'note' => $i['note'], 'course' => (int) $i['course'], 'station' => $i['station'],
            'status' => $i['status'], 'ticket' => $i['ticket_number'], 'reason' => $i['cancel_reason'],
        ], $o['items']);
        return ['order' => [
            'id' => (int) $o['id'], 'number' => $o['number'], 'status' => $o['status'], 'type' => $o['type'], 'covers' => (int) $o['covers'],
            'table' => $o['table_name'], 'area' => $o['area_name'], 'waiter' => $o['waiter'], 'opened_at' => $o['opened_at'], 'note' => $o['note'],
            'customer_id' => $o['customer_id'] ? (int) $o['customer_id'] : null, 'customer' => $o['customer'] ?? $o['customer_name'], 'customer_phone' => $o['customer_phone'],
            'address' => $o['delivery_address'], 'total' => round($o['total'], 2), 'due' => round($o['due'], 2), 'paid' => round($o['paid'], 2), 'items' => $o['items'],
        ]];
    }

    // ------------------------------------------------------------------ plan de salle
    public function floor(): void
    {
        $this->guardFeature();
        view('restaurant/floor', [
            'title' => 'Plan de salle',
            'areas' => DB::all('SELECT * FROM restaurant_areas WHERE active = 1 ORDER BY sort_order, name'),
            'data' => $this->floorData(),
            'scripts' => ['js/restaurant-floor.js'],
        ]);
    }

    private function floorData(): array
    {
        return [
            'tables' => array_map(fn($t) => [
                'id' => (int) $t['id'], 'name' => $t['name'], 'area' => (int) $t['area_id'], 'seats' => (int) $t['seats'], 'shape' => $t['shape'],
                'order' => $t['order_id'] ? (int) $t['order_id'] : null, 'number' => $t['number'], 'status' => $t['order_status'], 'covers' => (int) $t['covers'],
                'opened' => $t['opened_at'], 'waiter' => $t['waiter'], 'due' => (float) $t['due'], 'ready' => (int) $t['ready'], 'unsent' => (int) $t['unsent'],
            ], R::floor()),
            'others' => array_map(fn($o) => ['id' => (int) $o['id'], 'number' => $o['number'], 'type' => $o['type'], 'name' => $o['customer_name'], 'phone' => $o['customer_phone'],
                'status' => $o['status'], 'due' => (float) $o['due'], 'ready' => (int) $o['ready'], 'opened' => $o['opened_at'], 'waiter' => $o['waiter']], R::openWithoutTable()),
            'now' => date('Y-m-d H:i:s'),
        ];
    }

    public function floorJson(): void
    {
        $this->guardFeature();
        json_response(['ok' => true] + $this->floorData());
    }

    public function open(): void
    {
        $this->guardFeature();
        $this->jsonAttempt(function () {
            $id = R::open(Request::int('table_id') ?: null, Request::str('type', 'dine_in'), Request::int('covers', 1),
                ['name' => Request::str('customer_name'), 'phone' => Request::str('customer_phone'), 'address' => Request::str('address'), 'id' => Request::int('customer_id')]);
            return ['id' => $id, 'url' => url('/restaurant/orders/' . $id)];
        });
    }

    public function orders(): void
    {
        $this->guardFeature();
        [$from, $to] = date_range('today');
        $where = 'o.opened_at BETWEEN ? AND ?';
        $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
        if (($st = Request::query('status')) && in_array($st, ['open', 'billed', 'paid', 'cancelled'], true)) {
            $where .= ' AND o.status = ?';
            $params[] = $st;
        }
        $p = paginate("FROM restaurant_orders o LEFT JOIN restaurant_tables t ON t.id = o.table_id JOIN users u ON u.id = o.waiter_id WHERE $where", $params,
            "o.*, t.name AS table_name, u.full_name AS waiter, (SELECT COALESCE(SUM(i.quantity * i.unit_price),0) FROM restaurant_order_items i WHERE i.order_id = o.id AND i.status <> 'cancelled') AS total", 'o.id DESC');
        view('restaurant/orders', ['title' => 'Commandes restaurant', 'p' => $p, 'from' => $from, 'to' => $to]);
    }

    // ------------------------------------------------------------------ prise de commande
    public function show(int $id): void
    {
        $o = $this->orderOr404($id);
        if (!in_array($o['status'], ['open', 'billed'], true)) {
            view('restaurant/order_closed', ['title' => 'Commande ' . $o['number'], 'o' => $o]);
        }
        $u = $this->user();
        view('restaurant/order', [
            'o' => $o, 'u' => $u,
            'categories' => DB::all('SELECT id, name, color, icon, image FROM categories WHERE active = 1 AND parent_id IS NULL ORDER BY sort_order, name'),
            'tables' => DB::all("SELECT t.id, t.name, a.name AS area, (SELECT o.id FROM restaurant_orders o WHERE o.table_id = t.id AND o.status IN ('open','billed') LIMIT 1) AS order_id FROM restaurant_tables t JOIN restaurant_areas a ON a.id = t.area_id WHERE t.active = 1 ORDER BY a.sort_order, t.sort_order, t.name"),
            'config' => [
                'csrf' => csrf_token(), 'orderId' => (int) $o['id'],
                'courses' => R::COURSES,
                'perms' => ['pay' => can('pos.sell'), 'cancel' => can('restaurant.cancel_item'), 'transfer' => can('restaurant.transfer')],
                'autoPrintKitchen' => setting('restaurant_print_kitchen', '1') === '1',
            ] + $this->orderJson($id),
        ], null);
    }

    public function data(int $id): void
    {
        $this->orderOr404($id);
        json_response(['ok' => true] + $this->orderJson($id));
    }

    public function addItem(int $id): void
    {
        $this->orderOr404($id);
        $this->jsonAttempt(function () use ($id) {
            $course = Request::input('course');
            R::addItem($id, Request::int('product_id'), Request::num('quantity', 1), Request::str('note') ?: null, $course === null || $course === '' ? null : (int) $course);
            return $this->orderJson($id);
        });
    }

    private function itemOrder(int $itemId): int
    {
        $this->guardFeature();
        return (int) (DB::value('SELECT order_id FROM restaurant_order_items WHERE id = ?', [$itemId]) ?? throw new HttpException(404));
    }

    public function updateItem(int $id): void
    {
        $itemId = $id;
        $orderId = $this->itemOrder($itemId);
        $this->jsonAttempt(function () use ($itemId, $orderId) {
            $course = Request::input('course');
            R::updateItem($itemId, Request::num('quantity'), Request::input('note') === null ? null : Request::str('note'), $course === null || $course === '' ? null : (int) $course);
            return $this->orderJson($orderId);
        });
    }

    public function cancelItem(int $id): void
    {
        $itemId = $id;
        $orderId = $this->itemOrder($itemId);
        $this->jsonAttempt(function () use ($itemId, $orderId) {
            $sup = Request::input('supervisor');
            $by = null;
            if (!Auth::can('restaurant.cancel_item') && is_array($sup) && !empty($sup['username'])) {
                $by = Auth::verifySupervisor((string) $sup['username'], (string) ($sup['secret'] ?? ''), 'restaurant.cancel_item');
            }
            $ticket = R::cancelItem($itemId, Request::num('quantity'), Request::str('reason'), (bool) Request::input('waste'), $by);
            // Un bon d'annulation est toujours signalé à la cuisine (écran) et imprimé s'il y a une imprimante serveur
            $print = $ticket ? $this->printTickets([$ticket], true) : ['urls' => [], 'printed' => 0, 'errors' => []];
            return $this->orderJson($orderId) + ['print' => $print['urls'], 'printed_server' => $print['printed'], 'print_errors' => $print['errors']];
        });
    }

    public function send(int $id): void
    {
        $this->orderOr404($id);
        $this->jsonAttempt(function () use ($id) {
            $tickets = R::send($id);
            // Les commandes s'affichent sur l'écran cuisine ; impression automatique seulement si activée
            $print = setting('restaurant_print_kitchen', '0') === '1' ? $this->printTickets($tickets, false) : ['urls' => [], 'printed' => 0, 'errors' => []];
            return $this->orderJson($id) + ['print' => $print['urls'], 'printed_server' => $print['printed'], 'print_errors' => $print['errors'], 'sent' => count($tickets)];
        });
    }

    /**
     * Imprime des bons cuisine : sur l'imprimante serveur du poste si elle existe,
     * sinon renvoie l'adresse du bon pour une impression par le navigateur.
     */
    private function printTickets(array $tickets, bool $browserFallback): array
    {
        $urls = [];
        $printed = 0;
        $errors = [];
        foreach ($tickets as $t) {
            $station = (string) DB::value('SELECT station FROM kitchen_tickets WHERE id = ?', [$t]);
            if (\App\Services\PrintService::targets('kitchen:' . $station)) {
                $r = \App\Services\PrintService::printKitchenTicket((int) $t);
                $printed += $r['printed'];
                $errors = array_merge($errors, $r['errors']);
            } elseif ($browserFallback || setting('restaurant_print_kitchen', '0') === '1') {
                $urls[] = url('/kitchen/tickets/' . $t . '/print', ['auto' => 1]);
            }
        }
        return ['urls' => $urls, 'printed' => $printed, 'errors' => $errors];
    }

    /** Impression à la demande des bons en cours d'une commande (bouton « Bon cuisine »). */
    public function printKitchen(int $id): void
    {
        $this->orderOr404($id);
        $this->jsonAttempt(function () use ($id) {
            $tickets = array_map('intval', array_column(DB::all("SELECT id FROM kitchen_tickets WHERE order_id = ? AND status IN ('pending','preparing') ORDER BY id", [$id]), 'id'));
            if (!$tickets) {
                throw new BusinessException('Aucun bon en préparation pour cette commande.');
            }
            $p = $this->printTickets($tickets, true);
            return ['print' => $p['urls'], 'printed_server' => $p['printed'], 'print_errors' => $p['errors'], 'count' => count($tickets)];
        });
    }

    /** Écran cuisine : imprimer un bon au besoin. */
    public function ticketPrintNow(int $id): void
    {
        $this->guardFeature();
        if (!can('restaurant.order') && !can('restaurant.kitchen')) {
            throw new HttpException(403);
        }
        $this->findOr404('kitchen_tickets', $id);
        $this->jsonAttempt(function () use ($id) {
            $p = $this->printTickets([$id], true);
            return ['print' => $p['urls'], 'printed_server' => $p['printed'], 'print_errors' => $p['errors']];
        });
    }

    public function serve(int $id): void
    {
        $this->orderOr404($id);
        $this->jsonAttempt(function () use ($id) {
            R::serveReady($id);
            return $this->orderJson($id);
        });
    }

    public function update(int $id): void
    {
        $this->orderOr404($id);
        $this->jsonAttempt(function () use ($id) {
            $d = [];
            foreach (['covers', 'note', 'customer_name', 'customer_phone', 'delivery_address', 'customer_id'] as $k) {
                if (Request::input($k) !== null) {
                    $d[$k] = Request::input($k);
                }
            }
            R::update($id, $d);
            return $this->orderJson($id);
        });
    }

    public function transfer(int $id): void
    {
        $this->orderOr404($id);
        $target = Request::int('table_id');
        $other = DB::value("SELECT id FROM restaurant_orders WHERE table_id = ? AND status IN ('open','billed') AND id <> ?", [$target, $id]);
        $this->attempt(fn() => $other ? R::merge($id, (int) $other) : R::transfer($id, $target), '/restaurant/orders/' . $id);
        flash('success', $other ? 'Tables fusionnées.' : 'Commande transférée.');
        redirect('/restaurant/orders/' . ($other ?: $id));
    }

    public function cancel(int $id): void
    {
        $this->orderOr404($id);
        $this->attempt(fn() => R::cancelOrder($id, Request::str('reason')), '/restaurant/orders/' . $id);
        flash('success', 'Commande annulée.');
        redirect('/restaurant');
    }

    // ------------------------------------------------------------------ addition et paiement
    public function bill(int $id): void
    {
        $o = $this->orderOr404($id);
        if (in_array($o['status'], ['open', 'billed'], true)) {
            R::bill($id);
            Audit::log('restaurant.bill', 'restaurant_order', $id, ['count' => (int) $o['bill_count'] + 1]);
        }
        view('restaurant/bill', ['title' => 'Addition ' . $o['number'], 'o' => $o, 'bodyClass' => setting('ticket_width', '80') === '58' ? 't58' : 't80',
            'autoPrint' => Request::query('auto') === '1', 'backUrl' => url('/restaurant/orders/' . $id)], 'layouts/print');
    }

    public function payForm(int $id): void
    {
        $o = $this->orderOr404($id);
        if (!in_array($o['status'], ['open', 'billed'], true) || $o['due'] <= 0) {
            flash('warning', 'Rien à encaisser sur cette commande.');
            redirect('/restaurant/orders/' . $id);
        }
        view('restaurant/pay', ['title' => 'Encaissement ' . $o['number'] . ($o['table_name'] ? ' — Table ' . $o['table_name'] : ''), 'o' => $o,
            'uuid' => $this->uuid(), 'scripts' => ['js/restaurant-pay.js']]);
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public function pay(int $id): void
    {
        $this->orderOr404($id);
        $back = '/restaurant/orders/' . $id . '/pay';
        $payments = [];
        foreach ((array) Request::input('payments', []) as $p) {
            if (is_array($p) && (float) str_replace([' ', ','], ['', '.'], (string) ($p['amount'] ?? 0)) > 0) {
                [$method, $provider] = array_pad(explode('|', (string) ($p['method'] ?? ''), 2), 2, null);
                $payments[] = ['method' => $method, 'provider' => $provider, 'amount' => (float) str_replace([' ', ','], ['', '.'], (string) $p['amount']), 'reference' => (string) ($p['reference'] ?? '')];
            }
        }
        $sup = Request::str('sup_username') !== '' ? ['username' => Request::str('sup_username'), 'secret' => (string) Request::input('sup_secret', '')] : null;
        try {
            $r = R::pay($id, array_map('floatval', (array) Request::input('qty', [])), [
                'client_uuid' => Request::str('client_uuid'), 'payments' => $payments, 'customer_id' => Request::int('customer_id') ?: null,
                'discount_type' => Request::str('discount_type') === 'percent' ? 'percent' : 'amount', 'discount_value' => Request::num('discount_value'),
                'supervisor' => $sup,
            ]);
        } catch (BusinessException $e) {
            $msg = preg_replace('/^(AUTH_REQUIRED|PRESCRIPTION_REQUIRED):/', '', $e->getMessage());
            if (str_starts_with($e->getMessage(), 'AUTH_REQUIRED')) {
                $msg .= ' — un responsable doit saisir ses identifiants ci-dessous.';
            }
            $this->fail($msg, $back);
        }
        flash('success', sprintf('Paiement enregistré (%s). Monnaie à rendre : %s.', $r['number'], money($r['change'])));
        $next = $r['order_closed'] ? url('/restaurant') : url('/restaurant/orders/' . $id);
        if (setting('pos_auto_print', '1') === '1') {
            $print = \App\Services\PrintService::printReceipt($r['sale_id']);
            if ($print['printed'] > 0) {
                header('Location: ' . $next); // $next est déjà une adresse complète (url())
                exit;
            }
            if ($print['errors']) {
                flash('warning', implode(' ', $print['errors']));
            }
        }
        redirect('/sales/' . $r['sale_id'] . '/ticket', ['auto' => setting('pos_auto_print', '1') === '1' ? 1 : 0, 'next' => $next]);
    }

    // ------------------------------------------------------------------ cuisine
    public function kitchen(): void
    {
        $this->guardFeature();
        $stations = R::stations();
        $station = in_array(Request::query('station'), $stations, true) ? (string) Request::query('station') : $stations[0];
        view('restaurant/kitchen', ['stations' => $stations, 'station' => $station, 'u' => $this->user()], null);
    }

    public function kitchenFeed(): void
    {
        $this->guardFeature();
        $station = (string) Request::query('station', 'cuisine');
        if (!in_array($station, R::stations(), true)) {
            json_response(['ok' => false, 'error' => 'Poste inconnu.'], 422);
        }
        json_response(['ok' => true, 'tickets' => R::kitchenFeed($station), 'now' => date('H:i')]);
    }

    public function ticketStatus(int $id): void
    {
        $this->guardFeature();
        $this->jsonAttempt(function () use ($id) {
            R::ticketStatus($id, Request::str('status'));
            return [];
        });
    }

    public function ticketPrint(int $id): void
    {
        $this->guardFeature();
        if (!can('restaurant.order') && !can('restaurant.kitchen')) {
            throw new HttpException(403);
        }
        $t = DB::one('SELECT kt.*, o.number AS order_number, o.type AS order_type, o.covers, o.customer_name, o.note AS order_note, t.name AS table_name, u.full_name AS waiter
                FROM kitchen_tickets kt JOIN restaurant_orders o ON o.id = kt.order_id LEFT JOIN restaurant_tables t ON t.id = o.table_id JOIN users u ON u.id = kt.user_id WHERE kt.id = ?', [$id]);
        if (!$t) {
            throw new HttpException(404);
        }
        $items = $t['type'] === 'cancel'
            ? DB::all('SELECT i.name, c.quantity, i.note, i.course, i.cancel_reason FROM kitchen_ticket_cancellations c JOIN restaurant_order_items i ON i.id = c.order_item_id WHERE c.kitchen_ticket_id = ?', [$id])
            : DB::all('SELECT i.name, i.quantity, i.note, i.course FROM restaurant_order_items i WHERE i.kitchen_ticket_id = ? ORDER BY i.course, i.id', [$id]);
        DB::run('UPDATE kitchen_tickets SET print_count = print_count + 1 WHERE id = ?', [$id]);
        view('restaurant/kitchen_ticket', ['title' => 'Bon ' . $t['number'], 't' => $t, 'items' => $items, 'bodyClass' => setting('ticket_width', '80') === '58' ? 't58' : 't80',
            'autoPrint' => Request::query('auto') === '1', 'backUrl' => url('/restaurant')], 'layouts/print');
    }

    // ------------------------------------------------------------------ configuration
    public function setup(): void
    {
        $this->guardFeature();
        view('restaurant/setup', [
            'title' => 'Salles & tables',
            'areas' => DB::all('SELECT a.*, (SELECT COUNT(*) FROM restaurant_tables WHERE area_id = a.id AND active = 1) AS n FROM restaurant_areas a ORDER BY a.sort_order, a.name'),
            'tables' => DB::all('SELECT t.*, a.name AS area FROM restaurant_tables t JOIN restaurant_areas a ON a.id = t.area_id ORDER BY a.sort_order, t.sort_order, t.name'),
            'stores' => DB::all('SELECT id, name FROM stores WHERE active = 1'),
            'edit' => ($e = Request::int('edit')) ? DB::one('SELECT * FROM restaurant_tables WHERE id = ?', [$e]) : null,
        ]);
    }

    public function areaStore(): void
    {
        $this->guardFeature();
        $name = Request::str('name');
        if ($name === '' || mb_strlen($name) > 100) {
            $this->fail('Nom de salle invalide.', '/restaurant/setup');
        }
        $id = DB::insert('restaurant_areas', ['name' => $name, 'store_id' => Request::int('store_id') ?: null, 'sort_order' => Request::int('sort_order')]);
        Audit::log('restaurant.area_create', 'restaurant_area', $id);
        flash('success', 'Salle créée.');
        redirect('/restaurant/setup');
    }

    public function tableStore(): void
    {
        $this->guardFeature();
        $area = Request::int('area_id');
        $prefix = Request::str('name');
        $count = max(1, min(50, Request::int('count', 1)));
        $seats = max(1, min(50, Request::int('seats', 4)));
        $shape = in_array(Request::str('shape'), ['square', 'round', 'long'], true) ? Request::str('shape') : 'square';
        if (!DB::value('SELECT 1 FROM restaurant_areas WHERE id = ?', [$area]) || $prefix === '' || mb_strlen($prefix) > 40) {
            $this->fail('Salle ou nom de table invalide.', '/restaurant/setup');
        }
        $created = 0;
        for ($i = 0; $i < $count; $i++) {
            // « T » × 5 → T1…T5 (en continuant la numérotation existante) ; nom libre si 1 seule table
            $name = $count === 1 ? $prefix : $prefix . ((int) DB::value('SELECT COUNT(*) FROM restaurant_tables WHERE area_id = ?', [$area]) + 1);
            if (DB::value('SELECT 1 FROM restaurant_tables WHERE area_id = ? AND name = ?', [$area, $name])) {
                $name .= '-' . ($i + 1);
            }
            DB::insert('restaurant_tables', ['area_id' => $area, 'name' => mb_substr($name, 0, 50), 'seats' => $seats, 'shape' => $shape, 'sort_order' => (int) DB::value('SELECT COUNT(*) FROM restaurant_tables WHERE area_id = ?', [$area])]);
            $created++;
        }
        flash('success', "$created table(s) créée(s).");
        redirect('/restaurant/setup');
    }

    public function tableUpdate(int $id): void
    {
        $this->guardFeature();
        $t = $this->findOr404('restaurant_tables', $id);
        $name = Request::str('name');
        if ($name === '' || mb_strlen($name) > 50) {
            $this->fail('Nom invalide.', '/restaurant/setup?edit=' . $id);
        }
        $active = Request::input('active') === '1' ? 1 : 0;
        if (!$active && DB::value("SELECT 1 FROM restaurant_orders WHERE table_id = ? AND status IN ('open','billed')", [$id])) {
            $this->fail('Table occupée : impossible de la désactiver.', '/restaurant/setup?edit=' . $id);
        }
        DB::update('restaurant_tables', ['name' => $name, 'seats' => max(1, min(50, Request::int('seats', (int) $t['seats']))), 'area_id' => Request::int('area_id') ?: $t['area_id'],
            'shape' => in_array(Request::str('shape'), ['square', 'round', 'long'], true) ? Request::str('shape') : $t['shape'], 'sort_order' => Request::int('sort_order'), 'active' => $active], 'id = ?', [$id]);
        flash('success', 'Table mise à jour.');
        redirect('/restaurant/setup');
    }

    public function recipes(): void
    {
        $this->guardFeature();
        $rows = DB::all("SELECT p.id, p.name, p.sale_price, c.name AS category, (SELECT COUNT(*) FROM product_recipes r WHERE r.product_id = p.id) AS n,
                (SELECT COALESCE(SUM(r.quantity * i.cost_price),0) FROM product_recipes r JOIN products i ON i.id = r.ingredient_id WHERE r.product_id = p.id) AS cost
                FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.status = 'active' AND p.sellable = 1 ORDER BY c.name, p.name");
        view('restaurant/recipes', ['title' => 'Fiches techniques (recettes)', 'rows' => $rows]);
    }

    public function recipeEdit(int $id): void
    {
        $this->guardFeature();
        $p = $this->findOr404('products', $id);
        view('restaurant/recipe_edit', [
            'title' => 'Fiche technique : ' . $p['name'], 'p' => $p,
            'lines' => DB::all('SELECT r.*, i.name, i.reference, i.cost_price, u.short_name AS unit FROM product_recipes r JOIN products i ON i.id = r.ingredient_id LEFT JOIN units u ON u.id = i.unit_id WHERE r.product_id = ?', [$id]),
            'cost' => ProductService::recipeCost($id),
        ]);
    }

    public function recipeSave(int $id): void
    {
        $this->guardFeature();
        $this->findOr404('products', $id);
        $lines = [];
        foreach ($this->rowsFromForm() as $r) {
            if (!empty($r['product_id'])) {
                $lines[(int) $r['product_id']] = (float) ($r['quantity'] ?? 0);
            }
        }
        $this->attempt(fn() => ProductService::saveRecipe($id, $lines), '/restaurant/recipes/' . $id);
        flash('success', 'Fiche technique enregistrée : les ingrédients seront déstockés à chaque vente.');
        redirect('/restaurant/recipes/' . $id);
    }

    // ------------------------------------------------------------------ rapport
    public function report(): void
    {
        $this->guardFeature();
        [$from, $to] = date_range('first day of this month');
        $p = [$from . ' 00:00:00', $to . ' 23:59:59'];
        view('restaurant/report', [
            'title' => 'Rapport restaurant', 'from' => $from, 'to' => $to,
            'summary' => DB::one("SELECT COUNT(*) AS orders, COALESCE(SUM(covers),0) AS covers, SUM(type = 'dine_in') AS dine_in, SUM(type = 'takeaway') AS takeaway, SUM(type = 'delivery') AS delivery,
                    AVG(TIMESTAMPDIFF(MINUTE, opened_at, closed_at)) AS avg_minutes FROM restaurant_orders WHERE status = 'paid' AND opened_at BETWEEN ? AND ?", $p),
            'revenue' => (float) DB::value("SELECT COALESCE(SUM(s.total),0) FROM sales s JOIN restaurant_orders o ON o.id = s.restaurant_order_id WHERE s.status <> 'cancelled' AND o.opened_at BETWEEN ? AND ?", $p),
            'waiters' => DB::all("SELECT u.full_name, COUNT(DISTINCT o.id) AS orders, COALESCE(SUM(o.covers),0) AS covers,
                    (SELECT COALESCE(SUM(s.total),0) FROM sales s JOIN restaurant_orders o2 ON o2.id = s.restaurant_order_id WHERE o2.waiter_id = u.id AND s.status <> 'cancelled' AND o2.opened_at BETWEEN ? AND ?) AS revenue
                    FROM restaurant_orders o JOIN users u ON u.id = o.waiter_id WHERE o.status = 'paid' AND o.opened_at BETWEEN ? AND ? GROUP BY u.id, u.full_name ORDER BY revenue DESC", array_merge($p, $p)),
            'dishes' => DB::all("SELECT i.name, SUM(i.quantity) AS qty, SUM(i.quantity * i.unit_price) AS revenue FROM restaurant_order_items i JOIN restaurant_orders o ON o.id = i.order_id
                    WHERE i.status <> 'cancelled' AND o.status = 'paid' AND o.opened_at BETWEEN ? AND ? GROUP BY i.name ORDER BY qty DESC LIMIT 20", $p),
            'stations' => DB::all("SELECT station, COUNT(*) AS n, AVG(TIMESTAMPDIFF(SECOND, created_at, ready_at)) AS avg_prep FROM kitchen_tickets WHERE type = 'order' AND ready_at IS NOT NULL AND created_at BETWEEN ? AND ? GROUP BY station", $p),
            'cancels' => DB::all("SELECT i.name, i.quantity, i.cancel_reason, i.wasted, o.number, u.full_name, i.unit_price FROM restaurant_order_items i JOIN restaurant_orders o ON o.id = i.order_id LEFT JOIN users u ON u.id = i.cancelled_by
                    WHERE i.status = 'cancelled' AND i.cancel_reason IS NOT NULL AND o.opened_at BETWEEN ? AND ? ORDER BY i.id DESC LIMIT 50", $p),
            'tables' => DB::all("SELECT t.name, COUNT(o.id) AS turns FROM restaurant_orders o JOIN restaurant_tables t ON t.id = o.table_id WHERE o.status = 'paid' AND o.opened_at BETWEEN ? AND ? GROUP BY t.id, t.name ORDER BY turns DESC LIMIT 20", $p),
        ]);
    }
}
