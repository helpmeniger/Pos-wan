<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\OnlineOrderService;
use App\Services\SaleService;
use Throwable;

final class SaleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('sales.view');
        $tab = $request->str('tab', 'sales');
        $f = ['q' => $request->str('q'), 'from' => $request->str('from', date('Y-m-d', strtotime('-7 days'))), 'to' => $request->str('to', date('Y-m-d')),
            'channel' => $request->str('channel'), 'status' => $request->str('status'), 'method' => $request->int('method'), 'router_id' => $request->int('router_id', active_router_id())];
        $page = max(1, $request->int('page', 1));
        if ($tab === 'online') {
            $orders = Database::fetchAll(
                'SELECT oo.*, o.name AS offer_name, pm.name AS method, r.name AS router_name, t.username FROM online_orders oo
                   JOIN offers o ON o.id = oo.offer_id JOIN payment_methods pm ON pm.id = oo.payment_method_id JOIN routers r ON r.id = oo.router_id
                   LEFT JOIN tickets t ON t.id = oo.ticket_id ORDER BY oo.id DESC LIMIT 200');
            return $this->view('sales/online', compact('orders', 'tab'));
        }
        $where = ['s.created_at BETWEEN :from AND :to'];
        $p = ['from' => $f['from'] . ' 00:00:00', 'to' => $f['to'] . ' 23:59:59'];
        if ($f['q'] !== '') {
            $where[] = '(s.reference LIKE :q OR s.customer_phone LIKE :q2 OR s.customer_name LIKE :q3)';
            $p += ['q' => "%{$f['q']}%", 'q2' => "%{$f['q']}%", 'q3' => "%{$f['q']}%"];
        }
        foreach (['channel' => 's.channel', 'status' => 's.status'] as $k => $col) {
            if ($f[$k] !== '') {
                $where[] = "{$col} = :{$k}";
                $p[$k] = $f[$k];
            }
        }
        if ($f['method']) {
            $where[] = 's.payment_method_id = :m';
            $p['m'] = $f['method'];
        }
        if ($f['router_id']) {
            $where[] = 's.router_id = :r';
            $p['r'] = $f['router_id'];
        }
        $w = implode(' AND ', $where);
        $totals = Database::fetch("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN s.status='completed' THEN s.total END),0) total, COALESCE(SUM(CASE WHEN s.status='completed' THEN s.discount END),0) discount, COALESCE(SUM(CASE WHEN s.status='completed' THEN s.commission_amount END),0) commission FROM sales s WHERE {$w}", $p);
        $sales = Database::fetchAll(
            "SELECT s.*, u.full_name AS cashier, pm.name AS method, r.name AS router_name, rs.business_name AS reseller_name,
                    (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS items
               FROM sales s LEFT JOIN users u ON u.id = s.cashier_id LEFT JOIN payment_methods pm ON pm.id = s.payment_method_id
               LEFT JOIN routers r ON r.id = s.router_id LEFT JOIN resellers rs ON rs.id = s.reseller_id
              WHERE {$w} ORDER BY s.id DESC LIMIT 50 OFFSET " . (($page - 1) * 50), $p);
        return $this->view('sales/index', [
            'sales' => $sales, 'f' => $f, 'totals' => $totals, 'tab' => $tab, 'page' => $page, 'pages' => (int) ceil(((int) $totals['n']) / 50),
            'methods' => Database::fetchAll('SELECT id, name FROM payment_methods ORDER BY sort_order'),
            'routers' => Database::fetchAll('SELECT id, name FROM routers WHERE deleted_at IS NULL ORDER BY name'),
        ]);
    }

    private function load(int $id): array
    {
        $sale = Database::fetch(
            'SELECT s.*, u.full_name AS cashier, pm.name AS method, pm.type AS method_type, r.name AS router_name, rs.business_name AS reseller_name, cu.full_name AS cancelled_by_name
               FROM sales s LEFT JOIN users u ON u.id = s.cashier_id LEFT JOIN payment_methods pm ON pm.id = s.payment_method_id
               LEFT JOIN routers r ON r.id = s.router_id LEFT JOIN resellers rs ON rs.id = s.reseller_id LEFT JOIN users cu ON cu.id = s.cancelled_by
              WHERE s.id = :id', ['id' => $id]);
        $this->notFoundUnless($sale, 'Vente introuvable.');
        if (!can('sales.view') && (int) $sale['cashier_id'] !== Auth::id()) {
            $this->authorize('sales.view');
        }
        return $sale;
    }

    public function show(Request $request, string $id): Response
    {
        $this->authorize('sales.view', 'sales.create');
        $sale = $this->load((int) $id);
        $tickets = SaleService::saleTickets((int) $sale['id']);
        $payments = Database::fetchAll('SELECT p.*, pm.name FROM payments p JOIN payment_methods pm ON pm.id = p.payment_method_id WHERE p.sale_id = :s', ['s' => $sale['id']]);
        $refunds = Database::fetchAll('SELECT rf.*, u.full_name FROM refunds rf LEFT JOIN users u ON u.id = rf.approved_by WHERE rf.sale_id = :s', ['s' => $sale['id']]);
        return $this->view('sales/show', compact('sale', 'tickets', 'payments', 'refunds'));
    }

    public function receipt(Request $request, string $id): Response
    {
        $this->authorize('sales.view', 'sales.create');
        $sale = $this->load((int) $id);
        $tickets = \App\Services\TicketPrintService::prepare(array_column(SaleService::saleTickets((int) $sale['id']), 'id'));
        return Response::html(View::render('sales/receipt', compact('sale', 'tickets'), null));
    }

    public function cancel(Request $request, string $id): Response
    {
        $this->authorize('sales.cancel');
        $reason = $request->str('reason');
        if (mb_strlen($reason) < 3) {
            Session::flash('danger', 'Indiquez le motif de l\'annulation.');
            return $this->redirect('/sales/' . $id);
        }
        try {
            SaleService::cancel((int) $id, $reason, (int) Auth::id());
            Session::flash('success', 'Vente annulée : les tickets sont de nouveau disponibles.');
        } catch (Throwable $e) {
            Session::flash('danger', $e->getMessage());
        }
        return $this->redirect('/sales/' . $id);
    }

    public function refund(Request $request, string $id): Response
    {
        $this->authorize('sales.refund');
        try {
            SaleService::refund((int) $id, (float) $request->input('amount'), $request->str('reason') ?: 'Remboursement', (int) Auth::id());
            Session::flash('success', 'Remboursement enregistré.');
        } catch (Throwable $e) {
            Session::flash('danger', $e->getMessage());
        }
        return $this->redirect('/sales/' . $id);
    }

    /** Confirmation manuelle d'un paiement en ligne après vérification de la référence chez le prestataire. */
    public function confirmOnline(Request $request, string $id): Response
    {
        $this->authorize('payments.manage');
        try {
            OnlineOrderService::confirm((int) $id, $request->str('provider_reference'), 'manual', (int) Auth::id());
            Session::flash('success', 'Paiement confirmé : le ticket a été attribué au client.');
        } catch (Throwable $e) {
            Session::flash('danger', $e->getMessage());
        }
        return $this->redirect('/sales?tab=online');
    }

    public function export(Request $request): Response
    {
        $this->authorize('reports.view', 'sales.view');
        $from = $request->str('from', date('Y-m-01'));
        $to = $request->str('to', date('Y-m-d'));
        $rows = Database::fetchAll(
            "SELECT s.reference, s.created_at, s.channel, s.status, s.payment_status, s.subtotal, s.discount, s.total, s.commission_amount,
                    pm.name AS method, u.full_name AS cashier, r.name AS router, rs.business_name AS reseller, s.customer_phone
               FROM sales s LEFT JOIN payment_methods pm ON pm.id = s.payment_method_id LEFT JOIN users u ON u.id = s.cashier_id
               LEFT JOIN routers r ON r.id = s.router_id LEFT JOIN resellers rs ON rs.id = s.reseller_id
              WHERE s.created_at BETWEEN :f AND :t ORDER BY s.id", ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59']);
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Référence', 'Date', 'Canal', 'Statut', 'Paiement', 'Sous-total', 'Remise', 'Total', 'Commission', 'Moyen', 'Caissier', 'Routeur', 'Revendeur', 'Client'], ';');
        foreach ($rows as $r) {
            fputcsv($out, array_values($r), ';');
        }
        rewind($out);
        return new Response((string) stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"ventes-{$from}-{$to}.csv\""]);
    }
}
