<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\LoginThrottle;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        return $this->view('auth/login', [], 'layouts/auth');
    }

    public function login(Request $request): Response
    {
        $login = mb_strtolower($request->str('login'));
        $password = (string) $request->input('password', '');
        $ip = $request->ip();

        if ($login === '' || $password === '') {
            Session::flashErrors(['login' => 'Identifiant et mot de passe requis.']);
            Session::flashOld(['login' => $login]);
            return $this->redirect('/login');
        }

        $wait = LoginThrottle::secondsUntilUnlock($login, $ip);
        if ($wait > 0) {
            LoginThrottle::record($login, $ip, $request->userAgent(), false, null, 'throttled');
            Session::flashErrors(['login' => sprintf('Trop de tentatives. Réessayez dans %d minute(s).', (int) ceil($wait / 60))]);
            Session::flashOld(['login' => $login]);
            return $this->redirect('/login');
        }

        $user = Auth::attempt($login, $password);
        if (!$user) {
            LoginThrottle::record($login, $ip, $request->userAgent(), false, null, 'invalid_credentials');
            Session::flashErrors(['login' => 'Identifiant ou mot de passe incorrect.']);
            Session::flashOld(['login' => $login]);
            return $this->redirect('/login');
        }
        if ($user['status'] !== 'active') {
            LoginThrottle::record($login, $ip, $request->userAgent(), false, (int) $user['id'], 'account_' . $user['status']);
            Session::flashErrors(['login' => 'Ce compte est suspendu. Contactez un administrateur.']);
            return $this->redirect('/login');
        }

        LoginThrottle::record($login, $ip, $request->userAgent(), true, (int) $user['id']);
        Auth::login($user, $request);
        AuditService::log('auth.login', 'user', (int) $user['id'], 'Connexion réussie');

        $intended = (string) Session::get('_intended', '/');
        Session::forget('_intended');
        // Uniquement des chemins internes.
        if (!str_starts_with($intended, '/') || str_starts_with($intended, '//') || str_starts_with($intended, '/api/')) {
            $intended = '/';
        }
        return $this->redirect($intended);
    }

    public function logout(Request $request): Response
    {
        AuditService::log('auth.logout', 'user', Auth::id(), 'Déconnexion');
        Auth::logout();
        Session::start($request->isSecure());
        Session::flash('success', 'Vous êtes déconnecté.');
        return $this->redirect('/login');
    }
}
