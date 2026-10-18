<form method="get" class="card card-body py-2 mb-3"><div class="row g-2 align-items-end small">
  <div class="col-md-4"><select name="classe" class="form-select form-select-sm"><option value="">Toutes les classes</option><?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $classe === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nom']) ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><button class="btn btn-sm btn-primary">Filtrer</button> <a class="btn btn-sm btn-outline-secondary" href="<?= e(query_with(['export' => 'csv'])) ?>">CSV</a> <a class="btn btn-sm btn-outline-secondary" href="<?= e(query_with(['export' => 'pdf'])) ?>">PDF</a></div>
  <div class="col-auto ms-auto"><b>Total échu : <span class="text-danger"><?= money(array_sum(array_column($rows, 'echu'))) ?></span></b> · <?= count($rows) ?> dossier(s)</div>
</div></form>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Nom</th><th>Classe</th><th>Parent / tuteur</th><th>Depuis le</th><th class="text-end">Échu non payé</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><a href="<?= e(url('/ecole/eleves/' . $r['eleve_id'])) ?>"><b><?= e($r['nom']) ?></b> <?= e($r['prenom']) ?></a><br><span class="small text-secondary"><?= e($r['matricule']) ?></span></td><td><?= e($r['classe']) ?></td>
  <td class="small"><?= e((string) $r['tuteur_nom']) ?><br><?php if ($r['tuteur_tel']): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $r['tuteur_tel'])) ?>"><?= e($r['tuteur_tel']) ?></a><?php endif; ?></td>
  <td class="small"><?= e(fdate($r['plus_ancienne'])) ?></td><td class="text-end fw-bold text-danger"><?= money($r['echu']) ?></td>
  <td class="text-end"><?php if (can('ecole.caisse')): ?><a class="btn btn-sm btn-success" href="<?= e(url('/ecole/caisse', ['eleve' => $r['eleve_id']])) ?>">Encaisser</a><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="text-center text-secondary py-4">Aucun impayé échu.</td></tr><?php endif; ?>
</tbody></table></div></div>
