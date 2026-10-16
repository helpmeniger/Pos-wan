<?php $this->layout('layouts/app'); ?>
<div class="container py-5 jn-auth">
  <h1 class="h3 mb-1">Connexion</h1>
  <p class="text-muted">Pas encore de compte ? <a href="<?= e(url('/inscription')) ?>">Inscrivez-vous gratuitement</a>.</p>
  <form method="post" action="<?= e(url('/connexion')) ?>" class="jn-card p-4" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
      <label for="login" class="form-label">Téléphone ou e-mail</label>
      <input class="<?= e(field_class('login')) ?>" id="login" name="login" value="<?= e(old('login')) ?>" required maxlength="190"
             autocomplete="username" inputmode="text" placeholder="90 12 34 56" autofocus>
      <?= $this->partial('partials/field-error', ['name' => 'login']) ?>
    </div>
    <?= $this->partial('partials/password-field', ['name' => 'password', 'label' => 'Mot de passe', 'autocomplete' => 'current-password']) ?>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <button type="submit" class="btn btn-primary" data-loading="Connexion…"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Se connecter</button>
      <a href="<?= e(url('/mot-de-passe/oublie')) ?>" class="small">Mot de passe oublié ?</a>
    </div>
  </form>
</div>
