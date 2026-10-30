<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\Auth\AuthService;
use App\Services\Employment\EmploymentService;
use App\Services\Employment\PayrollService;
use App\Services\Employment\PayslipService;

/** Archives personnelles : contrats (clauses), fiches de paie, candidatures et entretiens passés. */
final class ArchiveController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AuthService $auth,
        private readonly EmploymentService $employments,
        private readonly PayslipService $payslips,
        private readonly PayrollService $payroll,
        private readonly Database $db,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        $userId = (int) $this->auth->id();
        $this->payroll->generateDue(null, $userId);
        $year = (int) $request->query('annee', 0);
        $payslips = $this->payslips->forUser($userId);
        if ($year > 0) {
            $payslips = array_values(array_filter($payslips, fn ($p) => (int) substr((string) $p['period_start'], 0, 4) === $year));
        }
        $years = array_values(array_unique(array_map(fn ($p) => (int) substr((string) $p['period_start'], 0, 4), $this->payslips->forUser($userId))));
        rsort($years);
        return $this->render('archive/index', [
            'title' => 'Mes archives',
            'employments' => $this->employments->forUser($userId),
            'payslips' => $payslips,
            'years' => $years,
            'year' => $year,
            'totals' => $this->db->fetch(
                "SELECT COALESCE(SUM(CASE WHEN employee_user_id = ? AND status = 'RECUE' THEN net_amount END), 0) AS received,
                        COALESCE(SUM(CASE WHEN employer_user_id = ? AND status = 'RECUE' THEN net_amount END), 0) AS paid
                 FROM payslips" . ($year > 0 ? ' WHERE YEAR(period_start) = ' . $year : ''),
                [$userId, $userId]
            ),
            'history' => $this->db->fetchAll(
                "SELECT 'Candidature' AS kind, a.status, a.created_at AS at, p.title, p.uuid AS post_uuid FROM job_applications a JOIN job_posts p ON p.id = a.job_post_id
                  WHERE a.worker_user_id = ? AND a.status IN ('REFUSEE','RETIREE','EXPIREE','ACCEPTEE')
                 UNION ALL
                 SELECT 'Entretien', i.status, i.scheduled_at, p.title, p.uuid FROM interviews i JOIN job_posts p ON p.id = i.job_post_id
                  WHERE (i.candidate_user_id = ? OR i.recruiter_user_id = ?) AND i.status IN ('CONCLUANT','NON_CONCLUANT','ANNULE')
                 UNION ALL
                 SELECT 'Offre', p.status, p.created_at, p.title, p.uuid FROM job_posts p
                  WHERE p.client_user_id = ? AND p.status IN ('TERMINEE','ANNULEE','EXPIREE','ATTRIBUEE','REFUSEE')
                 ORDER BY at DESC LIMIT 200",
                [$userId, $userId, $userId, $userId]
            ),
        ]);
    }
}
