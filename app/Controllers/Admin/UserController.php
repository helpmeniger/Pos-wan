<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Services\Admin\AdminService;
use App\Services\Auth\AuthService;

final class UserController extends Controller
{
    public function __construct(View $view, Session $session, private readonly AdminService $admin, private readonly AuthService $auth)
    {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        $f = ['q' => mb_substr($request->str('q'), 0, 100), 'type' => $request->str('type'), 'status' => $request->str('status'), 'verified' => $request->str('verified')];
        return $this->render('admin/users/index', ['title' => 'Utilisateurs', 'result' => $this->admin->users($f, (int) $request->query('page', 1)), 'f' => $f]);
    }

    public function show(Request $request): Response
    {
        $d = $this->admin->userDetail((string) $request->param('uuid'));
        return $this->render('admin/users/show', ['title' => $d['user']['first_name'] . ' ' . $d['user']['last_name'], 'd' => $d]);
    }

    public function status(Request $request): Response
    {
        $data = Validator::validate($request->all(), ['status' => 'required|in:ACTIVE,SUSPENDED,BANNED', 'reason' => 'required|string|min:5|max:500'], ['reason' => 'motif']);
        $this->admin->setUserStatus((int) $this->auth->id(), (string) $request->param('uuid'), (string) $data['status'], (string) $data['reason'], $request->ip());
        return $this->redirect('/admin/utilisateurs/' . $request->param('uuid'), 'Statut du compte mis à jour (action journalisée).');
    }

    public function verifyPhone(Request $request): Response
    {
        $this->admin->verifyPhoneManually((int) $this->auth->id(), (string) $request->param('uuid'));
        return $this->redirect('/admin/utilisateurs/' . $request->param('uuid'), 'Téléphone marqué comme vérifié.');
    }
}
