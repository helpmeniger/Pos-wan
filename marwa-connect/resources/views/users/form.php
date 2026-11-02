<?php
$editing = $user !== null;
$title = $editing ? 'Modifier un utilisateur' : 'Nouvel utilisateur';
$val = static fn (string $k, $d = '') => old($k, $editing ? ($user[$k] ?? $d) : $d);
?>
<div class="mc-page-head">
    <div>
        <h1><?= $editing ? 'Modifier' : 'Nouvel' ?> <span class="mc-accent">utilisateur</span></h1>
        <p><?= $editing ? e($user['full_name']) . ' — ' . e($user['username']) : 'Créer un compte et lui attribuer un rôle.' ?></p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/users')) ?>"><i class="bi bi-arrow-left"></i> Retour</a>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <form class="mc-card" method="post" action="<?= e(url($editing ? '/admin/users/' . $user['id'] : '/admin/users')) ?>" novalidate>
            <?= csrf_field() ?>
            <?= $editing ? method_field('PUT') : '' ?>
            <div class="mc-card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="full_name">Nom complet *</label>
                    <input class="form-control<?= invalid('full_name') ?>" id="full_name" name="full_name" value="<?= e($val('full_name')) ?>" required>
                    <?= error_for('full_name') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="username">Identifiant *</label>
                    <input class="form-control<?= invalid('username') ?>" id="username" name="username" value="<?= e($val('username')) ?>" autocomplete="off" required>
                    <?= error_for('username') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="email">E-mail *</label>
                    <input class="form-control<?= invalid('email') ?>" id="email" name="email" type="email" value="<?= e($val('email')) ?>" required>
                    <?= error_for('email') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="phone">Téléphone</label>
                    <input class="form-control<?= invalid('phone') ?>" id="phone" name="phone" value="<?= e($val('phone')) ?>" placeholder="+227 …">
                    <?= error_for('phone') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="role_id">Rôle *</label>
                    <select class="form-select<?= invalid('role_id') ?>" id="role_id" name="role_id" required <?= $editing && (int) $user['id'] === App\Core\Auth::id() ? 'disabled' : '' ?>>
                        <option value="">— Choisir —</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= (int) $r['id'] ?>" <?= (string) $val('role_id') === (string) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($editing && (int) $user['id'] === App\Core\Auth::id()): ?>
                        <input type="hidden" name="role_id" value="<?= (int) $user['role_id'] ?>">
                        <div class="form-text">Vous ne pouvez pas modifier votre propre rôle.</div>
                    <?php endif; ?>
                    <?= error_for('role_id') ?>
                </div>
                <?php if (!$editing): ?>
                    <div class="col-md-6"></div>
                    <div class="col-md-6">
                        <label class="form-label" for="password">Mot de passe *</label>
                        <input class="form-control<?= invalid('password') ?>" id="password" name="password" type="password" autocomplete="new-password" required>
                        <?= error_for('password') ?>
                        <div class="form-text">10 caractères minimum, lettres et chiffres.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="password_confirmation">Confirmation *</label>
                        <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="must_change_password" name="must_change_password" value="1" checked>
                            <label class="form-check-label" for="must_change_password">Exiger le changement du mot de passe à la première connexion</label>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="mc-card-head border-top border-bottom-0 justify-content-end">
                <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i> Enregistrer</button>
            </div>
        </form>
    </div>
    <?php if ($editing && (int) $user['id'] !== App\Core\Auth::id()): ?>
    <div class="col-lg-4">
        <form class="mc-card" method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/reset-password')) ?>" data-confirm="Réinitialiser le mot de passe de cet utilisateur ?">
            <?= csrf_field() ?>
            <div class="mc-card-head"><h2><i class="bi bi-key me-2 text-primary"></i>Réinitialiser le mot de passe</h2></div>
            <div class="mc-card-body">
                <label class="form-label" for="reset_password">Nouveau mot de passe temporaire</label>
                <input class="form-control" id="reset_password" name="password" type="password" autocomplete="new-password" required>
                <div class="form-text">L'utilisateur devra le changer à sa prochaine connexion.</div>
                <button class="btn btn-outline-primary w-100 mt-3" type="submit">Réinitialiser</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>
