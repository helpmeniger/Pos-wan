<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Session;
use App\Services\ValidationException;
use App\Support\Validator;

final class UserController extends Controller
{
    public function index(): string
    {
        Auth::require('users.manage');
        $users = Database::all(
            'SELECT u.id, u.username, u.full_name, u.service, u.is_active, u.disabled_reason, u.locked_until, u.last_login_at,
                    u.must_change_password, u.is_demo, r.label AS role_label
             FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.is_active DESC, u.username'
        );
        return $this->view('users/index', ['title' => 'Utilisateurs', 'users' => $users]);
    }

    public function create(): string
    {
        Auth::require('users.manage');
        return $this->form([], []);
    }

    public function store(): string
    {
        Auth::require('users.manage');
        try {
            $data = $this->validate($_POST, null);
        } catch (ValidationException $e) {
            return $this->form($_POST, $e->errors);
        }
        $temp = self::temporaryPassword();
        $now = date('Y-m-d H:i:s');
        $id = Database::insert('users', $data + [
            'password_hash' => Auth::hashPassword($temp), 'must_change_password' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Audit::log('user.create', 'user', $id, ['username' => $data['username'], 'role_id' => $data['role_id']]);
        Session::set('_temp_password', ['user' => $data['username'], 'password' => $temp]);
        $this->redirect('users.edit', ['id' => $id]);
    }

    public function edit(): string
    {
        Auth::require('users.manage');
        $user = Database::one('SELECT * FROM users WHERE id = :id', ['id' => $this->id()]);
        if (!$user) {
            throw new HttpException(404, 'Utilisateur introuvable.');
        }
        unset($user['password_hash']);
        $temp = Session::get('_temp_password');
        Session::forget('_temp_password');
        return $this->form($user, [], (int) $user['id'], $temp);
    }

    public function update(): string
    {
        Auth::require('users.manage');
        $id = $this->id();
        $user = Database::one('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        if (!$user) {
            throw new HttpException(404, 'Utilisateur introuvable.');
        }
        try {
            $data = $this->validate($_POST, $id);
        } catch (ValidationException $e) {
            return $this->form($_POST + ['id' => $id, 'username' => $user['username']], $e->errors, $id);
        }
        unset($data['username']);
        if ($id === Auth::id() && (int) $data['role_id'] !== (int) $user['role_id']) {
            return $this->form($_POST + ['username' => $user['username']], ['role_id' => 'Vous ne pouvez pas modifier votre propre rôle.'], $id);
        }
        Database::update('users', $data + ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        $changed = array_keys(array_filter($data, fn ($v, $k) => (string) $user[$k] !== (string) $v, ARRAY_FILTER_USE_BOTH));
        Audit::log('user.update', 'user', $id, ['champs' => $changed]);
        $this->back('users.index', [], 'success', 'Compte mis à jour.');
    }

    public function resetPassword(): never
    {
        Auth::require('users.manage');
        $id = $this->id();
        $user = Database::one('SELECT id, username FROM users WHERE id = :id', ['id' => $id]);
        if (!$user) {
            throw new HttpException(404, 'Utilisateur introuvable.');
        }
        if ($id === Auth::id()) {
            $this->back('users.edit', ['id' => $id], 'warning', 'Utilisez « Changer le mot de passe » pour votre propre compte.');
        }
        $temp = self::temporaryPassword();
        Database::update('users', [
            'password_hash' => Auth::hashPassword($temp), 'must_change_password' => 1,
            'failed_attempts' => 0, 'locked_until' => null, 'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);
        Audit::log('user.reset_password', 'user', $id);
        Session::set('_temp_password', ['user' => $user['username'], 'password' => $temp]);
        $this->redirect('users.edit', ['id' => $id]);
    }

    public function toggle(): never
    {
        Auth::require('users.manage');
        $id = $this->id();
        if ($id === Auth::id()) {
            $this->back('users.index', [], 'danger', 'Vous ne pouvez pas désactiver votre propre compte.');
        }
        $user = Database::one('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        if (!$user) {
            throw new HttpException(404, 'Utilisateur introuvable.');
        }
        $reason = $this->input('reason');
        if ((int) $user['is_active']) {
            if (mb_strlen($reason) < 5) {
                $this->back('users.index', [], 'danger', 'Le motif de désactivation est obligatoire.');
            }
            Database::update('users', ['is_active' => 0, 'disabled_reason' => mb_substr($reason, 0, 255), 'updated_at' => date('Y-m-d H:i:s')],
                'id = :id', ['id' => $id]);
            Audit::log('user.disable', 'user', $id, ['motif' => $reason]);
            $this->back('users.index', [], 'success', 'Compte désactivé. Les sessions ouvertes sont révoquées à la requête suivante.');
        }
        Database::update('users', ['is_active' => 1, 'disabled_reason' => null, 'failed_attempts' => 0, 'locked_until' => null,
            'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        Audit::log('user.enable', 'user', $id, ['motif' => $reason]);
        $this->back('users.index', [], 'success', 'Compte réactivé.');
    }

    private function validate(array $input, ?int $id): array
    {
        $roles = array_map('strval', array_column(Database::all('SELECT id FROM roles'), 'id'));
        $v = Validator::make($input)
            ->text('username', 'Identifiant', $id === null, 60, 3)
            ->text('full_name', 'Nom complet', true, 150)
            ->text('email', 'Courriel', false, 150)
            ->text('service', 'Service', false, 120)
            ->in('role_id', 'Rôle', $roles);
        $d = $v->data();
        if ($id === null) {
            $u = mb_strtolower((string) $d['username']);
            if (!preg_match('/^[a-z0-9._-]{3,60}$/', $u)) {
                $v->addError('username', 'Identifiant : 3 à 60 caractères parmi a-z, 0-9, point, tiret, souligné.');
            } elseif (Database::value('SELECT 1 FROM users WHERE username = :u', ['u' => $u])) {
                $v->addError('username', 'Cet identifiant existe déjà.');
            }
        }
        if ($d['email'] !== null && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $v->addError('email', 'Adresse de courriel invalide.');
        }
        $data = $v->validated();
        $data['role_id'] = (int) $data['role_id'];
        if ($id === null) {
            $data['username'] = mb_strtolower($data['username']);
        }
        return $data;
    }

    public static function temporaryPassword(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '-_.!@#'];
        $chars = [];
        foreach ($sets as $set) {
            $chars[] = $set[random_int(0, strlen($set) - 1)];
        }
        $all = implode('', $sets);
        while (count($chars) < 16) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }

    private function form(array $u, array $errors, ?int $id = null, ?array $temp = null): string
    {
        return $this->view('users/form', [
            'title' => $id ? 'Modifier le compte' : 'Nouveau compte',
            'u' => $u, 'errors' => $errors, 'id' => $id, 'temp' => $temp,
            'roles' => Database::all('SELECT * FROM roles ORDER BY id'),
        ]);
    }
}
