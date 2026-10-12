<?php $roles = ['client' => 'Client', 'driver' => 'Chauffeur / taximan', 'courier' => 'Livreur', 'merchant' => 'Restaurant / commerce', 'business' => 'Entreprise']; ?>
<div class="auth-wrap">
  <div class="card auth-card wide">
    <h1>Créer un compte</h1>
    <form id="register-form" novalidate>
      <div class="field" data-field="role"><span class="label">Je suis</span>
        <div class="role-grid"><?php foreach ($roles as $k => $l): ?><label><input type="radio" name="role" value="<?= e($k) ?>" <?= $k === $role ? 'checked' : '' ?>> <?= e($l) ?></label><?php endforeach; ?></div>
      </div>
      <div class="field" data-field="full_name"><label for="full_name">Nom complet *</label><input class="input" id="full_name" name="full_name" autocomplete="name" required maxlength="120"></div>
      <div class="form-row">
        <div class="field" data-field="phone"><label for="phone">Téléphone</label><input class="input" id="phone" name="phone" type="tel" autocomplete="tel" placeholder="90 12 34 56" inputmode="tel"><div class="hint">Format : 8 chiffres (Niger) ou +XXX…</div></div>
        <div class="field" data-field="email"><label for="email">E-mail</label><input class="input" id="email" name="email" type="email" autocomplete="email"></div>
      </div>
      <div data-pro hidden>
        <div class="field" data-field="business_name"><label for="business_name">Nom commercial *</label><input class="input" id="business_name" name="business_name" maxlength="150"></div>
        <div data-merchant hidden>
          <div class="field"><span class="label">Type</span><select class="input" name="merchant_kind"><option value="restaurant">Restaurant</option><option value="shop">Commerce / boutique</option></select></div>
          <div id="merchant-place"></div>
          <div id="merchant-map" class="map small" style="margin-bottom:14px"></div>
        </div>
      </div>
      <div class="field" data-field="password"><label for="password">Mot de passe *</label><input class="input" id="password" name="password" type="password" autocomplete="new-password" required minlength="8"><div class="hint">8 caractères minimum, avec au moins une lettre et un chiffre.</div></div>
      <div class="field" data-field="accept_terms"><label class="check"><input type="checkbox" name="accept_terms" required> <span>J'accepte les <a href="<?= e(url('pages/cgu')) ?>" target="_blank">conditions d'utilisation</a> et la <a href="<?= e(url('pages/confidentialite')) ?>" target="_blank">politique de confidentialité</a>.</span></label></div>
      <div class="alert alert-info" data-pro-note hidden>Votre compte professionnel sera activé après vérification de vos documents par l'équipe MARWA.</div>
      <button class="btn btn-primary btn-block btn-lg" type="submit">Créer mon compte</button>
    </form>
    <p style="margin-top:14px">Déjà inscrit ? <a href="<?= e(url('connexion')) ?>">Se connecter</a></p>
  </div>
</div>
