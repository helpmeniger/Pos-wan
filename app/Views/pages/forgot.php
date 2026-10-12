<div class="auth-wrap"><div class="card auth-card">
  <h1>Mot de passe oublié</h1>
  <p class="muted">Indiquez votre e-mail (vous recevrez un lien) ou votre téléphone (vous recevrez un code par SMS).</p>
  <form id="forgot-form" novalidate>
    <div class="field" data-field="identifier"><label for="identifier">Téléphone ou e-mail</label><input class="input" id="identifier" name="identifier" required></div>
    <button class="btn btn-primary btn-block" type="submit">Envoyer</button>
  </form>
  <form id="otp-reset-form" novalidate hidden style="margin-top:18px">
    <hr class="divider"><h3>Code reçu par SMS</h3>
    <div class="field" data-field="code"><label for="code">Code à 6 chiffres</label><input class="input" id="code" name="code" inputmode="numeric" maxlength="6"></div>
    <div class="field" data-field="password"><label for="np">Nouveau mot de passe</label><input class="input" id="np" name="password" type="password" autocomplete="new-password"></div>
    <button class="btn btn-primary btn-block" type="submit">Changer le mot de passe</button>
  </form>
  <p style="margin-top:14px"><a href="<?= e(url('connexion')) ?>">Retour à la connexion</a></p>
</div></div>
