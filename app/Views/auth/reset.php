<?php $this->layout('layouts/app'); ?>
<div class="container py-5 jn-auth">
  <h1 class="h3 mb-3">Choisir un nouveau mot de passe</h1>
  <form method="post" action="<?= e(url('/mot-de-passe/reinitialiser/' . $token)) ?>" class="jn-card p-4" novalidate>
    <?= csrf_field() ?>
    <?= $this->partial('partials/password-field', ['name' => 'password', 'label' => 'Nouveau mot de passe', 'autocomplete' => 'new-password', 'strength' => true]) ?>
    <?= $this->partial('partials/password-field', ['name' => 'password_confirmation', 'label' => 'Confirmer le mot de passe', 'autocomplete' => 'new-password']) ?>
    <button type="submit" class="btn btn-primary" data-loading="Enregistrement…">Enregistrer</button>
  </form>
</div>
