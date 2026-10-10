<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Session;

final class AuthController extends Controller
{
    public function form(): void
    {
        if (Auth::check()) {
            redirect('/');
        }
        $quickUsers = [];
        if (setting('pin_login_enabled', '1') === '1') {
            // Liste des comptes ayant un PIN (connexion rapide en caisse, comme sur l'ancien logiciel)
            $quickUsers = DB::all('SELECT username, full_name FROM users WHERE active = 1 AND pin_hash IS NOT NULL ORDER BY full_name LIMIT 30');
        }
        view('auth/login', ['quickUsers' => $quickUsers, 'flash' => Session::pullFlash()], null);
    }

    public function login(): void
    {
        $r = Auth::attemptPassword(Request::str('username'), (string) Request::input('password', ''));
        $this->finish($r);
    }

    public function loginPin(): void
    {
        $pin = Request::str('pin');
        if (!preg_match('/^\d{4,8}$/', $pin)) {
            flash('danger', 'Code PIN invalide.');
            redirect('/login');
        }
        $this->finish(Auth::attemptPin(Request::str('username'), $pin), 'pin');
    }

    public function loginBadge(): void
    {
        $this->finish(Auth::attemptBadge(Request::str('badge')), 'badge');
    }

    private function finish(array $r, string $tab = 'password'): never
    {
        if (!$r['ok']) {
            usleep(random_int(200000, 500000)); // ralentit les attaques automatisées
            flash('danger', $r['error']);
            redirect('/login', ['tab' => $tab]);
        }
        redirect('/');
    }

    public function logout(): void
    {
        Auth::logout();
        Session::start();
        flash('success', 'Vous êtes déconnecté.');
        redirect('/login');
    }

    public function ping(): void
    {
        json_response(['ok' => true]);
    }

    public function passwordForm(): void
    {
        view('auth/password', ['title' => 'Mon mot de passe et mon code PIN', 'u' => $this->user()]);
    }

    public function passwordUpdate(): void
    {
        $u = $this->user();
        $current = (string) Request::input('current_password', '');
        $new = (string) Request::input('password', '');
        $confirm = (string) Request::input('password_confirm', '');
        if (!password_verify($current, $u['password_hash'])) {
            Audit::log('auth.password_change_failed', 'user', (int) $u['id']);
            $this->fail('Mot de passe actuel incorrect.', '/account/password');
        }
        if ($new !== $confirm) {
            $this->fail('La confirmation ne correspond pas.', '/account/password');
        }
        if ($err = Auth::passwordPolicyError($new, $u['username'])) {
            $this->fail($err, '/account/password');
        }
        if (password_verify($new, $u['password_hash'])) {
            $this->fail('Le nouveau mot de passe doit être différent de l\'actuel.', '/account/password');
        }
        DB::update('users', ['password_hash' => Auth::hash($new), 'must_change_password' => 0, 'password_changed_at' => date('Y-m-d H:i:s')], 'id = ?', [$u['id']]);
        Audit::log('auth.password_changed', 'user', (int) $u['id']);
        // Nouvelle session (les autres sessions de ce compte sont invalidées par la signature)
        Auth::login(DB::one('SELECT * FROM users WHERE id = ?', [$u['id']]));
        flash('success', 'Mot de passe modifié.');
        redirect('/');
    }

    public function pinUpdate(): void
    {
        $u = $this->user();
        if (!password_verify((string) Request::input('current_password', ''), $u['password_hash'])) {
            $this->fail('Mot de passe actuel incorrect.', '/account/password');
        }
        $pin = Request::str('pin');
        if ($pin === '') {
            DB::update('users', ['pin_hash' => null], 'id = ?', [$u['id']]);
            Audit::log('auth.pin_removed', 'user', (int) $u['id']);
            flash('success', 'Code PIN supprimé.');
            redirect('/account/password');
        }
        if (!preg_match('/^\d{4,8}$/', $pin) || preg_match('/^(\d)\1+$/', $pin) || str_contains('0123456789', $pin) || str_contains('9876543210', $pin)) {
            $this->fail('Le PIN doit contenir 4 à 8 chiffres, sans suite simple (1234, 0000...).', '/account/password');
        }
        DB::update('users', ['pin_hash' => Auth::hash($pin)], 'id = ?', [$u['id']]);
        Audit::log('auth.pin_changed', 'user', (int) $u['id']);
        flash('success', 'Code PIN enregistré. Il permet la connexion rapide à la caisse.');
        redirect('/account/password');
    }
}
