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
            'hasPrinter' => \App\Services\PrintService::targets('receipt', $s['register_id'] ? (int) $s['register_id'] : null) !== [],
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
            'autoPrint' => Request::query('auto') === '1', 'backUrl' => self::safeNext(), 'returnAfterPrint' => Request::query('next') !== null,
        ], 'layouts/print');
    }

    /** URL de retour après impression : chemin local uniquement (pas de redirection ouverte). */
    private static function safeNext(): string
    {
        $n = (string) Request::query('next', '');
        if ($n !== '' && str_starts_with($n, '/') && !str_starts_with($n, '//') && !str_contains($n, '\\') && !preg_match('/[\r\n]/', $n)) {
            return $n;
        }
        return url(can('pos.sell') ? '/pos' : '/');
    }

    /** (Ré)impression du ticket sur l'imprimante de caisse configurée. */
    public function printServer(int $id): void
    {
        $s = $this->scopedSale($id);
        $dup = (int) $s['print_count'] > 0;
        if ($dup && !can('pos.reprint')) {
            throw new HttpException(403, 'Réimpression non autorisée : demandez à un responsable.');
        }
        $r = \App\Services\PrintService::printReceipt($id, $dup);
        if (\App\Core\Request::wantsJson()) {
            json_response(['ok' => $r['printed'] > 0, 'printed' => $r['printed'], 'error' => $r['errors'] ? implode(' ', $r['errors']) : ($r['printed'] ? null : 'Aucune imprimante de tickets configurée sur le serveur.')]);
        }
        flash($r['printed'] ? 'success' : 'warning', $r['printed'] ? 'Ticket envoyé à l\'imprimante.' : ($r['errors'] ? implode(' ', $r['errors']) : 'Aucune imprimante de tickets configurée : utilisez « Ticket » (navigateur).'));
        redirect('/sales/' . $id);
    }

    /** Bon de livraison A4 (quantités, sans ou avec prix). */
    public function delivery(int $id): void
    {
        $s = $this->scopedSale($id);
        view('sales/delivery', [
            'title' => 'Bon de livraison BL-' . $s['number'], 's' => $s, 'bodyClass' => 'a4', 'withPrices' => Request::query('prices') === '1',
            'items' => DB::all('SELECT si.*, p.reference, u.short_name AS unit FROM sale_items si JOIN products p ON p.id = si.product_id LEFT JOIN units u ON u.id = p.unit_id WHERE si.sale_id = ?', [$id]),
            'backUrl' => url('/sales/' . $id),
            'toolbarLinks' => [Request::query('prices') === '1' ? [url('/sales/' . $id . '/delivery'), 'Sans les prix', 'eye-slash'] : [url('/sales/' . $id . '/delivery', ['prices' => 1]), 'Avec les prix', 'cash-coin']],
        ], 'layouts/print');
    }

    /** Vérification d'un QR code de ticket / facture / devis (douchette 2D ou saisie). */
    public function verify(): void
    {
        $code = trim((string) Request::query('code', ''));
        $r = $code !== '' ? \App\Services\DocumentRef::verify($code) : null;
        view('sales/verify', ['title' => 'Vérifier un ticket ou une facture', 'code' => $code, 'r' => $r]);
    }

    public function invoice(int $id): void
    {
        $s = $this->scopedSale($id);
        view('sales/invoice', [
            'title' => 'Facture ' . $s['number'], 's' => $s, 'bodyClass' => 'a4',
            'items' => DB::all('SELECT si.*, p.reference FROM sale_items si JOIN products p ON p.id = si.product_id WHERE si.sale_id = ?', [$id]),
            'payments' => DB::all('SELECT * FROM sale_payments WHERE sale_id = ?', [$id]),
            'backUrl' => url('/sales/' . $id),
            'toolbarLinks' => [[url('/sales/' . $id . '/delivery'), 'Bon de livraison', 'truck']],
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
