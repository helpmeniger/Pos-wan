<?php $title = 'Connexion'; $errs = errors(); ?>
<div class="mb-4">
    <h2 class="mb-1">Connexion</h2>
    <p class="text-muted mb-0">Accédez à votre espace de gestion Wi-Fi.</p>
</div>
<?php if (!empty($errs['login'])): ?>
    <div class="alert alert-danger d-flex gap-2 align-items-center"><i class="bi bi-shield-exclamation"></i><?= e($errs['login']) ?></div>
<?php endif; ?>
<form method="post" action="<?= e(url('/login')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="login">Identifiant ou e-mail</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person"></i></span>
            <input class="form-control" id="login" name="login" value="<?= e(old('login')) ?>" autocomplete="username" required autofocus>
        </div>
    </div>
    <div class="mb-4">
        <label class="form-label" for="password">Mot de passe</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
            <button class="btn btn-outline-secondary" type="button" data-toggle-password="password" aria-label="Afficher le mot de passe"><i class="bi bi-eye"></i></button>
        </div>
    </div>
    <button class="btn btn-primary w-100 py-2 fw-semibold" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i> Se connecter</button>
</form>
<p class="text-muted small mt-4 mb-0"><i class="bi bi-shield-check me-1"></i>Connexion sécurisée — les tentatives répétées sont temporairement bloquées.</p>
