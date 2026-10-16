<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Core\Database;

/**
 * Indicateurs des tableaux de bord, calculés à partir des données réelles.
 * Règle financière : un montant en attente n'est jamais présenté comme encaissé.
 */
class DashboardService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed> */
    public function worker(int $userId): array
    {
        $profile = $this->db->fetch('SELECT * FROM user_profiles WHERE user_id = ?', [$userId]) ?? [];
        $checks = [
            'Photo de profil' => !empty($profile['avatar_file_id']),
            'Présentation' => !empty($profile['bio']),
            'Au moins un métier' => (bool) $this->db->fetchValue('SELECT COUNT(*) FROM worker_trades WHERE user_id = ?', [$userId]),
            'Compétences' => (bool) $this->db->fetchValue('SELECT COUNT(*) FROM user_skills WHERE user_id = ?', [$userId]),
            'Zones d\'intervention' => (bool) $this->db->fetchValue('SELECT COUNT(*) FROM worker_service_areas WHERE user_id = ?', [$userId]),
            'Disponibilités' => (bool) $this->db->fetchValue('SELECT COUNT(*) FROM worker_availabilities WHERE user_id = ?', [$userId]),
            'Téléphone vérifié' => (bool) $this->db->fetchValue('SELECT phone_verified_at IS NOT NULL FROM users WHERE id = ?', [$userId]),
        ];
        $applications = $this->countBy('SELECT status, COUNT(*) n FROM job_applications WHERE worker_user_id = ? GROUP BY status', [$userId]);
        $missions = $this->countBy('SELECT status, COUNT(*) n FROM missions WHERE worker_user_id = ? GROUP BY status', [$userId]);
        // Revenus : uniquement les paiements CONFIRMÉS (bruts, commission, net) ; en attente séparé
        $earnings = $this->db->fetch(
            "SELECT COALESCE(SUM(c.base_amount), 0) AS gross,
                    COALESCE(SUM(c.worker_commission), 0) AS commission,
                    COALESCE(SUM(c.base_amount - c.worker_commission), 0) AS net
             FROM commission_records c JOIN payments p ON p.id = c.payment_id
             WHERE p.payee_user_id = ? AND p.status IN ('CONFIRMED','PARTIALLY_REFUNDED')",
            [$userId]
        ) ?? ['gross' => 0, 'commission' => 0, 'net' => 0];
        $pending = $this->db->fetchValue(
            "SELECT COALESCE(SUM(service_amount), 0) FROM payments WHERE payee_user_id = ? AND status IN ('INITIATED','PENDING')",
            [$userId]
        );
        $paidOut = $this->db->fetchValue("SELECT COALESCE(SUM(amount), 0) FROM payouts WHERE worker_user_id = ? AND status = 'PAYE'", [$userId]);
        return [
            'profile' => $profile,
            'completion' => $checks,
            'completionPercent' => (int) round(100 * count(array_filter($checks)) / count($checks)),
            'applications' => $applications,
            'missions' => $missions,
            'earnings' => $earnings + ['pending' => $pending ?? 0, 'paid_out' => $paidOut ?? 0],
        ];
    }

    /** @return array<string, mixed> */
    public function client(int $userId): array
    {
        $posts = $this->countBy('SELECT status, COUNT(*) n FROM job_posts WHERE client_user_id = ? GROUP BY status', [$userId]);
        $missions = $this->countBy('SELECT status, COUNT(*) n FROM missions WHERE client_user_id = ? GROUP BY status', [$userId]);
        $applicationsReceived = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM job_applications a JOIN job_posts p ON p.id = a.job_post_id
             WHERE p.client_user_id = ? AND a.status IN ('SOUMISE','VUE','PRESELECTIONNEE','INFOS_DEMANDEES','EN_NEGOCIATION')",
            [$userId]
        );
        $spending = $this->db->fetch(
            "SELECT COALESCE(SUM(CASE WHEN status IN ('CONFIRMED','PARTIALLY_REFUNDED','REFUNDED') THEN amount END), 0) AS paid,
                    COALESCE(SUM(CASE WHEN status IN ('INITIATED','PENDING') THEN amount END), 0) AS pending,
                    COALESCE(SUM(refunded_amount), 0) AS refunded
             FROM payments WHERE payer_user_id = ?",
            [$userId]
        );
        $businesses = $this->db->fetchAll(
            "SELECT b.id, b.uuid, b.name, b.kind, b.verification_status, bm.member_role
             FROM business_members bm JOIN businesses b ON b.id = bm.business_id
             WHERE bm.user_id = ? AND bm.status = 'ACTIVE' ORDER BY b.name",
            [$userId]
        );
        return [
            'posts' => $posts,
            'missions' => $missions,
            'applicationsReceived' => $applicationsReceived,
            'spending' => $spending,
            'favorites' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM favorites WHERE client_user_id = ?', [$userId]),
            'openDisputes' => (int) $this->db->fetchValue(
                "SELECT COUNT(*) FROM disputes WHERE (opened_by = ? OR respondent_id = ?) AND status NOT IN ('RESOLU_AMIABLE','RESOLU_DECISION','REJETE','CLOS')",
                [$userId, $userId]
            ),
            'businesses' => $businesses,
        ];
    }

    /** @return array<string, mixed> */
    public function admin(): array
    {
        $users = $this->db->fetch(
            "SELECT COUNT(*) AS total,
                    SUM(primary_type = 'WORKER') AS workers,
                    SUM(primary_type = 'CLIENT') AS clients,
                    SUM(primary_type = 'STAFF') AS staff,
                    SUM(status = 'SUSPENDED') AS suspended,
                    SUM(primary_type = 'WORKER' AND last_login_at >= NOW() - INTERVAL 30 DAY) AS active_workers,
                    SUM(primary_type = 'CLIENT' AND last_login_at >= NOW() - INTERVAL 30 DAY) AS active_clients,
                    SUM(created_at >= NOW() - INTERVAL 7 DAY) AS new_7d
             FROM users WHERE is_demo = 0"
        );
        $balances = [];
        foreach ($this->db->fetchAll('SELECT code, balance FROM v_ledger_account_balances') as $row) {
            $balances[$row['code']] = (string) $row['balance'];
        }
        $b = fn (string $code): int => (int) round((float) ($balances[$code] ?? 0));
        $payments = $this->db->fetch(
            "SELECT COALESCE(SUM(CASE WHEN status IN ('CONFIRMED','PARTIALLY_REFUNDED','REFUNDED') THEN amount END), 0) AS confirmed_amount,
                    SUM(status IN ('INITIATED','PENDING')) AS pending_count,
                    SUM(status = 'FAILED') AS failed_count,
                    SUM(status = 'DISPUTED') AS disputed_count
             FROM payments"
        );
        return [
            'users' => $users,
            'businesses' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM businesses WHERE is_demo = 0'),
            'posts' => $this->countBy('SELECT status, COUNT(*) n FROM job_posts WHERE is_demo = 0 GROUP BY status'),
            'missions' => $this->countBy('SELECT status, COUNT(*) n FROM missions WHERE is_demo = 0 GROUP BY status'),
            'openDisputes' => (int) $this->db->fetchValue("SELECT COUNT(*) FROM disputes WHERE status NOT IN ('RESOLU_AMIABLE','RESOLU_DECISION','REJETE','CLOS')"),
            'openReports' => (int) $this->db->fetchValue("SELECT COUNT(*) FROM reports WHERE status IN ('NOUVEAU','EN_COURS')"),
            'pendingVerifications' => (int) $this->db->fetchValue("SELECT COUNT(*) FROM verification_requests WHERE status IN ('SOUMISE','EN_EXAMEN')"),
            'openTickets' => (int) $this->db->fetchValue("SELECT COUNT(*) FROM support_tickets WHERE status IN ('OUVERT','EN_COURS')"),
            'finance' => [
                'confirmed_payments' => (int) round((float) ($payments['confirmed_amount'] ?? 0)),
                'pending_count' => (int) ($payments['pending_count'] ?? 0),
                'failed_count' => (int) ($payments['failed_count'] ?? 0),
                'disputed_count' => (int) ($payments['disputed_count'] ?? 0),
                'commissions_earned' => $b('4010') - $b('4090'),
                'commissions_deferred' => $b('2030'),
                'commissions_receivable' => $b('1210'),
                'gateway_fees' => $b('6010'),
                'net_revenue_estimate' => $b('4010') + $b('4020') + $b('4030') - $b('4090') - $b('6010') - $b('6020') - $b('6030'),
                'due_to_workers' => $b('2010') + $b('2011'),
                'paid_out' => (int) round((float) $this->db->fetchValue("SELECT COALESCE(SUM(amount), 0) FROM payouts WHERE status = 'PAYE'")),
            ],
            'gateways' => $this->db->fetchAll('SELECT code, name, environment, is_active, holds_funds FROM payment_gateways ORDER BY id'),
            'recentSecurity' => $this->db->fetchAll(
                "SELECT event_type, severity, ip_address, created_at FROM security_events
                 WHERE severity <> 'INFO' ORDER BY id DESC LIMIT 8"
            ),
        ];
    }

    /** @return array<string, int> */
    private function countBy(string $sql, array $params = []): array
    {
        $out = [];
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }
        return $out;
    }
}
