<?php use Addons\Ecole\Ecole; ?>
<form method="get" class="card card-body py-2 mb-3"><div class="row g-2 align-items-end small">
  <div class="col-md-4"><label class="form-label mb-0">Recherche</label><input name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Nom, matricule, téléphone du tuteur"></div>
  <div class="col-md-3"><label class="form-label mb-0">Classe</label><select name="classe" class="form-select form-select-sm"><option value="">Toutes</option><?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $classe === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nom']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label mb-0">Afficher</label><select name="statut" class="form-select form-select-sm"><?php foreach (['inscrits' => 'Inscrits cette année', 'non_inscrits' => 'Actifs non inscrits', 'tous' => 'Toutes les fiches', 'suspendu' => 'Suspendus', 'sorti' => 'Sortis', 'diplome' => 'Diplômés'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $statut === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2 d-flex gap-1"><button class="btn btn-sm btn-primary flex-grow-1"><i class="bi bi-search"></i></button><a class="btn btn-sm btn-outline-secondary" href="<?= e(query_with(['export' => 'csv'])) ?>">CSV</a><a class="btn btn-sm btn-outline-secondary" href="<?= e(query_with(['export' => 'pdf'])) ?>">PDF</a></div>
</div></form>
<div class="d-flex mb-2"><?php if (can('ecole.manage')): ?><a class="btn btn-primary btn-sm ms-auto" href="<?= e(url('/ecole/eleves/nouveau')) ?>"><i class="bi bi-person-plus"></i> Nouvelle fiche</a><?php endif; ?></div>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th></th><th>Nom</th><th>Matricule</th><th>Classe</th><th>Né(e) le</th><th>Tuteur</th><th class="text-center" title="Visage enregistré pour le pointage">Visage</th></tr></thead><tbody>
<?php foreach ($p['rows'] as $r): ?>
  <tr><td style="width:44px"><?php if ($r['photo']): ?><img class="ec-mini" src="<?= e(url('/ecole/photo/eleve/' . $r['id'])) ?>" alt="" loading="lazy"><?php else: ?><span class="ec-mini ec-mini-empty"><i class="bi bi-person"></i></span><?php endif; ?></td>
    <td><a href="<?= e(url('/ecole/eleves/' . $r['id'])) ?>"><b><?= e($r['nom']) ?></b> <?= e($r['prenom']) ?></a><?= $r['statut'] !== 'actif' ? ' <span class="badge bg-secondary">' . e($r['statut']) . '</span>' : '' ?></td>
    <td class="small"><?= e($r['matricule']) ?></td><td><?= e($r['classe'] ?? '—') ?></td><td class="small"><?= $r['date_naissance'] ? e(fdate($r['date_naissance'])) : '' ?></td>
    <td class="small"><?= e((string) $r['tuteur_nom']) ?><br><?= e((string) $r['tuteur_tel']) ?></td>
    <td class="text-center small"><?= (int) $r['visages'] ? '<span class="badge bg-success">' . (int) $r['visages'] . '</span>' : ($r['consentement_visage'] ? '<span class="text-secondary">à prendre</span>' : '—') ?></td></tr>
<?php endforeach; ?>
<?php if (!$p['rows']): ?><tr><td colspan="7" class="text-center text-secondary py-4">Aucune fiche.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
