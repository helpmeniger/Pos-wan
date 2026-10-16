<div class="card" style="max-width:520px"><div class="card-body">
<form method="post" action="<?= url('account/password') ?>"><?= csrf_field() ?>
  <div class="mb-3"><label class="form-label">Mot de passe actuel</label><input type="password" name="current_password" class="form-control" required autocomplete="current-password"></div>
  <div class="mb-3"><label class="form-label">Nouveau mot de passe</label><input type="password" name="new_password" class="form-control" required minlength="10" autocomplete="new-password">
    <div class="form-text">10 caractères minimum, avec des lettres et des chiffres.</div></div>
  <div class="mb-3"><label class="form-label">Confirmation</label><input type="password" name="new_password_confirm" class="form-control" required autocomplete="new-password"></div>
  <button class="btn btn-primary">Enregistrer</button>
</form></div></div>
