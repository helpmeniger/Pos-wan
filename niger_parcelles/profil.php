<?php
/** Changement de son propre mot de passe. */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$error = null;
if (request_method() === 'POST') {
    require_csrf();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $hash = (string) db_value('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
    if (!password_verify($current, $hash)) {
        $error = 'Mot de passe actuel incorrect.';
    } elseif ($new !== $confirm) {
        $error = 'Les deux nouveaux mots de passe ne correspondent pas.';
    } elseif (($p = password_policy_error($new)) !== null) {
        $error = $p;
    } else {
        db_query('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);
        audit_log('mot_de_passe_modifie', 'user', (int) $user['id']);
        flash('success', 'Votre mot de passe a été modifié.');
        redirect('dashboard.php');
    }
}

$pageTitle = 'Mon mot de passe';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header"><h1><i class="bi bi-key"></i> Mon mot de passe</h1></div>
<?php if ((int) $user['must_change_password'] === 1): ?>
    <div class="alert alert-warning">Un administrateur a demandé que vous changiez votre mot de passe.</div>
<?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card" style="max-width:520px">
    <div class="card-body">
        <form method="post">
            <?= csrf_field() ?>
            <div class="mb-3"><label class="form-label" for="cp">Mot de passe actuel</label>
                <input class="form-control" type="password" id="cp" name="current_password" required autocomplete="current-password"></div>
            <div class="mb-3"><label class="form-label" for="np">Nouveau mot de passe</label>
                <input class="form-control" type="password" id="np" name="new_password" required minlength="10" autocomplete="new-password">
                <div class="form-text">10 caractères minimum, au moins une lettre et un chiffre.</div></div>
            <div class="mb-3"><label class="form-label" for="np2">Confirmation</label>
                <input class="form-control" type="password" id="np2" name="confirm_password" required minlength="10" autocomplete="new-password"></div>
            <button class="btn btn-primary">Enregistrer</button>
        </form>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
