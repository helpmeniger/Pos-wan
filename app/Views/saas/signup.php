<?php $o = fn(string $k, string $d = '') => e($old[$k] ?? $d); ?>
<section class="container py-5" style="max-width:860px">
  <h1 class="h2 mb-1">Créer mon espace POS Marwa</h1>
  <p class="text-muted"><?= (int) $trialDays ?> jours d'essai gratuit, sans moyen de paiement. À la fin de l'essai, sans paiement, l'espace passe en lecture seule : vos données restent consultables.</p>
  <?php foreach ($flash as $f): ?><div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div><?php endforeach; ?>
  <form method="post" action="<?= e(url('/inscription')) ?>" class="saas-card" autocomplete="off">
    <?= csrf_field() ?>
    <div class="visually-hidden" aria-hidden="true"><label>Site web <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
    <h2 class="h6 sec">1. Votre commerce</h2>
    <div class="row g-3">
      <div class="col-md-7"><label class="form-label">Nom du commerce</label><input name="company" class="form-control" required maxlength="150" value="<?= $o('company') ?>" id="fCompany"></div>
      <div class="col-md-5"><label class="form-label">Activité</label>
        <select name="business_type" class="form-select" required>
          <?php foreach ($types as $k => [$label]): ?><option value="<?= e($k) ?>" <?= ($old['business_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-7"><label class="form-label">Adresse de votre espace</label>
        <div class="input-group"><span class="input-group-text">https://</span><input name="slug" id="fSlug" class="form-control" required pattern="[a-z0-9][a-z0-9-]{1,38}[a-z0-9]" maxlength="40" value="<?= $o('slug') ?>" placeholder="boutique-awa"><span class="input-group-text">.<?= e($domain) ?></span></div>
        <div class="form-text">Lettres minuscules, chiffres et tirets. Elle ne pourra plus être changée.</div></div>
      <div class="col-md-5"><label class="form-label">Ville</label><input name="city" class="form-control" maxlength="80" value="<?= $o('city', 'Niamey') ?>"></div>
    </div>
    <h2 class="h6 sec">2. Vous</h2>
    <div class="row g-3">
      <div class="col-md-5"><label class="form-label">Nom et prénom</label><input name="contact_name" class="form-control" required maxlength="120" value="<?= $o('contact_name') ?>"></div>
      <div class="col-md-3"><label class="form-label">Téléphone</label><input name="phone" class="form-control" required inputmode="tel" maxlength="20" value="<?= $o('phone', '+227 ') ?>"></div>
      <div class="col-md-4"><label class="form-label">E-mail <span class="text-muted small">(facultatif)</span></label><input name="email" type="email" class="form-control" maxlength="150" value="<?= $o('email') ?>"></div>
    </div>
    <h2 class="h6 sec">3. Votre compte administrateur</h2>
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label">Identifiant</label><input name="admin_username" class="form-control" required pattern="[A-Za-z0-9._\-]{3,50}" value="<?= $o('admin_username', 'admin') ?>" autocomplete="username"></div>
      <div class="col-md-4"><label class="form-label">Mot de passe</label><input name="admin_password" type="password" class="form-control" required minlength="10" autocomplete="new-password"></div>
      <div class="col-md-4"><label class="form-label">Confirmation</label><input name="admin_password_confirm" type="password" class="form-control" required minlength="10" autocomplete="new-password"></div>
      <div class="col-12 form-text mt-1">Au moins 10 caractères, avec des lettres et des chiffres. Nous ne le connaissons pas : il est enregistré sous forme chiffrée irréversible.</div>
    </div>
    <h2 class="h6 sec">4. Offre</h2>
    <div class="row g-2">
      <?php foreach ($plans as $p): ?>
      <div class="col-md-4"><label class="saas-radio"><input type="radio" name="plan_id" value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) ($old['plan_id'] ?? $selected) ? 'checked' : '' ?>>
        <span><b><?= e($p['name']) ?></b><small><?= e(number_format((float) $p['price_month'], 0, ',', ' ')) ?> FCFA / mois · <?= (int) $p['max_users'] ?> utilisateurs · <?= (int) $p['max_registers'] ?> caisse(s)</small></span></label></div>
      <?php endforeach; ?>
    </div>
    <div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="accept" value="1" id="fAccept" required><label class="form-check-label" for="fAccept">J'accepte les <a href="<?= e(url('/conditions')) ?>" target="_blank">conditions du service et le traitement de mes données</a> (loi n° 2017-28 relative à la protection des données à caractère personnel).</label></div>
    <button class="btn btn-warning btn-lg fw-semibold mt-3"><i class="bi bi-rocket-takeoff"></i> Créer mon espace</button>
  </form>
</section>
