<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

final class AuthController extends Controller
{
    public function showLogin(Request $r): void
    {
        View::render('auth/login', ['title' => 'Connexion'], 'layouts/guest');
    }

    public function login(Request $r): void
    {
        $username = mb_substr((string) $r->input('username', ''), 0, 60);
        $password = (string) $r->input('password', '');
        if ($username === '' || $password === '') {
            Session::flash('error', 'Saisissez votre identifiant et votre mot de passe.');
            Response::redirect('login');
        }
        $res = Auth::attempt($username, $password, $r->ip());
        if (!$res['ok']) {
            Session::flash('error', $res['error']);
            Response::redirect('login');
        }
        if (Auth::user()['must_change_password'] ?? false) {
            Session::flash('warning', 'Veuillez définir un nouveau mot de passe.');
            Response::redirect('account/password');
        }
        $intended = (string) Session::get('_intended', '');
        Session::forget('_intended');
        $base = \App\Core\Router::basePath();
        if ($intended !== '' && str_starts_with($intended, $base . '/') && !str_contains($intended, '//')) {
            header('Location: ' . $intended, true, 303);
            exit;
        }
        Response::redirect(Auth::can('dashboard.view') ? 'dashboard' : (Auth::can('pos.sell') ? 'pos' : 'live'));
    }

    public function logout(Request $r): void
    {
        Auth::logout();
        Session::start();
        Session::flash('success', 'Vous êtes déconnecté.');
        Response::redirect('login');
    }

    public function showPassword(Request $r): void
    {
        $this->view('auth/password', ['title' => 'Changer mon mot de passe']);
    }

    public function updatePassword(Request $r): void
    {
        $user = Database::fetch('SELECT * FROM users WHERE id = :id', ['id' => Auth::id()]);
        if (!password_verify((string) $r->input('current_password'), $user['password_hash'])) {
            $this->back('error', 'Mot de passe actuel incorrect.');
        }
        $new = (string) $r->input('new_password');
        if ($new !== (string) $r->input('new_password_confirm')) {
            $this->back('error', 'La confirmation ne correspond pas.');
        }
        if ($err = Auth::passwordPolicyError($new)) {
            $this->back('error', $err);
        }
        Database::update('users', ['password_hash' => Auth::hashPassword($new), 'must_change_password' => 0], 'id = :id', ['id' => $user['id']]);
        Session::regenerate();
        Audit::log('mot_de_passe_modifie', 'user', $user['id']);
        Session::flash('success', 'Mot de passe modifié.');
        Response::redirect('dashboard');
    }

    public function switchStore(Request $r): void
    {
        if (!Auth::switchStore((int) $r->input('store_id'))) {
            $this->back('error', 'Magasin non autorisé.');
        }
        Audit::log('changement_magasin', 'store', (int) $r->input('store_id'));
        Response::back();
    }
}
