<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Session;
use App\Core\View;

final class AuthController extends Controller
{
    public function loginForm(): string
    {
        if (Auth::check(false)) {
            $this->redirect('dashboard');
        }
        return View::render('auth/login', ['title' => 'Connexion', 'username' => ''], 'layout/guest');
    }

    public function login(): string
    {
        $username = mb_substr($this->input('username'), 0, 60);
        $password = (string) ($_POST['password'] ?? '');
        if ($username === '' || $password === '' || strlen($password) > 1024) {
            Session::flash('danger', 'Veuillez saisir votre identifiant et votre mot de passe.');
            $this->redirect('auth.login');
        }
        $res = Auth::attempt($username, $password);
        if (!$res['ok']) {
            return View::render('auth/login', [
                'title' => 'Connexion', 'username' => $username, 'error' => $res['error'],
            ], 'layout/guest');
        }
        $this->redirect('dashboard');
    }

    public function logout(): never
    {
        Auth::logout();
        Session::flash('success', 'Vous êtes déconnecté.');
        $this->redirect('auth.login');
    }

    public function passwordForm(): string
    {
        return $this->view('auth/password', ['title' => 'Changer le mot de passe', 'errors' => []]);
    }

    public function passwordSave(): string
    {
        $user = Auth::user();
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        $errors = [];
        $hash = (string) Database::value('SELECT password_hash FROM users WHERE id = :id', ['id' => (int) $user['id']]);
        if (!password_verify($current, $hash)) {
            $errors[] = 'Le mot de passe actuel est incorrect.';
        }
        if ($new !== $confirm) {
            $errors[] = 'La confirmation ne correspond pas au nouveau mot de passe.';
        }
        if (password_verify($new, $hash)) {
            $errors[] = 'Le nouveau mot de passe doit être différent de l\'actuel.';
        }
        $errors = array_merge($errors, Auth::passwordPolicyErrors($new, (string) $user['username']));
        if ($errors) {
            return $this->view('auth/password', ['title' => 'Changer le mot de passe', 'errors' => $errors]);
        }
        Database::update('users', [
            'password_hash' => Auth::hashPassword($new),
            'must_change_password' => 0,
            'password_changed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => (int) $user['id']]);
        Session::regenerate();
        Audit::log('auth.password_changed', 'user', (int) $user['id']);
        Auth::reset();
        $this->back('dashboard', [], 'success', 'Mot de passe modifié.');
    }
}
