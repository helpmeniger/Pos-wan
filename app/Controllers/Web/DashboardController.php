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
        return $this->render('dashboard/worker', [
            'title' => 'Mon espace prestataire',
            'user' => $user,
            'data' => $this->dashboards->worker((int) $user['id']),
            'notifications' => $this->notifications->latest((int) $user['id'], 6),
        ]);
    }

    public function client(Request $request): Response
    {
        $user = $this->auth->user();
        return $this->render('dashboard/client', [
            'title' => 'Mon espace demandeur',
            'user' => $user,
            'data' => $this->dashboards->client((int) $user['id']),
            'notifications' => $this->notifications->latest((int) $user['id'], 6),
        ]);
    }
}
