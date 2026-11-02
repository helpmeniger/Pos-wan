<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;

final class ProfileController extends Controller
{
    public function index(Request $request): Response
    {
        $logins = Database::fetchAll(
            'SELECT ip_address, user_agent, success, reason, created_at FROM login_attempts WHERE user_id = :u ORDER BY id DESC LIMIT 10',
            ['u' => Auth::id()]
        );
        return $this->view('profile/index', ['user' => Auth::user(), 'logins' => $logins]);
    }

    public function update(Request $request): Response
    {
        $user = Auth::user();
        $data = $this->validate($request, [
            'full_name' => 'required|max:150',
            'email' => 'required|email|max:190|unique:users,email,' . $user['id'],
            'phone' => 'max:30',
            'theme' => 'required|in:light,dark,auto',
        ], ['full_name' => 'Nom complet', 'email' => 'E-mail', 'phone' => 'Téléphone', 'theme' => 'Thème']);
        $changes = [
            'full_name' => $data['full_name'],
            'email' => mb_strtolower($data['email']),
            'phone' => $data['phone'] ?: null,
            'theme' => $data['theme'],
        ];
        Database::update('users', $changes, 'id = :id', ['id' => $user['id']]);
        [$old, $new] = AuditService::diff($user, $changes);
        if ($new) {
            AuditService::log('profile.update', 'user', (int) $user['id'], 'Mise à jour du profil', $old, $new);
        }
        Session::flash('success', 'Profil mis à jour.');
        return $this->redirect('/profile');
    }

    public function password(Request $request): Response
    {
        $user = Auth::user();
        $current = (string) $request->input('current_password', '');
        $new = (string) $request->input('password', '');
        $hash = (string) Database::value('SELECT password_hash FROM users WHERE id = :id', ['id' => $user['id']]);

        $errors = [];
        if (!password_verify($current, $hash)) {
            $errors['current_password'] = 'Mot de passe actuel incorrect.';
        } elseif ($new !== (string) $request->input('password_confirmation', '')) {
            $errors['password'] = 'La confirmation ne correspond pas.';
        } elseif ($err = Auth::passwordPolicyError($new)) {
            $errors['password'] = $err;
        } elseif (password_verify($new, $hash)) {
            $errors['password'] = "Le nouveau mot de passe doit être différent de l'actuel.";
        }
        if ($errors) {
            Session::flashErrors($errors);
            return $this->redirect('/profile');
        }
        Database::update('users', [
            'password_hash' => Auth::hash($new),
            'must_change_password' => 0,
            'password_changed_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $user['id']]);
        Session::regenerate();
        Csrf::rotate();
        Auth::reset();
        AuditService::log('profile.password', 'user', (int) $user['id'], 'Changement de mot de passe');
        Session::flash('success', 'Mot de passe modifié avec succès.');
        return $this->redirect('/profile');
    }
}
