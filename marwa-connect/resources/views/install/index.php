<?php $title = 'Installation'; $cardClass = 'wide'; ?>
<div class="mb-4">
    <h2 class="mb-1">Installation de MARWA CONNECT</h2>
    <p class="text-muted mb-0">Configuration initiale : base de données et compte super administrateur.</p>
</div>

<div class="mc-card mb-4">
    <div class="mc-card-head"><h2><i class="bi bi-clipboard-check me-2 text-primary"></i>Vérification du serveur</h2>
        <?= $ready ? '<span class="mc-status ok">Prêt</span>' : '<span class="mc-status bad">Prérequis manquants</span>' ?></div>
    <ul class="list-group list-group-flush">
        <?php foreach ($checks as $c): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                <span>
                    <i class="bi <?= $c['ok'] ? 'bi-check-circle-fill text-success' : ($c['required'] ? 'bi-x-circle-fill text-danger' : 'bi-exclamation-circle-fill text-warning') ?> me-2"></i>
                    <?= e($c['label']) ?><?= $c['required'] ? '' : ' <span class="text-muted small">(recommandé)</span>' ?>
                </span>
                <?php if ($c['detail']): ?><small class="text-muted text-end"><?= e($c['detail']) ?></small><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<form method="post" action="<?= e(url('/install')) ?>" class="mc-card" novalidate>
    <?= csrf_field() ?>
    <div class="mc-card-body">
        <h3 class="h6 fw-bold text-uppercase text-muted mb-3"><i class="bi bi-database me-1"></i> Base de données MySQL / MariaDB</h3>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label" for="db_host">Serveur</label>
                <input class="form-control<?= invalid('db_host') ?>" id="db_host" name="db_host" value="<?= e(old('db_host', '127.0.0.1')) ?>" required>
                <?= error_for('db_host') ?>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="db_port">Port</label>
                <input class="form-control<?= invalid('db_port') ?>" id="db_port" name="db_port" value="<?= e(old('db_port', '3306')) ?>" inputmode="numeric" required>
                <?= error_for('db_port') ?>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="db_name">Nom de la base</label>
                <input class="form-control<?= invalid('db_name') ?>" id="db_name" name="db_name" value="<?= e(old('db_name', 'marwa_connect')) ?>" required>
                <?= error_for('db_name') ?>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="db_user">Utilisateur</label>
                <input class="form-control<?= invalid('db_user') ?>" id="db_user" name="db_user" value="<?= e(old('db_user', 'root')) ?>" autocomplete="off" required>
                <?= error_for('db_user') ?>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="db_password">Mot de passe</label>
                <input class="form-control" id="db_password" name="db_password" type="password" autocomplete="new-password">
                <div class="form-text">WampServer : « root » sans mot de passe par défaut. Un utilisateur dédié est recommandé.</div>
            </div>
            <div class="col-12">
                <label class="form-label" for="app_url">URL de l'application</label>
                <input class="form-control<?= invalid('app_url') ?>" id="app_url" name="app_url" value="<?= e(old('app_url', $suggestedUrl)) ?>" required>
                <?= error_for('app_url') ?>
            </div>
        </div>

        <h3 class="h6 fw-bold text-uppercase text-muted mb-3"><i class="bi bi-person-badge me-1"></i> Entreprise et super administrateur</h3>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="company_name">Nom de l'entreprise</label>
                <input class="form-control<?= invalid('company_name') ?>" id="company_name" name="company_name" value="<?= e(old('company_name', 'MARWA CONNECT')) ?>" required>
                <?= error_for('company_name') ?>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="admin_name">Nom complet</label>
                <input class="form-control<?= invalid('admin_name') ?>" id="admin_name" name="admin_name" value="<?= e(old('admin_name')) ?>" required>
                <?= error_for('admin_name') ?>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="admin_username">Identifiant</label>
                <input class="form-control<?= invalid('admin_username') ?>" id="admin_username" name="admin_username" value="<?= e(old('admin_username', 'admin')) ?>" autocomplete="off" required>
                <?= error_for('admin_username') ?>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="admin_email">E-mail</label>
                <input class="form-control<?= invalid('admin_email') ?>" id="admin_email" name="admin_email" type="email" value="<?= e(old('admin_email')) ?>" required>
                <?= error_for('admin_email') ?>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="admin_password">Mot de passe</label>
                <input class="form-control<?= invalid('admin_password') ?>" id="admin_password" name="admin_password" type="password" autocomplete="new-password" required>
                <?= error_for('admin_password') ?>
                <div class="form-text">10 caractères minimum, lettres et chiffres.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="admin_password_confirmation">Confirmation</label>
                <input class="form-control" id="admin_password_confirmation" name="admin_password_confirmation" type="password" autocomplete="new-password" required>
            </div>
        </div>
    </div>
    <div class="mc-card-head border-top border-bottom-0 justify-content-end">
        <button class="btn btn-primary px-4" type="submit" <?= $ready ? '' : 'disabled' ?>><i class="bi bi-rocket-takeoff me-1"></i> Installer</button>
    </div>
</form>
