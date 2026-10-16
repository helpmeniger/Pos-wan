<?php $this->layout('layouts/app');
$currentType = old('account_type', $selectedType);
if ($currentType === 'CLIENT_PRO') { $currentType = 'BUSINESS'; }
?>
<div class="container py-4 jn-auth-wide">
  <h1 class="h3 mb-1">Créer un compte</h1>
  <p class="text-muted">Gratuit, avec votre numéro de téléphone. Déjà inscrit ? <a href="<?= e(url('/connexion')) ?>">Connectez-vous</a>.</p>
  <form method="post" action="<?= e(url('/inscription')) ?>" class="jn-card p-4" novalidate>
    <?= csrf_field() ?>
    <fieldset class="mb-4">
      <legend class="form-label fw-semibold fs-6">Vous êtes…</legend>
      <div class="row g-2">
        <?php foreach ($accountTypes as $value => $label): ?>
          <div class="col-md-4">
            <label class="account-type-option d-flex gap-2 h-100">
              <input class="form-check-input mt-1" type="radio" name="account_type" value="<?= e($value) ?>"<?= $currentType === $value ? ' checked' : '' ?>>
              <span class="small"><?= e($label) ?></span>
            </label>
          </div>
        <?php endforeach; ?>
      </div>
      <?= $this->partial('partials/field-error', ['name' => 'account_type']) ?>
    </fieldset>

    <div class="row g-3" data-show-for="BUSINESS">
      <div class="col-md-7">
        <label class="form-label" for="business_name">Nom de l'organisation</label>
        <input class="<?= e(field_class('business_name')) ?>" id="business_name" name="business_name" value="<?= e(old('business_name')) ?>" maxlength="160" autocomplete="organization">
        <?= $this->partial('partials/field-error', ['name' => 'business_name']) ?>
      </div>
      <div class="col-md-5">
        <label class="form-label" for="business_kind">Type d'organisation</label>
        <select class="<?= e(field_class('business_kind', 'form-select')) ?>" id="business_kind" name="business_kind">
          <option value="">— Choisir —</option>
          <?php foreach ($businessKinds as $k => $v): ?><option value="<?= e($k) ?>"<?= old('business_kind') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
        <?= $this->partial('partials/field-error', ['name' => 'business_kind']) ?>
      </div>
      <div class="col-12"><hr class="my-1"></div>
    </div>

    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label" for="first_name">Prénom</label>
        <input class="<?= e(field_class('first_name')) ?>" id="first_name" name="first_name" value="<?= e(old('first_name')) ?>" required maxlength="80" autocomplete="given-name">
        <?= $this->partial('partials/field-error', ['name' => 'first_name']) ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="last_name">Nom</label>
        <input class="<?= e(field_class('last_name')) ?>" id="last_name" name="last_name" value="<?= e(old('last_name')) ?>" required maxlength="80" autocomplete="family-name">
        <?= $this->partial('partials/field-error', ['name' => 'last_name']) ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="phone">Téléphone</label>
        <div class="input-group has-validation">
          <span class="input-group-text">+227</span>
          <input class="<?= e(field_class('phone')) ?>" id="phone" name="phone" type="tel" inputmode="tel" value="<?= e(old('phone')) ?>" required placeholder="90 12 34 56" autocomplete="tel-national">
          <?= $this->partial('partials/field-error', ['name' => 'phone']) ?>
        </div>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="email">E-mail <?= $emailRequired ? '' : '<span class="text-muted small">(facultatif)</span>' ?></label>
        <input class="<?= e(field_class('email')) ?>" id="email" name="email" type="email" value="<?= e(old('email')) ?>"<?= $emailRequired ? ' required' : '' ?> maxlength="190" autocomplete="email">
        <?= $this->partial('partials/field-error', ['name' => 'email']) ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="city_id">Ville</label>
        <select class="<?= e(field_class('city_id', 'form-select')) ?>" id="city_id" name="city_id" required>
          <option value="">— Choisir —</option>
          <?php foreach ($cities as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (string) old('city_id') === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select>
        <?= $this->partial('partials/field-error', ['name' => 'city_id']) ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="birth_date">Date de naissance <span class="text-muted small" data-show-for="CLIENT,BUSINESS">(facultatif)</span></label>
        <input class="<?= e(field_class('birth_date')) ?>" id="birth_date" name="birth_date" type="date" value="<?= e(old('birth_date')) ?>" max="<?= e(date('Y-m-d')) ?>" autocomplete="bday">
        <div class="form-text">Jamais affichée publiquement ; sert à vérifier l'âge minimum de certaines missions.</div>
        <?= $this->partial('partials/field-error', ['name' => 'birth_date']) ?>
      </div>
      <div class="col-md-6">
        <?= $this->partial('partials/password-field', ['name' => 'password', 'label' => 'Mot de passe', 'autocomplete' => 'new-password', 'strength' => true]) ?>
      </div>
      <div class="col-md-6">
        <?= $this->partial('partials/password-field', ['name' => 'password_confirmation', 'label' => 'Confirmer le mot de passe', 'autocomplete' => 'new-password']) ?>
      </div>
    </div>

    <div class="form-check mt-2">
      <input class="form-check-input<?= error_for('accept_terms') ? ' is-invalid' : '' ?>" type="checkbox" value="1" id="accept_terms" name="accept_terms"<?= old('accept_terms') ? ' checked' : '' ?> required>
      <label class="form-check-label small" for="accept_terms">J'accepte les <a href="<?= e(url('/conditions-utilisation')) ?>" target="_blank" rel="noopener">conditions d'utilisation</a>, y compris les règles de commission.</label>
      <?= $this->partial('partials/field-error', ['name' => 'accept_terms']) ?>
    </div>
    <div class="form-check mt-2">
      <input class="form-check-input<?= error_for('accept_privacy') ? ' is-invalid' : '' ?>" type="checkbox" value="1" id="accept_privacy" name="accept_privacy"<?= old('accept_privacy') ? ' checked' : '' ?> required>
      <label class="form-check-label small" for="accept_privacy">J'ai lu la <a href="<?= e(url('/confidentialite')) ?>" target="_blank" rel="noopener">politique de confidentialité</a>.</label>
      <?= $this->partial('partials/field-error', ['name' => 'accept_privacy']) ?>
    </div>
    <button type="submit" class="btn btn-primary btn-lg mt-4 w-100" data-loading="Création du compte…">Créer mon compte</button>
  </form>
</div>
