<?php
declare(strict_types=1);

namespace App\Controllers\Saas;

use App\Controllers\Controller;
use App\Core\BusinessException;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Saas\Billing;
use App\Saas\Central;
use App\Saas\ConsoleAuth;
use App\Saas\Provisioner;
use App\Saas\Saas;
use App\Saas\Subscription;

/** Console de l'exploitant du service : clients, abonnements, paiements, offres. */
final class ConsoleController extends Controller
{
    private array $admin = [];

    private function guard(): void
    {
        $this->admin = ConsoleAuth::require();
    }

    private function page(string $view, array $data): never
    {
        $pending = (int) Central::value("SELECT COUNT(*) FROM saas_payments WHERE status = 'pending'");
        $waiting = (int) Central::value("SELECT COUNT(*) FROM saas_tenants WHERE state = 'waiting_db'");
        view('saas/console/' . $view, $data + ['admin' => $this->admin, 'pendingCount' => $pending, 'waitingCount' => $waiting, 'flash' => Session::pullFlash()], 'saas/console/layout');
    }

    private function run(callable $fn, string $back, string $ok): never
    {
        try {
            $fn();
        } catch (BusinessException $e) {
            $this->fail($e->getMessage(), $back);
        }
        flash('success', $ok);
        redirect($back);
    }

    // ------------------------------------------------------------ connexion

    public function loginForm(): void
    {
        if (ConsoleAuth::admin()) {
            redirect('/console');
        }
        $none = !(int) Central::value('SELECT COUNT(*) FROM saas_admins');
        view('saas/console/login', ['flash' => Session::pullFlash(), 'none' => $none], null);
    }

    public function login(): void
    {
        try {
            ConsoleAuth::attempt(Request::str('username'), (string) Request::input('password', ''));
        } catch (BusinessException $e) {
            flash('danger', $e->getMessage());
            redirect('/console/login');
        }
        redirect('/console');
    }

    public function logout(): void
    {
        ConsoleAuth::logout();
        redirect('/console/login');
    }

    // ------------------------------------------------------------ tableau de bord

    public function dashboard(): void
    {
        $this->guard();
        $counts = ['trial' => 0, 'active' => 0, 'expired' => 0, 'suspended' => 0];
        $expiring = [];
        foreach (Central::all("SELECT t.*, p.name AS plan_name FROM saas_tenants t JOIN saas_plans p ON p.id = t.plan_id WHERE t.state = 'ready'") as $t) {
            $s = Subscription::state($t);
            $counts[$s['state']]++;
            if (in_array($s['state'], ['trial', 'active'], true) && $s['days_left'] <= 7) {
                $expiring[] = $t + ['sub' => $s];
            }
        }
        usort($expiring, fn($a, $b) => $a['sub']['days_left'] <=> $b['sub']['days_left']);
        $this->page('dashboard', [
            'title' => 'Tableau de bord', 'counts' => $counts, 'mrr' => Billing::mrr(), 'expiring' => $expiring,
            'month' => (float) Central::value("SELECT COALESCE(SUM(amount),0) FROM saas_payments WHERE status = 'validated' AND validated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
            'signups' => Central::all('SELECT t.id, t.slug, t.company, t.state, t.created_at, p.name AS plan_name FROM saas_tenants t JOIN saas_plans p ON p.id = t.plan_id ORDER BY t.id DESC LIMIT 8'),
            'monthly' => Central::all("SELECT DATE_FORMAT(validated_at, '%Y-%m') m, SUM(amount) total, COUNT(*) n FROM saas_payments WHERE status = 'validated' AND validated_at >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH, '%Y-%m-01') GROUP BY m ORDER BY m"),
        ]);
    }

    // ------------------------------------------------------------ clients

    public function tenants(): void
    {
        $this->guard();
        $q = trim(Request::str('q'));
        $f = Request::str('etat');
        $sql = 'SELECT t.*, p.name AS plan_name, p.price_month FROM saas_tenants t JOIN saas_plans p ON p.id = t.plan_id';
        $params = [];
        if ($q !== '') {
            $sql .= ' WHERE (t.slug LIKE ? OR t.company LIKE ? OR t.phone LIKE ? OR t.contact_name LIKE ?)';
            $params = array_fill(0, 4, '%' . $q . '%');
        }
        $rows = [];
        foreach (Central::all($sql . ' ORDER BY t.company LIMIT 500', $params) as $t) {
            $t['sub'] = $t['state'] === 'ready' ? Subscription::state($t) : ['state' => $t['state'], 'until' => null, 'days_left' => 0];
            if ($f === '' || $t['sub']['state'] === $f) {
                $rows[] = $t;
            }
        }
        $this->page('tenants', ['title' => 'Clients', 'rows' => $rows, 'q' => $q, 'f' => $f]);
    }

    private function findTenant(int $id): array
    {
        return Central::one('SELECT * FROM saas_tenants WHERE id = ?', [$id]) ?? throw new HttpException(404);
    }

    public function tenant(int $id): void
    {
        $this->guard();
        $t = $this->findTenant($id);
        $this->page('tenant', [
            'title' => $t['company'], 't' => $t, 'plan' => Subscription::plan($t),
            'sub' => $t['state'] === 'ready' ? Subscription::state($t) : ['state' => $t['state'], 'until' => null, 'days_left' => 0],
            'plans' => Central::all('SELECT * FROM saas_plans WHERE active = 1 ORDER BY sort, price_month'),
            'payments' => Central::all('SELECT p.*, pl.name AS plan_name FROM saas_payments p JOIN saas_plans pl ON pl.id = p.plan_id WHERE p.tenant_id = ? ORDER BY p.id DESC', [$id]),
            'log' => Central::all('SELECT a.*, ad.username FROM saas_audit a LEFT JOIN saas_admins ad ON ad.id = a.admin_id WHERE a.tenant_id = ? ORDER BY a.id DESC LIMIT 30', [$id]),
            'url' => Saas::tenantUrl($t['slug'], '/login'), 'methods' => Subscription::METHODS,
        ]);
    }

    public function recordPayment(int $id): void
    {
        $this->guard();
        $this->findTenant($id);
        $this->run(fn() => Billing::record($id, $_POST, (int) $this->admin['id']), '/console/clients/' . $id, 'Paiement enregistré : abonnement prolongé et facture émise.');
    }

    public function extend(int $id): void
    {
        $this->guard();
        $this->run(fn() => Billing::extend($id, Request::int('days'), Request::str('reason'), (int) $this->admin['id']), '/console/clients/' . $id, 'Période prolongée.');
    }

    public function suspend(int $id): void
    {
        $this->guard();
        $this->findTenant($id);
        $this->run(function () use ($id) {
            $reason = trim(Request::str('reason'));
            if (mb_strlen($reason) < 3) {
                throw new BusinessException('Indiquez le motif de la suspension (le client le verra).');
            }
            Central::update('saas_tenants', ['suspended_at' => date('Y-m-d H:i:s'), 'suspended_reason' => mb_substr($reason, 0, 255)], 'id = ?', [$id]);
            Central::audit('tenant.suspended', $id, ['reason' => $reason], (int) $this->admin['id']);
        }, '/console/clients/' . $id, 'Espace suspendu : le client ne peut plus y accéder (données conservées).');
    }

    public function reactivate(int $id): void
    {
        $this->guard();
        $this->findTenant($id);
        Central::update('saas_tenants', ['suspended_at' => null, 'suspended_reason' => null], 'id = ?', [$id]);
        Central::audit('tenant.reactivated', $id, [], (int) $this->admin['id']);
        flash('success', 'Espace réactivé.');
        redirect('/console/clients/' . $id);
    }

    public function changePlan(int $id): void
    {
        $this->guard();
        $t = $this->findTenant($id);
        $plan = Central::one('SELECT * FROM saas_plans WHERE id = ? AND active = 1', [Request::int('plan_id')]);
        if (!$plan) {
            $this->fail('Offre invalide.', '/console/clients/' . $id);
        }
        Central::update('saas_tenants', ['plan_id' => $plan['id']], 'id = ?', [$id]);
        Central::audit('tenant.plan_changed', $id, ['from' => (int) $t['plan_id'], 'to' => (int) $plan['id']], (int) $this->admin['id']);
        flash('success', 'Offre changée : ' . $plan['name'] . '. Les limites s\'appliquent aux prochains ajouts (rien n\'est supprimé).');
        redirect('/console/clients/' . $id);
    }

    public function note(int $id): void
    {
        $this->guard();
        $this->findTenant($id);
        Central::update('saas_tenants', ['notes' => mb_substr(Request::str('notes'), 0, 5000) ?: null], 'id = ?', [$id]);
        flash('success', 'Note enregistrée.');
        redirect('/console/clients/' . $id);
    }

    public function allocate(int $id): void
    {
        $this->guard();
        $this->findTenant($id);
        $ok = Provisioner::allocate($id);
        flash($ok ? 'success' : 'warning', $ok ? 'Base attribuée : l\'espace sera installé à la première visite du client.' : 'Aucune base libre dans la réserve : ajoutez-en une (Bases de données).');
        redirect('/console/clients/' . $id);
    }

    // ------------------------------------------------------------ paiements

    public function payments(): void
    {
        $this->guard();
        $this->page('payments', [
            'title' => 'Paiements',
            'pending' => Central::all("SELECT p.*, t.company, t.slug, pl.name AS plan_name FROM saas_payments p JOIN saas_tenants t ON t.id = p.tenant_id JOIN saas_plans pl ON pl.id = p.plan_id WHERE p.status = 'pending' ORDER BY p.id"),
            'done' => Central::all("SELECT p.*, t.company, t.slug, pl.name AS plan_name FROM saas_payments p JOIN saas_tenants t ON t.id = p.tenant_id JOIN saas_plans pl ON pl.id = p.plan_id WHERE p.status <> 'pending' ORDER BY p.id DESC LIMIT 200"),
            'methods' => Subscription::METHODS,
        ]);
    }

    public function validatePayment(int $id): void
    {
        $this->guard();
        $this->run(fn() => Billing::validate($id, (int) $this->admin['id']), '/console/paiements', 'Paiement validé : abonnement prolongé, facture émise.');
    }

    public function rejectPayment(int $id): void
    {
        $this->guard();
        $this->run(fn() => Billing::reject($id, Request::str('reason'), (int) $this->admin['id']), '/console/paiements', 'Déclaration refusée : le client voit le motif dans « Mon abonnement ».');
    }

    public function invoice(int $id): void
    {
        $this->guard();
        $p = Central::one("SELECT * FROM saas_payments WHERE id = ? AND status = 'validated'", [$id]) ?? throw new HttpException(404);
        self::sendPdf(Billing::invoicePdf($p), 'Facture-' . $p['invoice_no'] . '.pdf');
    }

    public static function sendPdf(string $pdf, string $name): never
    {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $name) . '"');
        header('Cache-Control: private, no-store');
        echo $pdf;
        exit;
    }

    // ------------------------------------------------------------ offres

    public function plans(): void
    {
        $this->guard();
        $this->page('plans', ['title' => 'Offres', 'plans' => Central::all('SELECT p.*, (SELECT COUNT(*) FROM saas_tenants t WHERE t.plan_id = p.id) AS tenants FROM saas_plans p ORDER BY sort, price_month'),
            'addonNames' => PublicController::addonNames()]);
    }

    public function planSave(int $id = 0): void
    {
        $this->guard();
        $code = strtolower(Request::str('code'));
        $addons = Request::input('addons_all') ? '*' : implode(',', array_intersect(array_keys(PublicController::addonNames()), (array) Request::input('addons', [])));
        $d = [
            'name' => mb_substr(Request::str('name'), 0, 80), 'description' => mb_substr(Request::str('description'), 0, 255) ?: null,
            'price_month' => max(0, round(Request::num('price_month'), 2)), 'max_users' => max(1, Request::int('max_users')),
            'max_stores' => max(1, Request::int('max_stores')), 'max_registers' => max(1, Request::int('max_registers')),
            'addons' => $addons, 'public' => Request::input('public') ? 1 : 0, 'active' => Request::input('active') ? 1 : 0, 'sort' => Request::int('sort'),
        ];
        if ($d['name'] === '') {
            $this->fail('Nom de l\'offre obligatoire.', '/console/offres');
        }
        if ($id) {
            Central::update('saas_plans', $d, 'id = ?', [$id]);
        } else {
            if (!preg_match('/^[a-z0-9_-]{2,30}$/', $code) || Central::value('SELECT 1 FROM saas_plans WHERE code = ?', [$code])) {
                $this->fail('Code d\'offre invalide ou déjà utilisé.', '/console/offres');
            }
            $id = Central::insert('saas_plans', $d + ['code' => $code]);
        }
        Central::audit('plan.saved', null, ['plan' => $id, 'price' => $d['price_month']], (int) $this->admin['id']);
        flash('success', 'Offre enregistrée.');
        redirect('/console/offres');
    }

    // ------------------------------------------------------------ réserve de bases (o2switch)

    public function pool(): void
    {
        $this->guard();
        $this->page('pool', ['title' => 'Bases de données', 'mode' => config('saas.provision'),
            'rows' => Central::all('SELECT b.id, b.db_name, b.db_user, b.added_at, b.assigned_at, t.company, t.slug FROM saas_db_pool b LEFT JOIN saas_tenants t ON t.id = b.tenant_id ORDER BY b.tenant_id IS NOT NULL, b.id')]);
    }

    public function poolAdd(): void
    {
        $this->guard();
        $name = Request::str('db_name');
        $user = Request::str('db_user');
        $pass = (string) Request::input('db_pass', '');
        $this->run(function () use ($name, $user, $pass) {
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $user) || $pass === '') {
                throw new BusinessException('Nom de base, utilisateur ou mot de passe invalide.');
            }
            if (Central::value('SELECT 1 FROM saas_db_pool WHERE db_name = ?', [$name]) || Central::value('SELECT 1 FROM saas_tenants WHERE db_name = ?', [$name]) || $name === config('saas.central.database')) {
                throw new BusinessException('Cette base est déjà utilisée par le service.');
            }
            // Vérification réelle : connexion avec ces identifiants et base vide
            $c = config('saas.central');
            try {
                $pdo = new \PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $name), $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $n = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
            } catch (\PDOException $e) {
                throw new BusinessException('Connexion refusée avec ces identifiants : ' . mb_substr($e->getMessage(), 0, 200));
            }
            if ($n > 0) {
                throw new BusinessException('Cette base contient déjà ' . $n . ' table(s) : seule une base vide peut être ajoutée.');
            }
            Central::insert('saas_db_pool', ['db_name' => $name, 'db_user' => $user, 'db_pass_enc' => Saas::encrypt($pass)]);
            Central::audit('pool.added', null, ['db' => $name], (int) $this->admin['id']);
            // Espaces en attente d'une base : attribution immédiate au plus ancien
            $w = Central::value("SELECT id FROM saas_tenants WHERE state = 'waiting_db' ORDER BY id LIMIT 1");
            if ($w) {
                Provisioner::allocate((int) $w);
            }
        }, '/console/bases', 'Base ajoutée à la réserve (connexion vérifiée).');
    }

    // ------------------------------------------------------------ paramètres et journal

    public const SETTINGS = [
        'operator_name' => 'Nom de l\'exploitant (factures, site)', 'operator_address' => 'Adresse', 'operator_phone' => 'Téléphone',
        'operator_email' => 'E-mail', 'operator_nif' => 'NIF', 'operator_rccm' => 'RCCM',
        'payment_instructions' => 'Instructions de paiement montrées aux clients (numéros mobile money, RIB…)', 'invoice_note' => 'Mention en bas des factures',
        'trial_days' => 'Durée de l\'essai gratuit (jours)',
    ];

    public function settings(): void
    {
        $this->guard();
        $v = [];
        foreach (array_keys(self::SETTINGS) as $k) {
            $v[$k] = (string) Central::setting($k, $k === 'trial_days' ? (string) config('saas.trial_days') : '');
        }
        $this->page('settings', ['title' => 'Paramètres du service', 'v' => $v, 'labels' => self::SETTINGS]);
    }

    public function settingsSave(): void
    {
        $this->guard();
        foreach (array_keys(self::SETTINGS) as $k) {
            $val = mb_substr(trim((string) Request::input($k, '')), 0, 2000);
            if ($k === 'trial_days') {
                $val = (string) max(1, min(90, (int) $val));
            }
            Central::settingSet($k, $val);
        }
        Central::audit('settings.saved', null, [], (int) $this->admin['id']);
        flash('success', 'Paramètres enregistrés.');
        redirect('/console/parametres');
    }

    public function audit(): void
    {
        $this->guard();
        $this->page('audit', ['title' => 'Journal', 'rows' => Central::all('SELECT a.*, ad.username, t.company FROM saas_audit a LEFT JOIN saas_admins ad ON ad.id = a.admin_id LEFT JOIN saas_tenants t ON t.id = a.tenant_id ORDER BY a.id DESC LIMIT 300')]);
    }
}
