<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Repositories\SettingRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Finance\LedgerService;
use App\Services\Notifications\NotificationService;
use App\Support\Money;
use App\Support\References;
use App\Support\Uuid;

/** Requêtes et actions du back-office. Chaque action sensible est motivée et journalisée. */
class AdminService
{
    public const PER_PAGE = 25;

    public function __construct(
        private readonly Database $db,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly SettingRepository $settings,
        private readonly LedgerService $ledger,
    ) {
    }

    /** @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int} */
    private function paginate(string $select, string $from, string $where, array $params, string $order, int $page): array
    {
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) {$from} WHERE {$where}", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));
        $rows = $this->db->fetchAll("SELECT {$select} {$from} WHERE {$where} ORDER BY {$order} LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    private static function like(string $q): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    }

    // ------------------------------------------------------------------ Utilisateurs

    /** @param array{q?: string, type?: string, status?: string, verified?: string} $f */
    public function users(array $f, int $page): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.phone LIKE ? OR u.email LIKE ? OR b.name LIKE ?)';
            $like = self::like((string) $f['q']);
            array_push($params, $like, $like, $like, $like, $like);
        }
        if (!empty($f['type']) && in_array($f['type'], ['WORKER', 'CLIENT', 'STAFF'], true)) {
            $where[] = 'u.primary_type = ?';
            $params[] = $f['type'];
        }
        if (!empty($f['status']) && in_array($f['status'], ['ACTIVE', 'SUSPENDED', 'BANNED', 'DEACTIVATED', 'PENDING'], true)) {
            $where[] = 'u.status = ?';
            $params[] = $f['status'];
        }
        if (($f['verified'] ?? '') === '1') {
            $where[] = 'pr.verification_level >= 2';
        } elseif (($f['verified'] ?? '') === '0') {
            $where[] = 'pr.verification_level < 2';
        }
        return $this->paginate(
            "u.uuid, u.first_name, u.last_name, u.phone, u.email, u.primary_type, u.status, u.created_at, u.last_login_at,
             pr.verification_level, c.name AS city, b.name AS business_name,
             (SELECT COUNT(*) FROM verification_requests vr WHERE vr.user_id = u.id AND vr.status IN ('SOUMISE','EN_EXAMEN')) AS pending_docs",
            'FROM users u LEFT JOIN user_profiles pr ON pr.user_id = u.id LEFT JOIN cities c ON c.id = u.city_id
             LEFT JOIN business_members bm ON bm.user_id = u.id AND bm.member_role = \'OWNER\' LEFT JOIN businesses b ON b.id = bm.business_id',
            implode(' AND ', $where),
            $params,
            'u.id DESC',
            $page
        );
    }

    /** @return array<string, mixed> */
    public function userDetail(string $uuid): array
    {
        $u = $this->db->fetch(
            'SELECT u.*, pr.*, c.name AS city, u.id AS id, u.status AS status FROM users u LEFT JOIN user_profiles pr ON pr.user_id = u.id
             LEFT JOIN cities c ON c.id = u.city_id WHERE u.uuid = ?',
            [$uuid]
        ) ?? throw new HttpException(404);
        $id = (int) $u['id'];
        unset($u['password_hash']);
        return [
            'user' => $u,
            'roles' => array_column($this->db->fetchAll('SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$id]), 'code'),
            'businesses' => $this->db->fetchAll('SELECT b.*, m.member_role FROM business_members m JOIN businesses b ON b.id = m.business_id WHERE m.user_id = ?', [$id]),
            'documents' => $this->db->fetchAll(
                'SELECT r.id, r.kind, r.status, r.submitted_at, r.decision_note, d.doc_type, f.uuid AS file_uuid FROM verification_requests r
                 JOIN verification_documents d ON d.request_id = r.id JOIN stored_files f ON f.id = d.file_id WHERE r.user_id = ? ORDER BY r.id DESC',
                [$id]
            ),
            'posts' => $this->db->fetchAll('SELECT uuid, reference, title, status, created_at, positions_total, positions_filled FROM job_posts WHERE client_user_id = ? ORDER BY id DESC LIMIT 20', [$id]),
            'applications' => $this->db->fetchAll(
                'SELECT a.status, a.created_at, p.title, p.reference FROM job_applications a JOIN job_posts p ON p.id = a.job_post_id WHERE a.worker_user_id = ? ORDER BY a.id DESC LIMIT 20',
                [$id]
            ),
            'employments' => $this->db->fetchAll(
                "SELECT e.uuid, e.reference, e.position_title, e.status, e.salary_amount, e.pay_period, (e.employer_user_id = ?) AS as_employer,
                        CONCAT(o.first_name, ' ', o.last_name) AS other_party
                 FROM employments e JOIN users o ON o.id = IF(e.employer_user_id = ?, e.employee_user_id, e.employer_user_id)
                 WHERE e.employer_user_id = ? OR e.employee_user_id = ? ORDER BY e.id DESC LIMIT 20",
                [$id, $id, $id, $id]
            ),
            'payslips' => $this->db->fetchAll(
                'SELECT uuid, number, period_label, net_amount, status FROM payslips WHERE employer_user_id = ? OR employee_user_id = ? ORDER BY id DESC LIMIT 20',
                [$id, $id]
            ),
            'security' => $this->db->fetchAll('SELECT event_type, severity, ip_address, created_at FROM security_events WHERE user_id = ? ORDER BY id DESC LIMIT 15', [$id]),
            'commission_due' => $this->ledger->balance('1210', $id),
        ];
    }

    public function setUserStatus(int $adminId, string $uuid, string $status, string $reason, ?string $ip = null): void
    {
        if (!in_array($status, ['ACTIVE', 'SUSPENDED', 'BANNED'], true)) {
            throw new ValidationException(['status' => ['Statut invalide.']]);
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new ValidationException(['reason' => ['Motif obligatoire (5 caractères minimum).']]);
        }
        $u = $this->db->fetch('SELECT id, status, primary_type FROM users WHERE uuid = ?', [$uuid]) ?? throw new HttpException(404);
        if ((int) $u['id'] === $adminId) {
            throw new HttpException(403, 'Vous ne pouvez pas modifier le statut de votre propre compte.');
        }
        $isSuper = (bool) $this->db->fetchValue("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.code = 'SUPER_ADMIN'", [(int) $u['id']]);
        $actorIsSuper = (bool) $this->db->fetchValue("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.code = 'SUPER_ADMIN'", [$adminId]);
        if ($isSuper && !$actorIsSuper) {
            throw new HttpException(403, 'Seul un administrateur général peut modifier ce compte.');
        }
        $this->db->query('UPDATE users SET status = ?, status_reason = ? WHERE id = ?', [$status, $status === 'ACTIVE' ? null : $reason, (int) $u['id']]);
        $this->audit->record($adminId, 'user.status_changed', 'user', (int) $u['id'], ['status' => $u['status']], ['status' => $status], $reason);
        $messages = [
            'ACTIVE' => ['Compte réactivé', 'Votre compte est de nouveau actif.'],
            'SUSPENDED' => ['Compte suspendu', 'Motif : ' . $reason . '. Pour contester cette décision, contactez le support.'],
            'BANNED' => ['Compte fermé', 'Motif : ' . $reason . '. Pour contester cette décision, contactez le support.'],
        ];
        $this->notifications->notify((int) $u['id'], 'account.status', $messages[$status][0], $messages[$status][1]);
    }

    public function verifyPhoneManually(int $adminId, string $uuid): void
    {
        $id = (int) ($this->db->fetchValue('SELECT id FROM users WHERE uuid = ?', [$uuid]) ?? throw new HttpException(404));
        $this->db->query('UPDATE users SET phone_verified_at = COALESCE(phone_verified_at, NOW()) WHERE id = ?', [$id]);
        $this->db->query('UPDATE user_profiles SET verification_level = GREATEST(verification_level, 1) WHERE user_id = ?', [$id]);
        $this->audit->record($adminId, 'user.phone_verified_manually', 'user', $id, null, null, 'Vérification manuelle du téléphone');
    }

    // ------------------------------------------------------------------ Recrutement

    public function posts(array $f, int $page): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(p.title LIKE ? OR p.reference LIKE ? OR u.last_name LIKE ? OR b.name LIKE ?)';
            $like = self::like((string) $f['q']);
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($f['status'])) {
            $where[] = 'p.status = ?';
            $params[] = (string) $f['status'];
        }
        return $this->paginate(
            "p.id, p.uuid, p.reference, p.title, p.status, p.moderation_flags, p.created_at, p.positions_total, p.positions_filled,
             c.name AS city, s.name AS trade, s.risk_level, COALESCE(b.name, CONCAT(u.first_name, ' ', u.last_name)) AS recruiter, u.uuid AS recruiter_uuid,
             (SELECT COUNT(*) FROM job_applications a WHERE a.job_post_id = p.id) AS applications",
            'FROM job_posts p JOIN users u ON u.id = p.client_user_id LEFT JOIN businesses b ON b.id = p.business_id
             LEFT JOIN cities c ON c.id = p.city_id JOIN job_subcategories s ON s.id = p.subcategory_id',
            implode(' AND ', $where),
            $params,
            "FIELD(p.status, 'EN_MODERATION') DESC, p.id DESC",
            $page
        );
    }

    public function applications(array $f, int $page): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($f['status'])) {
            $where[] = 'a.status = ?';
            $params[] = (string) $f['status'];
        }
        if (!empty($f['q'])) {
            $where[] = '(p.title LIKE ? OR w.last_name LIKE ? OR w.first_name LIKE ? OR p.reference LIKE ?)';
            $like = self::like((string) $f['q']);
            array_push($params, $like, $like, $like, $like);
        }
        return $this->paginate(
            "a.status, a.created_at, p.title, p.reference, p.uuid AS post_uuid, CONCAT(w.first_name, ' ', w.last_name) AS candidate, w.uuid AS candidate_uuid,
             COALESCE(b.name, CONCAT(r.first_name, ' ', r.last_name)) AS recruiter,
             i.mode AS interview_mode, i.scheduled_at AS interview_at, i.status AS interview_status",
            'FROM job_applications a JOIN job_posts p ON p.id = a.job_post_id JOIN users w ON w.id = a.worker_user_id
             JOIN users r ON r.id = p.client_user_id LEFT JOIN businesses b ON b.id = p.business_id
             LEFT JOIN interviews i ON i.id = (SELECT MAX(i2.id) FROM interviews i2 WHERE i2.application_id = a.id)',
            implode(' AND ', $where),
            $params,
            'a.id DESC',
            $page
        );
    }

    public function employments(array $f, int $page): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($f['status'])) {
            $where[] = 'e.status = ?';
            $params[] = (string) $f['status'];
        }
        if (!empty($f['q'])) {
            $where[] = '(e.position_title LIKE ? OR e.reference LIKE ? OR ee.last_name LIKE ? OR er.last_name LIKE ?)';
            $like = self::like((string) $f['q']);
            array_push($params, $like, $like, $like, $like);
        }
        return $this->paginate(
            "e.uuid, e.reference, e.position_title, e.status, e.pay_period, e.salary_amount, e.start_date, e.end_date, e.hours_per_week,
             CONCAT(ee.first_name, ' ', ee.last_name) AS employee, ee.uuid AS employee_uuid,
             COALESCE(b.name, CONCAT(er.first_name, ' ', er.last_name)) AS employer, er.uuid AS employer_uuid,
             (SELECT COUNT(*) FROM payslips ps WHERE ps.employment_id = e.id) AS payslips",
            'FROM employments e JOIN users ee ON ee.id = e.employee_user_id JOIN users er ON er.id = e.employer_user_id LEFT JOIN businesses b ON b.id = e.business_id',
            implode(' AND ', $where),
            $params,
            'e.id DESC',
            $page
        );
    }

    // ------------------------------------------------------------------ Finances

    public function payslips(array $f, int $page): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($f['status'])) {
            $where[] = 'ps.status = ?';
            $params[] = (string) $f['status'];
        }
        if (!empty($f['from'])) {
            $where[] = 'ps.period_end >= ?';
            $params[] = (string) $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = 'ps.period_end <= ?';
            $params[] = (string) $f['to'];
        }
        if (!empty($f['q'])) {
            $where[] = '(ps.number LIKE ? OR ee.last_name LIKE ? OR er.last_name LIKE ? OR b.name LIKE ?)';
            $like = self::like((string) $f['q']);
            array_push($params, $like, $like, $like, $like);
        }
        return $this->paginate(
            "ps.uuid, ps.number, ps.period_label, ps.gross_amount, ps.commission_amount, ps.net_amount, ps.status, ps.payment_method,
             ps.created_at, ps.employee_confirmed_at, ps.dispute_note,
             CONCAT(ee.first_name, ' ', ee.last_name) AS employee, COALESCE(b.name, CONCAT(er.first_name, ' ', er.last_name)) AS employer",
            'FROM payslips ps JOIN employments e ON e.id = ps.employment_id JOIN users ee ON ee.id = ps.employee_user_id
             JOIN users er ON er.id = ps.employer_user_id LEFT JOIN businesses b ON b.id = e.business_id',
            implode(' AND ', $where),
            $params,
            "FIELD(ps.status, 'CONTESTEE') DESC, ps.id DESC",
            $page
        );
    }

    /** @return array<string, mixed> */
    public function financeSummary(): array
    {
        $byStatus = [];
        foreach ($this->db->fetchAll('SELECT status, COUNT(*) n, COALESCE(SUM(gross_amount),0) gross, COALESCE(SUM(net_amount),0) net, COALESCE(SUM(commission_amount),0) commission FROM payslips GROUP BY status') as $r) {
            $byStatus[$r['status']] = $r;
        }
        $balances = [];
        foreach ($this->db->fetchAll('SELECT code, name, balance FROM v_ledger_account_balances') as $r) {
            $balances[$r['code']] = ['name' => $r['name'], 'balance' => (int) round((float) $r['balance'])];
        }
        $monthly = $this->db->fetchAll(
            "SELECT DATE_FORMAT(employee_confirmed_at, '%Y-%m') AS month, COUNT(*) n, SUM(net_amount) net, SUM(commission_amount) commission
             FROM payslips WHERE status = 'RECUE' AND employee_confirmed_at >= NOW() - INTERVAL 12 MONTH GROUP BY month ORDER BY month"
        );
        $receivables = $this->db->fetchAll(
            "SELECT u.uuid, CONCAT(u.first_name, ' ', u.last_name) AS name, b.name AS business_name,
                    SUM(e.debit) - SUM(e.credit) AS due
             FROM financial_ledger_entries e JOIN financial_ledger_accounts a ON a.id = e.account_id AND a.code = '1210'
             JOIN users u ON u.id = e.user_id
             LEFT JOIN business_members m ON m.user_id = u.id AND m.member_role = 'OWNER' LEFT JOIN businesses b ON b.id = m.business_id
             GROUP BY u.id, u.uuid, u.first_name, u.last_name, b.name HAVING due > 0 ORDER BY due DESC LIMIT 50"
        );
        return [
            'byStatus' => $byStatus,
            'balances' => $balances,
            'monthly' => $monthly,
            'receivables' => $receivables,
            'transactions' => $this->db->fetchAll(
                "SELECT t.reference, t.type, t.amount, t.commission_amount, t.net_amount, t.status, t.created_at, t.description,
                        CONCAT(u.first_name, ' ', u.last_name) AS user_name
                 FROM transactions t LEFT JOIN users u ON u.id = t.user_id ORDER BY t.id DESC LIMIT 30"
            ),
            'unbalanced' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM v_ledger_unbalanced_journals'),
        ];
    }

    /** Enregistre l'encaissement de la commission due par un employeur (paiement reçu par JOB NIGER). */
    public function collectCommission(int $adminId, string $employerUuid, int $amount, string $reference): void
    {
        $employerId = (int) ($this->db->fetchValue('SELECT id FROM users WHERE uuid = ?', [$employerUuid]) ?? throw new HttpException(404));
        $due = $this->ledger->balance('1210', $employerId);
        if ($amount <= 0 || $amount > $due) {
            throw new ValidationException(['amount' => ['Montant invalide : la commission due est de ' . Money::xof($due)->format() . '.']]);
        }
        if (mb_strlen(trim($reference)) < 3) {
            throw new ValidationException(['reference' => ['Indiquez la référence du paiement reçu (reçu, opération Mobile Money…).']]);
        }
        $this->db->transaction(function (Database $db) use ($adminId, $employerId, $amount, $reference) {
            $txUuid = Uuid::v4();
            $journalId = $this->ledger->post('COMMISSION_COLLECTED', 'user', $employerId, 'commission:' . $employerId . ':' . $txUuid, [
                ['account' => '1020', 'debit' => $amount],
                ['account' => '1210', 'credit' => $amount, 'user_id' => $employerId],
            ], 'Encaissement commission — réf. ' . $reference, $adminId);
            $db->insert('transactions', [
                'uuid' => $txUuid, 'reference' => References::next($db, 'T'), 'type' => 'ENCAISSEMENT_COMMISSION', 'direction' => 'ENTRANT',
                'user_id' => $employerId, 'journal_id' => $journalId, 'amount' => $amount . '.00', 'commission_amount' => $amount . '.00',
                'net_amount' => $amount . '.00', 'status' => 'CONFIRMEE', 'gateway_reference' => mb_substr($reference, 0, 120),
                'description' => 'Commission JOB NIGER réglée par l\'employeur', 'idempotency_key' => 'tx:commission:' . $txUuid, 'created_by' => $adminId,
            ]);
        });
        $this->audit->record($adminId, 'finance.commission_collected', 'user', $employerId, null, ['amount' => $amount], $reference);
    }

    // ------------------------------------------------------------------ Paramètres

    public function updateCommissionRate(int $adminId, string $rate, string $reason): void
    {
        $bps = Money::rateToBps($rate);
        if ($bps > 5000) {
            throw new ValidationException(['commission_rate' => ['Le taux ne peut pas dépasser 50 %.']]);
        }
        $current = $this->db->fetch("SELECT * FROM commission_rules WHERE scope = 'GLOBAL' AND is_active = 1 ORDER BY priority DESC, id DESC LIMIT 1");
        $new = number_format($bps / 100, 2, '.', '');
        if ($current !== null && (string) $current['worker_rate'] === $new) {
            return;
        }
        $this->db->transaction(function (Database $db) use ($current, $new, $adminId) {
            if ($current !== null) {
                $db->query('UPDATE commission_rules SET is_active = 0, valid_to = NOW() WHERE id = ?', [(int) $current['id']]);
            }
            $db->insert('commission_rules', [
                'name' => 'Commission standard JOB NIGER (' . $new . ' %)', 'scope' => 'GLOBAL', 'client_rate' => '0.00',
                'worker_rate' => $new, 'fixed_fee' => '0.00', 'priority' => 0, 'valid_from' => date('Y-m-d H:i:s'), 'created_by' => $adminId,
            ]);
        });
        $this->audit->record($adminId, 'commission_rule.updated', 'commission_rule', null, ['worker_rate' => $current['worker_rate'] ?? null], ['worker_rate' => $new], $reason);
    }

    public const EDITABLE_SETTINGS = [
        'jobs.moderation_required' => 'Toutes les offres passent en modération avant publication',
        'jobs.require_verified_recruiter' => 'Publication réservée aux recruteurs vérifiés',
        'applications.require_verified_worker' => 'Candidature réservée aux prestataires vérifiés',
        'payroll.commission_enabled' => 'Commission JOB NIGER appliquée sur les nouvelles fiches de paie (contrats futurs)',
        'auth.phone_otp_enabled' => 'Vérification du téléphone par SMS (fournisseur SMS requis)',
        'auth.email_required' => 'E-mail obligatoire à l\'inscription',
    ];

    /** @param array<string, bool> $values */
    public function updateSettings(int $adminId, array $values): void
    {
        $before = [];
        foreach (self::EDITABLE_SETTINGS as $key => $_) {
            $before[$key] = (bool) $this->settings->get($key, false);
            $this->settings->set($key, !empty($values[$key]), $adminId);
        }
        $after = array_map(fn ($k) => !empty($values[$k]), array_combine(array_keys(self::EDITABLE_SETTINGS), array_keys(self::EDITABLE_SETTINGS)));
        $this->audit->record($adminId, 'settings.updated', 'platform_settings', null, $before, $after);
    }

    // ------------------------------------------------------------------ Journaux et support

    public function auditLogs(int $page): array
    {
        return $this->paginate(
            "l.action, l.entity_type, l.entity_id, l.reason, l.ip_address, l.created_at, CONCAT(u.first_name, ' ', u.last_name) AS actor",
            'FROM admin_audit_logs l LEFT JOIN users u ON u.id = l.actor_user_id',
            '1 = 1',
            [],
            'l.id DESC',
            $page
        );
    }

    public function securityEvents(int $page, bool $warningsOnly): array
    {
        return $this->paginate(
            "s.event_type, s.severity, s.ip_address, s.created_at, CONCAT(u.first_name, ' ', u.last_name) AS user_name",
            'FROM security_events s LEFT JOIN users u ON u.id = s.user_id',
            $warningsOnly ? "s.severity <> 'INFO'" : '1 = 1',
            [],
            's.id DESC',
            $page
        );
    }

    public function tickets(string $status, int $page): array
    {
        $where = $status === 'open' ? "t.status IN ('OUVERT','EN_COURS','ATTENTE_UTILISATEUR')" : '1 = 1';
        return $this->paginate(
            "t.id, t.reference, t.subject, t.category, t.status, t.priority, t.created_at, t.contact_name, t.contact_phone, t.contact_email,
             (SELECT m.body FROM support_ticket_messages m WHERE m.ticket_id = t.id ORDER BY m.id LIMIT 1) AS first_message",
            'FROM support_tickets t',
            $where,
            [],
            "FIELD(t.priority, 'URGENTE','HAUTE','NORMALE','BASSE'), t.id DESC",
            $page
        );
    }

    public function setTicketStatus(int $adminId, int $ticketId, string $status): void
    {
        if (!in_array($status, ['OUVERT', 'EN_COURS', 'ATTENTE_UTILISATEUR', 'RESOLU', 'FERME'], true)) {
            throw new ValidationException(['status' => ['Statut invalide.']]);
        }
        $this->db->query('UPDATE support_tickets SET status = ?, assigned_to = COALESCE(assigned_to, ?), closed_at = IF(? IN (\'RESOLU\',\'FERME\'), NOW(), NULL) WHERE id = ?', [$status, $adminId, $status, $ticketId]);
        $this->audit->record($adminId, 'support.ticket_status', 'support_ticket', $ticketId, null, ['status' => $status]);
    }

    /** Indicateurs du tableau de bord (recrutement et paie). @return array<string, mixed> */
    public function recruitmentKpis(): array
    {
        return $this->db->fetch(
            "SELECT
               (SELECT COUNT(*) FROM job_posts WHERE status IN ('PUBLIEE','CANDIDATURES_OUVERTES')) AS open_posts,
               (SELECT COUNT(*) FROM job_posts WHERE status = 'EN_MODERATION') AS moderation_posts,
               (SELECT COUNT(*) FROM job_applications) AS applications,
               (SELECT COUNT(*) FROM job_applications WHERE created_at >= NOW() - INTERVAL 7 DAY) AS applications_7d,
               (SELECT COUNT(*) FROM interviews WHERE status IN ('PROPOSE','CONFIRME') AND scheduled_at >= NOW()) AS upcoming_interviews,
               (SELECT COUNT(*) FROM employments WHERE status = 'ACTIF') AS active_employments,
               (SELECT COUNT(*) FROM employments WHERE status = 'EN_ATTENTE_ACCEPTATION') AS pending_employments,
               (SELECT COUNT(*) FROM payslips WHERE status IN ('EMISE','PAIEMENT_DEMANDE','PAYEE_DECLAREE')) AS open_payslips,
               (SELECT COUNT(*) FROM payslips WHERE status = 'CONTESTEE') AS disputed_payslips,
               (SELECT COALESCE(SUM(net_amount), 0) FROM payslips WHERE status = 'RECUE') AS salaries_confirmed,
               (SELECT COUNT(*) FROM verification_requests WHERE status IN ('SOUMISE','EN_EXAMEN')) AS pending_verifications"
        ) ?? [];
    }
}
