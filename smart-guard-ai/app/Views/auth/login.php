<div class="card shadow">
  <div class="card-body p-4">
    <h2 class="h5 mb-3">Connexion</h2>
    <form method="post" action="<?= url('login') ?>" autocomplete="on">
      <?= csrf_field() ?>
      <div class="mb-3"><label class="form-label" for="username">Identifiant</label>
        <input class="form-control" id="username" name="username" required maxlength="60" autofocus autocomplete="username"></div>
      <div class="mb-3"><label class="form-label" for="password">Mot de passe</label>
        <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password"></div>
      <button class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right me-1"></i>Se connecter</button>
    </form>
  </div>
  <div class="card-footer small text-muted">Accès réservé au personnel autorisé. Les connexions sont journalisées.</div>
</div>
