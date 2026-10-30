<?php

declare(strict_types=1);

namespace App\Services\Employment;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Services\Audit\AuditLogger;
use App\Services\Finance\LedgerService;
use App\Services\Notifications\NotificationService;
use App\Support\Money;
use App\Support\Phone;
use App\Support\References;
use App\Support\Uuid;

/**
 * Paiement d'une fiche de paie :
 *   EMISE → (employé) PAIEMENT_DEMANDE → (employeur) PAYEE_DECLAREE → (employé) RECUE
 *                                                                  ↘ (employé) CONTESTEE → (employeur) PAYEE_DECLAREE…
 * Le salaire est versé DIRECTEMENT par l'employeur (Mobile Money ou en main propre) : la plateforme
 * ne détient pas les fonds. À la confirmation de réception, le paiement est enregistré (paiement,
 * transaction, écritures mémo) et la commission éventuelle devient une créance sur l'employeur.
 */
class PayslipService
{
    public const LABELS = [
        'EMISE' => 'Émise — paiement à demander', 'PAIEMENT_DEMANDE' => 'Paiement demandé', 'PAYEE_DECLAREE' => 'Payée (à confirmer par l\'employé)',
        'RECUE' => 'Reçue — confirmée par l\'employé', 'CONTESTEE' => 'Contestée par l\'employé', 'ANNULEE' => 'Annulée',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly LedgerService $ledger,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function find(string $uuid): ?array
    {
        return $this->db->fetch(
            "SELECT ps.*, e.uuid AS employment_uuid, e.reference AS employment_reference, e.position_title, e.pay_period, e.hours_per_week,
                    e.work_days, e.daily_start_time, e.daily_end_time, e.start_date AS contract_start, e.work_location,
                    CONCAT(er.first_name, ' ', er.last_name) AS employer_name, er.phone AS employer_phone, b.name AS business_name,
                    b.rccm_number, b.nif_number, b.address AS business_address,
                    CONCAT(ee.first_name, ' ', ee.last_name) AS employee_name, ee.phone AS employee_phone, c.name AS employee_city,
                    p.reference AS payment_reference
             FROM payslips ps JOIN employments e ON e.id = ps.employment_id
             JOIN users er ON er.id = ps.employer_user_id JOIN users ee ON ee.id = ps.employee_user_id
             LEFT JOIN businesses b ON b.id = e.business_id LEFT JOIN cities c ON c.id = ee.city_id
             LEFT JOIN payments p ON p.id = ps.payment_id
             WHERE ps.uuid = ?",
            [$uuid]
        );
    }

    /** @return array<string, mixed> fiche visible seulement par l'employeur ou l'employé */
    public function findForParty(string $uuid, int $userId): array
    {
        $p = $this->find($uuid);
        if ($p === null || ((int) $p['employer_user_id'] !== $userId && (int) $p['employee_user_id'] !== $userId)) {
            throw new HttpException(404);
        }
        return $p;
    }

    public function requestPayment(int $employeeId, string $uuid, string $method, ?string $phone, string $ip): void
    {
        $p = $this->findForParty($uuid, $employeeId);
        $this->assertActor($p, 'employee', $employeeId, ['EMISE', 'PAIEMENT_DEMANDE']);
        $payoutPhone = null;
        if ($method === 'MOBILE_MONEY') {
            $payoutPhone = Phone::normalize((string) $phone);
            if ($payoutPhone === null) {
                throw new ValidationException(['payout_phone' => ['Indiquez le numéro Mobile Money qui recevra le paiement.']]);
            }
        }
        $this->transition($p, 'PAIEMENT_DEMANDE', $employeeId, $ip, $method === 'MOBILE_MONEY' ? 'Mobile Money : ' . Phone::format((string) $payoutPhone) : 'Paiement en présentiel',
            ['payment_method' => $method, 'payout_phone' => $payoutPhone, 'requested_at' => date('Y-m-d H:i:s')]);
        $this->notifications->notify((int) $p['employer_user_id'], 'payslip.payment_requested', 'Demande de paiement : ' . $p['period_label'],
            $p['employee_name'] . ' demande le versement de ' . money((string) $p['net_amount'])
            . ($method === 'MOBILE_MONEY' ? ' par Mobile Money au ' . Phone::format((string) $payoutPhone) : ' en main propre') . '.',
            '/fiches-de-paie/' . $uuid);
    }

    public function declarePaid(int $employerId, string $uuid, ?string $reference, string $ip): void
    {
        $p = $this->findForParty($uuid, $employerId);
        $this->assertActor($p, 'employer', $employerId, ['EMISE', 'PAIEMENT_DEMANDE', 'CONTESTEE']);
        $this->transition($p, 'PAYEE_DECLAREE', $employerId, $ip, $reference ? 'Référence : ' . $reference : 'Paiement déclaré',
            ['employer_paid_at' => date('Y-m-d H:i:s'), 'employer_reference' => $reference ?: null]);
        $this->notifications->notify((int) $p['employee_user_id'], 'payslip.paid', 'Salaire versé : ' . $p['period_label'],
            'Votre employeur déclare vous avoir versé ' . money((string) $p['net_amount']) . '. Confirmez la réception dans l\'application.',
            '/fiches-de-paie/' . $uuid);
    }

    /** L'employé confirme avoir reçu son salaire : enregistrement financier atomique et idempotent. */
    public function confirmReceived(int $employeeId, string $uuid, string $ip): void
    {
        $p = $this->findForParty($uuid, $employeeId);
        $this->assertActor($p, 'employee', $employeeId, ['PAYEE_DECLAREE']);
        $this->db->transaction(function (Database $db) use ($p, $employeeId, $ip) {
            $locked = $db->fetch('SELECT status FROM payslips WHERE id = ? FOR UPDATE', [(int) $p['id']]);
            if ($locked['status'] !== 'PAYEE_DECLAREE') {
                throw new ValidationException(['status' => ['Cette fiche a déjà été traitée.']]);
            }
            $net = Money::parse((string) $p['net_amount']);
            $gross = Money::parse((string) $p['gross_amount']);
            $commission = Money::parse((string) $p['commission_amount']);
            $methodCode = $p['payment_method'] === 'ESPECES' ? 'CASH' : 'DIRECT_MOMO';
            $method = $db->fetch('SELECT id, gateway_id FROM payment_methods WHERE code = ?', [$methodCode]);
            $paymentId = $db->insert('payments', [
                'uuid' => Uuid::v4(), 'reference' => References::next($db, 'P'), 'purpose' => 'SALAIRE',
                'payer_user_id' => (int) $p['employer_user_id'], 'payee_user_id' => (int) $p['employee_user_id'],
                'payment_method_id' => (int) $method['id'], 'gateway_id' => (int) $method['gateway_id'],
                'mode' => 'DIRECT', 'verification_type' => 'MANUELLE',
                'amount' => $net->toDecimal(), 'service_amount' => $gross->toDecimal(), 'client_commission' => '0.00',
                'confirmed_amount' => $net->toDecimal(), 'status' => 'CONFIRMED',
                'gateway_reference' => $p['employer_reference'] ? mb_substr('EMP-' . $p['id'] . '-' . $p['employer_reference'], 0, 120) : null,
                'payer_account_masked' => $p['payout_phone'] ? Phone::mask((string) $p['payout_phone']) : null,
                'verified_by' => $employeeId, 'verified_at' => date('Y-m-d H:i:s'),
                'idempotency_key' => 'payslip:' . $p['id'], 'confirmed_at' => date('Y-m-d H:i:s'),
            ]);
            $db->insert('payment_status_history', ['payment_id' => $paymentId, 'to_status' => 'CONFIRMED', 'source' => 'UTILISATEUR', 'actor_user_id' => $employeeId, 'note' => 'Réception confirmée par l\'employé (fiche ' . $p['number'] . ')']);

            // Écritures : salaire versé hors plateforme (comptes mémo) + commission due par l'employeur
            $lines = [
                ['account' => '9010', 'debit' => $net->amount()],
                ['account' => '9020', 'credit' => $net->amount()],
            ];
            if ($commission->amount() > 0) {
                $lines[] = ['account' => '1210', 'debit' => $commission->amount(), 'user_id' => (int) $p['employer_user_id']];
                $lines[] = ['account' => '4010', 'credit' => $commission->amount()];
            }
            $journalId = $this->ledger->post('SALARY_PAID', 'payslip', (int) $p['id'], 'payslip:' . $p['id'] . ':received', $lines,
                'Salaire ' . $p['number'] . ' (' . $p['period_label'] . ')', $employeeId);

            $db->insert('transactions', [
                'uuid' => Uuid::v4(), 'reference' => References::next($db, 'T'), 'type' => 'SALAIRE', 'direction' => 'INTERNE',
                'user_id' => (int) $p['employee_user_id'], 'payment_id' => $paymentId, 'journal_id' => $journalId,
                'amount' => $gross->toDecimal(), 'commission_amount' => $commission->toDecimal(), 'net_amount' => $net->toDecimal(),
                'payment_method_id' => (int) $method['id'], 'status' => 'CONFIRMEE',
                'description' => 'Fiche de paie ' . $p['number'] . ' — ' . $p['period_label'],
                'idempotency_key' => 'tx:payslip:' . $p['id'], 'created_by' => $employeeId,
            ]);
            $this->transition($p, 'RECUE', $employeeId, $ip, 'Réception confirmée', ['employee_confirmed_at' => date('Y-m-d H:i:s'), 'payment_id' => $paymentId]);
        });
        $this->notifications->notify((int) $p['employer_user_id'], 'payslip.received', 'Paiement confirmé : ' . $p['period_label'],
            $p['employee_name'] . ' confirme avoir reçu ' . money((string) $p['net_amount']) . '.', '/fiches-de-paie/' . $uuid);
    }

    public function dispute(int $employeeId, string $uuid, string $note, string $ip): void
    {
        $p = $this->findForParty($uuid, $employeeId);
        $this->assertActor($p, 'employee', $employeeId, ['PAYEE_DECLAREE']);
        $this->transition($p, 'CONTESTEE', $employeeId, $ip, $note, ['dispute_note' => mb_substr($note, 0, 1000)]);
        $this->notifications->notify((int) $p['employer_user_id'], 'payslip.disputed', 'Paiement non reçu : ' . $p['period_label'],
            $p['employee_name'] . ' indique ne pas avoir reçu le paiement : « ' . $note . ' ». Vérifiez puis déclarez à nouveau le paiement.',
            '/fiches-de-paie/' . $uuid);
        foreach ($this->db->fetchAll(
            "SELECT DISTINCT ur.user_id FROM user_roles ur JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions pm ON pm.id = rp.permission_id WHERE pm.code = 'finance.dashboard.view'"
        ) as $staff) {
            $this->notifications->notify((int) $staff['user_id'], 'admin.payslip_disputed', 'Fiche de paie contestée ' . $p['number'],
                'Employé : ' . $p['employee_name'] . ' — employeur : ' . $p['employer_name'] . '.', '/admin/finances');
        }
    }

    /** Annulation par l'administration (fiche erronée) : jamais de suppression, motif obligatoire. */
    public function cancelByAdmin(int $adminId, string $uuid, string $reason, string $ip): void
    {
        $p = $this->find($uuid) ?? throw new HttpException(404);
        if (!in_array($p['status'], ['EMISE', 'PAIEMENT_DEMANDE', 'CONTESTEE'], true)) {
            throw new ValidationException(['reason' => ['Seule une fiche non payée ou contestée peut être annulée.']]);
        }
        $this->transition($p, 'ANNULEE', $adminId, $ip, 'Annulée par l\'administration : ' . $reason, []);
        $this->audit->record($adminId, 'payslip.cancelled', 'payslip', (int) $p['id'], ['status' => $p['status']], ['status' => 'ANNULEE'], $reason);
        foreach ([(int) $p['employee_user_id'], (int) $p['employer_user_id']] as $uid) {
            $this->notifications->notify($uid, 'payslip.cancelled', 'Fiche de paie annulée : ' . $p['number'], 'Motif : ' . $reason, '/fiches-de-paie/' . $uuid);
        }
    }

    /** @param list<string> $from */
    private function assertActor(array $p, string $role, int $userId, array $from): void
    {
        $owner = $role === 'employee' ? (int) $p['employee_user_id'] : (int) $p['employer_user_id'];
        if ($owner !== $userId) {
            throw new HttpException(403, $role === 'employee' ? 'Action réservée à l\'employé.' : 'Action réservée à l\'employeur.');
        }
        if (!in_array($p['status'], $from, true)) {
            throw new ValidationException(['status' => ['Action impossible : la fiche est « ' . (self::LABELS[$p['status']] ?? $p['status']) . ' ».']]);
        }
    }

    /** @param array<string, mixed> $fields */
    private function transition(array $p, string $to, int $actorId, string $ip, ?string $note, array $fields): void
    {
        $this->db->transaction(function (Database $db) use ($p, $to, $actorId, $ip, $note, $fields) {
            $set = ['status' => $to] + $fields;
            $db->update('payslips', $set, ['id' => (int) $p['id']]);
            $db->insert('payslip_events', [
                'payslip_id' => (int) $p['id'], 'actor_user_id' => $actorId, 'from_status' => $p['status'], 'to_status' => $to,
                'note' => $note !== null ? mb_substr($note, 0, 1000) : null, 'ip_address' => $ip,
            ]);
        });
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId, ?string $employmentUuid = null): array
    {
        $sql = "SELECT ps.uuid, ps.number, ps.period_label, ps.period_start, ps.period_end, ps.gross_amount, ps.commission_amount, ps.net_amount,
                       ps.status, ps.payment_method, ps.created_at, e.position_title, e.uuid AS employment_uuid,
                       (ps.employer_user_id = ?) AS as_employer,
                       CONCAT(ee.first_name, ' ', ee.last_name) AS employee_name, CONCAT(er.first_name, ' ', er.last_name) AS employer_name
                FROM payslips ps JOIN employments e ON e.id = ps.employment_id
                JOIN users ee ON ee.id = ps.employee_user_id JOIN users er ON er.id = ps.employer_user_id
                WHERE (ps.employer_user_id = ? OR ps.employee_user_id = ?)";
        $params = [$userId, $userId, $userId];
        if ($employmentUuid !== null) {
            $sql .= ' AND e.uuid = ?';
            $params[] = $employmentUuid;
        }
        return $this->db->fetchAll($sql . ' ORDER BY ps.period_start DESC, ps.id DESC', $params);
    }

    /** @return list<array<string, mixed>> */
    public function events(int $payslipId): array
    {
        return $this->db->fetchAll(
            "SELECT pe.*, CONCAT(u.first_name, ' ', u.last_name) AS actor_name FROM payslip_events pe
             LEFT JOIN users u ON u.id = pe.actor_user_id WHERE pe.payslip_id = ? ORDER BY pe.id",
            [$payslipId]
        );
    }
}
