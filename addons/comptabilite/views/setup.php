<?php use Addons\Comptabilite\Plans; ?>
<link rel="stylesheet" href="<?= e(asset('addons/comptabilite/compta.css')) ?>">
<div class="row justify-content-center"><div class="col-xl-9">
<div class="card mb-3"><div class="card-body">
  <h2 class="h4"><i class="bi bi-journal-bookmark text-primary"></i> Mise en place de la comptabilité</h2>
  <p class="text-secondary mb-0">Trois choix et la comptabilité est prête : le plan comptable, les dates du premier exercice, puis vous pourrez saisir vos écritures ou les faire proposer à partir de la caisse. Les journaux usuels (achats, ventes, caisse, banque, mobile money, opérations diverses, à-nouveaux) sont créés automatiquement et restent modifiables.</p>
</div></div>
<?php if (!can('compta.admin')): ?>
  <div class="alert alert-warning">La mise en place est réservée à une personne qui a le droit « Comptabilité : plan comptable, journaux, exercices, clôture, paramètres ».</div>
<?php else: ?>
<form method="post" action="<?= e(url('/compta/mise-en-place')) ?>" class="card"><div class="card-body"><?= csrf_field() ?>
  <h3 class="h6 text-uppercase text-secondary">1. Plan comptable</h3>
  <div class="row g-2 mb-3">
    <?php foreach (Plans::PLANS as $code => [$label, $desc]): $n = count(Plans::accounts($code)); ?>
      <div class="col-md-6"><label class="card h-100 p-2 d-flex flex-row gap-2" style="cursor:pointer">
        <input class="form-check-input mt-1" type="radio" name="plan" value="<?= e($code) ?>" <?= $code === 'syscohada' ? 'checked' : '' ?> required>
        <span><b><?= e($label) ?></b><?= $code === 'syscohada' ? ' <span class="badge bg-success">recommandé au Niger</span>' : '' ?><br><small class="text-secondary"><?= e($desc) ?><?= $n ? ' — ' . $n . ' comptes.' : '' ?></small></span>
      </label></div>
    <?php endforeach; ?>
  </div>
  <h3 class="h6 text-uppercase text-secondary">2. Premier exercice comptable</h3>
  <div class="row g-2 mb-3">
    <div class="col-sm-3"><label class="form-label small">Code</label><input name="code" class="form-control" value="<?= $year ?>" maxlength="20" required></div>
    <div class="col-sm-3"><label class="form-label small">Début</label><input type="date" name="date_debut" class="form-control" value="<?= $year ?>-01-01" required></div>
    <div class="col-sm-3"><label class="form-label small">Fin</label><input type="date" name="date_fin" class="form-control" value="<?= $year ?>-12-31" required></div>
    <div class="col-sm-3"><label class="form-label small">Libellé</label><input name="libelle" class="form-control" value="Exercice <?= $year ?>" maxlength="100"></div>
  </div>
  <h3 class="h6 text-uppercase text-secondary">3. Écritures de la caisse</h3>
  <div class="mb-3"><label class="form-label small">Proposer les écritures des ventes, achats, dépenses et banques à partir du</label>
    <input type="date" name="generation_depuis" class="form-control w-auto d-inline-block">
    <div class="form-text">Laissé vide : le premier jour de l'exercice. Les journées antérieures ne sont jamais reprises. Modifiable ensuite dans Paramètres comptables.</div></div>
  <button class="btn btn-primary btn-lg"><i class="bi bi-check2-circle"></i> Mettre la comptabilité en place</button>
</div></form>
<?php endif; ?>
</div></div>
