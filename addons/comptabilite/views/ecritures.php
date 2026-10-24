<?php use Addons\Comptabilite\Compta; require __DIR__ . '/_nav.php'; $nbBr = count(array_filter($rows, fn($r) => $r['statut'] === 'brouillard')); ?>
<form method="get" class="card mb-2"><div class="card-body py-2"><div class="row g-2 align-items-end small">
  <div class="col-6 col-md-2"><label class="form-label mb-0">Exercice</label><select name="exercice" class="form-select form-select-sm"><?php foreach ($exercices as $x): ?><option value="<?= (int) $x['id'] ?>" <?= (int) $x['id'] === (int) $ex['id'] ? 'selected' : '' ?>><?= e($x['code']) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label mb-0">Journal</label><select name="journal" class="form-select form-select-sm"><option value="">Tous</option><?php foreach ($journaux as $j): ?><option value="<?= (int) $j['id'] ?>" <?= (int) $f['journal_id'] === (int) $j['id'] ? 'selected' : '' ?>><?= e($j['code']) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label mb-0">Statut</label><select name="statut" class="form-select form-select-sm"><option value="">Tous</option><option value="brouillard" <?= $f['statut'] === 'brouillard' ? 'selected' : '' ?>>Brouillard</option><option value="valide" <?= $f['statut'] === 'valide' ? 'selected' : '' ?>>Validées</option></select></div>
  <div class="col-6 col-md-2"><label class="form-label mb-0">Origine</label><select name="origine" class="form-select form-select-sm"><option value="">Toutes</option><option value="manuel" <?= $f['origine'] === 'manuel' ? 'selected' : '' ?>>Saisie manuelle</option><option value="auto" <?= $f['origine'] === 'auto' ? 'selected' : '' ?>>Caisse (automatique)</option><option value="extourne" <?= $f['origine'] === 'extourne' ? 'selected' : '' ?>>Extournes</option><option value="cloture" <?= $f['origine'] === 'cloture' ? 'selected' : '' ?>>À-nouveaux</option></select></div>
  <div class="col-6 col-md-2"><label class="form-label mb-0">Du</label><input type="date" name="from" value="<?= e($f['from']) ?>" class="form-control form-control-sm"></div>
  <div class="col-6 col-md-2"><label class="form-label mb-0">Au</label><input type="date" name="to" value="<?= e($f['to']) ?>" class="form-control form-control-sm"></div>
  <div class="col-6 col-md-2"><label class="form-label mb-0">Compte (début du n°)</label><input name="compte" value="<?= e($f['compte']) ?>" class="form-control form-control-sm" placeholder="ex. 411"></div>
  <div class="col-6 col-md-2"><label class="form-label mb-0">Montant exact</label><input name="montant" value="<?= e($f['montant']) ?>" class="form-control form-control-sm" inputmode="decimal"></div>
  <div class="col-md-4"><label class="form-label mb-0">Libellé ou pièce</label><input name="q" value="<?= e($f['q']) ?>" class="form-control form-control-sm"></div>
  <div class="col-md-4 d-flex gap-1"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Filtrer</button>
    <?php if (can('reports.export')): ?><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> CSV</a><a class="btn btn-sm btn-outline-danger" href="<?= e(query_with(['export' => 'pdf'])) ?>"><i class="bi bi-filetype-pdf"></i> PDF</a><?php endif; ?>
    <?php if (can('compta.saisie')): ?><a class="btn btn-sm btn-success ms-auto" href="<?= e(url('/compta/saisie')) ?>"><i class="bi bi-plus-lg"></i> Saisir</a><?php endif; ?></div>
</div></div></form>
<form method="post" action="<?= e(url('/compta/ecritures/valider')) ?>" data-confirm="Valider les écritures sélectionnées ? Elles recevront leur numéro définitif et ne pourront plus être modifiées.">
<?= csrf_field() ?>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 small cpt-table">
  <thead><tr><th><?php if (can('compta.valider') && $nbBr): ?><input type="checkbox" class="form-check-input" data-check-all="ids[]" title="Tout cocher"><?php endif; ?></th><th>Date</th><th>N°</th><th>Pièce</th><th>Libellé</th><th class="num">Montant</th><th>Origine</th><th>Statut</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?php if (can('compta.valider') && $r['statut'] === 'brouillard'): ?><input type="checkbox" class="form-check-input" name="ids[]" value="<?= (int) $r['id'] ?>"><?php endif; ?></td>
      <td class="text-nowrap"><?= e(fdate($r['date_ecriture'], false)) ?></td>
      <td class="text-nowrap"><a href="<?= e(url('/compta/ecritures/' . $r['id'])) ?>"><?= e(Compta::pieceNo($r)) ?></a></td>
      <td><?= e((string) $r['piece']) ?></td>
      <td><?= e($r['libelle']) ?><?= $r['extournee_par'] ? ' <span class="badge bg-secondary">extournée</span>' : '' ?></td>
      <td class="num"><?= Compta::num((float) $r['total']) ?></td>
      <td><?= e(match (true) { $r['origine'] === 'manuel' => 'Saisie', $r['origine'] === 'extourne' => 'Extourne', $r['origine'] === 'cloture' => 'À-nouveaux', str_starts_with($r['origine'], 'pos_') => 'Caisse', default => $r['origine'] }) ?></td>
      <td><?= $r['statut'] === 'valide' ? '<span class="badge bg-success-subtle text-success-emphasis">validée</span>' : '<span class="badge bg-warning-subtle text-warning-emphasis">brouillard</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-secondary py-4">Aucune écriture pour ces critères.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php if (count($rows) >= 500): ?><div class="card-footer small text-secondary">500 premières écritures affichées : affinez les filtres (l'export CSV reprend tout).</div><?php endif; ?>
</div>
<?php if (can('compta.valider') && $nbBr): ?><div class="cpt-sel-bar d-flex gap-2 mt-2"><button class="btn btn-sm btn-success"><i class="bi bi-check2-all"></i> Valider la sélection</button></div><?php endif; ?>
</form>
<?php if (can('compta.valider') && $ex['statut'] === 'ouvert'): ?>
<form method="post" action="<?= e(url('/compta/ecritures/valider')) ?>" class="mt-2" data-confirm="Valider TOUT le brouillard de l'exercice <?= e($ex['code']) ?> ? Les écritures recevront leur numéro définitif.">
  <?= csrf_field() ?><input type="hidden" name="tout" value="1"><input type="hidden" name="exercice_id" value="<?= (int) $ex['id'] ?>">
  <button class="btn btn-sm btn-outline-success"><i class="bi bi-check2-all"></i> Valider tout le brouillard de l'exercice</button>
</form>
<?php endif; ?>
<script src="<?= e(asset('addons/comptabilite/compta.js')) ?>"></script>
