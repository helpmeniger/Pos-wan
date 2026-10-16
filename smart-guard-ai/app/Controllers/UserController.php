<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class UserController extends Controller
{
    public function index(Request $r): void
    {
        $users = Database::fetchAll("SELECT u.*, GROUP_CONCAT(DISTINCT r.name SEPARATOR ', ') roles, GROUP_CONCAT(DISTINCT s.name SEPARATOR ', ') stores
            FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
            LEFT JOIN store_users su ON su.user_id = u.id LEFT JOIN stores s ON s.id = su.store_id
            WHERE u.deleted_at IS NULL GROUP BY u.id ORDER BY u.full_name");
        $roles = Database::fetchAll('SELECT * FROM roles ORDER BY id');
        $perms = Database::fetchAll('SELECT * FROM permissions ORDER BY module, code');
        $matrix = [];
        foreach (Database::fetchAll('SELECT role_id, permission_id FROM role_permissions') as $rp) {
            $matrix[$rp['role_id']][$rp['permission_id']] = true;
        }
        $this->view('users/index', ['title' => 'Utilisateurs et droits', 'users' => $users, 'roles' => $roles, 'perms' => $perms, 'matrix' => $matrix,
            'stores' => Database::fetchAll('SELECT * FROM stores ORDER BY id')]);
    }

    public function create(Request $r): void
    {
        $this->view('users/form', ['title' => 'Nouvel utilisateur', 'u' => ['is_active' => 1], 'userRoles' => [], 'userStores' => [Auth::storeId()]] + $this->lists());
    }

    public function edit(Request $r): void
    {
        $u = Database::fetch('SELECT * FROM users WHERE id = :id AND deleted_at IS NULL', ['id' => (int) $r->param('id')]);
        if (!$u) {
            throw new HttpException(404);
        }
        $this->view('users/form', ['title' => 'Modifier : ' . $u['full_name'], 'u' => $u,
            'userRoles' => array_map('intval', array_column(Database::fetchAll('SELECT role_id FROM user_roles WHERE user_id = :u', ['u' => $u['id']]), 'role_id')),
            'userStores' => array_map('intval', array_column(Database::fetchAll('SELECT store_id FROM store_users WHERE user_id = :u', ['u' => $u['id']]), 'store_id'))] + $this->lists());
    }

    private function lists(): array
    {
        return ['roles' => Database::fetchAll('SELECT * FROM roles ORDER BY id'), 'stores' => Database::fetchAll('SELECT * FROM stores WHERE is_active = 1 ORDER BY id')];
    }

    public function store(Request $r): void
    {
        $this->save($r, null);
    }

    public function update(Request $r): void
    {
        $this->save($r, (int) $r->param('id'));
    }

    private function save(Request $r, ?int $id): void
    {
        $v = Validator::make($r->all(), ['username' => 'required|string|min:3|max:60|regex:/^[a-zA-Z0-9._-]+$/', 'full_name' => 'required|string|max:120',
            'email' => 'email|max:190', 'phone' => 'string|max:30', 'is_active' => 'bool', 'must_change_password' => 'bool']);
        if ($v->fails()) {
            $this->back('error', 'Formulaire invalide : ' . $v->firstError());
        }
        $d = $v->data;
        $pwd = (string) $r->input('password', '');
        if ($id === null || $pwd !== '') {
            if ($err = Auth::passwordPolicyError($pwd)) {
                $this->back('error', $err);
            }
            $d['password_hash'] = Auth::hashPassword($pwd);
        }
        if ($id === Auth::id() && !$d['is_active']) {
            $this->back('error', 'Vous ne pouvez pas désactiver votre propre compte.');
        }
        $roleIds = array_map('intval', (array) $r->input('roles', []));
        $storeIds = array_map('intval', (array) $r->input('stores', []));
        if (!$roleIds) {
            $this->back('error', 'Attribuez au moins un rôle.');
        }
        $adminRole = (int) Database::value("SELECT id FROM roles WHERE code = 'admin'");
        try {
            Database::transaction(static function () use (&$id, $d, $roleIds, $storeIds, $adminRole) {
                if ($id === null) {
                    $id = Database::insert('users', $d + ['uuid' => uuid4()]);
                } else {
                    Database::update('users', $d, 'id = :id', ['id' => $id]);
                }
                Database::execute('DELETE FROM user_roles WHERE user_id = :u', ['u' => $id]);
                foreach ($roleIds as $rid) {
                    Database::execute('INSERT IGNORE INTO user_roles (user_id, role_id) SELECT :u, id FROM roles WHERE id = :r', ['u' => $id, 'r' => $rid]);
                }
                Database::execute('DELETE FROM store_users WHERE user_id = :u', ['u' => $id]);
                foreach ($storeIds as $sid) {
                    Database::execute('INSERT IGNORE INTO store_users (store_id, user_id) SELECT id, :u FROM stores WHERE id = :s', ['u' => $id, 's' => $sid]);
                }
                $admins = (int) Database::value('SELECT COUNT(*) FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE ur.role_id = :r AND u.is_active = 1 AND u.deleted_at IS NULL', ['r' => $adminRole]);
                if ($admins === 0) {
                    throw new \DomainException('Il doit rester au moins un administrateur actif.');
                }
            });
        } catch (\PDOException $e) {
            $this->back('error', 'Identifiant déjà utilisé.');
        } catch (\DomainException $e) {
            $this->back('error', $e->getMessage());
        }
        Audit::log($id ? 'utilisateur_enregistre' : 'utilisateur_cree', 'user', $id, ['roles' => $roleIds, 'magasins' => $storeIds, 'actif' => $d['is_active']]);
        $this->flash('success', 'Utilisateur enregistré.');
        Response::redirect('users');
    }

    /** Matrice rôles / permissions configurable (le rôle administrateur garde toujours tous les droits). */
    public function saveRoles(Request $r): void
    {
        $matrix = (array) $r->input('perm', []);
        Database::transaction(static function () use ($matrix) {
            foreach (Database::fetchAll("SELECT id FROM roles WHERE code <> 'admin'") as $role) {
                Database::execute('DELETE FROM role_permissions WHERE role_id = :r', ['r' => $role['id']]);
                foreach (array_keys((array) ($matrix[$role['id']] ?? [])) as $pid) {
                    Database::execute('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT :r, id FROM permissions WHERE id = :p', ['r' => $role['id'], 'p' => (int) $pid]);
                }
            }
        });
        Audit::log('droits_modifies', 'role', null);
        $this->back('success', 'Droits enregistrés (appliqués immédiatement).', 'users');
    }

    public function saveStore(Request $r): void
    {
        $v = Validator::make($r->all(), ['id' => 'int', 'code' => 'required|string|max:30|regex:/^[A-Za-z0-9_-]+$/', 'name' => 'required|string|max:120',
            'city' => 'string|max:80', 'address' => 'string|max:255', 'phone' => 'string|max:30', 'is_active' => 'bool']);
        if ($v->fails()) {
            $this->back('error', $v->firstError(), 'users');
        }
        $d = $v->data;
        $id = $d['id'];
        unset($d['id']);
        try {
            if ($id) {
                Database::update('stores', $d, 'id = :id', ['id' => $id]);
            } else {
                $id = Database::insert('stores', $d);
                Database::execute('INSERT IGNORE INTO store_users (store_id, user_id) VALUES (:s, :u)', ['s' => $id, 'u' => Auth::id()]);
            }
        } catch (\PDOException $e) {
            $this->back('error', 'Code magasin déjà utilisé.', 'users');
        }
        Audit::log('magasin_enregistre', 'store', $id, $d);
        $this->back('success', 'Magasin enregistré.', 'users');
    }
}
