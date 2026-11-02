<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\ResellerService;
use Throwable;

/** Espace personnel du revendeur. */
final class ResellerPortalController extends Controller
{
    private function me(): array
    {
        $this->authorize('reseller.portal');
        $r = ResellerService::forUser((int) Auth::id());
        if (!$r) {
            throw new HttpException(403, "Votre compte n'est rattaché à aucun revendeur. Contactez l'administrateur.");
        }
        return $r;
    }

    public function index(Request $request): Response
    {
        $r = $this->me();
        $stats = ResellerService::stats((int) $r['id']);
        $q = $request->str('q');
        $offerId = $request->int('offer_id');
        $where = 't.reseller_id = :r';
        $p = ['r' => $r['id']];
        $where .= $r['business_model'] === 'wholesale' ? " AND t.status = 'sold' AND t.customer_phone IS NULL" : " AND t.status = 'available'";
        if ($offerId) {
            $where .= ' AND t.offer_id = :o';
            $p['o'] = $offerId;
        }
        if ($q !== '') {
            $where .= ' AND t.username LIKE :q';
            $p['q'] = "%{$q}%";
        }
        $tickets = Database::fetchAll("SELECT t.id, t.username, t.price, o.name AS offer_name FROM tickets t JOIN offers o ON o.id = t.offer_id WHERE {$where} ORDER BY t.id LIMIT 200", $p);
        $sales = Database::fetchAll(
            "SELECT t.username, t.customer_phone, t.sold_at, t.status, o.name AS offer_name, t.price FROM tickets t JOIN offers o ON o.id = t.offer_id
              WHERE t.reseller_id = :r AND (t.customer_phone IS NOT NULL OR t.status NOT IN ('available','sold')) ORDER BY t.sold_at DESC LIMIT 30", ['r' => $r['id']]);
        $transactions = Database::fetchAll('SELECT * FROM reseller_transactions WHERE reseller_id = :r ORDER BY id DESC LIMIT 30', ['r' => $r['id']]);
        $templates = Database::fetchAll('SELECT id, name FROM ticket_print_templates ORDER BY is_default DESC, id');
        return $this->view('portal/index', compact('r', 'stats', 'tickets', 'sales', 'transactions', 'q', 'offerId', 'templates'));
    }

    public function sell(Request $request): Response
    {
        $r = $this->me();
        if ($r['status'] !== 'active') {
            Session::flash('danger', 'Compte revendeur suspendu.');
            return $this->redirect('/reseller');
        }
        try {
            $t = ResellerService::sellTicket($r, $request->int('ticket_id'), $request->str('phone') ?: null, (int) Auth::id());
            Session::flash('success', "Ticket {$t['username']} remis au client.");
        } catch (Throwable $e) {
            Session::flash('danger', $e->getMessage());
        }
        return $this->redirect('/reseller');
    }
}
