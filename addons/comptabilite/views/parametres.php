<?php use Addons\Comptabilite\Compta; require __DIR__ . '/_nav.php'; ?>
<form method="post" action="<?= e(url('/compta/parametres')) ?>"><?= csrf_field() ?>
<div class="row g-3">
<div class="col-xl-6"><div class="card h-100"><div class="card-body">
  <h3 class="h6">Comptes de liaison</h3>
  <p class="small text-secondary">Comptes utilisés pour les écritures générées depuis la caisse et pour les tiers créés automatiquement. Référentiel : <b><?= Compta::referentiel() === 'pcg' ? 'PCG français' : 'SYSCOHADA révisé' ?></b>.</p>
  <?php foreach (Compta::LIAISONS as $k => $label): ?>
    <div class="row g-1 align-items-center mb-1 small"><label class="col-7 col-form-label py-0" for="l-<?= e($k) ?>"><?= e($label) ?></label>
      <div class="col-5"><input id="l-<?= e($k) ?>" name="cpt[<?= e($k) ?>]" list="dl-pc" class="form-control form-control-sm cpt-mono" value="<?= e(Compta::liaisonNumero($k)) ?>" required></div></div>
  <?php endforeach; ?>
</div></div></div>
<div class="col-xl-6">
  <div class="card mb-3"><div class="card-body">
    <h3 class="h6">Dépenses : compte par catégorie</h3>
    <p class="small text-secondary">Sans compte précisé, une dépense va au compte « Dépenses sans compte précisé », une recette au compte « Recettes diverses ».</p>
    <?php foreach ($categories as $c): ?>
      <div class="row g-1 align-items-center mb-1 small"><label class="col-7 col-form-label py-0"><?= e($c['name']) ?> <span class="text-secondary">(<?= $c['type'] === 'income' ? 'recette' : 'dépense' ?>)</span></label>
        <div class="col-5"><input name="cat[<?= (int) $c['id'] ?>]" list="dl-pc" class="form-control form-control-sm cpt-mono" value="<?= e((string) setting('compta_cat_' . (int) $c['id'], '')) ?>" placeholder="<?= $c['type'] === 'income' ? e(Compta::liaisonNumero('produits_divers')) : e(Compta::liaisonNumero('charges_diverses')) ?>"></div></div>
    <?php endforeach; ?>
    <?php if (!$categories): ?><div class="small text-secondary">Aucune catégorie de dépense.</div><?php endif; ?>
  </div></div>
  <?php if ($banks): ?><div class="card mb-3"><div class="card-body">
    <h3 class="h6">Banques et coffres : compte comptable</h3>
    <p class="small text-secondary">Vide : compte banque, coffre ou mobile money des comptes de liaison selon le type. Créez un sous-compte par banque (ex. 5211, 5212) pour les suivre séparément.</p>
    <?php foreach ($banks as $b): ?>
      <div class="row g-1 align-items-center mb-1 small"><label class="col-7 col-form-label py-0"><?= e($b['name']) ?></label>
        <div class="col-5"><input name="bank[<?= (int) $b['id'] ?>]" list="dl-pc" class="form-control form-control-sm cpt-mono" value="<?= e((string) setting('compta_bank_' . (int) $b['id'], '')) ?>"></div></div>
    <?php endforeach; ?>
  </div></div><?php endif; ?>
  <div class="card"><div class="card-body">
    <h3 class="h6">Génération des écritures</h3>
    <div class="row g-1 align-items-center mb-2 small"><label class="col-7 col-form-label py-0" for="gd">Reprendre l'activité de la caisse à partir du</label>
      <div class="col-5"><input type="date" id="gd" name="generation_depuis" class="form-control form-control-sm" value="<?= e((string) setting('compta_generation_depuis', '')) ?>"></div></div>
    <div class="form-check small"><input class="form-check-input" type="checkbox" name="validation_directe" value="1" id="vd" <?= setting('compta_validation_directe') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="vd">Cocher par défaut « Valider directement » lors de la génération</label></div>
  </div></div>
</div>
</div>
<div class="mt-3"><button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer les paramètres</button></div>
</form>
<datalist id="dl-pc"><?php foreach ($comptes as $c): ?><option value="<?= e($c['numero']) ?>"><?= e($c['libelle']) ?></option><?php endforeach; ?></datalist>
