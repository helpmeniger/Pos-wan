<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;

/**
 * Rôles et matrice de permissions.
 */
final class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $roles = Database::fetchAll(
            'SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.deleted_at IS NULL) AS users_count,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS perms_count
               FROM roles r ORDER BY r.is_system DESC, r.name'
        );
        return $this->view('roles/index', compact('roles'));
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name' => 'required|max:100',
            'slug' => 'required|regex:/^[a-z0-9_]+$/|max:50|unique:roles,slug',
            'description' => 'max:255',
        ], ['name' => 'Nom', 'slug' => 'Code', 'description' => 'Description']);
        $id = Database::insert('roles', ['name' => $data['name'], 'slug' => $data['slug'], 'description' => $data['description'] ?? null, 'is_system' => 0]);
        AuditService::log('role.create', 'role', $id, "Création du rôle {$data['name']}");
        Session::flash('success', 'Rôle créé. Définissez maintenant ses permissions.');
        return $this->redirect('/admin/roles/' . $id);
    }

    public function edit(Request $request, string $id): Response
    {
        $role = Database::fetch('SELECT * FROM roles WHERE id = :id', ['id' => (int) $id]);
        $this->notFoundUnless($role, 'Rôle introuvable.');
        $permissions = Database::fetchAll('SELECT * FROM permissions ORDER BY sort_order');
        $modules = [];
        foreach ($permissions as $p) {
            $modules[$p['module']][] = $p;
        }
        $granted = array_map('intval', array_column(
            Database::fetchAll('SELECT permission_id FROM role_permissions WHERE role_id = :r', ['r' => $role['id']]),
            'permission_id'
        ));
        return $this->view('roles/edit', compact('role', 'modules', 'granted'));
    }

    public function update(Request $request, string $id): Response
    {
        $role = Database::fetch('SELECT * FROM roles WHERE id = :id', ['id' => (int) $id]);
        $this->notFoundUnless($role, 'Rôle introuvable.');
        if ($role['slug'] === 'super_admin') {
            throw new HttpException(403, 'Le rôle super administrateur dispose toujours de toutes les permissions.');
        }
        $data = $this->validate($request, ['name' => 'required|max:100', 'description' => 'max:255'], ['name' => 'Nom']);
        $selected = array_map('intval', (array) $request->input('permissions', []));
        $valid = array_map('intval', array_column(Database::fetchAll('SELECT id FROM permissions'), 'id'));
        $selected = array_values(array_intersect($selected, $valid));

        $before = array_map('intval', array_column(Database::fetchAll('SELECT permission_id FROM role_permissions WHERE role_id = :r', ['r' => $role['id']]), 'permission_id'));

        Database::transaction(static function () use ($role, $data, $selected): void {
            Database::update('roles', ['name' => $data['name'], 'description' => $data['description'] ?? null], 'id = :id', ['id' => $role['id']]);
            Database::query('DELETE FROM role_permissions WHERE role_id = :r', ['r' => $role['id']]);
            foreach ($selected as $pid) {
                Database::insert('role_permissions', ['role_id' => $role['id'], 'permission_id' => $pid]);
            }
        });
        $slugs = static fn (array $ids) => $ids ? array_column(Database::fetchAll(
            'SELECT slug FROM permissions WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')'
        ), 'slug') : [];
        AuditService::log('role.update', 'role', (int) $role['id'], "Mise à jour des permissions du rôle {$role['name']}",
            ['removed' => $slugs(array_diff($before, $selected))], ['added' => $slugs(array_diff($selected, $before))]);
        Session::flash('success', 'Permissions enregistrées.');
        return $this->redirect('/admin/roles/' . $role['id']);
    }

    public function destroy(Request $request, string $id): Response
    {
        $role = Database::fetch('SELECT * FROM roles WHERE id = :id', ['id' => (int) $id]);
        $this->notFoundUnless($role, 'Rôle introuvable.');
        if ((int) $role['is_system'] === 1) {
            throw new HttpException(403, 'Les rôles système ne peuvent pas être supprimés.');
        }
        if ((int) Database::value('SELECT COUNT(*) FROM users WHERE role_id = :r', ['r' => $role['id']]) > 0) {
            Session::flash('danger', 'Ce rôle est attribué à des utilisateurs : réaffectez-les avant de le supprimer.');
            return $this->redirect('/admin/roles');
        }
        Database::query('DELETE FROM roles WHERE id = :id', ['id' => $role['id']]);
        AuditService::log('role.delete', 'role', (int) $role['id'], "Suppression du rôle {$role['name']}");
        Session::flash('success', 'Rôle supprimé.');
        return $this->redirect('/admin/roles');
    }
}
