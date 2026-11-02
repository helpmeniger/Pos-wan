<?php
/**
 * Rôles et permissions. Idempotent : n'écrase pas les permissions modifiées par
 * l'administrateur sur un rôle existant ; ajoute uniquement les nouvelles permissions
 * aux rôles nouvellement créés. Le super administrateur reçoit toujours tout.
 */
return static function (PDO $pdo): void {
    $catalog = require BASE_PATH . '/config/permissions.php';

    $insPerm = $pdo->prepare('INSERT INTO permissions (slug, name, module, sort_order) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name = VALUES(name), module = VALUES(module), sort_order = VALUES(sort_order)');
    $order = 0;
    $all = [];
    foreach ($catalog['modules'] as $module => $perms) {
        foreach ($perms as $slug => $name) {
            $insPerm->execute([$slug, $name, $module, $order++]);
            $all[] = $slug;
        }
    }
    $permIds = $pdo->query('SELECT slug, id FROM permissions')->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach ($catalog['roles'] as $slug => $role) {
        $existing = $pdo->prepare('SELECT id FROM roles WHERE slug = ?');
        $existing->execute([$slug]);
        $roleId = $existing->fetchColumn();
        $isNew = $roleId === false;
        if ($isNew) {
            $pdo->prepare('INSERT INTO roles (slug, name, description, is_system) VALUES (?, ?, ?, 1)')
                ->execute([$slug, $role['name'], $role['description']]);
            $roleId = (int) $pdo->lastInsertId();
        }
        if (!$isNew && $slug !== 'super_admin') {
            continue;
        }
        $granted = [];
        foreach ($role['permissions'] as $p) {
            if ($p === '*') {
                $granted = $all;
            } elseif (str_starts_with($p, '-')) {
                $granted = array_diff($granted, [substr($p, 1)]);
            } else {
                $granted[] = $p;
            }
        }
        $ins = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
        foreach (array_unique($granted) as $p) {
            if (isset($permIds[$p])) {
                $ins->execute([$roleId, $permIds[$p]]);
            }
        }
    }
};
