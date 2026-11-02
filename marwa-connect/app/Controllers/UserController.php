<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;

/**
 * Gestion des comptes utilisateurs de l'application.
 */
final class UserController extends Controller
{
    private const LABELS = [
        'full_name' => 'Nom complet', 'username' => 'Identifiant', 'email' => 'E-mail',
        'phone' => 'Téléphone', 'role_id' => 'Rôle', 'password' => 'Mot de passe', 'status' => 'Statut',
    ];

    public function index(Request $request): Response
    {
        $this->authorize('users.view', 'users.manage');
        $q = $request->str('q');
        $role = $request->int('role');
        $status = $request->str('status');

        $where = ['u.deleted_at IS NULL'];
        $params = [];
        if ($q !== '') {
            $where[] = '(u.full_name LIKE :q1 OR u.username LIKE :q2 OR u.email LIKE :q3 OR u.phone LIKE :q4)';
            foreach (['q1', 'q2', 'q3', 'q4'] as $k) {
                $params[$k] = '%' . $q . '%';
            }
        }
        if ($role > 0) {
            $where[] = 'u.role_id = :role';
            $params['role'] = $role;
        }
        if (in_array($status, ['active', 'suspended', 'disabled'], true)) {
            $where[] = 'u.status = :status';
            $params['status'] = $status;
        }
        $users = Database::fetchAll(
            'SELECT u.id, u.full_name, u.username, u.email, u.phone, u.status, u.last_login_at, u.created_at,
                    r.name AS role_name, r.slug AS role_slug
               FROM users u JOIN roles r ON r.id = u.role_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY u.full_name LIMIT 500',
            $params
        );
        $roles = Database::fetchAll('SELECT id, name FROM roles ORDER BY name');
        return $this->view('users/index', compact('users', 'roles', 'q', 'role', 'status'));
    }

    public function create(Request $request): Response
    {
        $this->authorize('users.manage');
        return $this->view('users/form', ['user' => null, 'roles' => $this->assignableRoles()]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('users.manage');
        $data = $this->validate($request, [
            'full_name' => 'required|max:150',
            'username' => 'required|alpha_dash|min:3|max:60|unique:users,username',
            'email' => 'required|email|max:190|unique:users,email',
            'phone' => 'max:30',
            'role_id' => 'required|integer|exists:roles,id',
            'password' => 'required|confirmed',
        ], self::LABELS);
        $this->assertRoleAssignable((int) $data['role_id']);
        if ($err = Auth::passwordPolicyError((string) $data['password'])) {
            Session::flashErrors(['password' => $err]);
            Session::flashOld($request->all());
            return $this->back($request);
        }

        $id = Database::insert('users', [
            'role_id' => (int) $data['role_id'],
            'full_name' => $data['full_name'],
            'username' => $data['username'],
            'email' => mb_strtolower($data['email']),
            'phone' => $data['phone'] ?: null,
            'password_hash' => Auth::hash((string) $data['password']),
            'must_change_password' => $request->input('must_change_password') ? 1 : 0,
            'created_by' => Auth::id(),
        ]);
        AuditService::log('user.create', 'user', $id, "Création de l'utilisateur {$data['username']}", null, [
            'username' => $data['username'], 'email' => $data['email'], 'role_id' => $data['role_id'],
        ]);
        Session::flash('success', 'Utilisateur créé avec succès.');
        return $this->redirect('/admin/users');
    }

    public function edit(Request $request, string $id): Response
    {
        $this->authorize('users.manage');
        $user = $this->findEditable((int) $id);
        return $this->view('users/form', ['user' => $user, 'roles' => $this->assignableRoles()]);
    }

    public function update(Request $request, string $id): Response
    {
        $this->authorize('users.manage');
        $user = $this->findEditable((int) $id);
        $data = $this->validate($request, [
            'full_name' => 'required|max:150',
            'username' => 'required|alpha_dash|min:3|max:60|unique:users,username,' . $user['id'],
            'email' => 'required|email|max:190|unique:users,email,' . $user['id'],
            'phone' => 'max:30',
            'role_id' => 'required|integer|exists:roles,id',
        ], self::LABELS);
        $newRole = (int) $data['role_id'];
        $this->assertRoleAssignable($newRole);
        if ($newRole !== (int) $user['role_id']) {
            if ((int) $user['id'] === Auth::id()) {
                throw new HttpException(403, 'Vous ne pouvez pas modifier votre propre rôle.');
            }
            $this->assertNotLastSuperAdmin($user);
        }
        $changes = [
            'full_name' => $data['full_name'],
            'username' => $data['username'],
            'email' => mb_strtolower($data['email']),
            'phone' => $data['phone'] ?: null,
            'role_id' => $newRole,
        ];
        Database::update('users', $changes, 'id = :id', ['id' => $user['id']]);
        [$old, $new] = AuditService::diff($user, $changes);
        if ($new) {
            AuditService::log('user.update', 'user', (int) $user['id'], "Modification de l'utilisateur {$user['username']}", $old, $new);
        }
        Session::flash('success', 'Utilisateur mis à jour.');
        return $this->redirect('/admin/users');
    }

    public function toggleStatus(Request $request, string $id): Response
    {
        $this->authorize('users.manage');
        $user = $this->findEditable((int) $id);
        if ((int) $user['id'] === Auth::id()) {
            throw new HttpException(403, 'Vous ne pouvez pas suspendre votre propre compte.');
        }
        $newStatus = $user['status'] === 'active' ? 'suspended' : 'active';
        if ($newStatus !== 'active') {
            $this->assertNotLastSuperAdmin($user);
        }
        Database::update('users', ['status' => $newStatus], 'id = :id', ['id' => $user['id']]);
        AuditService::log('user.status', 'user', (int) $user['id'], "Statut de {$user['username']} : {$newStatus}", ['status' => $user['status']], ['status' => $newStatus]);
        Session::flash('success', $newStatus === 'active' ? 'Compte réactivé.' : 'Compte suspendu.');
        return $this->redirect('/admin/users');
    }

    public function resetPassword(Request $request, string $id): Response
    {
        $this->authorize('users.manage');
        $user = $this->findEditable((int) $id);
        $password = (string) $request->input('password', '');
        if ($err = Auth::passwordPolicyError($password)) {
            Session::flash('danger', $err);
            return $this->back($request, '/admin/users');
        }
        Database::update('users', [
            'password_hash' => Auth::hash($password),
            'must_change_password' => 1,
            'password_changed_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $user['id']]);
        AuditService::log('user.password_reset', 'user', (int) $user['id'], "Réinitialisation du mot de passe de {$user['username']}");
        Session::flash('success', "Mot de passe réinitialisé. L'utilisateur devra le changer à sa prochaine connexion.");
        return $this->redirect('/admin/users');
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->authorize('users.manage');
        $user = $this->findEditable((int) $id);
        if ((int) $user['id'] === Auth::id()) {
            throw new HttpException(403, 'Vous ne pouvez pas supprimer votre propre compte.');
        }
        $this->assertNotLastSuperAdmin($user);
        // Suppression logique : l'historique (ventes, audit) reste rattaché à l'utilisateur.
        Database::update('users', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'status' => 'disabled',
            'username' => mb_substr($user['username'] . '#del' . $user['id'], 0, 60),
            'email' => mb_substr($user['email'] . '#del' . $user['id'], 0, 190),
        ], 'id = :id', ['id' => $user['id']]);
        AuditService::log('user.delete', 'user', (int) $user['id'], "Suppression de l'utilisateur {$user['username']}");
        Session::flash('success', 'Utilisateur supprimé.');
        return $this->redirect('/admin/users');
    }

    private function findEditable(int $id): array
    {
        $user = Database::fetch(
            'SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id AND u.deleted_at IS NULL',
            ['id' => $id]
        );
        $this->notFoundUnless($user, 'Utilisateur introuvable.');
        if ($user['role_slug'] === 'super_admin' && !Auth::hasRole('super_admin')) {
            throw new HttpException(403, 'Seul un super administrateur peut modifier ce compte.');
        }
        unset($user['password_hash']);
        return $user;
    }

    private function assignableRoles(): array
    {
        $roles = Database::fetchAll('SELECT id, slug, name FROM roles ORDER BY is_system DESC, name');
        if (!Auth::hasRole('super_admin')) {
            $roles = array_values(array_filter($roles, static fn ($r) => $r['slug'] !== 'super_admin'));
        }
        return $roles;
    }

    private function assertRoleAssignable(int $roleId): void
    {
        $slug = Database::value('SELECT slug FROM roles WHERE id = :id', ['id' => $roleId]);
        if ($slug === 'super_admin' && !Auth::hasRole('super_admin')) {
            throw new HttpException(403, 'Seul un super administrateur peut attribuer ce rôle.');
        }
    }

    private function assertNotLastSuperAdmin(array $user): void
    {
        if ($user['role_slug'] !== 'super_admin') {
            return;
        }
        $count = (int) Database::value(
            "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
              WHERE r.slug = 'super_admin' AND u.status = 'active' AND u.deleted_at IS NULL"
        );
        if ($count <= 1) {
            throw new HttpException(403, 'Impossible : il doit rester au moins un super administrateur actif.');
        }
    }
}
