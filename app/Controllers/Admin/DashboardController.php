<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\Auth\AuthService;
use App\Services\Auth\PermissionService;
use App\Services\Dashboard\DashboardService;

final class DashboardController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly DashboardService $dashboards,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        $userId = (int) $this->auth->id();
        return $this->render('admin/dashboard', [
            'title' => 'Administration',
            'data' => $this->dashboards->admin(),
            'kpi' => app(\App\Services\Admin\AdminService::class)->recruitmentKpis(),
            'canFinance' => $this->permissions->allows($userId, 'finance.dashboard.view'),
            'canAudit' => $this->permissions->allows($userId, 'audit.view'),
            'roles' => $this->permissions->roles($userId),
        ]);
    }
}
