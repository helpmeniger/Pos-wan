<?php $this->layout('layouts/app');
$currentType = old('account_type', $selectedType);
$icons = ['WORKER' => 'person-workspace', 'CLIENT' => 'person-check', 'BUSINESS' => 'building'];
?>
<div class="container py-4 jn-auth">
  <h1 class="h3 mb-1">Créer mon compte</h1>
  <p class="text-muted">Gratuit, en 1 minute, avec votre numéro de téléphone. Déjà inscrit ? <a href="<?= e(url('/connexion')) ?>">Connexion</a></p>
  <form method="post" action="<?= e(url('/inscription')) ?>" class="jn-card p-4" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <fieldset class="mb-3">
      <legend class="visually-hidden">Type de compte</legend>
      <div class="d-grid gap-2">
        <?php foreach ($accountTypes as $value => $label): ?>
          <label class="account-type-option d-flex gap-2 align-items-center">
            <input class="form-check-input mt-0" type="radio" name="account_type" value="<?= e($value) ?>"<?= $currentType === $value ? ' checked' : '' ?>>
            <i class="bi bi-<?= e($icons[$value]) ?> text-primary" aria-hidden="true"></i>
            <span class="fw-semibold"><?= e($label) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <?= $this->partial('partials/field-error', ['name' => 'account_type']) ?>
    </fieldset>

    <div data-show-for="BUSINESS" class="mb-1">
      <div class="mb-3">
        <label class="form-label" for="business_name">Nom de l'entreprise / organisation</label>
        <input class="<?= e(field_class('business_name')) ?>" id="business_name" name="business_name" value="<?= e(old('business_name')) ?>" maxlength="160" autocomplete="organization">
        <?= $this->partial('partials/field-error', ['name' => 'business_name']) ?>
      </div>
      <div class="mb-3">
        <label class="form-label" for="business_kind">Secteur</label>
        <select class="<?= e(field_class('business_kind', 'form-select')) ?>" id="business_kind" name="business_kind">
          <option value="">— Choisir —</option>
          <?php foreach ($businessKinds as $k => $v): ?><option value="<?= e($k) ?>"<?= old('business_kind') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
        <?= $this->partial('partials/field-error', ['name' => 'business_kind']) ?>
      </div>
    </div>

    <div class="row g-2">
      <div class="col-6 mb-2">
        <label class="form-label" for="first_name">Prénom</label>
        <input class="<?= e(field_class('first_name')) ?>" id="first_name" name="first_name" value="<?= e(old('first_name')) ?>" required maxlength="80" autocomplete="given-name">
        <?= $this->partial('partials/field-error', ['name' => 'first_name']) ?>
      </div>
      <div class="col-6 mb-2">
        <label class="form-label" for="last_name">Nom</label>
        <input class="<?= e(field_class('last_name')) ?>" id="last_name" name="last_name" value="<?= e(old('last_name')) ?>" required maxlength="80" autocomplete="family-name">
        <?= $this->partial('partials/field-error', ['name' => 'last_name']) ?>
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label" for="phone">Téléphone</label>
      <div class="input-group has-validation">
        <span class="input-group-text">+227</span>
        <input class="<?= e(field_class('phone')) ?>" id="phone" name="phone" type="tel" inputmode="tel" value="<?= e(old('phone')) ?>" required placeholder="90 12 34 56" autocomplete="tel-national">
        <?= $this->partial('partials/field-error', ['name' => 'phone']) ?>
      </div>
      <div class="form-text">Il vous servira à vous connecter.</div>
    </div>
    <div class="mb-3">
      <label class="form-label" for="city_id">Ville</label>
      <select class="<?= e(field_class('city_id', 'form-select')) ?>" id="city_id" name="city_id" required>
        <?php foreach ($cities as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (string) old('city_id', (string) $cities[0]['id']) === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
      <?= $this->partial('partials/field-error', ['name' => 'city_id']) ?>
    </div>
    <?= $this->partial('partials/password-field', ['name' => 'password', 'label' => 'Mot de passe', 'autocomplete' => 'new-password', 'strength' => true]) ?>

    <details class="mb-3"<?= error_for('email') || error_for('document') || error_for('doc_type') ? ' open' : '' ?>>
      <summary class="small text-primary fw-semibold">Ajouter mon document d'identité ou professionnel (facultatif, recommandé)</summary>
      <div class="pt-3">
        <p class="small text-muted">Un profil vérifié inspire confiance : l'équipe JOB NIGER contrôle le document puis affiche le badge
          « Profil vérifié ». Le document reste privé et n'est jamais publié.</p>
        <div class="mb-2">
          <label class="form-label" for="doc_type">Type de document</label>
          <select class="<?= e(field_class('doc_type', 'form-select')) ?>" id="doc_type" name="doc_type">
            <?php foreach ($docTypes as $k => $v): ?><option value="<?= e($k) ?>"<?= old('doc_type') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label" for="document">Photo ou PDF (5 Mo maximum)</label>
          <input class="<?= e(field_class('document')) ?>" type="file" id="document" name="document" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment">
          <?= $this->partial('partials/field-error', ['name' => 'document']) ?>
        </div>
        <div class="mb-1">
          <label class="form-label" for="email">E-mail (facultatif)</label>
          <input class="<?= e(field_class('email')) ?>" id="email" name="email" type="email" value="<?= e(old('email')) ?>"<?= $emailRequired ? ' required' : '' ?> maxlength="190" autocomplete="email">
          <?= $this->partial('partials/field-error', ['name' => 'email']) ?>
        </div>
      </div>
    </details>

    <div class="form-check mb-3">
      <input class="form-check-input<?= error_for('accept_terms') ? ' is-invalid' : '' ?>" type="checkbox" value="1" id="accept_terms" name="accept_terms"<?= old('accept_terms') ? ' checked' : '' ?> required>
      <label class="form-check-label small" for="accept_terms">J'accepte les <a href="<?= e(url('/conditions-utilisation')) ?>" target="_blank" rel="noopener">conditions d'utilisation</a>
        et la <a href="<?= e(url('/confidentialite')) ?>" target="_blank" rel="noopener">politique de confidentialité</a>.</label>
      <?= $this->partial('partials/field-error', ['name' => 'accept_terms']) ?>
    </div>
    <button type="submit" class="btn btn-primary btn-lg w-100" data-loading="Création du compte…">Créer mon compte</button>
  </form>
</div>
