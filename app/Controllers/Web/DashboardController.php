<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\Auth\AuthService;
use App\Services\Dashboard\DashboardService;
use App\Services\Notifications\NotificationService;

final class DashboardController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AuthService $auth,
        private readonly DashboardService $dashboards,
        private readonly NotificationService $notifications,
        private readonly \App\Services\Jobs\InterviewService $interviews,
        private readonly \App\Services\Jobs\JobPostService $posts,
        private readonly \App\Services\Employment\PayrollService $payroll,
        private readonly \App\Services\Employment\PayslipService $payslips,
        private readonly \App\Services\Employment\EmploymentService $employments,
    ) {
        parent::__construct($view, $session);
    }

    /** /tableau-de-bord : redirige vers l'espace correspondant au rôle principal. */
    public function home(Request $request): Response
    {
        return Response::redirect($this->auth->homePath((int) $this->auth->id()));
    }

    public function worker(Request $request): Response
    {
        $user = $this->auth->user();
        $this->payroll->generateDue(null, (int) $user['id']);
        return $this->render('dashboard/worker', [
            'title' => 'Mon espace prestataire',
            'user' => $user,
            'data' => $this->dashboards->worker((int) $user['id']),
            'interviews' => $this->interviews->upcoming((int) $user['id']),
            'offers' => $this->posts->search(['city' => (int) $user['city_id']], 1, 4)['rows'],
            'employments' => $this->employments->forUser((int) $user['id'], ['EN_ATTENTE_ACCEPTATION', 'ACTIF']),
            'payslips' => array_slice(array_values(array_filter($this->payslips->forUser((int) $user['id']), fn ($p) => !in_array($p['status'], ['RECUE', 'ANNULEE'], true))), 0, 5),
            'notifications' => $this->notifications->latest((int) $user['id'], 6),
        ]);
    }

    public function client(Request $request): Response
    {
        $user = $this->auth->user();
        $this->payroll->generateDue(null, (int) $user['id']);
        return $this->render('dashboard/client', [
            'title' => 'Mon espace recruteur',
            'user' => $user,
            'data' => $this->dashboards->client((int) $user['id']),
            'interviews' => $this->interviews->upcoming((int) $user['id']),
            'posts' => array_slice($this->posts->forRecruiter((int) $user['id']), 0, 5),
            'employments' => $this->employments->forUser((int) $user['id'], ['EN_ATTENTE_ACCEPTATION', 'ACTIF']),
            'payslips' => array_slice(array_values(array_filter($this->payslips->forUser((int) $user['id']), fn ($p) => !in_array($p['status'], ['RECUE', 'ANNULEE'], true))), 0, 5),
            'notifications' => $this->notifications->latest((int) $user['id'], 6),
        ]);
    }
}
