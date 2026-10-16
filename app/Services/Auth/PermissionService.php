<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Database;

/** Contrôle d'accès par rôles et permissions (RBAC), mis en cache pour la durée de la requête. */
class PermissionService
{
    /** @var array<int, array{roles: list<string>, permissions: list<string>}> */
    private array $cache = [];

    public function __construct(private readonly Database $db)
    {
    }

    public function allows(int $userId, string $permission): bool
    {
        return in_array($permission, $this->load($userId)['permissions'], true);
    }

    public function hasRole(int $userId, string $role): bool
    {
        return in_array($role, $this->load($userId)['roles'], true);
    }

    /** @return list<string> */
    public function roles(int $userId): array
    {
        return $this->load($userId)['roles'];
    }

    /** @return list<string> */
    public function permissions(int $userId): array
    {
        return $this->load($userId)['permissions'];
    }

    public function isStaff(int $userId): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.is_staff = 1',
            [$userId]
        );
    }

    public function grantRole(int $userId, string $roleCode, ?int $grantedBy = null): void
    {
        $roleId = $this->db->fetchValue('SELECT id FROM roles WHERE code = ?', [$roleCode]);
        if ($roleId === null) {
            throw new \InvalidArgumentException("Rôle inconnu : {$roleCode}");
        }
        $this->db->query(
            'INSERT IGNORE INTO user_roles (user_id, role_id, granted_by) VALUES (?, ?, ?)',
            [$userId, (int) $roleId, $grantedBy]
        );
        unset($this->cache[$userId]);
    }

    public function flush(?int $userId = null): void
    {
        if ($userId === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$userId]);
        }
    }

    /** @return array{roles: list<string>, permissions: list<string>} */
    private function load(int $userId): array
    {
        if (!isset($this->cache[$userId])) {
            $roles = array_column($this->db->fetchAll(
                'SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?',
                [$userId]
            ), 'code');
            $permissions = array_column($this->db->fetchAll(
                'SELECT DISTINCT p.code FROM user_roles ur
                 JOIN role_permissions rp ON rp.role_id = ur.role_id
                 JOIN permissions p ON p.id = rp.permission_id
                 WHERE ur.user_id = ?',
                [$userId]
            ), 'code');
            $this->cache[$userId] = ['roles' => $roles, 'permissions' => $permissions];
        }
        return $this->cache[$userId];
    }
}
