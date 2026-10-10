<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\SaleService;

final class SaleController extends Controller
{
    /** Un vendeur sans « sales.view_all » ne voit que ses propres ventes. */
    private function scopedSale(int $id): array
    {
        $s = DB::one('SELECT s.*, u.full_name AS cashier, c.name AS customer_name, c.phone AS customer_phone, c.address AS customer_address, c.nif AS customer_nif,
                st.name AS store_name, st.address AS store_address, st.phone AS store_phone, r.name AS register_name, a.full_name AS authorizer, cb.full_name AS canceller
                FROM sales s JOIN users u ON u.id = s.user_id LEFT JOIN customers c ON c.id = s.customer_id JOIN stores st ON st.id = s.store_id
                LEFT JOIN cash_registers r ON r.id = s.register_id LEFT JOIN users a ON a.id = s.authorized_by LEFT JOIN users cb ON cb.id = s.cancelled_by WHERE s.id = ?', [$id]);
        if (!$s) {
            throw new HttpException(404);
        }
        if (!can('sales.view_all') && (int) $s['user_id'] !== (int) $this->user()['id']) {
            throw new HttpException(403);
        }
        return $s;
    }

    public function index(): void
    {
        [$from, $to] = date_range('today');
        $where = ['s.created_at BETWEEN ? AND ?'];
        $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
        if (!can('sales.view_all')) {
            $where[] = 's.user_id = ?';
            $params[] = $this->user()['id'];
        } elseif ($uid = (int) Request::query('user', 0)) {
            $where[] = 's.user_id = ?';
            $params[] = $uid;
        }
        if ($status = Request::query('status')) {
            if (in_array($status, ['completed', 'cancelled', 'partially_returned', 'returned'], true)) {
                $where[] = 's.status = ?';
                $params[] = $status;
            }
        }
        if (($q = trim((string) Request::query('q', ''))) !== '') {
            $where[] = '(s.number LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }
        if (Request::query('credit') === '1') {
            $where[] = 's.credit_amount > 0';
        }
        $from_ = 'FROM sales s JOIN users u ON u.id = s.user_id LEFT JOIN customers c ON c.id = s.customer_id WHERE ' . implode(' AND ', $where);
        if (Request::query('export') === 'csv' && can('reports.export')) {
            $rows = DB::all("SELECT s.number, s.created_at, u.full_name, c.name, s.subtotal, s.discount_total, s.tax_total, s.total, s.paid_total, s.credit_amount, s.status $from_ ORDER BY s.id", $params);
            \App\Core\Csv::download("ventes_{$from}_{$to}.csv", ['N°', 'Date', 'Vendeur', 'Client', 'Sous-total', 'Remise', 'Taxe incluse', 'Total', 'Payé', 'Crédit', 'Statut'], $rows);
        }
        $p = paginate($from_, $params, 's.*, u.full_name AS cashier, c.name AS customer', 's.id DESC', 30);
        $tot = DB::one("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN s.status <> 'cancelled' THEN s.total END),0) t $from_", $params);
        $users = can('sales.view_all') ? DB::all('SELECT id, full_name FROM users ORDER BY full_name') : [];
        view('sales/index', ['title' => 'Historique des ventes', 'p' => $p, 'from' => $from, 'to' => $to, 'tot' => $tot, 'users' => $users]);
    }

    public function show(int $id): void
    {
        $s = $this->scopedSale($id);
        view('sales/show', [
            'title' => 'Vente ' . $s['number'],
            's' => $s,
            'items' => DB::all('SELECT si.*, p.reference FROM sale_items si JOIN products p ON p.id = si.product_id WHERE si.sale_id = ?', [$id]),
            'payments' => DB::all('SELECT * FROM sale_payments WHERE sale_id = ?', [$id]),
            'returns' => DB::all('SELECT r.*, u.full_name FROM sale_returns r JOIN users u ON u.id = r.user_id WHERE r.sale_id = ?', [$id]),
            'lots' => DB::all('SELECT sil.*, l.lot_number, l.expiry_date, si.product_name FROM sale_item_lots sil JOIN stock_lots l ON l.id = sil.lot_id JOIN sale_items si ON si.id = sil.sale_item_id WHERE si.sale_id = ?', [$id]),
            'prescription' => DB::one('SELECT p.*, u.full_name AS validator FROM prescriptions p JOIN users u ON u.id = p.validated_by WHERE p.sale_id = ?', [$id]),
        ]);
    }

    /**
     * Ticket thermique. Première impression libre ; toute réimpression exige
     * la permission pos.reprint, est journalisée et porte la mention DUPLICATA.
     */
    public function ticket(int $id): void
    {
        $s = $this->scopedSale($id);
        $dup = (int) $s['print_count'] > 0;
        if ($dup && !can('pos.reprint')) {
            throw new HttpException(403, 'Réimpression non autorisée : demandez à un responsable.');
        }
        DB::run('UPDATE sales SET print_count = print_count + 1 WHERE id = ?', [$id]);
        if ($dup) {
            Audit::log('sale.reprint', 'sale', $id, ['number' => $s['number'], 'count' => (int) $s['print_count'] + 1]);
        }
        $width = setting('ticket_width', '80') === '58' ? 't58' : 't80';
        view('sales/ticket', [
            'title' => 'Ticket ' . $s['number'], 's' => $s, 'dup' => $dup, 'bodyClass' => $width,
            'items' => DB::all('SELECT * FROM sale_items WHERE sale_id = ?', [$id]),
            'payments' => DB::all('SELECT * FROM sale_payments WHERE sale_id = ?', [$id]),
            'autoPrint' => Request::query('auto') === '1', 'backUrl' => url('/pos'),
        ], 'layouts/print');
    }

    public function invoice(int $id): void
    {
        $s = $this->scopedSale($id);
        view('sales/invoice', [
            'title' => 'Facture ' . $s['number'], 's' => $s, 'bodyClass' => 'a4',
            'items' => DB::all('SELECT si.*, p.reference FROM sale_items si JOIN products p ON p.id = si.product_id WHERE si.sale_id = ?', [$id]),
            'payments' => DB::all('SELECT * FROM sale_payments WHERE sale_id = ?', [$id]),
            'backUrl' => url('/sales/' . $id),
        ], 'layouts/print');
    }

    public function cancel(int $id): void
    {
        $this->scopedSale($id);
        $this->attempt(fn() => SaleService::cancel($id, Request::str('reason')), '/sales/' . $id);
        flash('success', 'Vente annulée : stock, caisse et compte client ont été contre-passés.');
        redirect('/sales/' . $id);
    }

    public function returnForm(int $id): void
    {
        $s = $this->scopedSale($id);
        view('sales/return', [
            'title' => 'Retour sur la vente ' . $s['number'], 's' => $s,
            'items' => DB::all('SELECT * FROM sale_items WHERE sale_id = ?', [$id]),
        ]);
    }

    public function returnStore(int $id): void
    {
        $this->scopedSale($id);
        $items = Request::input('qty', []);
        $retId = $this->attempt(fn() => SaleService::createReturn($id, is_array($items) ? $items : [], Request::str('refund_method'), Request::str('reason'), Request::input('restock') === '1'), '/sales/' . $id . '/return');
        flash('success', 'Retour enregistré.');
        redirect('/returns/' . $retId);
    }

    public function returns(): void
    {
        [$from, $to] = date_range('-30 days');
        $p = paginate('FROM sale_returns r JOIN sales s ON s.id = r.sale_id JOIN users u ON u.id = r.user_id LEFT JOIN customers c ON c.id = s.customer_id WHERE r.created_at BETWEEN ? AND ?' . (can('sales.view_all') ? '' : ' AND r.user_id = ' . (int) $this->user()['id']),
            [$from . ' 00:00:00', $to . ' 23:59:59'], 'r.*, s.number AS sale_number, u.full_name, c.name AS customer', 'r.id DESC');
        view('sales/returns', ['title' => 'Retours & avoirs', 'p' => $p, 'from' => $from, 'to' => $to]);
    }

    public function returnReceipt(int $id): void
    {
        $r = DB::one('SELECT r.*, s.number AS sale_number, s.customer_id, s.user_id AS sale_user, u.full_name, c.name AS customer FROM sale_returns r JOIN sales s ON s.id = r.sale_id JOIN users u ON u.id = r.user_id LEFT JOIN customers c ON c.id = s.customer_id WHERE r.id = ?', [$id]);
        if (!$r) {
            throw new HttpException(404);
        }
        if (!can('sales.view_all') && (int) $r['user_id'] !== (int) $this->user()['id']) {
            throw new HttpException(403);
        }
        view('sales/return_receipt', [
            'title' => 'Reçu de remboursement ' . $r['number'], 'r' => $r, 'bodyClass' => setting('ticket_width', '80') === '58' ? 't58' : 't80',
            'items' => DB::all('SELECT ri.*, si.product_name, si.unit_price FROM sale_return_items ri JOIN sale_items si ON si.id = ri.sale_item_id WHERE ri.return_id = ?', [$id]),
            'backUrl' => url('/returns'),
        ], 'layouts/print');
    }
}
