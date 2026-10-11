<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>
<form method="post" action="<?= e(url('auth.login.submit')) ?>" autocomplete="off" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="username">Identifiant</label>
        <input class="form-control" id="username" name="username" value="<?= e($username) ?>" required maxlength="60" autofocus autocapitalize="none">
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Mot de passe</label>
        <input class="form-control" id="password" name="password" type="password" required maxlength="1024" autocomplete="current-password">
    </div>
    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-box-arrow-in-right"></i> Se connecter</button>
</form>
