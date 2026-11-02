<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\SaleService;
use Throwable;

/**
 * Caisse : vente rapide de tickets, ouverture / clôture avec rapprochement.
 */
final class PosController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('sales.create');
        $routers = Database::fetchAll("SELECT id, name, connection_state FROM routers WHERE deleted_at IS NULL AND status = 'active' ORDER BY is_default DESC, name");
        $routerId = $request->int('router_id', active_router_id()) ?: (int) ($routers[0]['id'] ?? 0);
        $today = date('Y-m-d');
        $offers = Database::fetchAll(
            "SELECT o.*, (SELECT COUNT(*) FROM tickets t WHERE t.offer_id = o.id AND t.router_id = :r AND t.status = 'available' AND t.on_router = 1 AND t.reseller_id IS NULL) AS stock
               FROM offers o WHERE o.status = 'active' AND (o.start_date IS NULL OR o.start_date <= :d1) AND (o.end_date IS NULL OR o.end_date >= :d2)
              ORDER BY o.sort_order, o.price", ['r' => $routerId, 'd1' => $today, 'd2' => $today]);
        $methods = Database::fetchAll('SELECT * FROM payment_methods WHERE is_active = 1 AND available_pos = 1 ORDER BY sort_order');
        $session = SaleService::openSession((int) Auth::id());
        $recent = Database::fetchAll(
            "SELECT s.id, s.reference, s.total, s.created_at, s.status, pm.name AS method FROM sales s LEFT JOIN payment_methods pm ON pm.id = s.payment_method_id
              WHERE s.cashier_id = :u AND s.channel = 'physical' ORDER BY s.id DESC LIMIT 8", ['u' => Auth::id()]);
        $sessionTotals = $session ? Database::fetch("SELECT COUNT(*) n, COALESCE(SUM(total),0) total FROM sales WHERE cash_session_id = :c AND status = 'completed'", ['c' => $session['id']]) : null;
        return $this->view('pos/index', [
            'routers' => $routers, 'routerId' => $routerId, 'offers' => $offers, 'methods' => $methods, 'session' => $session,
            'sessionTotals' => $sessionTotals, 'expected' => $session ? SaleService::expectedCash($session) : 0, 'recent' => $recent,
            'requireSession' => (bool) setting('sales.require_open_cash_session', true), 'idempotency' => bin2hex(random_bytes(16)),
        ]);
    }

    public function sell(Request $request): Response
    {
        $this->authorize('sales.create');
        $d = $this->validate($request, [
            'offer_id' => 'required|integer', 'router_id' => 'required|integer', 'quantity' => 'required|integer',
            'payment_method_id' => 'required|integer', 'discount' => 'numeric', 'customer_name' => 'max:150',
            'customer_phone' => 'regex:/^[0-9+ ]{6,20}$/', 'payment_reference' => 'max:120', 'idempotency_key' => 'regex:/^[a-f0-9]{32}$/',
        ], ['offer_id' => 'Offre', 'quantity' => 'Quantité', 'payment_method_id' => 'Moyen de paiement', 'customer_phone' => 'Téléphone']);
        try {
            $saleId = SaleService::sell($d, (int) Auth::id(), can('sales.discount'));
        } catch (Throwable $e) {
            Session::flash('danger', $e->getMessage());
            return $this->redirect('/pos?router_id=' . (int) $d['router_id']);
        }
        Session::flash('success', 'Vente enregistrée.');
        return $this->redirect('/sales/' . $saleId . '?printed=0');
    }

    public function open(Request $request): Response
    {
        $this->authorize('cash.manage');
        try {
            SaleService::openCash((int) Auth::id(), (float) $request->input('opening_amount', 0));
            Session::flash('success', 'Caisse ouverte.');
        } catch (Throwable $e) {
            Session::flash('danger', $e->getMessage());
        }
        return $this->redirect('/pos');
    }

    public function close(Request $request): Response
    {
        $this->authorize('cash.manage');
        $session = SaleService::openSession((int) Auth::id());
        if (!$session) {
            Session::flash('warning', 'Aucune caisse ouverte.');
            return $this->redirect('/pos');
        }
        $r = SaleService::closeCash($session, (float) $request->input('declared_amount', 0), $request->str('notes'), (int) Auth::id());
        Session::flash(abs($r['diff']) < 0.01 ? 'success' : 'warning', sprintf('Caisse clôturée. Attendu %s, déclaré %s, écart %s.', money($r['expected']), money($r['declared']), money($r['diff'])));
        return $this->redirect('/pos/sessions/' . $session['id']);
    }

    public function sessions(Request $request): Response
    {
        $this->authorize('sales.view', 'cash.manage');
        $where = can('sales.view') ? '1=1' : 'cs.user_id = ' . (int) Auth::id();
        $sessions = Database::fetchAll(
            "SELECT cs.*, u.full_name, (SELECT COUNT(*) FROM sales s WHERE s.cash_session_id = cs.id AND s.status = 'completed') AS sales_count,
                    (SELECT COALESCE(SUM(total),0) FROM sales s WHERE s.cash_session_id = cs.id AND s.status = 'completed') AS sales_total
               FROM cash_sessions cs JOIN users u ON u.id = cs.user_id WHERE {$where} ORDER BY cs.id DESC LIMIT 200");
        return $this->view('pos/sessions', compact('sessions'));
    }

    public function session(Request $request, string $id): Response
    {
        $this->authorize('sales.view', 'cash.manage');
        $s = Database::fetch('SELECT cs.*, u.full_name FROM cash_sessions cs JOIN users u ON u.id = cs.user_id WHERE cs.id = :id', ['id' => (int) $id]);
        $this->notFoundUnless($s);
        if (!can('sales.view') && (int) $s['user_id'] !== Auth::id()) {
            $this->authorize('sales.view');
        }
        $byMethod = Database::fetchAll(
            "SELECT pm.name, COUNT(*) n, SUM(s.total) total FROM sales s JOIN payment_methods pm ON pm.id = s.payment_method_id
              WHERE s.cash_session_id = :c AND s.status = 'completed' GROUP BY pm.id", ['c' => $s['id']]);
        $sales = Database::fetchAll("SELECT s.*, pm.name AS method FROM sales s LEFT JOIN payment_methods pm ON pm.id = s.payment_method_id WHERE s.cash_session_id = :c ORDER BY s.id DESC", ['c' => $s['id']]);
        $expected = $s['status'] === 'open' ? SaleService::expectedCash($s) : (float) $s['expected_amount'];
        return $this->view('pos/session', compact('s', 'byMethod', 'sales', 'expected'));
    }
}
