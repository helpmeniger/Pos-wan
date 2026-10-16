<?php $this->layout('layouts/app'); ?>
<div class="container py-5 jn-auth">
  <h1 class="h3 mb-1">Changer mon mot de passe</h1>
  <?php if ($forced): ?>
    <div class="alert alert-warning">Votre compte utilise un mot de passe temporaire. Choisissez un mot de passe personnel pour continuer.</div>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/mot-de-passe/changer')) ?>" class="jn-card p-4" novalidate>
    <?= csrf_field() ?>
    <?= $this->partial('partials/password-field', ['name' => 'current_password', 'label' => $forced ? 'Mot de passe temporaire' : 'Mot de passe actuel', 'autocomplete' => 'current-password']) ?>
    <?= $this->partial('partials/password-field', ['name' => 'password', 'label' => 'Nouveau mot de passe', 'autocomplete' => 'new-password', 'strength' => true]) ?>
    <?= $this->partial('partials/password-field', ['name' => 'password_confirmation', 'label' => 'Confirmer le nouveau mot de passe', 'autocomplete' => 'new-password']) ?>
    <button type="submit" class="btn btn-primary" data-loading="Enregistrement…">Enregistrer</button>
  </form>
</div>
