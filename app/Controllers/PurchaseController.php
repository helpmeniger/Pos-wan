<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\PurchaseService;

final class PurchaseController extends Controller
{
    /** Recherche d'articles (JSON) pour les formulaires à lignes. */
    public function productSearch(): void
    {
        $q = trim((string) Request::query('q', ''));
        if ($q === '') {
            json_response(['ok' => true, 'items' => []]);
        }
        $wh = (int) Request::query('wh', 0);
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $rows = DB::all("SELECT p.id, p.name, p.reference, p.barcode, p.cost_price, p.sale_price, p.track_lots" . ($wh ? ', COALESCE(sl.quantity,0) AS stock' : '') . "
                FROM products p" . ($wh ? ' LEFT JOIN stock_levels sl ON sl.product_id = p.id AND sl.warehouse_id = ' . $wh : '') . "
                WHERE p.status = 'active' AND (p.barcode = ? OR p.reference = ? OR p.name LIKE ?) ORDER BY (p.barcode = ?) DESC, p.name LIMIT 15", [$q, $q, $like, $q]);
        if (!can('products.cost_view')) {
            foreach ($rows as &$r) {
                unset($r['cost_price']);
            }
        }
        json_response(['ok' => true, 'items' => array_map(fn($r) => array_merge($r, ['track_lots' => (int) $r['track_lots'] === 1]), $rows)]);
    }

    public function index(): void
    {
        $where = '1=1';
        $params = [];
        if (($st = Request::query('status')) && in_array($st, ['draft', 'ordered', 'partial', 'received', 'cancelled'], true)) {
            $where = 'po.status = ?';
            $params[] = $st;
        }
        $p = paginate("FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id JOIN warehouses w ON w.id = po.warehouse_id WHERE $where", $params, 'po.*, s.name AS supplier, w.name AS warehouse', 'po.id DESC');
        view('purchases/index', ['title' => 'Commandes fournisseurs', 'p' => $p]);
    }

    public function create(): void
    {
        view('purchases/form', ['title' => 'Nouvelle commande fournisseur', 'suppliers' => DB::all('SELECT id, name FROM suppliers WHERE active = 1 ORDER BY name'), 'warehouses' => $this->warehouses(), 'wh' => $this->defaultWarehouseId(), 'supplierId' => (int) Request::query('supplier', 0)]);
    }

    public function store(): void
    {
        $id = $this->attempt(fn() => PurchaseService::createOrder(Request::int('supplier_id'), Request::int('warehouse_id'), $this->rowsFromForm(), Request::str('expected_date') ?: null, Request::str('note') ?: null, Request::str('status') === 'draft' ? 'draft' : 'ordered'), '/purchases/create');
        flash('success', 'Commande enregistrée. Elle n\'augmente pas le stock tant qu\'elle n\'est pas réceptionnée.');
        redirect('/purchases/' . $id);
    }

    private function order(int $id): array
    {
        $po = DB::one('SELECT po.*, s.name AS supplier, s.phone AS supplier_phone, s.address AS supplier_address, w.name AS warehouse, u.full_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id JOIN warehouses w ON w.id = po.warehouse_id JOIN users u ON u.id = po.user_id WHERE po.id = ?', [$id]);
        if (!$po) {
            throw new HttpException(404);
        }
        return $po;
    }

    public function show(int $id): void
    {
        $po = $this->order($id);
        $items = DB::all('SELECT i.*, p.name, p.reference FROM purchase_order_items i JOIN products p ON p.id = i.product_id WHERE i.purchase_order_id = ?', [$id]);
        if (Request::query('print') === '1') {
            view('purchases/print', ['title' => 'Bon de commande ' . $po['number'], 'po' => $po, 'items' => $items, 'bodyClass' => 'a4', 'backUrl' => url('/purchases/' . $id)], 'layouts/print');
        }
        view('purchases/show', ['title' => 'Commande ' . $po['number'], 'po' => $po, 'items' => $items,
            'receptions' => DB::all('SELECT * FROM receptions WHERE purchase_order_id = ? ORDER BY id', [$id])]);
    }

    public function status(int $id): void
    {
        $po = $this->order($id);
        $to = Request::str('status');
        $allowed = ['draft' => ['ordered', 'cancelled'], 'ordered' => ['cancelled'], 'partial' => ['received']];
        if (!in_array($to, $allowed[$po['status']] ?? [], true)) {
            $this->fail('Changement de statut non autorisé.', '/purchases/' . $id);
        }
        DB::update('purchase_orders', ['status' => $to], 'id = ?', [$id]);
        Audit::log('purchase.status', 'purchase_order', $id, ['from' => $po['status'], 'to' => $to]);
        flash('success', $to === 'received' ? 'Commande soldée (reliquat abandonné).' : 'Statut mis à jour.');
        redirect('/purchases/' . $id);
    }

    public function receiveForm(int $id): void
    {
        $po = $this->order($id);
        if (!in_array($po['status'], ['ordered', 'partial', 'draft'], true)) {
            $this->fail('Cette commande ne peut plus être réceptionnée.', '/purchases/' . $id);
        }
        view('purchases/receive', ['title' => 'Réception de la commande ' . $po['number'], 'po' => $po,
            'items' => DB::all('SELECT i.*, p.name, p.reference, p.track_lots FROM purchase_order_items i JOIN products p ON p.id = i.product_id WHERE i.purchase_order_id = ?', [$id])]);
    }

    public function receiveDirectForm(): void
    {
        view('purchases/receive_direct', ['title' => 'Réception de marchandises', 'suppliers' => DB::all('SELECT id, name FROM suppliers WHERE active = 1 ORDER BY name'), 'warehouses' => $this->warehouses(), 'wh' => $this->defaultWarehouseId(), 'supplierId' => (int) Request::query('supplier', 0)]);
    }

    public function receiveStore(): void
    {
        $poId = Request::int('purchase_order_id') ?: null;
        $back = $poId ? '/purchases/' . $poId . '/receive' : '/receptions/create';
        $id = $this->attempt(fn() => PurchaseService::receive(Request::int('supplier_id'), Request::int('warehouse_id'), $this->rowsFromForm(), Request::num('extra_costs'), $poId, Request::str('supplier_invoice_ref') ?: null, Request::str('due_date') ?: null, Request::str('note') ?: null), $back);
        flash('success', 'Réception enregistrée : stock, coût moyen et dette fournisseur mis à jour.');
        redirect('/receptions/' . $id);
    }

    public function receptions(): void
    {
        [$from, $to] = date_range('-30 days');
        $p = paginate('FROM receptions r JOIN suppliers s ON s.id = r.supplier_id JOIN warehouses w ON w.id = r.warehouse_id JOIN users u ON u.id = r.user_id LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id WHERE r.created_at BETWEEN ? AND ?',
            [$from . ' 00:00:00', $to . ' 23:59:59'], 'r.*, s.name AS supplier, w.name AS warehouse, u.full_name, po.number AS po_number', 'r.id DESC');
        view('purchases/receptions', ['title' => 'Réceptions', 'p' => $p, 'from' => $from, 'to' => $to]);
    }

    public function receptionShow(int $id): void
    {
        $r = DB::one('SELECT r.*, s.name AS supplier, s.address AS supplier_address, s.phone AS supplier_phone, w.name AS warehouse, u.full_name, po.number AS po_number FROM receptions r JOIN suppliers s ON s.id = r.supplier_id JOIN warehouses w ON w.id = r.warehouse_id JOIN users u ON u.id = r.user_id LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id WHERE r.id = ?', [$id]);
        if (!$r) {
            throw new HttpException(404);
        }
        view('purchases/reception_print', ['title' => 'Bon de réception ' . $r['number'], 'r' => $r, 'bodyClass' => 'a4', 'backUrl' => url('/receptions'),
            'items' => DB::all('SELECT ri.*, p.name, p.reference FROM reception_items ri JOIN products p ON p.id = ri.product_id WHERE ri.reception_id = ?', [$id])], 'layouts/print');
    }

    public function supplierReturns(): void
    {
        $p = paginate('FROM supplier_returns r JOIN suppliers s ON s.id = r.supplier_id JOIN users u ON u.id = r.user_id', [], 'r.*, s.name AS supplier, u.full_name', 'r.id DESC');
        view('purchases/supplier_returns', ['title' => 'Retours fournisseurs', 'p' => $p]);
    }

    public function supplierReturnForm(): void
    {
        view('purchases/supplier_return_form', ['title' => 'Nouveau retour fournisseur', 'suppliers' => DB::all('SELECT id, name FROM suppliers WHERE active = 1 ORDER BY name'), 'warehouses' => $this->warehouses(), 'wh' => $this->defaultWarehouseId()]);
    }

    public function supplierReturnStore(): void
    {
        $this->attempt(fn() => PurchaseService::supplierReturn(Request::int('supplier_id'), Request::int('warehouse_id'), $this->rowsFromForm(), Request::str('reason')), '/supplier-returns/create');
        flash('success', 'Retour fournisseur enregistré : stock diminué et dette fournisseur réduite.');
        redirect('/supplier-returns');
    }
}
