<div class="auth-wrap">
  <div class="card auth-card">
    <h1>Connexion</h1>
    <p class="muted">Avec votre numéro de téléphone ou votre adresse e-mail.</p>
    <form id="login-form" novalidate data-back="<?= e($back) ?>">
      <div class="field" data-field="identifier"><label for="identifier">Téléphone ou e-mail</label><input class="input" id="identifier" name="identifier" autocomplete="username" required inputmode="email" placeholder="90 12 34 56"></div>
      <div class="field" data-field="password"><label for="password">Mot de passe</label><input class="input" id="password" name="password" type="password" autocomplete="current-password" required></div>
      <button class="btn btn-primary btn-block btn-lg" type="submit">Se connecter</button>
    </form>
    <div class="row between" style="margin-top:14px"><a href="<?= e(url('mot-de-passe-oublie')) ?>">Mot de passe oublié ?</a><a href="<?= e(url('inscription')) ?>">Créer un compte</a></div>
    <?php if (App\Core\Config::get('app.demo_mode')): ?>
      <div class="alert alert-info" style="margin-top:16px"><strong>Comptes de démonstration</strong> (mot de passe <code>Demo2026!</code>) :<br>
        client@marwa.demo · chauffeur@marwa.demo · livreur@marwa.demo · restaurant@marwa.demo · entreprise@marwa.demo · admin@marwa.demo</div>
    <?php endif; ?>
  </div>
</div>
