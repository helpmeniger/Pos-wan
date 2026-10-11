<?php
/** Gestion des utilisateurs et des rôles (administrateur général). */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$me = require_login();
require_permission('user.manage');
$roles = db_all('SELECT * FROM roles ORDER BY id');
$roleIds = array_map('intval', array_column($roles, 'id'));
$error = null;

$countAdmins = static fn () => (int) db_value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'admin_general' AND u.is_active = 1");

if (request_method() === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        $username = trim((string) ($_POST['username'] ?? ''));
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? '')) ?: null;
        $phone = trim((string) ($_POST['phone'] ?? '')) ?: null;
        $roleId = (int) ($_POST['role_id'] ?? 0);
        if (in_array($action, ['create', 'update'], true)) {
            if ($fullName === '' || mb_strlen($fullName) > 150) {
                throw new UserFacingException('Nom complet obligatoire (150 caractères max).');
            }
            if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new UserFacingException('Adresse e-mail invalide.');
            }
            if ($phone !== null && !preg_match('/^[0-9 +().\-]{6,40}$/', $phone)) {
                throw new UserFacingException('Téléphone invalide.');
            }
            if (!in_array($roleId, $roleIds, true)) {
                throw new UserFacingException('Rôle invalide.');
            }
        }
        switch ($action) {
            case 'create':
                if (!preg_match('/^[A-Za-z0-9_.\-]{3,60}$/', $username)) {
                    throw new UserFacingException('Identifiant invalide (3 à 60 caractères : lettres, chiffres, . _ -).');
                }
                if (db_value('SELECT 1 FROM users WHERE username = ?', [$username])) {
                    throw new UserFacingException('Cet identifiant est déjà utilisé.');
                }
                if ($email !== null && db_value('SELECT 1 FROM users WHERE email = ?', [$email])) {
                    throw new UserFacingException('Cette adresse e-mail est déjà utilisée.');
                }
                $pwd = (string) ($_POST['password'] ?? '');
                if (($pe = password_policy_error($pwd)) !== null) {
                    throw new UserFacingException($pe);
                }
                db_query('INSERT INTO users (username, password_hash, full_name, email, phone, role_id, is_active, must_change_password) VALUES (?, ?, ?, ?, ?, ?, 1, 1)',
                    [$username, password_hash($pwd, PASSWORD_DEFAULT), $fullName, $email, $phone, $roleId]);
                audit_log('utilisateur_creation', 'user', (int) db()->lastInsertId(), ['username' => $username, 'role_id' => $roleId]);
                flash('success', "Compte « $username » créé. L'utilisateur devra changer son mot de passe à la première connexion.");
                break;
            case 'update':
                $id = (int) ($_POST['id'] ?? 0);
                $target = db_one('SELECT u.*, r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$id]);
                if (!$target) {
                    throw new UserFacingException('Utilisateur introuvable.', 404);
                }
                $active = !empty($_POST['is_active']) ? 1 : 0;
                $newRole = db_value('SELECT code FROM roles WHERE id = ?', [$roleId]);
                if ($target['code'] === 'admin_general' && ($newRole !== 'admin_general' || !$active) && $countAdmins() <= 1 && (int) $target['is_active'] === 1) {
                    throw new UserFacingException('Impossible : il doit rester au moins un administrateur général actif.');
                }
                if ($id === (int) $me['id'] && !$active) {
                    throw new UserFacingException('Vous ne pouvez pas désactiver votre propre compte.');
                }
                if ($email !== null && db_value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $id])) {
                    throw new UserFacingException('Cette adresse e-mail est déjà utilisée.');
                }
                db_query('UPDATE users SET full_name = ?, email = ?, phone = ?, role_id = ?, is_active = ? WHERE id = ?', [$fullName, $email, $phone, $roleId, $active, $id]);
                audit_log('utilisateur_modification', 'user', $id, ['role_id' => $roleId, 'actif' => $active]);
                flash('success', 'Compte mis à jour.');
                break;
            case 'reset_password':
                $id = (int) ($_POST['id'] ?? 0);
                $pwd = (string) ($_POST['password'] ?? '');
                if (($pe = password_policy_error($pwd)) !== null) {
                    throw new UserFacingException($pe);
                }
                if (!db_value('SELECT 1 FROM users WHERE id = ?', [$id])) {
                    throw new UserFacingException('Utilisateur introuvable.', 404);
                }
                db_query('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($pwd, PASSWORD_DEFAULT), $id]);
                audit_log('utilisateur_reinit_mdp', 'user', $id);
                flash('success', 'Mot de passe réinitialisé ; il devra être changé à la prochaine connexion.');
                break;
            default:
                throw new UserFacingException('Action inconnue.');
        }
        redirect('utilisateurs.php');
    } catch (UserFacingException $e) {
        $error = $e->getMessage();
    }
}

$users = db_all('SELECT u.*, r.name AS role_name, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.is_active DESC, u.full_name');
$pageTitle = 'Utilisateurs';
$activeMenu = 'utilisateurs';
require __DIR__ . '/includes/header.php';
$roleSelect = static function (int $selected) use ($roles): string {
    $h = '<select class="form-select form-select-sm" name="role_id" aria-label="Rôle">';
    foreach ($roles as $r) {
        $h .= '<option value="' . (int) $r['id'] . '"' . ((int) $r['id'] === $selected ? ' selected' : '') . '>' . e($r['name']) . '</option>';
    }
    return $h . '</select>';
};
?>
<div class="page-header"><h1><i class="bi bi-person-gear"></i> Utilisateurs</h1></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card mb-3"><div class="card-header">Utilisateurs (<?= count($users) ?>)</div><div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Identifiant</th><th>Nom complet / e-mail / téléphone</th><th>Rôle</th><th>Actif</th><th>Dernière connexion</th><th></th><th>Réinitialiser le mot de passe</th></tr></thead><tbody>
    <?php foreach ($users as $u): ?>
    <tr class="<?= (int) $u['is_active'] ? '' : 'text-muted' ?>">
        <td><strong><?= e($u['username']) ?></strong><?= (int) $u['must_change_password'] ? '<br><span class="badge text-bg-warning">changement de mot de passe requis</span>' : '' ?></td>
        <td colspan="4">
            <form method="post" class="row g-1 align-items-center" id="uf<?= (int) $u['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <div class="col-md-3"><input class="form-control form-control-sm" name="full_name" value="<?= e($u['full_name']) ?>" required aria-label="Nom complet"></div>
                <div class="col-md-3"><input class="form-control form-control-sm" name="email" type="email" value="<?= e($u['email']) ?>" placeholder="E-mail" aria-label="E-mail"></div>
                <div class="col-md-2"><input class="form-control form-control-sm" name="phone" value="<?= e($u['phone']) ?>" placeholder="Téléphone" aria-label="Téléphone"></div>
                <div class="col-md-2"><?= $roleSelect((int) $u['role_id']) ?></div>
                <div class="col-md-1"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?= (int) $u['is_active'] ? 'checked' : '' ?> aria-label="Actif"></div></div>
                <div class="col-md-1 small text-muted"><?= e(fmt_date($u['last_login_at'], true)) ?></div>
            </form></td>
        <td><button class="btn btn-sm btn-outline-primary" form="uf<?= (int) $u['id'] ?>">Enregistrer</button></td>
        <td><form method="post" class="d-flex gap-1" data-confirm="Réinitialiser le mot de passe de <?= e($u['username']) ?> ?"><?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <input class="form-control form-control-sm" type="password" name="password" placeholder="Nouveau mot de passe" minlength="10" required autocomplete="new-password" aria-label="Nouveau mot de passe">
            <button class="btn btn-sm btn-outline-danger" title="Réinitialiser"><i class="bi bi-key"></i></button></form></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>

<div class="row g-3">
    <div class="col-lg-6"><div class="card"><div class="card-header">Créer un compte</div><div class="card-body">
        <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="create">
            <div class="col-md-6"><label class="form-label small mb-0" for="nu">Identifiant *</label><input class="form-control form-control-sm" id="nu" name="username" required pattern="[A-Za-z0-9_.\-]{3,60}"></div>
            <div class="col-md-6"><label class="form-label small mb-0" for="nf">Nom complet *</label><input class="form-control form-control-sm" id="nf" name="full_name" required maxlength="150"></div>
            <div class="col-md-6"><label class="form-label small mb-0" for="ne">E-mail</label><input class="form-control form-control-sm" id="ne" name="email" type="email"></div>
            <div class="col-md-6"><label class="form-label small mb-0" for="nt">Téléphone</label><input class="form-control form-control-sm" id="nt" name="phone"></div>
            <div class="col-md-6"><label class="form-label small mb-0">Rôle *</label><?= $roleSelect(3) ?></div>
            <div class="col-md-6"><label class="form-label small mb-0" for="np">Mot de passe provisoire *</label><input class="form-control form-control-sm" id="np" name="password" type="password" required minlength="10" autocomplete="new-password"></div>
            <div class="col-12 form-text">L'utilisateur devra choisir un nouveau mot de passe à sa première connexion. Communiquez le mot de passe provisoire par un canal sûr.</div>
            <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-person-plus"></i> Créer</button></div>
        </form></div></div></div>
    <div class="col-lg-6"><div class="card"><div class="card-header">Rôles et permissions (contrôlés côté serveur)</div><div class="card-body small">
        <?php foreach ($roles as $r): ?>
            <p class="mb-1"><strong><?= e($r['name']) ?></strong> — <?= e($r['description']) ?></p>
            <p class="text-muted"><?php $perms = ROLE_PERMISSIONS[$r['code']] ?? [];
                echo e(in_array('*', $perms, true) ? 'Toutes les permissions' : implode(' · ', array_map(static fn ($p) => PERMISSIONS[$p] ?? $p, $perms))); ?></p>
        <?php endforeach; ?>
    </div></div></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
