<?php $this->layout('layouts/app'); $cur = null; ?>
<div class="container py-4" style="max-width:820px">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h3 mb-0">Mon profil</h1>
    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/verification')) ?>"><i class="bi bi-patch-check" aria-hidden="true"></i> Vérification <?= (int) $p['verification_level'] >= 2 ? '(vérifié)' : '' ?></a>
  </div>
  <form method="post" action="<?= e(url('/profil')) ?>" class="jn-card p-4" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-6"><label class="form-label" for="first_name">Prénom</label><input class="<?= e(field_class('first_name')) ?>" id="first_name" name="first_name" value="<?= e(old('first_name', $p['first_name'])) ?>" required maxlength="80"><?= $this->partial('partials/field-error', ['name' => 'first_name']) ?></div>
      <div class="col-6"><label class="form-label" for="last_name">Nom</label><input class="<?= e(field_class('last_name')) ?>" id="last_name" name="last_name" value="<?= e(old('last_name', $p['last_name'])) ?>" required maxlength="80"><?= $this->partial('partials/field-error', ['name' => 'last_name']) ?></div>
      <div class="col-md-6"><label class="form-label" for="city_id">Ville</label>
        <select class="form-select" id="city_id" name="city_id"><?php foreach ($cities as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) old('city_id', $p['city_id']) === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-6"><label class="form-label" for="email">E-mail (facultatif)</label><input class="<?= e(field_class('email')) ?>" id="email" name="email" type="email" value="<?= e(old('email', $p['email'] ?? '')) ?>"><?= $this->partial('partials/field-error', ['name' => 'email']) ?></div>
      <div class="col-md-6"><label class="form-label" for="birth_date">Date de naissance</label>
        <input class="<?= e(field_class('birth_date')) ?>" id="birth_date" name="birth_date" type="date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('birth_date', $p['birth_date'] ?? '')) ?>"<?= $p['birth_date'] ? ' disabled' : '' ?>>
        <div class="form-text"><?= $p['birth_date'] ? 'Enregistrée. Pour la corriger, contactez le support.' : 'Jamais affichée publiquement.' ?></div><?= $this->partial('partials/field-error', ['name' => 'birth_date']) ?></div>
      <?php if ($isWorker): ?>
        <div class="col-md-6"><label class="form-label" for="headline">Métier principal (affiché aux recruteurs)</label><input class="form-control" id="headline" name="headline" maxlength="160" value="<?= e(old('headline', $p['headline'] ?? '')) ?>" placeholder="Ex : Serveuse expérimentée"></div>
        <div class="col-12"><label class="form-label" for="bio">Présentation</label><textarea class="form-control" id="bio" name="bio" rows="3" maxlength="2000" placeholder="Expérience, qualités, disponibilités…"><?= e(old('bio', $p['bio'] ?? '')) ?></textarea></div>
        <div class="col-md-8"><label class="form-label" for="trades">Mes métiers (jusqu'à 5 — maintenez Ctrl pour en choisir plusieurs)</label>
          <select class="form-select" id="trades" name="trades[]" multiple size="8">
            <?php foreach ($trades as $t): if ($t['category'] !== $cur): ?><?= $cur !== null ? '</optgroup>' : '' ?><optgroup label="<?= e($t['category']) ?>"><?php $cur = $t['category']; endif; ?>
              <option value="<?= (int) $t['id'] ?>"<?= in_array((int) $t['id'], $p['trades'], true) ? ' selected' : '' ?>><?= e($t['name']) ?></option>
            <?php endforeach; ?></optgroup>
          </select></div>
        <div class="col-md-4">
          <label class="form-label" for="daily_rate">Tarif indicatif / jour (FCFA)</label><input class="<?= e(field_class('daily_rate')) ?>" id="daily_rate" name="daily_rate" inputmode="numeric" value="<?= e(old('daily_rate', $p['daily_rate'] !== null ? (string) (int) $p['daily_rate'] : '')) ?>">
          <label class="form-label mt-2" for="years_experience">Années d'expérience</label><input class="form-control" id="years_experience" name="years_experience" type="number" min="0" max="60" value="<?= e(old('years_experience', (string) ($p['years_experience'] ?? ''))) ?>">
          <div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="is_available" name="is_available" value="1"<?= (int) $p['is_available'] === 1 ? ' checked' : '' ?>><label class="form-check-label" for="is_available">Disponible pour travailler</label></div>
        </div>
      <?php endif; ?>
    </div>
    <button type="submit" class="btn btn-primary mt-3">Enregistrer</button>
  </form>
</div>
