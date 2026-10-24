<?php
/**
 * En-tête commun des états : filtres à l'écran ; titre, société et période à l'impression.
 * Variables : $title, $ex, $print, $extra (HTML des filtres propres à l'état), $period (texte), $showFrom (bool).
 */
$valid = App\Core\Request::query('statut') === 'valide';
?>
<link rel="stylesheet" href="<?= e(asset('addons/comptabilite/compta.css')) ?>">
<?php if ($print): ?>
<div class="sheet" style="width:auto;max-width:297mm">
<div class="cpt-title"><div><h1><?= e($title) ?></h1><div><?= e($period) ?> · exercice <?= e($ex['code']) ?><?= $valid ? '' : ' · écritures validées et brouillard' ?></div></div>
  <div style="text-align:right"><b><?= e((string) setting('company_name', '')) ?></b><br><?= e((string) setting('company_address', '')) ?><br><?= setting('company_nif') ? 'NIF ' . e((string) setting('company_nif')) : '' ?><br>Édité le <?= e(date('d/m/Y H:i')) ?></div></div>
<?php else: require __DIR__ . '/_nav.php'; ?>
<form method="get" class="card mb-2 d-print-none"><div class="card-body py-2"><div class="row g-2 align-items-end small">
  <div class="col-6 col-md-2"><label class="form-label mb-0">Exercice</label><select name="exercice" class="form-select form-select-sm"><?php foreach ($exercices as $x): ?><option value="<?= (int) $x['id'] ?>" <?= (int) $x['id'] === (int) $ex['id'] ? 'selected' : '' ?>><?= e($x['code']) ?></option><?php endforeach; ?></select></div>
  <?php if ($showFrom ?? true): ?><div class="col-6 col-md-2"><label class="form-label mb-0">Du</label><input type="date" name="from" class="form-control form-control-sm" value="<?= e($from ?? $ex['date_debut']) ?>"></div><?php endif; ?>
  <div class="col-6 col-md-2"><label class="form-label mb-0"><?= ($showFrom ?? true) ? 'Au' : 'Arrêté au' ?></label><input type="date" name="to" class="form-control form-control-sm" value="<?= e($to ?? $ex['date_fin']) ?>"></div>
  <?= $extra ?? '' ?>
  <div class="col-6 col-md-2"><label class="form-label mb-0">Écritures</label><select name="statut" class="form-select form-select-sm"><option value="">Validées + brouillard</option><option value="valide" <?= $valid ? 'selected' : '' ?>>Validées seulement</option></select></div>
  <div class="col-md-auto d-flex gap-1"><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat"></i> Afficher</button>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(query_with(['print' => 1])) ?>"><i class="bi bi-printer"></i> Imprimer</a>
    <?php if (can('reports.export') && ($exportable ?? true)): ?><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> CSV</a><a class="btn btn-sm btn-outline-danger" href="<?= e(query_with(['export' => 'pdf'])) ?>"><i class="bi bi-filetype-pdf"></i> PDF</a><?php endif; ?></div>
</div></div></form>
<div class="d-flex align-items-baseline gap-2 mb-2"><h2 class="h5 mb-0"><?= e($title) ?></h2><span class="small text-secondary"><?= e($period) ?><?= $valid ? ' · écritures validées seulement' : ' · écritures validées et brouillard (provisoire)' ?></span></div>
<?php endif; ?>
