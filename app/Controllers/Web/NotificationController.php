<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\Auth\AuthService;
use App\Services\Notifications\NotificationService;

final class NotificationController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AuthService $auth,
        private readonly NotificationService $notifications,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        $userId = (int) $this->auth->id();
        return $this->render('notifications/index', [
            'title' => 'Mes notifications',
            'notifications' => $this->notifications->latest($userId, 50),
        ]);
    }

    public function read(Request $request): Response
    {
        // L'identifiant ne suffit pas : la requête SQL filtre aussi sur le propriétaire (anti-IDOR)
        $this->notifications->markRead((int) $this->auth->id(), (int) $request->param('id'));
        return $request->wantsJson() ? Response::json(['ok' => true]) : $this->redirect('/notifications');
    }

    public function readAll(Request $request): Response
    {
        $n = $this->notifications->markAllRead((int) $this->auth->id());
        return $this->redirect('/notifications', $n > 0 ? "{$n} notification(s) marquée(s) comme lue(s)." : null);
    }
}
