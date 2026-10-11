<?php use App\Core\Auth; use App\Core\Settings; ?>
<div class="page-header"><h1><i class="bi bi-key"></i> Changer le mot de passe</h1></div>
<?php if ((int) (Auth::user()['must_change_password'] ?? 0)): ?>
    <div class="alert alert-warning">Vous devez définir un mot de passe personnel avant de continuer.</div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<div class="card"><div class="card-body">
<form method="post" action="<?= e(url('auth.password.save')) ?>" class="row g-3" autocomplete="off">
    <?= csrf_field() ?>
    <div class="col-md-6 col-lg-4">
        <label class="form-label" for="current_password">Mot de passe actuel</label>
        <input class="form-control" id="current_password" name="current_password" type="password" required autocomplete="current-password">
    </div>
    <div class="w-100"></div>
    <div class="col-md-6 col-lg-4">
        <label class="form-label" for="new_password">Nouveau mot de passe</label>
        <input class="form-control" id="new_password" name="new_password" type="password" required autocomplete="new-password" minlength="<?= Settings::int('password_min_length') ?>">
        <div class="form-text">Au moins <?= Settings::int('password_min_length') ?> caractères, combinant trois types parmi : minuscules, majuscules, chiffres, symboles.</div>
    </div>
    <div class="col-md-6 col-lg-4">
        <label class="form-label" for="confirm_password">Confirmation</label>
        <input class="form-control" id="confirm_password" name="confirm_password" type="password" required autocomplete="new-password">
    </div>
    <div class="col-12"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
</form>
</div></div>
