<div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-header"><i class="bi bi-key"></i> Changer mon mot de passe</div>
      <div class="card-body">
        <?php if ((int) $u['must_change_password'] === 1): ?><div class="alert alert-warning small">Un nouveau mot de passe est exigé avant d'utiliser l'application.</div><?php endif; ?>
        <form method="post" action="<?= e(url('/account/password')) ?>">
          <?= csrf_field() ?>
          <div class="mb-3"><label class="form-label required">Mot de passe actuel</label><input type="password" name="current_password" class="form-control" required autocomplete="current-password"></div>
          <div class="mb-3"><label class="form-label required">Nouveau mot de passe</label><input type="password" name="password" class="form-control" required minlength="<?= (int) config('app.security.password_min_length') ?>" autocomplete="new-password">
            <div class="form-text">Au moins <?= (int) config('app.security.password_min_length') ?> caractères, avec lettres et chiffres.</div></div>
          <div class="mb-3"><label class="form-label required">Confirmation</label><input type="password" name="password_confirm" class="form-control" required autocomplete="new-password"></div>
          <button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer</button>
        </form>
      </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-header"><i class="bi bi-grid-3x3-gap"></i> Code PIN de connexion rapide</div>
      <div class="card-body">
        <p class="small text-secondary">Le PIN permet de se connecter rapidement à la caisse avec le pavé numérique. Statut : <b><?= $u['pin_hash'] ? 'défini' : 'non défini' ?></b>.</p>
        <form method="post" action="<?= e(url('/account/pin')) ?>">
          <?= csrf_field() ?>
          <div class="mb-3"><label class="form-label required">Mot de passe actuel</label><input type="password" name="current_password" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Nouveau PIN (4 à 8 chiffres, vide = supprimer)</label><input type="password" name="pin" class="form-control" inputmode="numeric" pattern="\d{4,8}" maxlength="8" autocomplete="off"></div>
          <button class="btn btn-outline-primary"><i class="bi bi-check2"></i> Enregistrer le PIN</button>
        </form>
      </div></div>
  </div>
</div>
