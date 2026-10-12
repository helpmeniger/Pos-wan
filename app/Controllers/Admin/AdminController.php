<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Services\DispatchService;
use App\Services\NotificationService;
use App\Services\OrderPolicy;
use App\Services\OrderService;
use App\Services\OrderStateMachine;
use App\Services\ReportService;
use App\Services\TrackingService;

/** Administration : tableau de bord, carte opérationnelle, utilisateurs, validations, commandes. */
final class AdminController extends Controller
{
    public function dashboard(Request $r): Response
    {
        DispatchService::tick();
        return $this->json(ReportService::dashboard(ReportService::filters($r->query)));
    }

    /** Carte opérationnelle : prestataires en ligne et commandes actives (personnel habilité uniquement). */
    public function map(Request $r): Response
    {
        $providers = Database::select("SELECT p.user_id id, u.full_name, p.provider_type, p.current_order_id, l.lat, l.lng, l.heading, l.updated_at, v.category
                                         FROM provider_profiles p JOIN users u ON u.id = p.user_id JOIN provider_locations l ON l.provider_id = p.user_id
                                         LEFT JOIN vehicles v ON v.provider_id = p.user_id AND v.is_active = 1
                                        WHERE p.is_online = 1 AND l.updated_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
        foreach ($providers as &$p) {
            $p['icon'] = TrackingService::iconFor($p['category'], $p['provider_type']);
            $p['age_seconds'] = time() - strtotime((string) $p['updated_at']);
        }
        unset($p);
        $orders = Database::select("SELECT id, reference, service_type, status, dispatch_status, pickup_lat, pickup_lng, dropoff_lat, dropoff_lng, provider_id, created_at
                                      FROM orders WHERE status IN ('" . implode("','", OrderService::OPEN_STATUSES) . "') ORDER BY id DESC LIMIT 300");
        return $this->json(['providers' => $providers, 'orders' => $orders]);
    }

    // ------------------------------------------------------------------ Utilisateurs

    public function users(Request $r): Response
    {
        [$limit, $offset] = $this->page($r, 50, 200);
        $w = ['1=1'];
        $p = [];
        if ($q = trim((string) $r->input('q', ''))) {
            $w[] = '(u.full_name LIKE :q OR u.phone LIKE :q2 OR u.email LIKE :q3)';
            $p['q'] = $p['q2'] = $p['q3'] = '%' . $q . '%';
        }
        if ($role = $r->input('role')) {
            $w[] = 'r.code = :r';
            $p['r'] = (string) $role;
        }
        if ($status = $r->input('status')) {
            $w[] = 'u.status = :s';
            $p['s'] = (string) $status;
        }
        $rows = Database::select('SELECT u.id, u.full_name, u.phone, u.email, u.status, u.created_at, u.last_login_at, u.phone_verified_at, u.is_demo, r.code role, r.name role_name
                                    FROM users u JOIN roles r ON r.id = u.role_id WHERE ' . implode(' AND ', $w) . " ORDER BY u.id DESC LIMIT $limit OFFSET $offset", $p);
        $total = (int) Database::value('SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE ' . implode(' AND ', $w), $p);
        return Response::json(['data' => $rows, 'total' => $total]);
    }

    public function showUser(Request $r): Response
    {
        $id = $r->intParam('id');
        $u = Database::first('SELECT u.id, u.uuid, u.full_name, u.phone, u.email, u.status, u.created_at, u.last_login_at, u.failed_logins, u.locked_until, r.code role
                                FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id', ['id' => $id]);
        if (!$u) {
            throw HttpException::notFound();
        }
        $u['orders'] = Database::select('SELECT id, reference, service_type, status, total, created_at FROM orders WHERE customer_id = :u OR provider_id = :u2 ORDER BY id DESC LIMIT 20', ['u' => $id, 'u2' => $id]);
        $u['complaints'] = Database::select('SELECT id, reference, subject, status, created_at FROM complaints WHERE user_id = :u ORDER BY id DESC LIMIT 20', ['u' => $id]);
        $u['decisions'] = Database::select("SELECT action, new_values, created_at FROM audit_logs WHERE entity_type IN ('user','provider','merchant','business') AND entity_id = :id ORDER BY id DESC LIMIT 30", ['id' => $id]);
        return $this->json($u);
    }

    public function setUserStatus(Request $r): Response
    {
        $id = $r->intParam('id');
        $v = $this->validate($r, ['status' => 'required|in:active,suspended', 'reason' => 'required|string|min:3|max:255']);
        $u = Database::first('SELECT u.*, r.is_staff FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id', ['id' => $id]);
        if (!$u || $u['status'] === 'deleted') {
            throw HttpException::notFound();
        }
        if ((int) $u['id'] === (int) Auth::id()) {
            throw HttpException::forbidden('Vous ne pouvez pas modifier votre propre statut.');
        }
        if ((int) $u['is_staff'] && !Auth::can('settings.manage')) {
            throw HttpException::forbidden();
        }
        Database::update('users', ['status' => $v['status']], 'id = :id', ['id' => $id]);
        if ($v['status'] === 'suspended') {
            Database::execute('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = :u AND revoked_at IS NULL', ['u' => $id]);
            Database::execute('UPDATE provider_profiles SET is_online = 0 WHERE user_id = :u', ['u' => $id]);
        }
        Audit::log('user.' . ($v['status'] === 'suspended' ? 'suspended' : 'reactivated'), 'user', $id, ['status' => $u['status']], $v);
        NotificationService::notify($id, 'account_status', $v['status'] === 'suspended' ? 'Compte suspendu' : 'Compte réactivé', $v['reason'], [], ['email', 'sms']);
        return $this->ok('Statut mis à jour.');
    }

    /** Création d'un compte du personnel (administrateur, assistance, finance). */
    public function createStaff(Request $r): Response
    {
        $v = $this->validate($r, ['full_name' => 'required|string|max:120', 'email' => 'required|email', 'phone' => 'nullable|phone', 'role' => 'required|in:admin,support,finance', 'password' => 'required|password']);
        if (Database::value('SELECT 1 FROM users WHERE email = :e', ['e' => $v['email']]) || (!empty($v['phone']) && Database::value('SELECT 1 FROM users WHERE phone = :p', ['p' => $v['phone']]))) {
            throw \App\Core\ValidationException::field('email', 'Un compte existe déjà avec cet e-mail ou ce téléphone.');
        }
        $id = Database::insert('users', ['uuid' => Str::uuid(), 'role_id' => (int) Database::value('SELECT id FROM roles WHERE code = :c', ['c' => $v['role']]),
            'full_name' => $v['full_name'], 'email' => $v['email'], 'phone' => $v['phone'] ?? null, 'password_hash' => Auth::hashPassword($v['password'])]);
        Audit::log('staff.created', 'user', $id, null, ['email' => $v['email'], 'role' => $v['role']]);
        return $this->json(['id' => $id], 201);
    }

    // ------------------------------------------------------------------ Validation des partenaires

    public function providers(Request $r): Response
    {
        $w = ['1=1'];
        $p = [];
        if ($s = $r->input('status')) {
            $w[] = 'p.verification_status = :s';
            $p['s'] = (string) $s;
        }
        if ($t = $r->input('type')) {
            $w[] = 'p.provider_type = :t';
            $p['t'] = (string) $t;
        }
        return $this->json(Database::select('SELECT p.user_id id, u.full_name, u.phone, u.status user_status, p.provider_type, p.verification_status, p.is_online, p.rating_avg, p.rating_count,
                p.license_expires_at, p.created_at, v.category, v.plate, (SELECT COUNT(*) FROM provider_documents d WHERE d.provider_id = p.user_id AND d.status = \'pending\') pending_docs
              FROM provider_profiles p JOIN users u ON u.id = p.user_id LEFT JOIN vehicles v ON v.provider_id = p.user_id AND v.is_active = 1
             WHERE ' . implode(' AND ', $w) . ' ORDER BY p.verification_status = \'pending\' DESC, p.created_at DESC LIMIT 300', $p));
    }

    public function provider(Request $r): Response
    {
        $id = $r->intParam('id');
        $p = Database::first('SELECT p.*, u.full_name, u.phone, u.email, u.photo_file_id, u.created_at user_created FROM provider_profiles p JOIN users u ON u.id = p.user_id WHERE p.user_id = :id', ['id' => $id]);
        if (!$p) {
            throw HttpException::notFound();
        }
        $p['vehicles'] = Database::select('SELECT * FROM vehicles WHERE provider_id = :id', ['id' => $id]);
        $p['documents'] = array_map(static fn ($d) => $d + ['url' => url('fichiers/' . $d['file_id'])], Database::select('SELECT * FROM provider_documents WHERE provider_id = :id ORDER BY id DESC', ['id' => $id]));
        $p['balance'] = \App\Services\LedgerService::partnerBalance('provider', $id);
        return $this->json($p);
    }

    public function verifyProvider(Request $r): Response
    {
        $id = $r->intParam('id');
        $v = $this->validate($r, ['decision' => 'required|in:verified,refused,suspended,pending', 'note' => 'nullable|string|max:255']);
        $p = Database::first('SELECT * FROM provider_profiles WHERE user_id = :id', ['id' => $id]);
        if (!$p) {
            throw HttpException::notFound();
        }
        if ($v['decision'] === 'verified') {
            if (!Database::value('SELECT 1 FROM vehicles WHERE provider_id = :id AND is_active = 1', ['id' => $id])) {
                throw HttpException::conflict('Le prestataire doit avoir enregistré un véhicule avant validation.');
            }
            if ($p['provider_type'] === 'driver' && !Database::value("SELECT 1 FROM provider_documents WHERE provider_id = :id AND doc_type = 'driving_license' AND status = 'approved'", ['id' => $id])) {
                throw HttpException::conflict('Le permis de conduire doit être approuvé avant de valider un chauffeur.');
            }
        }
        $data = ['verification_status' => $v['decision'], 'verification_note' => $v['note'] ?? null];
        if ($v['decision'] !== 'verified') {
            $data['is_online'] = 0;
        }
        Database::update('provider_profiles', $data, 'user_id = :id', ['id' => $id]);
        Audit::log('partner.provider_' . $v['decision'], 'provider', $id, ['verification_status' => $p['verification_status']], $v);
        $labels = ['verified' => 'validé', 'refused' => 'refusé', 'suspended' => 'suspendu', 'pending' => 'remis en vérification'];
        NotificationService::notify($id, 'verification', 'Compte prestataire ' . $labels[$v['decision']], $v['note'] ?: 'Décision de l\'équipe MARWA : compte ' . $labels[$v['decision']] . '.', [], ['sms']);
        return $this->ok('Décision enregistrée.');
    }

    public function reviewDocument(Request $r): Response
    {
        $id = $r->intParam('id');
        $v = $this->validate($r, ['status' => 'required|in:approved,rejected', 'note' => 'nullable|string|max:255', 'expires_at' => 'nullable|date']);
        $d = Database::first('SELECT * FROM provider_documents WHERE id = :id', ['id' => $id]);
        if (!$d) {
            throw HttpException::notFound();
        }
        $data = ['status' => $v['status'], 'review_note' => $v['note'] ?? null, 'reviewed_by' => Auth::id(), 'reviewed_at' => date('Y-m-d H:i:s')];
        if (!empty($v['expires_at'])) {
            $data['expires_at'] = $v['expires_at'];
        }
        Database::update('provider_documents', $data, 'id = :id', ['id' => $id]);
        if ($d['doc_type'] === 'driving_license' && $v['status'] === 'approved' && ($v['expires_at'] ?? $d['expires_at'])) {
            Database::update('provider_profiles', ['license_expires_at' => $v['expires_at'] ?? $d['expires_at']], 'user_id = :u', ['u' => $d['provider_id']]);
        }
        Audit::log('partner.document_' . $v['status'], 'provider_document', $id, ['status' => $d['status']], $v);
        return $this->ok('Document ' . ($v['status'] === 'approved' ? 'approuvé' : 'rejeté') . '.');
    }

    public function merchants(Request $r): Response
    {
        $s = $r->input('status');
        return $this->json(Database::select('SELECT m.id, m.name, m.kind, m.cuisine, m.status, m.is_open, m.address_text, m.lat, m.lng, m.delivery_mode, m.rating_avg, m.rccm, m.tax_id, m.created_at,
                u.full_name owner, u.phone, (SELECT COUNT(*) FROM products p WHERE p.merchant_id = m.id AND p.deleted_at IS NULL) products
              FROM merchants m JOIN users u ON u.id = m.owner_user_id ' . ($s ? 'WHERE m.status = :s ' : '') . 'ORDER BY m.status = \'pending\' DESC, m.id DESC LIMIT 300', $s ? ['s' => (string) $s] : []));
    }

    public function verifyMerchant(Request $r): Response
    {
        $id = $r->intParam('id');
        $v = $this->validate($r, ['decision' => 'required|in:verified,refused,suspended,pending', 'note' => 'nullable|string|max:255']);
        $m = Database::first('SELECT * FROM merchants WHERE id = :id', ['id' => $id]);
        if (!$m) {
            throw HttpException::notFound();
        }
        Database::update('merchants', ['status' => $v['decision'], 'is_open' => $v['decision'] === 'verified' ? $m['is_open'] : 0], 'id = :id', ['id' => $id]);
        Audit::log('partner.merchant_' . $v['decision'], 'merchant', $id, ['status' => $m['status']], $v);
        NotificationService::notify((int) $m['owner_user_id'], 'verification', 'Décision sur votre commerce', ($v['note'] ?? '') ?: 'Statut : ' . $v['decision'], [], ['sms']);
        return $this->ok('Décision enregistrée.');
    }

    public function businesses(Request $r): Response
    {
        return $this->json(Database::select('SELECT b.*, u.full_name owner, u.phone, (SELECT COUNT(*) FROM business_members m WHERE m.business_id = b.id) members,
                (SELECT COALESCE(-balance,0) FROM partner_balances pb WHERE pb.owner_type = \'business\' AND pb.owner_id = b.id) outstanding
              FROM businesses b JOIN users u ON u.id = b.owner_user_id ORDER BY b.status = \'pending\' DESC, b.id DESC LIMIT 300'));
    }

    public function verifyBusiness(Request $r): Response
    {
        $id = $r->intParam('id');
        $v = $this->validate($r, ['decision' => 'required|in:verified,refused,suspended,pending', 'postpaid_enabled' => 'nullable|bool', 'credit_limit' => 'nullable|int|min:0', 'note' => 'nullable|string|max:255']);
        $b = Database::first('SELECT * FROM businesses WHERE id = :id', ['id' => $id]);
        if (!$b) {
            throw HttpException::notFound();
        }
        $data = ['status' => $v['decision'], 'postpaid_enabled' => !empty($v['postpaid_enabled']) && $v['decision'] === 'verified' ? 1 : 0, 'credit_limit' => $v['credit_limit'] ?? (int) $b['credit_limit']];
        Database::update('businesses', $data, 'id = :id', ['id' => $id]);
        Audit::log('partner.business_' . $v['decision'], 'business', $id, $b, $data);
        NotificationService::notify((int) $b['owner_user_id'], 'verification', 'Décision sur votre compte entreprise', ($v['note'] ?? '') ?: 'Statut : ' . $v['decision']);
        return $this->ok('Décision enregistrée.');
    }

    // ------------------------------------------------------------------ Commandes

    public function orders(Request $r): Response
    {
        [$limit, $offset] = $this->page($r, 50, 200);
        $w = ['1=1'];
        $p = [];
        foreach (['status' => 'o.status', 'service_type' => 'o.service_type', 'payment_status' => 'o.payment_status'] as $k => $col) {
            if ($val = $r->input($k)) {
                $w[] = "$col = :$k";
                $p[$k] = (string) $val;
            }
        }
        if ($r->input('active')) {
            $w[] = "o.status IN ('" . implode("','", OrderService::OPEN_STATUSES) . "')";
        }
        if ($q = trim((string) $r->input('q', ''))) {
            $w[] = '(o.reference LIKE :q OR cu.full_name LIKE :q2)';
            $p['q'] = $p['q2'] = '%' . $q . '%';
        }
        $rows = Database::select('SELECT o.id, o.reference, o.service_type, o.status, o.dispatch_status, o.total, o.payment_method, o.payment_status, o.created_at, o.is_demo,
                cu.full_name customer, pu.full_name provider, m.name merchant
              FROM orders o JOIN users cu ON cu.id = o.customer_id LEFT JOIN users pu ON pu.id = o.provider_id LEFT JOIN merchants m ON m.id = o.merchant_id
             WHERE ' . implode(' AND ', $w) . " ORDER BY o.id DESC LIMIT $limit OFFSET $offset", $p);
        foreach ($rows as &$row) {
            $row['status_label'] = OrderStateMachine::label($row['status']);
        }
        return $this->json($rows);
    }

    public function order(Request $r): Response
    {
        $order = OrderPolicy::load($r->intParam('id'), $this->user(), ['admin', 'customer', 'provider', 'merchant', 'business']);
        $data = OrderPolicy::present($order, 'admin');
        $data['offers'] = Database::select('SELECT d.provider_id, u.full_name, d.round_no, d.distance_m, d.status, d.refusal_reason, d.sent_at, d.responded_at FROM dispatch_offers d JOIN users u ON u.id = d.provider_id WHERE d.order_id = :o ORDER BY d.id', ['o' => $order['id']]);
        $data['negotiations_log'] = Database::select('SELECT proposed_by, provider_id, amount, status, created_at FROM fare_negotiations WHERE order_id = :o ORDER BY id', ['o' => $order['id']]);
        $data['assignments'] = Database::select('SELECT a.provider_id, u.full_name, a.agreed_price, a.assigned_at, a.ended_at, a.end_reason FROM assignments a JOIN users u ON u.id = a.provider_id WHERE a.order_id = :o ORDER BY a.id', ['o' => $order['id']]);
        $data['refunds'] = Database::select('SELECT * FROM refunds WHERE order_id = :o ORDER BY id', ['o' => $order['id']]);
        $data['admin_actions'] = OrderStateMachine::next($order['flow'], $order['status'], 'admin');
        return $this->json($data);
    }

    public function orderAction(Request $r): Response
    {
        $order = DispatchService::fresh($r->intParam('id'));
        $action = (string) $r->input('action', '');
        $reason = trim((string) $r->input('reason', ''));
        if ($reason === '') {
            throw \App\Core\ValidationException::field('reason', 'Le motif est obligatoire pour toute action administrative.');
        }
        $uid = (int) Auth::id();
        switch ($action) {
            case 'cancel':
                OrderService::cancel($order, 'admin', $uid, $reason);
                break;
            case 'reassign':
                DispatchService::release((int) $order['id'], $reason, 'admin', $uid);
                break;
            case 'redispatch':
                if ($order['provider_id'] || !in_array($order['dispatch_status'], ['no_provider', 'none', 'searching'], true)) {
                    throw HttpException::conflict('Relance impossible pour cette commande.');
                }
                DispatchService::startSearch($order);
                break;
            default:
                if (!array_key_exists($action, OrderStateMachine::TRANSITIONS[$order['flow']][$order['status']] ?? [])) {
                    throw HttpException::badRequest('Action inconnue.');
                }
                if (in_array($action, ['completed', 'delivered'], true)) {
                    OrderService::advance($order, $action, 'admin', $uid, $r->all() + ['delivery_code' => $order['delivery_code']]);
                } else {
                    OrderStateMachine::transition($order, $action, 'admin', $uid, $reason);
                }
        }
        Audit::log('order.admin_' . $action, 'order', (int) $order['id'], ['status' => $order['status']], ['reason' => $reason]);
        return $this->json(OrderPolicy::present(DispatchService::fresh((int) $order['id']), 'admin'));
    }
}
