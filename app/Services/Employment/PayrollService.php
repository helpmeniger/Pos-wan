<?php

declare(strict_types=1);

namespace App\Services\Employment;

use App\Core\Database;
use App\Services\Notifications\NotificationService;
use App\Support\Money;
use App\Support\References;
use App\Support\Uuid;
use DateTimeImmutable;

/**
 * Génération AUTOMATIQUE des fiches de paie :
 *  - MENSUEL : une fiche par mois civil, émise le dernier jour du mois ; premier et dernier mois au prorata
 *    des jours calendaires ; la fin anticipée du contrat déclenche la dernière fiche ;
 *  - PRESTATION : une seule fiche, émise à la date de fin (prorata si la prestation est arrêtée plus tôt).
 * Idempotent (clé unique emploi + début de période) : peut être relancé sans risque à tout moment.
 */
class PayrollService
{
    private const MONTHS = [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

    public function __construct(private readonly Database $db, private readonly NotificationService $notifications)
    {
    }

    /**
     * @param int|null $userId limiter aux contrats d'un utilisateur (appel depuis ses pages)
     * @return int nombre de fiches créées
     */
    public function generateDue(?DateTimeImmutable $today = null, ?int $userId = null): int
    {
        $today ??= new DateTimeImmutable('today');
        $today = $today->setTime(0, 0);
        $sql = "SELECT * FROM employments WHERE status IN ('ACTIF','TERMINE') AND employee_accepted_at IS NOT NULL";
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND (employer_user_id = ? OR employee_user_id = ?)';
            $params = [$userId, $userId];
        }
        $created = 0;
        foreach ($this->db->fetchAll($sql, $params) as $e) {
            $created += $e['pay_period'] === 'MENSUEL' ? $this->monthly($e, $today) : $this->prestation($e, $today);
            // Contrat arrivé à son terme prévu : clôture automatique
            if ($e['status'] === 'ACTIF' && $e['end_date'] !== null && (string) $e['end_date'] <= $today->format('Y-m-d')) {
                $this->db->query(
                    "UPDATE employments SET status = 'TERMINE', ended_at = NOW(), end_reason = COALESCE(end_reason, 'Fin prévue du contrat') WHERE id = ? AND status = 'ACTIF'",
                    [(int) $e['id']]
                );
            }
        }
        return $created;
    }

    private function monthly(array $e, DateTimeImmutable $today): int
    {
        $created = 0;
        $last = $this->db->fetchValue("SELECT MAX(period_end) FROM payslips WHERE employment_id = ? AND status <> 'ANNULEE'", [(int) $e['id']]);
        $cursor = $last !== null ? (new DateTimeImmutable((string) $last))->modify('+1 day') : new DateTimeImmutable((string) $e['start_date']);
        $contractEnd = $e['end_date'] !== null ? new DateTimeImmutable((string) $e['end_date']) : null;
        $salary = Money::parse((string) $e['salary_amount']);

        for ($guard = 0; $guard < 120; $guard++) {
            if ($contractEnd !== null && $cursor > $contractEnd) {
                break;
            }
            $monthStart = $cursor->modify('first day of this month');
            $monthEnd = $cursor->modify('last day of this month');
            $periodEnd = ($contractEnd !== null && $contractEnd < $monthEnd) ? $contractEnd : $monthEnd;
            if ($periodEnd > $today) {
                break; // période pas encore terminée : rien à émettre
            }
            $days = (int) $cursor->diff($periodEnd)->days + 1;
            $daysInMonth = (int) $monthEnd->format('j');
            $gross = ($cursor == $monthStart && $periodEnd == $monthEnd)
                ? $salary
                : Money::xof(self::prorata($salary->amount(), $days, $daysInMonth));
            $label = self::MONTHS[(int) $cursor->format('n')] . ' ' . $cursor->format('Y') . ($days < $daysInMonth ? ' (' . $days . ' j.)' : '');
            $created += $this->createPayslip($e, $cursor, $periodEnd, $label, $days, $salary, $gross);
            $cursor = $periodEnd->modify('+1 day');
        }
        return $created;
    }

    private function prestation(array $e, DateTimeImmutable $today): int
    {
        if ($e['end_date'] === null) {
            return 0;
        }
        $start = new DateTimeImmutable((string) $e['start_date']);
        $end = new DateTimeImmutable((string) $e['end_date']);
        if ($end > $today || $this->db->fetchValue("SELECT id FROM payslips WHERE employment_id = ? AND status <> 'ANNULEE'", [(int) $e['id']]) !== null) {
            return 0;
        }
        $salary = Money::parse((string) $e['salary_amount']);
        // Durée prévue au contrat (clauses figées) : prorata si la prestation a été arrêtée avant son terme
        $terms = json_decode((string) $e['terms_snapshot'], true) ?: [];
        $plannedEnd = !empty($terms['date_fin']) ? new DateTimeImmutable((string) $terms['date_fin']) : $end;
        $plannedDays = (int) $start->diff($plannedEnd)->days + 1;
        $days = (int) $start->diff($end)->days + 1;
        $gross = $days >= $plannedDays ? $salary : Money::xof(self::prorata($salary->amount(), $days, $plannedDays));
        $label = 'Prestation du ' . $start->format('d/m/Y') . ' au ' . $end->format('d/m/Y');
        return $this->createPayslip($e, $start, $end, $label, $days, $salary, $gross);
    }

    private function createPayslip(array $e, DateTimeImmutable $start, DateTimeImmutable $end, string $label, int $days, Money $reference, Money $gross): int
    {
        if ($gross->amount() <= 0) {
            return 0;
        }
        $commission = $gross->percentBps(Money::rateToBps((string) $e['commission_rate']));
        $net = $gross->subtract($commission);
        $uuid = Uuid::v4();
        $inserted = $this->db->transaction(function (Database $db) use ($e, $start, $end, $label, $days, $reference, $gross, $commission, $net, $uuid) {
            // Idempotence : la clé unique (employment_id, period_start) empêche tout doublon, même en concurrence
            if ($db->fetchValue('SELECT id FROM payslips WHERE employment_id = ? AND period_start = ?', [(int) $e['id'], $start->format('Y-m-d')]) !== null) {
                return false;
            }
            $number = References::next($db, 'FP');
            $hashSource = implode('|', [$number, $e['reference'], $start->format('Y-m-d'), $end->format('Y-m-d'), $gross->amount(), $commission->amount(), $net->amount()]);
            $id = $db->insert('payslips', [
                'uuid' => $uuid, 'number' => $number, 'employment_id' => (int) $e['id'],
                'employer_user_id' => (int) $e['employer_user_id'], 'employee_user_id' => (int) $e['employee_user_id'],
                'period_start' => $start->format('Y-m-d'), 'period_end' => $end->format('Y-m-d'),
                'period_label' => $label, 'days_in_period' => $days,
                'reference_salary' => $reference->toDecimal(), 'gross_amount' => $gross->toDecimal(),
                'commission_rate' => (string) $e['commission_rate'], 'commission_amount' => $commission->toDecimal(),
                'net_amount' => $net->toDecimal(), 'payment_method' => $e['payment_method'],
                'content_hash' => hash('sha256', $hashSource),
            ]);
            $db->insert('payslip_events', ['payslip_id' => $id, 'to_status' => 'EMISE', 'note' => 'Génération automatique']);
            return true;
        });
        if (!$inserted) {
            return 0;
        }
        $this->notifications->notify((int) $e['employee_user_id'], 'payslip.issued', 'Nouvelle fiche de paie : ' . $label,
            'Net à percevoir : ' . $net->format() . '. Vous pouvez maintenant demander votre paiement.', '/fiches-de-paie/' . $uuid);
        $this->notifications->notify((int) $e['employer_user_id'], 'payslip.issued', 'Fiche de paie émise : ' . $label,
            'Montant à verser à l\'employé : ' . $net->format() . ' (' . $e['position_title'] . ').', '/fiches-de-paie/' . $uuid);
        return 1;
    }

    /** Prorata arrondi au franc (demi-supérieur), en entiers uniquement. */
    public static function prorata(int $amount, int $days, int $totalDays): int
    {
        $num = $amount * $days;
        $q = intdiv($num, $totalDays);
        return ($num % $totalDays) * 2 >= $totalDays ? $q + 1 : $q;
    }
}
