<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\ResellerService;
use Throwable;

final class ResellerController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('resellers.view');
        $resellers = Database::fetchAll(
            "SELECT rs.*, u.username, u.status AS user_status,
                    (SELECT COUNT(*) FROM tickets t WHERE t.reseller_id = rs.id AND t.status = 'available') AS stock,
                    (SELECT COALESCE(SUM(total),0) FROM sales s WHERE s.reseller_id = rs.id AND s.status = 'completed' AND s.created_at >= :m) AS sales_month,
                    (SELECT GROUP_CONCAT(r.name SEPARATOR ', ') FROM reseller_routers rr JOIN routers r ON r.id = rr.router_id WHERE rr.reseller_id = rs.id) AS routers
               FROM resellers rs LEFT JOIN users u ON u.id = rs.user_id ORDER BY rs.business_name", ['m' => date('Y-m-01')]);
        return $this->view('resellers/index', compact('resellers'));
    }

    public function create(Request $request): Response
    {
        $this->authorize('resellers.manage');
        return $this->view('resellers/form', ['reseller' => null, 'routers' => $this->routers(), 'linked' => []]);
    }

    private function routers(): array
    {
        return Database::fetchAll("SELECT id, name FROM routers WHERE deleted_at IS NULL AND status = 'active' ORDER BY name");
    }

    private function data(Request $request, ?int $id = null): array
    {
        $d = $this->validate($request, [
            'business_name' => 'required|max:150', 'code' => 'required|regex:/^[A-Za-z0-9_-]+$/|max:30|unique:resellers,code' . ($id ? ',' . $id : ''),
            'contact_name' => 'max:150', 'phone' => 'max:30', 'email' => 'email|max:190', 'address' => 'max:255',
            'business_model' => 'required|in:wholesale,commission', 'commission_rate' => 'required|numeric', 'wholesale_discount' => 'required|numeric',
            'credit_limit' => 'required|numeric', 'notes' => 'max:2000',
        ], ['business_name' => 'Nom commercial', 'code' => 'Code', 'commission_rate' => 'Commission', 'wholesale_discount' => 'Remise de gros', 'credit_limit' => 'Plafond de crédit']);
        foreach (['commission_rate', 'wholesale_discount'] as $k) {
            if ((float) $d[$k] < 0 || (float) $d[$k] > 100) {
                Session::flashErrors([$k => 'Pourcentage entre 0 et 100.']);
                throw new ValidationFailed([], $this->back($request));
            }
        }
        return [
            'business_name' => $d['business_name'], 'code' => strtoupper($d['code']), 'contact_name' => $d['contact_name'] ?: null,
            'phone' => $d['phone'] ?: null, 'email' => $d['email'] ?: null, 'address' => $d['address'] ?: null,
            'business_model' => $d['business_model'], 'commission_rate' => (float) $d['commission_rate'],
            'wholesale_discount' => (float) $d['wholesale_discount'], 'credit_limit' => max(0, (float) $d['credit_limit']), 'notes' => $d['notes'] ?: null,
        ];
    }

    private function linkRouters(int $id, array $routers): void
    {
        Database::query('DELETE FROM reseller_routers WHERE reseller_id = :r', ['r' => $id]);
        foreach (array_unique(array_filter(array_map('intval', $routers))) as $rid) {
            Database::insert('reseller_routers', ['reseller_id' => $id, 'router_id' => $rid]);
        }
    }

    public function store(Request $request): Response
    {
        $this->authorize('resellers.manage');
        $data = $this->data($request);
        $login = $request->str('username');
        $password = (string) $request->input('password', '');
        if ($login !== '') {
            if (Database::value('SELECT id FROM users WHERE username = :u OR email = :e', ['u' => $login, 'e' => $data['email'] ?? '-'])) {
                Session::flashErrors(['username' => 'Identifiant ou e-mail déjà utilisé.']);
                Session::flashOld($request->all());
                return $this->back($request, '/resellers/create');
            }
            if ($err = Auth::passwordPolicyError($password)) {
                Session::flashErrors(['password' => $err]);
                Session::flashOld($request->all());
                return $this->back($request, '/resellers/create');
            }
        }
        $id = Database::transaction(function () use ($data, $login, $password, $request) {
            $userId = null;
            if ($login !== '') {
                $userId = Database::insert('users', [
                    'role_id' => (int) Database::value("SELECT id FROM roles WHERE slug = 'reseller'"), 'full_name' => $data['contact_name'] ?: $data['business_name'],
                    'username' => $login, 'email' => $data['email'] ?: ($login . '@revendeur.local'), 'phone' => $data['phone'],
                    'password_hash' => Auth::hash($password), 'must_change_password' => 1, 'created_by' => Auth::id(),
                ]);
            }
            $id = Database::insert('resellers', $data + ['user_id' => $userId, 'created_by' => Auth::id()]);
            $this->linkRouters($id, (array) $request->input('routers', []));
            return $id;
        });
        AuditService::log('reseller.create', 'reseller', $id, "Création du revendeur {$data['business_name']}");
        Session::flash('success', 'Revendeur créé.' . ($login ? ' Son compte de connexion est actif.' : ''));
        return $this->redirect('/resellers/' . $id);
    }

    public function edit(Request $request, string $id): Response
    {
        $this->authorize('resellers.manage');
        $r = Database::fetch('SELECT rs.*, u.username FROM resellers rs LEFT JOIN users u ON u.id = rs.user_id WHERE rs.id = :id', ['id' => (int) $id]);
        $this->notFoundUnless($r);
        $linked = array_map('intval', array_column(Database::fetchAll('SELECT router_id FROM reseller_routers WHERE reseller_id = :r', ['r' => $r['id']]), 'router_id'));
        return $this->view('resellers/form', ['reseller' => $r, 'routers' => $this->routers(), 'linked' => $linked]);
    }

    public function update(Request $request, string $id): Response
    {
        $this->authorize('resellers.manage');
        $r = Database::fetch('SELECT * FROM resellers WHERE id = :id', ['id' => (int) $id]);
        $this->notFoundUnless($r);
        $data = $this->data($request, (int) $r['id']);
        Database::update('resellers', $data, 'id = :id', ['id' => $r['id']]);
        $this->linkRouters((int) $r['id'], (array) $request->input('routers', []));
        [$old, $new] = AuditService::diff($r, $data);
        AuditService::log('reseller.update', 'reseller', (int) $r['id'], "Modification du revendeur {$r['business_name']}", $old, $new);
        Session::flash('success', 'Revendeur mis à jour.');
        return $this->redirect('/resellers/' . $r['id']);
    }

    public function show(Request $request, string $id): Response
    {
        $this->authorize('resellers.view');
        $r = Database::fetch('SELECT rs.*, u.username, u.status AS user_status FROM resellers rs LEFT JOIN users u ON u.id = rs.user_id WHERE rs.id = :id', ['id' => (int) $id]);
        $this->notFoundUnless($r, 'Revendeur introuvable.');
        $stats = ResellerService::stats((int) $r['id']);
        $transactions = Database::fetchAll('SELECT rt.*, u.full_name FROM reseller_transactions rt LEFT JOIN users u ON u.id = rt.created_by WHERE rt.reseller_id = :r ORDER BY rt.id DESC LIMIT 50', ['r' => $r['id']]);
        $routers = Database::fetchAll('SELECT r.id, r.name FROM reseller_routers rr JOIN routers r ON r.id = rr.router_id WHERE rr.reseller_id = :r', ['r' => $r['id']]);
        $offers = Database::fetchAll("SELECT id, name, price FROM offers WHERE status = 'active' ORDER BY price");
        $monthly = Database::fetchAll("SELECT DATE_FORMAT(created_at, '%Y-%m') m, COUNT(*) n, SUM(total) total, SUM(commission_amount) commission FROM sales WHERE reseller_id = :r AND status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY m ORDER BY m", ['r' => $r['id']]);
        return $this->view('resellers/show', compact('r', 'stats', 'transactions', 'routers', 'offers', 'monthly'));
    }

    public function topup(Request $request, string $id): Response
    {
        $this->authorize('resellers.manage');
        try {
            $type = $request->str('type') === 'adjustment' ? 'adjustment' : ($request->str('type') === 'payout' ? 'payout' : 'topup');
            $amount = (float) $request->input('amount');
            if ($type === 'payout') {
                $amount = -abs($amount);
            }
            $b = ResellerService::topup((int) $id, $amount, $request->str('note'), (int) Auth::id(), $type);
            Session::flash('success', 'Mouvement enregistré. Nouveau solde : ' . money($b));
        } catch (Throwable $e) {
            Session::flash('danger', $e->getMessage());
        }
        return $this->redirect('/resellers/' . $id);
    }

    public function stock(Request $request, string $id): Response
    {
        $this->authorize('resellers.manage');
        try {
            if ($request->str('mode') === 'recall') {
                $n = ResellerService::recallStock((int) $id, $request->int('offer_id'), max(1, $request->int('quantity')), (int) Auth::id());
                Session::flash('success', "{$n} ticket(s) repris dans le stock de l'opérateur.");
            } else {
                $r = ResellerService::assignStock((int) $id, $request->int('offer_id'), $request->int('router_id'), max(1, min(2000, $request->int('quantity'))), (int) Auth::id());
                Session::flash('success', "{$r['count']} ticket(s) attribué(s)" . ($r['cost'] ? ' — débit de ' . money($r['cost']) : ' en dépôt-vente') . '.');
            }
        } catch (Throwable $e) {
            Session::flash('danger', $e->getMessage());
        }
        return $this->redirect('/resellers/' . $id);
    }

    public function toggle(Request $request, string $id): Response
    {
        $this->authorize('resellers.manage');
        $r = Database::fetch('SELECT * FROM resellers WHERE id = :id', ['id' => (int) $id]);
        $this->notFoundUnless($r);
        $new = $r['status'] === 'active' ? 'suspended' : 'active';
        Database::update('resellers', ['status' => $new], 'id = :id', ['id' => $r['id']]);
        if ($r['user_id']) {
            Database::update('users', ['status' => $new === 'active' ? 'active' : 'suspended'], 'id = :id', ['id' => $r['user_id']]);
        }
        AuditService::log('reseller.status', 'reseller', (int) $r['id'], "Revendeur {$r['business_name']} : {$new}");
        Session::flash('success', $new === 'active' ? 'Revendeur réactivé.' : 'Revendeur suspendu (son compte de connexion aussi).');
        return $this->redirect('/resellers/' . $id);
    }
}
