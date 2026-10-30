<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Core\ValidationException;
use App\Services\Employment\EmploymentService;
use App\Services\Employment\PayrollService;
use App\Services\Employment\PayslipService;
use App\Services\Finance\LedgerService;
use App\Services\Jobs\ApplicationService;
use App\Services\Jobs\InterviewService;
use DateTimeImmutable;
use Tests\TestCase;

/** Parcours complet : offre → candidature → entretien → embauche → fiches de paie → paiement confirmé. */
final class RecruitmentPayrollTest extends TestCase
{
    /** @return array{recruiter: int, worker: int, application: string} */
    private function applied(): array
    {
        $recruiter = Factory::user(self::$app, 'BUSINESS');
        $worker = Factory::user(self::$app, 'WORKER');
        $post = Factory::post(self::$app, $recruiter);
        $this->assertSame('CANDIDATURES_OUVERTES', $post['status']);
        $application = $this->service(ApplicationService::class)->apply($worker, $post['uuid'], 'Disponible immédiatement', null);
        return ['recruiter' => $recruiter, 'worker' => $worker, 'application' => $application, 'post' => $post['uuid']];
    }

    /** @return array{recruiter: int, worker: int, employment: string} */
    private function hired(string $period = 'MENSUEL', string $start = '2026-01-15', ?string $end = null, int $salary = 90000): array
    {
        $c = $this->applied();
        $uuid = $this->service(EmploymentService::class)->propose($c['recruiter'], $c['application'], [
            'position_title' => 'Serveur', 'duties' => 'Service en salle', 'work_location' => 'Plateau', 'work_days' => 'Lundi au samedi',
            'daily_start_time' => '08:00', 'daily_end_time' => '16:00', 'hours_per_week' => '40.00', 'pay_period' => $period,
            'salary_amount' => (string) $salary, 'payment_method' => 'MOBILE_MONEY', 'start_date' => $start, 'end_date' => $end,
        ]);
        $this->service(EmploymentService::class)->accept($c['worker'], $uuid, '10.0.0.9');
        return ['recruiter' => $c['recruiter'], 'worker' => $c['worker'], 'employment' => $uuid];
    }

    public function testApplicationInterviewAndHiringFlow(): void
    {
        $c = $this->applied();
        try {
            $this->service(ApplicationService::class)->apply($c['worker'], $c['post'], null, null);
            $this->fail('Double candidature acceptée');
        } catch (ValidationException) {
        }
        $interviews = $this->service(InterviewService::class);
        try {
            $interviews->schedule($c['recruiter'], $c['application'], ['mode' => 'EN_LIGNE', 'date' => date('Y-m-d', strtotime('+2 days')), 'time' => '10:00', 'duration' => 30, 'meeting_link' => 'javascript:alert(1)', 'location' => null, 'notes' => null]);
            $this->fail('Lien non https accepté');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('meeting_link', $e->errors());
        }
        $iv = $interviews->schedule($c['recruiter'], $c['application'], ['mode' => 'EN_LIGNE', 'date' => date('Y-m-d', strtotime('+2 days')), 'time' => '10:00', 'duration' => 30, 'meeting_link' => 'https://meet.google.com/abc-defg-hij', 'location' => null, 'notes' => 'Préparez vos questions']);
        $this->assertSame('PRESELECTIONNEE', $this->db()->fetchValue('SELECT status FROM job_applications WHERE uuid = ?', [$c['application']]));
        $interviews->respond($c['worker'], $iv, 'confirm', null);
        $interviews->conclude($c['recruiter'], $iv, 'CONCLUANT', 'Très bon contact');
        $this->assertSame('CONCLUANT', $this->db()->fetchValue('SELECT status FROM interviews WHERE uuid = ?', [$iv]));

        // Un autre recruteur ne voit pas la candidature
        $intruder = Factory::user(self::$app, 'CLIENT');
        $this->expectException(HttpException::class);
        $this->service(ApplicationService::class)->findForParty($c['application'], $intruder);
    }

    public function testHiringFillsPositionAndEmployeeMustAccept(): void
    {
        $c = $this->applied();
        $uuid = $this->service(EmploymentService::class)->propose($c['recruiter'], $c['application'], [
            'position_title' => 'Serveur', 'duties' => null, 'work_location' => null, 'work_days' => 'Lundi au vendredi',
            'daily_start_time' => null, 'daily_end_time' => null, 'hours_per_week' => '35.00', 'pay_period' => 'MENSUEL',
            'salary_amount' => '75000', 'payment_method' => 'ESPECES', 'start_date' => '2026-02-01', 'end_date' => null,
        ]);
        $e = $this->db()->fetch('SELECT e.status, e.commission_rate, e.content_hash, e.terms_snapshot, p.positions_filled, p.status AS post_status FROM employments e JOIN job_posts p ON p.id = e.job_post_id WHERE e.uuid = ?', [$uuid]);
        $this->assertSame('EN_ATTENTE_ACCEPTATION', $e['status']);
        $this->assertSame('10.00', (string) $e['commission_rate']);
        $this->assertSame(hash('sha256', $e['terms_snapshot']), $e['content_hash']);
        $this->assertSame(1, (int) $e['positions_filled']);
        $this->assertSame('ATTRIBUEE', $e['post_status'], 'Poste unique pourvu : offre fermée aux candidatures');
        // Aucune fiche tant que le contrat n'est pas accepté
        $this->assertSame(0, $this->service(PayrollService::class)->generateDue(new DateTimeImmutable('2026-05-31'), $c['worker']));
        // Le recruteur ne peut pas accepter à la place de l'employé
        try {
            $this->service(EmploymentService::class)->accept($c['recruiter'], $uuid, '1.1.1.1');
            $this->fail('Acceptation par l\'employeur');
        } catch (ValidationException) {
        }
        $this->service(EmploymentService::class)->accept($c['worker'], $uuid, '10.0.0.1');
        $this->assertSame('ACTIF', $this->db()->fetchValue('SELECT status FROM employments WHERE uuid = ?', [$uuid]));
        // Clauses figées après acceptation
        $this->expectException(\PDOException::class);
        $this->db()->query('UPDATE employments SET salary_amount = 1000 WHERE uuid = ?', [$uuid]);
    }

    public function testMonthlyPayslipsAreGeneratedAutomaticallyWithProrata(): void
    {
        $h = $this->hired('MENSUEL', '2026-01-15');
        $payroll = $this->service(PayrollService::class);
        $this->assertSame(0, $payroll->generateDue(new DateTimeImmutable('2026-01-30'), $h['worker']), 'Mois non terminé : pas de fiche');
        $this->assertSame(1, $payroll->generateDue(new DateTimeImmutable('2026-01-31'), $h['worker']), 'Fiche émise le dernier jour du mois');
        $this->assertSame(2, $payroll->generateDue(new DateTimeImmutable('2026-03-31'), $h['worker']));
        $this->assertSame(0, $payroll->generateDue(new DateTimeImmutable('2026-03-31'), $h['worker']), 'Idempotent');

        $rows = $this->db()->fetchAll('SELECT ps.period_start, ps.period_end, ps.gross_amount, ps.commission_amount, ps.net_amount, ps.days_in_period
            FROM payslips ps JOIN employments e ON e.id = ps.employment_id WHERE e.uuid = ? ORDER BY ps.period_start', [$h['employment']]);
        $this->assertCount(3, $rows);
        // Janvier : 17 jours sur 31 → 90 000 × 17 / 31 = 49 354,8 → 49 355
        $this->assertSame(['2026-01-15', '2026-01-31', '49355.00', '4936.00', '44419.00', 17],
            [$rows[0]['period_start'], $rows[0]['period_end'], (string) $rows[0]['gross_amount'], (string) $rows[0]['commission_amount'], (string) $rows[0]['net_amount'], (int) $rows[0]['days_in_period']]);
        $this->assertSame(['90000.00', '9000.00', '81000.00'], [(string) $rows[1]['gross_amount'], (string) $rows[1]['commission_amount'], (string) $rows[1]['net_amount']]);
        $this->assertSame('2026-03-01', $rows[2]['period_start']);
        $this->assertSame(3, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'payslip.issued'", [$h['worker']]), 'L\'employé est prévenu à chaque fiche');
    }

    public function testEarlyEndGeneratesFinalProrataPayslip(): void
    {
        $h = $this->hired('MENSUEL', '2026-04-01');
        $this->service(PayrollService::class)->generateDue(new DateTimeImmutable('2026-04-30'), $h['worker']);
        $this->service(EmploymentService::class)->end($h['recruiter'], $h['employment'], '2026-05-10', 'Fin de saison');
        $this->service(PayrollService::class)->generateDue(new DateTimeImmutable('2026-05-10'), $h['worker']);
        $last = $this->db()->fetch('SELECT ps.gross_amount, ps.period_end FROM payslips ps JOIN employments e ON e.id = ps.employment_id WHERE e.uuid = ? ORDER BY ps.period_start DESC LIMIT 1', [$h['employment']]);
        $this->assertSame('2026-05-10', $last['period_end']);
        $this->assertSame((string) PayrollService::prorata(90000, 10, 31) . '.00', (string) $last['gross_amount']);
        $this->assertSame('TERMINE', $this->db()->fetchValue('SELECT status FROM employments WHERE uuid = ?', [$h['employment']]));
    }

    public function testPrestationPayslipAtEndDate(): void
    {
        $h = $this->hired('PRESTATION', '2026-02-01', '2026-02-10', 50000);
        $payroll = $this->service(PayrollService::class);
        $this->assertSame(0, $payroll->generateDue(new DateTimeImmutable('2026-02-09'), $h['worker']));
        $this->assertSame(1, $payroll->generateDue(new DateTimeImmutable('2026-02-10'), $h['worker']));
        $this->assertSame('50000.00', (string) $this->db()->fetchValue('SELECT ps.gross_amount FROM payslips ps JOIN employments e ON e.id = ps.employment_id WHERE e.uuid = ?', [$h['employment']]));
        $this->assertSame('TERMINE', $this->db()->fetchValue('SELECT status FROM employments WHERE uuid = ?', [$h['employment']]), 'Clôture automatique à la date de fin');
    }

    public function testPaymentRequestDeclarationAndConfirmationAreRecorded(): void
    {
        $h = $this->hired('MENSUEL', '2026-06-01');
        $this->service(PayrollService::class)->generateDue(new DateTimeImmutable('2026-06-30'), $h['worker']);
        $uuid = (string) $this->db()->fetchValue('SELECT ps.uuid FROM payslips ps JOIN employments e ON e.id = ps.employment_id WHERE e.uuid = ?', [$h['employment']]);
        $slips = $this->service(PayslipService::class);

        try {
            $slips->confirmReceived($h['worker'], $uuid, '1.1.1.1');
            $this->fail('Confirmation avant paiement');
        } catch (ValidationException) {
        }
        $slips->requestPayment($h['worker'], $uuid, 'MOBILE_MONEY', '96 11 22 33', '1.1.1.1');
        $this->assertSame('+22796112233', $this->db()->fetchValue('SELECT payout_phone FROM payslips WHERE uuid = ?', [$uuid]));
        try {
            $slips->declarePaid($h['worker'], $uuid, 'X', '1.1.1.1');
            $this->fail('L\'employé ne peut pas déclarer le paiement');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status());
        }
        $slips->declarePaid($h['recruiter'], $uuid, 'MP260630.1234.A12345', '1.1.1.2');
        $slips->dispute($h['worker'], $uuid, 'Je n\'ai rien reçu sur mon numéro', '1.1.1.1');
        $this->assertSame('CONTESTEE', $this->db()->fetchValue('SELECT status FROM payslips WHERE uuid = ?', [$uuid]));
        $slips->declarePaid($h['recruiter'], $uuid, 'MP260701.0001.B99999', '1.1.1.2');
        $slips->confirmReceived($h['worker'], $uuid, '1.1.1.1');

        $p = $this->db()->fetch('SELECT ps.status, ps.payment_id, ps.commission_amount, ps.net_amount FROM payslips ps WHERE uuid = ?', [$uuid]);
        $this->assertSame('RECUE', $p['status']);
        $payment = $this->db()->fetch('SELECT purpose, status, amount, mode FROM payments WHERE id = ?', [(int) $p['payment_id']]);
        $this->assertSame(['SALAIRE', 'CONFIRMED', '81000.00', 'DIRECT'], [$payment['purpose'], $payment['status'], (string) $payment['amount'], $payment['mode']]);
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM transactions WHERE payment_id = ? AND type = 'SALAIRE'", [(int) $p['payment_id']]));
        $this->assertSame(9000, $this->service(LedgerService::class)->balance('1210', $h['recruiter']), 'Commission due par l\'employeur');
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM v_ledger_unbalanced_journals'));
        $this->assertSame(6, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM payslip_events pe JOIN payslips ps ON ps.id = pe.payslip_id WHERE ps.uuid = ?', [$uuid]));
        // Double confirmation impossible ; montants et historique immuables
        try {
            $slips->confirmReceived($h['worker'], $uuid, '1.1.1.1');
            $this->fail('Double confirmation');
        } catch (ValidationException) {
        }
        $this->expectException(\PDOException::class);
        $this->db()->query('UPDATE payslips SET net_amount = 1, gross_amount = 1, commission_amount = 0 WHERE uuid = ?', [$uuid]);
    }

    public function testPayslipIsPrivateToBothParties(): void
    {
        $h = $this->hired('MENSUEL', '2026-07-01');
        $this->service(PayrollService::class)->generateDue(new DateTimeImmutable('2026-07-31'), $h['worker']);
        $uuid = (string) $this->db()->fetchValue('SELECT ps.uuid FROM payslips ps JOIN employments e ON e.id = ps.employment_id WHERE e.uuid = ?', [$h['employment']]);
        $stranger = Factory::user(self::$app, 'WORKER');
        $this->expectException(HttpException::class);
        $this->service(PayslipService::class)->findForParty($uuid, $stranger);
    }

    public function testProrataRounding(): void
    {
        $this->assertSame(49355, PayrollService::prorata(90000, 17, 31));
        $this->assertSame(90000, PayrollService::prorata(90000, 31, 31));
        $this->assertSame(2, PayrollService::prorata(3, 1, 2), '1,5 → 2 (arrondi demi-supérieur)');
        $this->assertSame(0, PayrollService::prorata(1, 1, 3));
    }
}
