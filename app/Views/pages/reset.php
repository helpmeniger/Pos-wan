<div class="auth-wrap"><div class="card auth-card">
  <h1>Nouveau mot de passe</h1>
  <form id="reset-form" novalidate data-token="<?= e($token) ?>">
    <div class="field" data-field="password"><label for="password">Nouveau mot de passe</label><input class="input" id="password" name="password" type="password" autocomplete="new-password" required></div>
    <button class="btn btn-primary btn-block" type="submit">Enregistrer</button>
  </form>
</div></div>
