<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\Catalog;

final class UserController extends Controller
{
    private function lists(): array
    {
        $roles = DB::all('SELECT * FROM roles ORDER BY id');
        // Seul un administrateur peut attribuer le rôle administrateur
        if ($this->user()['role_code'] !== 'admin') {
            $roles = array_values(array_filter($roles, fn($r) => $r['code'] !== 'admin'));
        }
        return [
            'roles' => $roles,
            'stores' => DB::all('SELECT id, name FROM stores WHERE active = 1 ORDER BY name'),
            'registers' => DB::all('SELECT r.id, r.name, s.name AS store FROM cash_registers r JOIN stores s ON s.id = r.store_id WHERE r.active = 1 ORDER BY s.name, r.name'),
        ];
    }

    public function index(): void
    {
        $users = DB::all('SELECT u.*, r.name AS role, s.name AS store, cr.name AS register FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN stores s ON s.id = u.store_id LEFT JOIN cash_registers cr ON cr.id = u.register_id ORDER BY u.active DESC, u.full_name');
        view('users/index', ['title' => 'Utilisateurs', 'users' => $users]);
    }

    public function create(): void
    {
        view('users/form', ['title' => 'Nouvel utilisateur', 'x' => []] + $this->lists());
    }

    private function guardTarget(array $target): void
    {
        if ($target['role_id'] && DB::value('SELECT code FROM roles WHERE id = ?', [$target['role_id']]) === 'admin' && $this->user()['role_code'] !== 'admin') {
            throw new HttpException(403, 'Seul un administrateur peut modifier un compte administrateur.');
        }
    }

    private function data(?array $existing): array
    {
        $back = $existing ? '/users/' . $existing['id'] . '/edit' : '/users/create';
        $username = Request::str('username');
        $fullName = Request::str('full_name');
        $roleId = Request::int('role_id');
        if (!preg_match('/^[a-z0-9._-]{3,50}$/i', $username)) {
            $this->fail('Identifiant invalide (3 à 50 caractères : lettres, chiffres, . _ -).', $back);
        }
        if ($fullName === '' || mb_strlen($fullName) > 150) {
            $this->fail('Nom complet obligatoire.', $back);
        }
        if (DB::value('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $existing['id'] ?? 0])) {
            $this->fail('Cet identifiant est déjà utilisé.', $back);
        }
        $role = DB::one('SELECT * FROM roles WHERE id = ?', [$roleId]);
        if (!$role || ($role['code'] === 'admin' && $this->user()['role_code'] !== 'admin')) {
            $this->fail('Rôle invalide.', $back);
        }
        $email = Request::str('email');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('E-mail invalide.', $back);
        }
        $d = [
            'username' => $username, 'full_name' => $fullName, 'email' => $email ?: null, 'phone' => Request::str('phone') ?: null,
            'role_id' => $roleId, 'store_id' => Request::int('store_id') ?: null, 'register_id' => Request::int('register_id') ?: null,
        ];
        $pwd = (string) Request::input('password', '');
        if ($pwd !== '' || !$existing) {
            if ($err = Auth::passwordPolicyError($pwd, $username)) {
                $this->fail($err, $back);
            }
            $d['password_hash'] = Auth::hash($pwd);
            // Mot de passe défini par un tiers : l'utilisateur devra le changer à la première connexion
            $d['must_change_password'] = (int) ($existing['id'] ?? 0) === (int) $this->user()['id'] ? 0 : 1;
            $d['password_changed_at'] = date('Y-m-d H:i:s');
        }
        $badge = Request::str('badge');
        if ($badge !== '') {
            $hash = Auth::badgeHash($badge);
            if (DB::value('SELECT id FROM users WHERE badge_hash = ? AND id <> ?', [$hash, $existing['id'] ?? 0])) {
                $this->fail('Ce badge est déjà attribué.', $back);
            }
            $d['badge_hash'] = $hash;
        } elseif (Request::input('remove_badge') === '1') {
            $d['badge_hash'] = null;
        }
        return $d;
    }

    public function store(): void
    {
        $d = $this->data(null);
        $id = DB::insert('users', $d);
        Audit::log('user.create', 'user', $id, ['username' => $d['username'], 'role' => $d['role_id']]);
        flash('success', 'Utilisateur créé. Il devra changer son mot de passe à la première connexion.');
        redirect('/users');
    }

    public function edit(int $id): void
    {
        $x = $this->findOr404('users', $id);
        $this->guardTarget($x);
        view('users/form', ['title' => 'Modifier : ' . $x['full_name'], 'x' => $x] + $this->lists());
    }

    public function update(int $id): void
    {
        $x = $this->findOr404('users', $id);
        $this->guardTarget($x);
        $d = $this->data($x);
        // Ne jamais retirer le dernier administrateur actif
        $adminRole = (int) DB::value("SELECT id FROM roles WHERE code = 'admin'");
        if ((int) $x['role_id'] === $adminRole && (int) $d['role_id'] !== $adminRole && (int) DB::value('SELECT COUNT(*) FROM users WHERE role_id = ? AND active = 1', [$adminRole]) <= 1) {
            $this->fail('Impossible : c\'est le dernier administrateur actif.', '/users/' . $id . '/edit');
        }
        DB::update('users', $d, 'id = ?', [$id]);
        $changed = array_keys(array_filter($d, fn($v, $k) => $k !== 'password_hash' && (string) ($x[$k] ?? '') !== (string) $v, ARRAY_FILTER_USE_BOTH));
        Audit::log('user.update', 'user', $id, ['fields' => $changed, 'password_reset' => isset($d['password_hash'])]);
        flash('success', 'Utilisateur mis à jour.');
        redirect('/users');
    }

    public function toggle(int $id): void
    {
        $x = $this->findOr404('users', $id);
        $this->guardTarget($x);
        if ($id === (int) $this->user()['id']) {
            $this->fail('Vous ne pouvez pas désactiver votre propre compte.', '/users');
        }
        $adminRole = (int) DB::value("SELECT id FROM roles WHERE code = 'admin'");
        if ((int) $x['active'] === 1 && (int) $x['role_id'] === $adminRole && (int) DB::value('SELECT COUNT(*) FROM users WHERE role_id = ? AND active = 1', [$adminRole]) <= 1) {
            $this->fail('Impossible de désactiver le dernier administrateur.', '/users');
        }
        DB::update('users', ['active' => (int) $x['active'] === 1 ? 0 : 1], 'id = ?', [$id]);
        Audit::log((int) $x['active'] === 1 ? 'user.disable' : 'user.enable', 'user', $id);
        flash('success', (int) $x['active'] === 1 ? 'Compte désactivé (ses sessions sont immédiatement invalidées).' : 'Compte réactivé.');
        redirect('/users');
    }

    public function unlock(int $id): void
    {
        $this->findOr404('users', $id);
        DB::update('users', ['failed_attempts' => 0, 'locked_until' => null], 'id = ?', [$id]);
        Audit::log('user.unlock', 'user', $id);
        flash('success', 'Compte déverrouillé.');
        redirect('/users');
    }

    // ------------------------------------------------------------------ rôles
    public function roles(): void
    {
        view('users/roles', ['title' => 'Rôles & permissions', 'roles' => DB::all('SELECT r.*, (SELECT COUNT(*) FROM users WHERE role_id = r.id) AS n, (SELECT COUNT(*) FROM role_permissions WHERE role_id = r.id) AS p FROM roles r ORDER BY r.id')]);
    }

    public function roleStore(): void
    {
        $name = Request::str('name');
        if ($name === '' || mb_strlen($name) > 100) {
            $this->fail('Nom de rôle invalide.', '/roles');
        }
        $code = 'custom_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $id = DB::insert('roles', ['code' => $code, 'name' => $name, 'description' => Request::str('description') ?: null]);
        Audit::log('role.create', 'role', $id, ['name' => $name]);
        redirect('/roles/' . $id);
    }

    public function roleEdit(int $id): void
    {
        $role = $this->findOr404('roles', $id);
        $perms = [];
        foreach (DB::all('SELECT * FROM permissions ORDER BY module, id') as $p) {
            $perms[$p['module']][] = $p;
        }
        view('users/role_edit', ['title' => 'Rôle : ' . $role['name'], 'role' => $role, 'perms' => $perms, 'granted' => Auth::permissionsForRole($id)]);
    }

    public function roleUpdate(int $id): void
    {
        $role = $this->findOr404('roles', $id);
        if ($role['code'] === 'admin') {
            $this->fail('Le rôle Administrateur dispose toujours de toutes les permissions.', '/roles');
        }
        $codes = array_filter((array) Request::input('perms', []), 'is_string');
        $valid = array_column(DB::all('SELECT id, code FROM permissions'), 'id', 'code');
        $before = array_keys(Auth::permissionsForRole($id));
        DB::transaction(function () use ($id, $codes, $valid) {
            DB::run('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
            foreach ($codes as $c) {
                if (isset($valid[$c])) {
                    DB::run('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$id, $valid[$c]]);
                }
            }
            $name = Request::str('name');
            if ($name !== '') {
                DB::update('roles', ['name' => mb_substr($name, 0, 100)], 'id = ?', [$id]);
            }
        });
        Audit::log('role.permissions', 'role', $id, ['added' => array_values(array_diff($codes, $before)), 'removed' => array_values(array_diff($before, $codes))]);
        flash('success', 'Permissions enregistrées.');
        redirect('/roles');
    }
}
