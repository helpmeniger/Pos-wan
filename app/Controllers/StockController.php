<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Csv;
use App\Core\DB;
use App\Core\Export;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\InventoryService;
use App\Services\StockService;

final class StockController extends Controller
{
    public function levels(): void
    {
        $wh = (int) Request::query('warehouse', 0);
        $where = ["p.status = 'active'", 'p.track_stock = 1'];
        $params = [];
        if (($q = trim((string) Request::query('q', ''))) !== '') {
            $where[] = '(p.name LIKE ? OR p.reference LIKE ? OR p.barcode = ?)';
            array_push($params, "%$q%", "%$q%", $q);
        }
        if ($c = (int) Request::query('category', 0)) {
            $where[] = 'p.category_id = ?';
            $params[] = $c;
        }
        $whJoin = $wh ? ' AND sl.warehouse_id = ' . $wh : '';
        $filter = Request::query('filter');
        $having = $filter === 'low' ? ' HAVING qty <= p.min_stock' : ($filter === 'out' ? ' HAVING qty <= 0' : '');
        $sql = "SELECT p.id, p.name, p.reference, p.min_stock, p.cost_price, p.sale_price, c.name AS category, u.short_name AS unit, COALESCE(SUM(sl.quantity),0) AS qty
                FROM products p LEFT JOIN stock_levels sl ON sl.product_id = p.id $whJoin LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN units u ON u.id = p.unit_id
                WHERE " . implode(' AND ', $where) . " GROUP BY p.id, p.name, p.reference, p.min_stock, p.cost_price, p.sale_price, c.name, u.short_name $having";
        if (Export::requested()) {
            $rows = DB::all($sql . ' ORDER BY p.name', $params);
            $cost = can('products.cost_view');
            Export::download('stock_' . date('Ymd') . '.csv', 'État des stocks', array_merge(['Référence', 'Article', 'Catégorie', 'Stock', 'Unité', 'Seuil'], $cost ? ['CMP', 'Valeur'] : []),
                array_map(fn($r) => array_merge([$r['reference'], $r['name'], $r['category'], $r['qty'], $r['unit'], $r['min_stock']], $cost ? [$r['cost_price'], round($r['qty'] * $r['cost_price'], 2)] : []), $rows));
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM ($sql) t", $params);
        $page = max(1, (int) Request::query('page', 1));
        $rows = DB::all($sql . ' ORDER BY p.name LIMIT 50 OFFSET ' . (($page - 1) * 50), $params);
        $value = DB::value("SELECT COALESCE(SUM(qty * cost_price),0) FROM ($sql) t WHERE qty > 0", $params);
        view('stock/levels', [
            'title' => 'État des stocks', 'rows' => $rows, 'p' => ['total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / 50)), 'rows' => $rows],
            'warehouses' => $this->warehouses(), 'categories' => DB::all('SELECT id, name FROM categories ORDER BY name'), 'value' => (float) $value, 'wh' => $wh,
        ]);
    }

    public function movements(): void
    {
        [$from, $to] = date_range('-7 days');
        $where = ['m.created_at BETWEEN ? AND ?'];
        $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
        foreach (['product' => 'm.product_id', 'warehouse' => 'm.warehouse_id', 'user' => 'm.user_id'] as $k => $col) {
            if ($v = (int) Request::query($k, 0)) {
                $where[] = "$col = ?";
                $params[] = $v;
            }
        }
        if (($t = (string) Request::query('type', '')) !== '' && isset(\App\Services\Catalog::STOCK_MOVEMENT_TYPES[$t])) {
            $where[] = 'm.type = ?';
            $params[] = $t;
        }
        $from_ = 'FROM stock_movements m JOIN products p ON p.id = m.product_id JOIN warehouses w ON w.id = m.warehouse_id LEFT JOIN users u ON u.id = m.user_id LEFT JOIN stock_lots l ON l.id = m.lot_id WHERE ' . implode(' AND ', $where);
        if (Export::requested()) {
            Export::download("mouvements_{$from}_{$to}.csv", 'Mouvements de stock', ['Date', 'Article', 'Entrepôt', 'Type', 'Lot', 'Quantité', 'Solde', 'Motif', 'Utilisateur'],
                DB::all("SELECT m.created_at, p.name, w.name AS w, m.type, l.lot_number, m.quantity, m.balance_after, m.reason, u.full_name $from_ ORDER BY m.id", $params));
        }
        $p = paginate($from_, $params, 'm.*, p.name AS product, w.name AS warehouse, u.full_name, l.lot_number', 'm.id DESC', 50);
        view('stock/movements', ['title' => 'Mouvements de stock', 'p' => $p, 'from' => $from, 'to' => $to, 'warehouses' => $this->warehouses(),
            'product' => ($pid = (int) Request::query('product', 0)) ? DB::one('SELECT id, name FROM products WHERE id = ?', [$pid]) : null]);
    }

    public function adjustForm(): void
    {
        $pid = (int) Request::query('product', 0);
        $product = $pid ? DB::one('SELECT * FROM products WHERE id = ?', [$pid]) : null;
        view('stock/adjust', [
            'title' => 'Ajustement de stock', 'product' => $product, 'warehouses' => $this->warehouses(), 'wh' => $this->defaultWarehouseId(),
            'levels' => $product ? DB::all('SELECT w.id, w.name, COALESCE(sl.quantity,0) AS q FROM warehouses w LEFT JOIN stock_levels sl ON sl.warehouse_id = w.id AND sl.product_id = ? WHERE w.active = 1', [$pid]) : [],
            'lots' => $product ? DB::all('SELECT l.*, w.name AS warehouse FROM stock_lots l JOIN warehouses w ON w.id = l.warehouse_id WHERE l.product_id = ? AND l.quantity > 0 ORDER BY l.expiry_date', [$pid]) : [],
        ]);
    }

    public function adjust(): void
    {
        $pid = Request::int('product_id');
        $wh = Request::int('warehouse_id');
        $mode = Request::str('mode');
        $qty = Request::num('quantity');
        $back = '/stock/adjust?product=' . $pid;
        if ($pid <= 0 || $wh <= 0 || $qty < 0) {
            $this->fail('Saisie invalide.', $back);
        }
        $current = StockService::level($pid, $wh);
        $lotId = Request::int('lot_id') ?: null;
        // Mode « set » : on saisit la quantité réelle, l'écart est calculé
        $delta = match ($mode) {
            'set' => round($qty - $current, 3),
            'add' => $qty,
            default => -$qty,
        };
        $type = in_array(Request::str('type'), ['adjustment', 'loss', 'expired'], true) ? Request::str('type') : 'adjustment';
        if (abs($delta) < 0.0005) {
            $this->fail('Aucun écart : rien à ajuster.', $back);
        }
        if ($delta > 0 && $type !== 'adjustment') {
            $this->fail('Une perte ou un produit périmé ne peut que diminuer le stock.', $back);
        }
        $this->attempt(fn() => StockService::adjust($pid, $wh, $delta, $type, Request::str('reason'), $lotId, Request::str('lot_number') ?: null, Request::str('expiry_date') ?: null), $back);
        flash('success', 'Stock ajusté (' . ($delta > 0 ? '+' : '') . qty($delta) . ').');
        redirect('/products/' . $pid);
    }

    // ------------------------------------------------------------------ transferts
    public function transfers(): void
    {
        $p = paginate('FROM stock_transfers t JOIN warehouses a ON a.id = t.from_warehouse_id JOIN warehouses b ON b.id = t.to_warehouse_id JOIN users u ON u.id = t.user_id', [],
            't.*, a.name AS from_name, b.name AS to_name, u.full_name, (SELECT COUNT(*) FROM stock_transfer_items WHERE transfer_id = t.id) AS line_count', 't.id DESC');
        view('stock/transfers', ['title' => 'Transferts entre entrepôts', 'p' => $p]);
    }

    public function transferForm(): void
    {
        $ws = $this->warehouses();
        if (count($ws) < 2) {
            flash('warning', 'Créez au moins deux entrepôts (Boutiques, entrepôts, caisses) pour effectuer un transfert.');
        }
        view('stock/transfer_form', ['title' => 'Nouveau transfert', 'warehouses' => $ws, 'wh' => $this->defaultWarehouseId()]);
    }

    public function transferStore(): void
    {
        $items = array_map(fn($r) => ['product_id' => (int) ($r['product_id'] ?? 0), 'quantity' => (float) ($r['quantity'] ?? 0)], $this->rowsFromForm());
        $items = array_values(array_filter($items, fn($i) => $i['product_id'] > 0 && $i['quantity'] > 0));
        $id = $this->attempt(fn() => StockService::transfer(Request::int('from_warehouse_id'), Request::int('to_warehouse_id'), $items, Request::str('note') ?: null), '/stock/transfers/create');
        flash('success', 'Transfert enregistré.');
        redirect('/stock/transfers/' . $id);
    }

    public function transferShow(int $id): void
    {
        $t = DB::one('SELECT t.*, a.name AS from_name, b.name AS to_name, u.full_name FROM stock_transfers t JOIN warehouses a ON a.id = t.from_warehouse_id JOIN warehouses b ON b.id = t.to_warehouse_id JOIN users u ON u.id = t.user_id WHERE t.id = ?', [$id]);
        if (!$t) {
            throw new HttpException(404);
        }
        view('stock/transfer_show', ['title' => 'Bon de transfert ' . $t['number'], 't' => $t, 'bodyClass' => 'a4', 'backUrl' => url('/stock/transfers'),
            'items' => DB::all('SELECT ti.*, p.name, p.reference FROM stock_transfer_items ti JOIN products p ON p.id = ti.product_id WHERE ti.transfer_id = ?', [$id])], 'layouts/print');
    }

    // ------------------------------------------------------------------ péremption, lots, rappels
    public function expiry(): void
    {
        $days = max(1, min(730, (int) Request::query('days', (int) setting('expiry_alert_days', '60'))));
        $rows = DB::all('SELECT l.*, p.name, p.reference, p.cost_price, w.name AS warehouse, DATEDIFF(l.expiry_date, CURDATE()) AS days_left FROM stock_lots l JOIN products p ON p.id = l.product_id JOIN warehouses w ON w.id = l.warehouse_id
                WHERE l.quantity > 0 AND l.expiry_date IS NOT NULL AND l.expiry_date <= CURDATE() + INTERVAL ? DAY ORDER BY l.expiry_date', [$days]);
        if (Export::requested()) {
            Export::download('peremptions_' . date('Ymd') . '.csv', 'Lots proches de la péremption', ['Article', 'Lot', 'Entrepôt', 'Péremption', 'Jours restants', 'Quantité'], array_map(fn($r) => [$r['name'], $r['lot_number'], $r['warehouse'], $r['expiry_date'], $r['days_left'], $r['quantity']], $rows));
        }
        view('stock/expiry', ['title' => 'Lots & péremptions', 'rows' => $rows, 'days' => $days]);
    }

    public function lots(): void
    {
        $q = trim((string) Request::query('q', ''));
        $rows = $q === '' ? [] : DB::all('SELECT l.*, p.name, w.name AS warehouse, (SELECT COALESCE(SUM(sil.quantity),0) FROM sale_item_lots sil WHERE sil.lot_id = l.id) AS sold FROM stock_lots l JOIN products p ON p.id = l.product_id JOIN warehouses w ON w.id = l.warehouse_id WHERE l.lot_number LIKE ? OR p.name LIKE ? ORDER BY l.expiry_date LIMIT 200', ["%$q%", "%$q%"]);
        $sales = [];
        if (($lotId = (int) Request::query('lot', 0)) > 0) {
            // Traçabilité descendante : à qui ce lot a-t-il été délivré ?
            $sales = DB::all('SELECT s.id, s.number, s.created_at, c.name AS customer, c.phone, sil.quantity, pr.patient_name FROM sale_item_lots sil JOIN sale_items si ON si.id = sil.sale_item_id JOIN sales s ON s.id = si.sale_id LEFT JOIN customers c ON c.id = s.customer_id LEFT JOIN prescriptions pr ON pr.sale_id = s.id WHERE sil.lot_id = ? ORDER BY s.id DESC', [$lotId]);
        }
        view('stock/lots', ['title' => 'Traçabilité des lots', 'rows' => $rows, 'q' => $q, 'sales' => $sales, 'lotId' => $lotId]);
    }

    /** Rappel de lot : bloque la vente du lot (et sort éventuellement la quantité du stock vendable). */
    public function recall(int $id): void
    {
        $lot = $this->findOr404('stock_lots', $id);
        $reason = Request::str('reason');
        if ($reason === '') {
            $this->fail('Motif du rappel obligatoire.', '/stock/lots?q=' . urlencode($lot['lot_number']));
        }
        DB::update('stock_lots', ['recalled' => 1, 'recall_reason' => mb_substr($reason, 0, 255)], 'id = ?', [$id]);
        Audit::log('pharmacy.lot_recall', 'stock_lot', $id, ['lot' => $lot['lot_number'], 'reason' => $reason, 'qty' => $lot['quantity']]);
        flash('warning', 'Lot ' . $lot['lot_number'] . ' rappelé : il ne peut plus être vendu. Retournez-le au fournisseur ou déclarez-le en perte.');
        redirect('/stock/lots?q=' . urlencode($lot['lot_number']) . '&lot=' . $id);
    }

    // ------------------------------------------------------------------ inventaires
    public function inventories(): void
    {
        $p = paginate('FROM inventories i JOIN warehouses w ON w.id = i.warehouse_id JOIN users u ON u.id = i.user_id', [],
            'i.*, w.name AS warehouse, u.full_name, (SELECT COUNT(*) FROM inventory_items WHERE inventory_id = i.id) AS n, (SELECT COUNT(*) FROM inventory_items WHERE inventory_id = i.id AND counted_qty IS NOT NULL) AS counted', 'i.id DESC');
        view('stock/inventories', ['title' => 'Inventaires', 'p' => $p, 'warehouses' => $this->warehouses(), 'categories' => DB::all('SELECT id, name FROM categories ORDER BY name')]);
    }

    public function inventoryStart(): void
    {
        $id = $this->attempt(fn() => InventoryService::start(Request::int('warehouse_id'), Request::int('category_id') ?: null, Request::str('note') ?: null), '/inventories');
        flash('success', 'Inventaire créé : saisissez les quantités comptées.');
        redirect('/inventories/' . $id);
    }

    private function inventoryOr404(int $id): array
    {
        return DB::one('SELECT i.*, w.name AS warehouse, u.full_name, v.full_name AS validator, c.name AS category FROM inventories i JOIN warehouses w ON w.id = i.warehouse_id JOIN users u ON u.id = i.user_id LEFT JOIN users v ON v.id = i.validated_by LEFT JOIN categories c ON c.id = i.category_id WHERE i.id = ?', [$id])
            ?? throw new HttpException(404);
    }

    private function inventoryItems(int $id, string $q = '', string $filter = ''): array
    {
        $sql = 'SELECT ii.*, p.name, p.reference, p.barcode, p.cost_price, c.name AS category, cu.full_name AS counter FROM inventory_items ii JOIN products p ON p.id = ii.product_id
                LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN users cu ON cu.id = ii.counted_by WHERE ii.inventory_id = ?';
        $params = [$id];
        if ($q !== '') {
            $sql .= ' AND (p.name LIKE ? OR p.barcode = ? OR p.reference = ?)';
            array_push($params, '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%', $q, $q);
        }
        $sql .= match ($filter) {
            'uncounted' => ' AND ii.counted_qty IS NULL',
            'diff' => ' AND ii.counted_qty IS NOT NULL AND ABS(ii.counted_qty - ii.expected_qty) >= 0.0005',
            'surplus' => ' AND ii.counted_qty IS NOT NULL AND ii.counted_qty - ii.expected_qty >= 0.0005',
            'missing' => ' AND ii.counted_qty IS NOT NULL AND ii.expected_qty - ii.counted_qty >= 0.0005',
            'conform' => ' AND ii.counted_qty IS NOT NULL AND ABS(ii.counted_qty - ii.expected_qty) < 0.0005',
            default => '',
        };
        return DB::all($sql . ' ORDER BY c.name, p.name', $params);
    }

    public function inventoryShow(int $id): void
    {
        $inv = $this->inventoryOr404($id);
        $q = trim((string) Request::query('q', ''));
        $filter = (string) Request::query('f', '');
        $items = $this->inventoryItems($id, $q, $filter);
        if (Request::query('print') === '1') {
            view('stock/inventory_sheet', ['title' => 'Feuille d\'inventaire ' . $inv['number'], 'inv' => $inv, 'items' => $items, 'bodyClass' => 'a4', 'backUrl' => url('/inventories/' . $id)], 'layouts/print');
        }
        view('stock/inventory', ['title' => 'Inventaire ' . $inv['number'], 'inv' => $inv, 'items' => $items, 'q' => $q, 'filter' => $filter,
            'sum' => InventoryService::summary($id), 'scripts' => ['js/inventory.js'],
            'log' => DB::all('SELECT l.*, p.name, u.full_name FROM inventory_count_log l JOIN inventory_items ii ON ii.id = l.item_id JOIN products p ON p.id = ii.product_id JOIN users u ON u.id = l.user_id WHERE l.inventory_id = ? ORDER BY l.id DESC LIMIT 30', [$id])]);
    }

    /** Lecture d'un code-barres (douchette ou caméra) : +1 sur l'article. */
    public function inventoryScan(int $id): void
    {
        $this->jsonAttempt(fn() => InventoryService::scan($id, Request::str('code')));
    }

    /** Saisie manuelle d'une ligne, correction ou remise à zéro (enregistrée immédiatement). */
    public function inventoryCount(int $id): void
    {
        $this->jsonAttempt(fn() => InventoryService::setCount($id, Request::int('item_id'), Request::input('qty'), Request::input('note') !== null ? Request::str('note') : null, Request::input('reset') === true || Request::str('reset') === '1'));
    }

    /** Rapport d'écarts : impression, CSV, PDF. */
    public function inventoryReport(int $id): void
    {
        $inv = $this->inventoryOr404($id);
        $filter = (string) Request::query('f', 'diff');
        $items = $this->inventoryItems($id, '', $filter);
        $sum = InventoryService::summary($id);
        if (\App\Core\Export::requested()) {
            $rows = array_map(fn($i) => [$i['category'] ?? '', $i['name'], $i['reference'], $i['barcode'] ?? '', (float) $i['expected_qty'], $i['counted_qty'] !== null ? (float) $i['counted_qty'] : '',
                $i['counted_qty'] !== null ? round((float) $i['counted_qty'] - (float) $i['expected_qty'], 3) : '', $i['counted_qty'] !== null ? round(((float) $i['counted_qty'] - (float) $i['expected_qty']) * (float) $i['cost_price'], 2) : '', $i['note'] ?? '', $i['counter'] ?? ''], $items);
            \App\Core\Export::download('inventaire_' . $inv['number'] . '.csv', 'Inventaire ' . $inv['number'] . ' — ' . $inv['warehouse'], ['Catégorie', 'Article', 'Référence', 'Code-barres', 'Théorique', 'Compté', 'Écart', 'Valeur écart', 'Observation', 'Compté par'], $rows,
                ['subtitle' => 'Statut : ' . $inv['status'] . ' · excédents ' . $sum['surplus'] . ' · manquants ' . $sum['missing'] . ' · conformes ' . $sum['conform'] . ' · non comptés ' . $sum['uncounted'], 'landscape' => true]);
        }
        view('stock/inventory_report', ['title' => 'Rapport d\'inventaire ' . $inv['number'], 'inv' => $inv, 'items' => $items, 'sum' => $sum, 'filter' => $filter, 'bodyClass' => 'a4', 'backUrl' => url('/inventories/' . $id)], 'layouts/print');
    }

    public function inventorySave(int $id): void
    {
        $counts = Request::input('counted', []);
        $notes = Request::input('notes', []);
        $this->attempt(fn() => InventoryService::saveCounts($id, is_array($counts) ? $counts : [], is_array($notes) ? $notes : []), '/inventories/' . $id);
        flash('success', 'Comptages enregistrés.');
        redirect('/inventories/' . $id);
    }

    public function inventoryValidate(int $id): void
    {
        $r = $this->attempt(fn() => InventoryService::validate($id), '/inventories/' . $id);
        flash('success', sprintf('Inventaire validé : %d article(s) ajusté(s), écart valorisé %s.', $r['adjusted'], money($r['value'])));
        redirect('/inventories/' . $id);
    }

    public function inventoryCancel(int $id): void
    {
        $inv = $this->findOr404('inventories', $id);
        if ($inv['status'] !== 'draft') {
            $this->fail('Seul un brouillon peut être annulé.', '/inventories/' . $id);
        }
        DB::update('inventories', ['status' => 'cancelled'], 'id = ?', [$id]);
        Audit::log('inventory.cancel', 'inventory', $id);
        flash('success', 'Inventaire annulé (aucun stock modifié).');
        redirect('/inventories');
    }
}
