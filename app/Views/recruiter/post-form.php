<?php $this->layout('layouts/app');
$f = function (string $name, string $label, string $type = 'text', string $attrs = '', string $default = '', string $help = ''): string {
    return '<label class="form-label" for="' . e($name) . '">' . e($label) . '</label><input class="' . e(field_class($name)) . '" id="' . e($name)
        . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e(old($name, $default)) . '" ' . $attrs . '>'
        . (error_for($name) ? '<div class="invalid-feedback">' . e(error_for($name)) . '</div>' : '') . ($help ? '<div class="form-text">' . e($help) . '</div>' : '');
};
$currentCat = null;
?>
<div class="container py-4" style="max-width:860px">
  <h1 class="h3">Publier une offre</h1>
  <?php if (!$verified): ?>
    <div class="alert alert-info small"><i class="bi bi-patch-check" aria-hidden="true"></i> Astuce : les offres des recruteurs vérifiés attirent plus de candidats.
      <a href="<?= e(url('/verification')) ?>">Envoyer mes documents</a>.</div>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/recruteur/offres')) ?>" class="jn-card p-4" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-12"><?= $f('title', 'Intitulé du poste', 'text', 'required maxlength="160" placeholder="Ex : Serveur pour restaurant, Vendeuse en boutique"') ?></div>
      <div class="col-md-7">
        <label class="form-label" for="subcategory_id">Métier</label>
        <select class="<?= e(field_class('subcategory_id', 'form-select')) ?>" id="subcategory_id" name="subcategory_id" required>
          <option value="">— Choisir —</option>
          <?php foreach ($trades as $t): if ($t['category'] !== $currentCat): ?><?= $currentCat !== null ? '</optgroup>' : '' ?><optgroup label="<?= e($t['category']) ?>"><?php $currentCat = $t['category']; endif; ?>
            <option value="<?= (int) $t['id'] ?>"<?= (string) old('subcategory_id') === (string) $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option>
          <?php endforeach; ?></optgroup>
        </select>
        <?= $this->partial('partials/field-error', ['name' => 'subcategory_id']) ?>
      </div>
      <div class="col-md-5">
        <label class="form-label" for="contract_type">Type de contrat</label>
        <select class="form-select" id="contract_type" name="contract_type">
          <?php foreach ($contractTypes as $c): ?><option value="<?= e($c['code']) ?>"<?= old('contract_type', 'EMPLOI_TEMPORAIRE') === $c['code'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label" for="description">Description du travail</label>
        <textarea class="<?= e(field_class('description')) ?>" id="description" name="description" rows="5" required maxlength="5000" placeholder="Tâches, profil recherché, conditions de travail, matériel fourni…"><?= e(old('description')) ?></textarea>
        <?= $this->partial('partials/field-error', ['name' => 'description']) ?>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="city_id">Ville</label>
        <select class="<?= e(field_class('city_id', 'form-select')) ?>" id="city_id" name="city_id">
          <?php foreach ($cities as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (string) old('city_id') === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-8"><?= $f('address', 'Adresse ou quartier (communiquée aux candidats retenus)', 'text', 'maxlength="255"') ?></div>
      <div class="col-6 col-md-4"><?= $f('pay_rate', 'Rémunération (FCFA)', 'text', 'required inputmode="numeric" placeholder="Ex : 60000"') ?></div>
      <div class="col-6 col-md-4">
        <label class="form-label" for="pay_unit">Par</label>
        <select class="form-select" id="pay_unit" name="pay_unit">
          <?php foreach (['MOIS' => 'mois (salaire mensuel)', 'JOUR' => 'jour', 'HEURE' => 'heure', 'TACHE' => 'tâche', 'FORFAIT' => 'forfait (toute la mission)'] as $k => $v): ?>
            <option value="<?= $k ?>"<?= old('pay_unit', 'MOIS') === $k ? ' selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4"><?= $f('positions', 'Nombre de postes', 'number', 'min="1" max="200" required', '1') ?></div>
      <div class="col-6 col-md-4"><?= $f('start_date', 'Date de début', 'date', 'required min="' . date('Y-m-d') . '"', date('Y-m-d', strtotime('+7 days'))) ?></div>
      <div class="col-6 col-md-4"><?= $f('end_date', 'Date de fin (facultatif)', 'date', '', '', 'Vide = poste durable') ?></div>
      <div class="col-md-4"><?= $f('application_deadline', 'Candidatures jusqu\'au', 'date', '', '', 'Par défaut : début du poste') ?></div>
      <div class="col-6 col-md-3"><?= $f('daily_start_time', 'Heure de début', 'time') ?></div>
      <div class="col-6 col-md-3"><?= $f('daily_end_time', 'Heure de fin', 'time') ?></div>
      <div class="col-md-6"><?= $f('schedule_notes', 'Jours / organisation', 'text', 'maxlength="500" placeholder="Ex : du lundi au samedi"') ?></div>
    </div>
    <div class="alert alert-light border small mt-3 mb-3"><i class="bi bi-shield-check" aria-hidden="true"></i>
      Il est interdit de demander de l'argent aux candidats. Les offres concernant des métiers à risque sont vérifiées par l'équipe avant publication.</div>
    <button type="submit" class="btn btn-primary btn-lg" data-loading="Publication…">Publier l'offre</button>
  </form>
</div>
