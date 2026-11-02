<?php $title = 'Mon profil'; ?>
<div class="mc-page-head">
    <div>
        <h1>Mon <span class="mc-accent">profil</span></h1>
        <p><?= e($user['role_name']) ?> · dernière connexion <?= e(date_fr($user['last_login_at'])) ?></p>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-6">
        <form class="mc-card h-100" method="post" action="<?= e(url('/profile')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mc-card-head"><h2><i class="bi bi-person me-2 text-primary"></i>Informations</h2></div>
            <div class="mc-card-body row g-3">
                <div class="col-12">
                    <label class="form-label" for="full_name">Nom complet</label>
                    <input class="form-control<?= invalid('full_name') ?>" id="full_name" name="full_name" value="<?= e(old('full_name', $user['full_name'])) ?>" required>
                    <?= error_for('full_name') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="email">E-mail</label>
                    <input class="form-control<?= invalid('email') ?>" id="email" name="email" type="email" value="<?= e(old('email', $user['email'])) ?>" required>
                    <?= error_for('email') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="phone">Téléphone</label>
                    <input class="form-control" id="phone" name="phone" value="<?= e(old('phone', $user['phone'])) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Identifiant</label>
                    <input class="form-control" value="<?= e($user['username']) ?>" disabled>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="theme">Thème</label>
                    <select class="form-select" id="theme" name="theme">
                        <?php foreach (['auto' => 'Automatique (système)', 'light' => 'Clair', 'dark' => 'Sombre'] as $k => $l): ?>
                            <option value="<?= e($k) ?>" <?= old('theme', $user['theme']) === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mc-card-head border-top border-bottom-0 justify-content-end">
                <button class="btn btn-primary" type="submit">Enregistrer</button>
            </div>
        </form>
    </div>
    <div class="col-lg-6">
        <form class="mc-card h-100" method="post" action="<?= e(url('/profile/password')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mc-card-head"><h2><i class="bi bi-shield-lock me-2 text-primary"></i>Changer le mot de passe</h2></div>
            <div class="mc-card-body">
                <?php if (!empty($user['must_change_password'])): ?>
                    <div class="alert alert-warning small">Un changement de mot de passe est requis avant de continuer.</div>
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label" for="current_password">Mot de passe actuel</label>
                    <input class="form-control<?= invalid('current_password') ?>" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                    <?= error_for('current_password') ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Nouveau mot de passe</label>
                    <input class="form-control<?= invalid('password') ?>" id="password" name="password" type="password" autocomplete="new-password" required>
                    <?= error_for('password') ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password_confirmation">Confirmation</label>
                    <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
                </div>
            </div>
            <div class="mc-card-head border-top border-bottom-0 justify-content-end">
                <button class="btn btn-primary" type="submit">Mettre à jour</button>
            </div>
        </form>
    </div>
    <div class="col-12">
        <div class="mc-card">
            <div class="mc-card-head"><h2><i class="bi bi-clock-history me-2 text-primary"></i>Mes dernières connexions</h2></div>
            <div class="table-responsive">
                <table class="table mc-table table-sm">
                    <thead><tr><th>Date</th><th>Résultat</th><th>IP</th><th>Navigateur</th></tr></thead>
                    <tbody>
                    <?php foreach ($logins as $l): ?>
                        <tr>
                            <td class="small text-nowrap"><?= e(date_fr($l['created_at'], 'd/m/Y H:i:s')) ?></td>
                            <td><?= $l['success'] ? '<span class="mc-status ok">Réussie</span>' : '<span class="mc-status bad">Échec</span>' ?></td>
                            <td class="small"><?= e($l['ip_address']) ?></td>
                            <td class="small text-muted text-truncate" style="max-width:320px"><?= e($l['user_agent']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
