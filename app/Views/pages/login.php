<?php $phoneLogin = App\Services\PhoneLoginService::enabled(); ?>
<div class="auth-wrap">
  <div class="card auth-card">
    <h1>Connexion</h1>
    <?php if ($phoneLogin): ?>
    <div class="segmented" role="tablist" style="margin-bottom:16px">
      <button type="button" class="active" data-tab="phone" role="tab">Client : code par SMS</button>
      <button type="button" data-tab="password" role="tab">Mot de passe</button>
    </div>
    <div data-pane="phone">
      <p class="muted small">Entrez votre numéro : vous recevez un code par SMS. Aucun mot de passe à retenir. Pas encore de compte ? Il est créé automatiquement.</p>
      <form id="phone-form" novalidate>
        <div class="field" data-field="phone"><label for="phone">Numéro de téléphone</label>
          <div class="phone-input"><span class="prefix">+227</span><input class="input" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel-national" required placeholder="90 12 34 56"></div></div>
        <label class="check"><input type="checkbox" name="remember" checked> <span>Se souvenir de moi sur cet appareil</span></label>
        <button class="btn btn-primary btn-block btn-lg" type="submit">Recevoir le code</button>
      </form>
    </div>
    <?php endif; ?>
    <div data-pane="password" <?= $phoneLogin ? 'hidden' : '' ?>>
      <p class="muted small">Chauffeurs, livreurs, commerces, entreprises et équipe MARWA : téléphone ou e-mail et mot de passe.</p>
      <form id="login-form" novalidate data-back="<?= e($back) ?>">
        <div class="field" data-field="identifier"><label for="identifier">Téléphone ou e-mail</label><input class="input" id="identifier" name="identifier" autocomplete="username" required inputmode="email" placeholder="90 12 34 56"></div>
        <div class="field" data-field="password"><label for="password">Mot de passe</label><input class="input" id="password" name="password" type="password" autocomplete="current-password" required></div>
        <label class="check"><input type="checkbox" name="remember"> <span>Se souvenir de moi sur cet appareil</span></label>
        <button class="btn btn-primary btn-block btn-lg" type="submit">Se connecter</button>
      </form>
      <div class="row between" style="margin-top:14px"><a href="<?= e(url('mot-de-passe-oublie')) ?>">Mot de passe oublié ?</a><a href="<?= e(url('inscription')) ?>">Créer un compte</a></div>
    </div>
    <p class="small muted" style="margin-top:14px">« Se souvenir de moi » : vous restez connecté jusqu'à 30 jours sur cet appareil. Ne l'utilisez pas sur un téléphone partagé.</p>
    <?php if (App\Core\Config::get('app.demo_mode')): ?>
      <div class="alert alert-info" style="margin-top:16px"><strong>Comptes de démonstration</strong> (mot de passe <code>Demo2026!</code>) :<br>
        client@marwa.demo · chauffeur@marwa.demo · livreur@marwa.demo · restaurant@marwa.demo · entreprise@marwa.demo · admin@marwa.demo<br>
        Connexion par SMS du client de démonstration : 90 00 00 01 (le code s'affiche à l'écran : aucun SMS réel en démonstration).</div>
    <?php endif; ?>
  </div>
</div>
