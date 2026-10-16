<?php $this->layout('layouts/app'); ?>
<div class="container py-5 jn-auth">
  <h1 class="h3 mb-1">Mot de passe oublié</h1>
  <p class="text-muted">Indiquez votre numéro de téléphone ou votre e-mail : vous recevrez un lien pour choisir un nouveau mot de passe.</p>
  <form method="post" action="<?= e(url('/mot-de-passe/oublie')) ?>" class="jn-card p-4" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
      <label for="login" class="form-label">Téléphone ou e-mail</label>
      <input class="<?= e(field_class('login')) ?>" id="login" name="login" value="<?= e(old('login')) ?>" required maxlength="190" autocomplete="username">
      <?= $this->partial('partials/field-error', ['name' => 'login']) ?>
    </div>
    <button type="submit" class="btn btn-primary" data-loading="Envoi…">Recevoir le lien</button>
    <a href="<?= e(url('/connexion')) ?>" class="btn btn-link">Retour à la connexion</a>
  </form>
</div>
