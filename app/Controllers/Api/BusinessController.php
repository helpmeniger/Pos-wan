<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Services\BusinessService;
use App\Services\LedgerService;
use App\Services\OrderPolicy;

/** Espace entreprise / client professionnel. */
final class BusinessController extends Controller
{
    private function business(bool $ownerOnly = false): array
    {
        $b = BusinessService::managedBy((int) $this->user()['id']);
        if (!$b || ($ownerOnly && $b['member_role'] !== 'owner')) {
            throw HttpException::forbidden('Réservé aux gestionnaires du compte entreprise.');
        }
        return $b;
    }

    public function show(Request $r): Response
    {
        $b = $this->business();
        $members = Database::select("SELECT m.id, m.user_id, m.member_role, m.monthly_limit, m.can_order, m.status, u.full_name, u.phone, u.email,
                (SELECT COALESCE(SUM(total),0) FROM orders o WHERE o.business_id = m.business_id AND o.customer_id = m.user_id AND o.payment_method = 'business_account'
                    AND o.status NOT IN ('cancelled','expired','refused','refunded') AND o.created_at >= :mo) month_spend
              FROM business_members m JOIN users u ON u.id = m.user_id WHERE m.business_id = :b ORDER BY m.member_role, u.full_name", ['b' => $b['id'], 'mo' => date('Y-m-01')]);
        return $this->json([
            'business' => $b + ['logo_url' => !empty($b['logo_file_id']) ? url('fichiers/' . $b['logo_file_id']) : null],
            'members' => $members,
            'month_spend' => BusinessService::monthSpend((int) $b['id']),
            'outstanding' => max(0, -LedgerService::partnerBalance('business', (int) $b['id'])),
            'addresses' => Database::select('SELECT id, label, address_text, lat, lng FROM addresses WHERE business_id = :b ORDER BY label', ['b' => $b['id']]),
        ]);
    }

    public function update(Request $r): Response
    {
        $b = $this->business(true);
        $v = $this->validate($r, ['name' => 'required|string|max:150', 'tax_id' => 'nullable|string|max:60', 'rccm' => 'nullable|string|max:60',
            'billing_email' => 'nullable|email', 'billing_address' => 'nullable|string|max:255', 'monthly_budget' => 'nullable|int|min:0']);
        Database::update('businesses', $v, 'id = :id', ['id' => $b['id']]);
        return $this->ok('Profil entreprise enregistré.');
    }

    public function addMember(Request $r): Response
    {
        $b = $this->business();
        $v = $this->validate($r, ['identifier' => 'required|string|max:190', 'member_role' => 'required|in:manager,employee', 'monthly_limit' => 'nullable|int|min:0', 'can_order' => 'nullable|bool']);
        if ($v['member_role'] === 'manager' && $b['member_role'] !== 'owner') {
            throw HttpException::forbidden('Seul le propriétaire peut ajouter un gestionnaire.');
        }
        $phone = Str::normalizePhone($v['identifier']);
        $user = filter_var($v['identifier'], FILTER_VALIDATE_EMAIL)
            ? Database::first("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = :e AND u.status = 'active' AND r.code IN ('client','business')", ['e' => mb_strtolower($v['identifier'])])
            : ($phone ? Database::first("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.phone = :p AND u.status = 'active' AND r.code IN ('client','business')", ['p' => $phone]) : null);
        if (!$user) {
            throw HttpException::notFound('Aucun compte client MARWA trouvé. Le collaborateur doit d\'abord créer son compte client.');
        }
        Database::execute('INSERT INTO business_members (business_id, user_id, member_role, monthly_limit, can_order) VALUES (:b, :u, :r, :l, :c)
                           ON DUPLICATE KEY UPDATE member_role = VALUES(member_role), monthly_limit = VALUES(monthly_limit), can_order = VALUES(can_order), status = \'active\'',
            ['b' => $b['id'], 'u' => $user['id'], 'r' => $v['member_role'], 'l' => $v['monthly_limit'] ?? null, 'c' => ($v['can_order'] ?? true) ? 1 : 0]);
        Audit::log('business.member_added', 'business', (int) $b['id'], null, ['user_id' => $user['id'], 'role' => $v['member_role']]);
        \App\Services\NotificationService::notify((int) $user['id'], 'business_member', 'Compte entreprise', 'Vous pouvez désormais commander pour ' . $b['name'] . '.');
        return $this->ok('Collaborateur ajouté.');
    }

    public function updateMember(Request $r): Response
    {
        $b = $this->business();
        $v = $this->validate($r, ['monthly_limit' => 'nullable|int|min:0', 'can_order' => 'required|bool', 'status' => 'required|in:active,disabled']);
        $m = Database::first('SELECT * FROM business_members WHERE id = :id AND business_id = :b', ['id' => $r->intParam('id'), 'b' => $b['id']]);
        if (!$m || $m['member_role'] === 'owner') {
            throw HttpException::forbidden('Ce membre ne peut pas être modifié.');
        }
        Database::update('business_members', ['monthly_limit' => $v['monthly_limit'] ?? null, 'can_order' => $v['can_order'] ? 1 : 0, 'status' => $v['status']], 'id = :id', ['id' => $m['id']]);
        Audit::log('business.member_updated', 'business', (int) $b['id'], $m, $v);
        return $this->ok('Collaborateur mis à jour.');
    }

    public function orders(Request $r): Response
    {
        $b = $this->business();
        [$limit, $offset] = $this->page($r, 50, 200);
        $rows = Database::select("SELECT o.*, u.full_name FROM orders o JOIN users u ON u.id = o.customer_id WHERE o.business_id = :b ORDER BY o.id DESC LIMIT $limit OFFSET $offset", ['b' => $b['id']]);
        return $this->json(array_map(static fn ($o) => OrderPolicy::present($o, 'business', false) + ['employee' => $o['full_name']], $rows));
    }

    /** Relevé mensuel (rapport et export) des commandes facturées au compte entreprise. */
    public function statement(Request $r): Response
    {
        $b = $this->business();
        $month = (string) $r->input('month', date('Y-m'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw \App\Core\ValidationException::field('month', 'Mois invalide (AAAA-MM).');
        }
        $from = $month . '-01 00:00:00';
        $to = date('Y-m-t 23:59:59', strtotime($from));
        $rows = Database::select("SELECT o.reference, DATE_FORMAT(o.created_at, '%d/%m/%Y %H:%i') date, o.service_type, u.full_name employee, o.pickup_text, o.dropoff_text, o.total, o.payment_method, o.status
                                   FROM orders o JOIN users u ON u.id = o.customer_id
                                  WHERE o.business_id = :b AND o.created_at BETWEEN :f AND :t AND o.status NOT IN ('cancelled','expired','refused') ORDER BY o.created_at",
            ['b' => $b['id'], 'f' => $from, 't' => $to]);
        $report = [
            'title' => 'Relevé ' . $b['name'] . ' — ' . date('m/Y', strtotime($from)),
            'subtitle' => 'Commandes du ' . date('d/m/Y', strtotime($from)) . ' au ' . date('d/m/Y', strtotime($to)),
            'columns' => ['label' => 'Référence', 'date' => 'Date', 'service_type' => 'Service', 'employee' => 'Collaborateur', 'pickup_text' => 'Départ', 'dropoff_text' => 'Arrivée', 'total' => 'Montant', 'payment_method' => 'Paiement'],
            'rows' => array_map(static fn ($x) => ['label' => $x['reference']] + $x + ['total' => (int) $x['total']], $rows),
        ];
        $report['totals'] = $rows ? ['label' => 'TOTAL', 'total' => array_sum(array_column($report['rows'], 'total'))] : [];
        $format = (string) $r->input('format', 'json');
        if ($format !== 'json') {
            return ExportResponder::respond($report, $format, 'releve-' . $month);
        }
        return $this->json($report + ['by_employee' => Database::select("SELECT u.full_name, COUNT(*) orders, SUM(o.total) amount FROM orders o JOIN users u ON u.id = o.customer_id
                WHERE o.business_id = :b AND o.created_at BETWEEN :f AND :t AND o.status NOT IN ('cancelled','expired','refused') GROUP BY u.id, u.full_name ORDER BY amount DESC", ['b' => $b['id'], 'f' => $from, 't' => $to])]);
    }

    /** Logo de l'entreprise (propriétaire ou responsable). */
    public function logo(Request $r): Response
    {
        $b = $this->business();
        if (!in_array($b['member_role'], ['owner', 'manager'], true)) {
            throw HttpException::forbidden();
        }
        $f = \App\Services\UploadService::store($r->file('file'), (int) $this->user()['id'], 'logo', true);
        Database::update('businesses', ['logo_file_id' => $f['id']], 'id = :id', ['id' => $b['id']]);
        return $this->json(['logo_url' => url('fichiers/' . $f['id'])]);
    }

    public function saveAddress(Request $r): Response
    {
        $b = $this->business();
        $v = $this->validate($r, ['label' => 'required|string|max:60', 'address_text' => 'required|string|max:255', 'lat' => 'nullable|lat', 'lng' => 'nullable|lng']);
        $id = Database::insert('addresses', $v + ['business_id' => $b['id']]);
        return $this->json(['id' => $id], 201);
    }

    public function deleteAddress(Request $r): Response
    {
        $b = $this->business();
        Database::execute('DELETE FROM addresses WHERE id = :id AND business_id = :b', ['id' => $r->intParam('id'), 'b' => $b['id']]);
        return $this->ok('Adresse supprimée.');
    }
}
