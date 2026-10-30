<?php $this->layout('layouts/app'); ?>
<?= $this->partial('partials/admin-nav') ?>
<div class="container pb-4" style="max-width:860px">
  <h1 class="h4">Paramètres</h1>
  <form method="post" action="<?= e(url('/admin/parametres')) ?>" class="jn-card p-3 mb-3"><?= csrf_field() ?>
    <h2 class="jn-section-title">Règles de la plateforme</h2>
    <?php foreach ($editable as $key => $label): $field = str_replace('.', '__', $key); ?>
      <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="<?= e($field) ?>" name="<?= e($field) ?>" value="1"<?= $values[$key] ? ' checked' : '' ?>>
        <label class="form-check-label" for="<?= e($field) ?>"><?= e($label) ?></label></div>
    <?php endforeach; ?>
    <button class="btn btn-primary btn-sm mt-2" type="submit">Enregistrer</button>
  </form>
  <?php if ($canRate): ?>
  <form method="post" action="<?= e(url('/admin/parametres/commission')) ?>" class="jn-card p-3" novalidate><?= csrf_field() ?>
    <h2 class="jn-section-title">Commission JOB NIGER</h2>
    <p class="small text-muted">Taux actuel : <strong><?= e(rtrim(rtrim($rate, '0'), '.') ?: '0') ?> %</strong> du salaire brut / de la prestation. Un nouveau taux s'applique aux contrats signés ensuite ;
      les contrats en cours conservent le taux accepté.</p>
    <div class="row g-2">
      <div class="col-md-3"><label class="form-label small" for="commission_rate">Nouveau taux (%)</label><input class="<?= e(field_class('commission_rate')) ?>" id="commission_rate" name="commission_rate" type="number" min="0" max="50" step="0.5" value="<?= e(old('commission_rate', $rate)) ?>"><?= $this->partial('partials/field-error', ['name' => 'commission_rate']) ?></div>
      <div class="col-md-9"><label class="form-label small" for="reason">Motif (journal d'audit)</label><input class="<?= e(field_class('reason')) ?>" id="reason" name="reason" maxlength="500" required><?= $this->partial('partials/field-error', ['name' => 'reason']) ?></div>
    </div>
    <button class="btn btn-outline-primary btn-sm mt-2" type="submit" data-confirm="Modifier le taux de commission ?">Appliquer le nouveau taux</button>
  </form>
  <?php endif; ?>
</div>
