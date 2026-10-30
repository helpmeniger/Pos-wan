<?php

declare(strict_types=1);

namespace App\Services\Employment;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Repositories\SettingRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Jobs\ApplicationService;
use App\Services\Notifications\NotificationService;
use App\Support\Money;
use App\Support\References;
use App\Support\Uuid;

/**
 * Contrat d'emploi ou de prestation : poste, temps de travail, salaire, mode de paiement.
 * Proposé par le recruteur, accepté explicitement par l'employé ; les clauses sont figées
 * (empreinte SHA-256) et restent consultables en archive par les deux parties.
 */
class EmploymentService
{
    public const STATUS_LABELS = [
        'EN_ATTENTE_ACCEPTATION' => 'En attente de votre acceptation', 'ACTIF' => 'En cours',
        'TERMINE' => 'Terminé', 'REFUSE' => 'Refusé par le candidat', 'ANNULE' => 'Annulé',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly ApplicationService $applications,
        private readonly SettingRepository $settings,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @param array<string, mixed> $d données validées du formulaire d'embauche */
    public function propose(int $recruiterId, string $applicationUuid, array $d): string
    {
        $a = $this->applications->findForParty($applicationUuid, $recruiterId);
        if ((int) $a['client_user_id'] !== $recruiterId) {
            throw new HttpException(404);
        }
        if (!in_array($a['status'], ApplicationService::ACTIVE, true)) {
            throw new ValidationException(['position_title' => ['Cette candidature n\'est plus active.']], $d);
        }
        $salary = Money::parse((string) $d['salary_amount']);
        if ($salary->amount() <= 0) {
            throw new ValidationException(['salary_amount' => ['Indiquez un salaire supérieur à zéro.']], $d);
        }
        if ($d['pay_period'] === 'PRESTATION' && empty($d['end_date'])) {
            throw new ValidationException(['end_date' => ['Une prestation doit avoir une date de fin (la fiche de paie est générée à cette date).']], $d);
        }
        if (!empty($d['end_date']) && $d['end_date'] < $d['start_date']) {
            throw new ValidationException(['end_date' => ['La date de fin doit être après la date de début.']], $d);
        }
        $rate = '0.00';
        if ($this->settings->get('payroll.commission_enabled', true)) {
            $rate = (string) ($this->db->fetchValue(
                "SELECT worker_rate FROM commission_rules WHERE scope = 'GLOBAL' AND is_active = 1 AND valid_from <= NOW()
                 AND (valid_to IS NULL OR valid_to > NOW()) ORDER BY priority DESC, id DESC LIMIT 1"
            ) ?? '0.00');
        }
        $employer = $this->db->fetch(
            "SELECT u.first_name, u.last_name, u.phone, b.id AS business_id, b.name AS business_name FROM users u
             LEFT JOIN business_members m ON m.user_id = u.id AND m.status = 'ACTIVE' LEFT JOIN businesses b ON b.id = m.business_id
             WHERE u.id = ? LIMIT 1",
            [$recruiterId]
        );
        $employee = $this->db->fetch('SELECT first_name, last_name, phone FROM users WHERE id = ?', [(int) $a['worker_user_id']]);
        $uuid = Uuid::v4();

        $this->db->transaction(function (Database $db) use ($a, $d, $salary, $rate, $recruiterId, $employer, $employee, $uuid) {
            $post = $db->fetch('SELECT positions_total, positions_filled, status FROM job_posts WHERE id = ? FOR UPDATE', [(int) $a['post_id']]);
            if ((int) $post['positions_filled'] >= (int) $post['positions_total']) {
                throw new ValidationException(['position_title' => ['Tous les postes de cette offre sont déjà pourvus.']], $d);
            }
            $reference = References::next($db, 'E');
            $terms = [
                'reference' => $reference,
                'employeur' => trim(($employer['business_name'] ?? '') !== '' ? $employer['business_name'] . ' (' . $employer['first_name'] . ' ' . $employer['last_name'] . ')' : $employer['first_name'] . ' ' . $employer['last_name']),
                'employe' => $employee['first_name'] . ' ' . $employee['last_name'],
                'poste' => $d['position_title'],
                'taches_et_clauses' => $d['duties'] ?: null,
                'lieu' => $d['work_location'] ?: null,
                'jours' => $d['work_days'],
                'horaires' => ($d['daily_start_time'] ?: '') . ($d['daily_end_time'] ? ' – ' . $d['daily_end_time'] : ''),
                'heures_par_semaine' => (string) $d['hours_per_week'],
                'periodicite' => $d['pay_period'],
                'salaire' => $salary->amount(),
                'devise' => 'XOF',
                'mode_paiement' => $d['payment_method'],
                'commission_jobniger_pourcentage' => $rate,
                'date_debut' => $d['start_date'],
                'date_fin' => $d['end_date'] ?: null,
                'fiche_de_paie' => $d['pay_period'] === 'MENSUEL'
                    ? 'Générée automatiquement à la fin de chaque mois (au prorata le premier et le dernier mois).'
                    : 'Générée automatiquement à la date de fin de la prestation.',
            ];
            $snapshot = (string) json_encode($terms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $db->insert('employments', [
                'uuid' => $uuid, 'reference' => $reference,
                'job_post_id' => (int) $a['post_id'], 'application_id' => (int) $a['id'],
                'employer_user_id' => $recruiterId, 'employee_user_id' => (int) $a['worker_user_id'],
                'business_id' => $employer['business_id'] ?? null,
                'position_title' => $d['position_title'], 'duties' => $d['duties'] ?: null,
                'work_location' => $d['work_location'] ?: null, 'work_days' => $d['work_days'],
                'daily_start_time' => $d['daily_start_time'] ?: null, 'daily_end_time' => $d['daily_end_time'] ?: null,
                'hours_per_week' => (string) $d['hours_per_week'], 'pay_period' => $d['pay_period'],
                'salary_amount' => $salary->toDecimal(), 'payment_method' => $d['payment_method'],
                'commission_rate' => $rate, 'start_date' => $d['start_date'], 'end_date' => $d['end_date'] ?: null,
                'terms_snapshot' => $snapshot, 'content_hash' => hash('sha256', $snapshot),
            ]);
            $db->query('UPDATE job_posts SET positions_filled = positions_filled + 1 WHERE id = ?', [(int) $a['post_id']]);
            if ((int) $post['positions_filled'] + 1 >= (int) $post['positions_total']) {
                $db->query("UPDATE job_posts SET status = 'ATTRIBUEE', closed_at = NOW() WHERE id = ?", [(int) $a['post_id']]);
            }
            $this->applications->setStatus((int) $a['id'], 'ACCEPTEE', $recruiterId);
        });
        $this->notifications->notify(
            (int) $a['worker_user_id'],
            'employment.proposed',
            'Félicitations ! Un contrat vous est proposé',
            'Poste : ' . $d['position_title'] . ' — salaire ' . $salary->format() . ($d['pay_period'] === 'MENSUEL' ? ' par mois' : ' pour la prestation') . '. Lisez et acceptez le contrat.',
            '/emplois/' . $uuid
        );
        return $uuid;
    }

    /** @return array<string, mixed> contrat visible seulement par l'employeur ou l'employé */
    public function findForParty(string $uuid, int $userId): array
    {
        $e = $this->find($uuid);
        if ($e === null || ((int) $e['employer_user_id'] !== $userId && (int) $e['employee_user_id'] !== $userId)) {
            throw new HttpException(404);
        }
        return $e;
    }

    /** @return array<string, mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->db->fetch(
            "SELECT e.*, CONCAT(er.first_name, ' ', er.last_name) AS employer_name, er.phone AS employer_phone,
                    CONCAT(ee.first_name, ' ', ee.last_name) AS employee_name, ee.phone AS employee_phone, b.name AS business_name,
                    p.uuid AS post_uuid, p.reference AS post_reference
             FROM employments e JOIN users er ON er.id = e.employer_user_id JOIN users ee ON ee.id = e.employee_user_id
             LEFT JOIN businesses b ON b.id = e.business_id LEFT JOIN job_posts p ON p.id = e.job_post_id WHERE e.uuid = ?",
            [$uuid]
        );
    }

    public function accept(int $employeeId, string $uuid, string $ip): void
    {
        $e = $this->findForParty($uuid, $employeeId);
        if ((int) $e['employee_user_id'] !== $employeeId || $e['status'] !== 'EN_ATTENTE_ACCEPTATION') {
            throw new ValidationException(['contract' => ['Ce contrat ne peut plus être accepté.']]);
        }
        if (!hash_equals((string) $e['content_hash'], hash('sha256', (string) $e['terms_snapshot']))) {
            throw new \RuntimeException('Empreinte du contrat invalide');
        }
        $this->db->query(
            "UPDATE employments SET status = 'ACTIF', employee_accepted_at = NOW(), employee_accept_ip = ? WHERE id = ? AND status = 'EN_ATTENTE_ACCEPTATION'",
            [$ip, (int) $e['id']]
        );
        $this->audit->record($employeeId, 'employment.accepted', 'employment', (int) $e['id'], null, ['hash' => $e['content_hash']]);
        $this->notifications->notify((int) $e['employer_user_id'], 'employment.accepted', 'Contrat accepté', $e['employee_name'] . ' a accepté le contrat « ' . $e['position_title'] . ' ».', '/emplois/' . $uuid);
    }

    public function decline(int $employeeId, string $uuid, ?string $reason): void
    {
        $e = $this->findForParty($uuid, $employeeId);
        if ((int) $e['employee_user_id'] !== $employeeId || $e['status'] !== 'EN_ATTENTE_ACCEPTATION') {
            throw new ValidationException(['contract' => ['Ce contrat ne peut plus être refusé.']]);
        }
        $this->db->transaction(function (Database $db) use ($e, $reason, $employeeId) {
            $db->query("UPDATE employments SET status = 'REFUSE', end_reason = ?, ended_at = NOW(), ended_by = ? WHERE id = ?", [$reason ?: null, $employeeId, (int) $e['id']]);
            if ($e['job_post_id'] !== null) {
                $db->query('UPDATE job_posts SET positions_filled = GREATEST(positions_filled - 1, 0) WHERE id = ?', [(int) $e['job_post_id']]);
                $db->query("UPDATE job_posts SET status = 'CANDIDATURES_OUVERTES', closed_at = NULL WHERE id = ? AND status = 'ATTRIBUEE' AND application_deadline >= NOW()", [(int) $e['job_post_id']]);
            }
            if ($e['application_id'] !== null) {
                $this->applications->setStatus((int) $e['application_id'], 'RETIREE', $employeeId, 'Contrat refusé');
            }
        });
        $this->notifications->notify((int) $e['employer_user_id'], 'employment.declined', 'Contrat refusé', $e['employee_name'] . ' a refusé le contrat « ' . $e['position_title'] . ' ».' . ($reason ? ' Motif : ' . $reason : ''), '/emplois/' . $uuid);
    }

    /** Fin du contrat par l'une ou l'autre partie : la dernière fiche de paie est générée au prorata. */
    public function end(int $userId, string $uuid, string $endDate, string $reason): void
    {
        $e = $this->findForParty($uuid, $userId);
        if ($e['status'] === 'EN_ATTENTE_ACCEPTATION' && (int) $e['employer_user_id'] === $userId) {
            $this->db->query("UPDATE employments SET status = 'ANNULE', ended_at = NOW(), ended_by = ?, end_reason = ? WHERE id = ?", [$userId, $reason, (int) $e['id']]);
            if ($e['job_post_id'] !== null) {
                $this->db->query('UPDATE job_posts SET positions_filled = GREATEST(positions_filled - 1, 0) WHERE id = ?', [(int) $e['job_post_id']]);
            }
            $this->notifications->notify((int) $e['employee_user_id'], 'employment.cancelled', 'Proposition de contrat annulée', 'La proposition « ' . $e['position_title'] . ' » a été retirée par l\'employeur.', '/emplois/' . $uuid);
            return;
        }
        if ($e['status'] !== 'ACTIF') {
            throw new ValidationException(['end_date' => ['Seul un contrat en cours peut être terminé.']]);
        }
        if ($endDate < (string) $e['start_date']) {
            throw new ValidationException(['end_date' => ['La date de fin ne peut pas précéder le début du contrat (' . date_fr((string) $e['start_date']) . ').']]);
        }
        $lastPaid = $this->db->fetchValue('SELECT MAX(period_end) FROM payslips WHERE employment_id = ? AND status <> \'ANNULEE\'', [(int) $e['id']]);
        if ($lastPaid !== null && $endDate < (string) $lastPaid) {
            throw new ValidationException(['end_date' => ['Une fiche de paie existe déjà jusqu\'au ' . date_fr((string) $lastPaid) . ' : choisissez une date ultérieure.']]);
        }
        $this->db->query(
            "UPDATE employments SET status = 'TERMINE', end_date = ?, ended_at = NOW(), ended_by = ?, end_reason = ? WHERE id = ?",
            [$endDate, $userId, $reason, (int) $e['id']]
        );
        $this->audit->record($userId, 'employment.ended', 'employment', (int) $e['id'], ['status' => 'ACTIF'], ['status' => 'TERMINE', 'end_date' => $endDate], $reason);
        $other = (int) $e['employer_user_id'] === $userId ? (int) $e['employee_user_id'] : (int) $e['employer_user_id'];
        $this->notifications->notify($other, 'employment.ended', 'Contrat terminé', '« ' . $e['position_title'] . ' » prend fin le ' . date_fr($endDate) . '. Motif : ' . $reason, '/emplois/' . $uuid);
    }

    /** @return list<array<string, mixed>> contrats d'un utilisateur (employeur ou employé) */
    public function forUser(int $userId, ?array $statuses = null): array
    {
        $sql = "SELECT e.uuid, e.reference, e.position_title, e.status, e.pay_period, e.salary_amount, e.start_date, e.end_date,
                       e.employer_user_id, e.employee_user_id, (e.employer_user_id = ?) AS as_employer,
                       CONCAT(er.first_name, ' ', er.last_name) AS employer_name, b.name AS business_name,
                       CONCAT(ee.first_name, ' ', ee.last_name) AS employee_name,
                       (SELECT COUNT(*) FROM payslips ps WHERE ps.employment_id = e.id AND ps.status IN ('EMISE','PAIEMENT_DEMANDE','PAYEE_DECLAREE','CONTESTEE')) AS open_payslips
                FROM employments e JOIN users er ON er.id = e.employer_user_id JOIN users ee ON ee.id = e.employee_user_id
                LEFT JOIN businesses b ON b.id = e.business_id
                WHERE (e.employer_user_id = ? OR e.employee_user_id = ?)";
        $params = [$userId, $userId, $userId];
        if ($statuses) {
            $sql .= ' AND e.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($params, ...$statuses);
        }
        return $this->db->fetchAll($sql . ' ORDER BY e.id DESC', $params);
    }
}
