<form method="get" class="row g-2 align-items-end mb-3">
  <div class="col-auto"><label class="form-label small mb-0">Du</label><input type="date" name="du" class="form-control form-control-sm" value="<?= e($du) ?>"></div>
  <div class="col-auto"><label class="form-label small mb-0">Au</label><input type="date" name="au" class="form-control form-control-sm" value="<?= e($au) ?>"></div>
  <div class="col-auto"><label class="form-label small mb-0">Classe</label><select name="classe" class="form-select form-select-sm"><option value="">Toutes</option><?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $classe ? 'selected' : '' ?>><?= e($c['nom']) ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><button class="btn btn-sm btn-primary">Afficher</button> <a class="btn btn-sm btn-outline-secondary" href="<?= e(query_with(['export' => 'csv'])) ?>">CSV</a> <a class="btn btn-sm btn-outline-secondary" href="<?= e(query_with(['export' => 'pdf'])) ?>">PDF</a></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead><tr><th>Nom</th><th>Classe</th><th class="text-center">Présences</th><th class="text-center">Retards</th><th class="text-center">Absences</th><th class="text-center">Excusées</th><th>Parent</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><a href="<?= e(url('/ecole/eleves/' . $r['id'])) ?>"><?= e($r['nom'] . ' ' . $r['prenom']) ?></a> <span class="small text-secondary"><?= e($r['matricule']) ?></span></td><td><?= e($r['classe']) ?></td>
  <td class="text-center"><?= (int) $r['presents'] ?></td><td class="text-center <?= (int) $r['retards'] ? 'text-warning fw-bold' : '' ?>"><?= (int) $r['retards'] ?></td><td class="text-center <?= (int) $r['absents'] ? 'text-danger fw-bold' : '' ?>"><?= (int) $r['absents'] ?></td><td class="text-center"><?= (int) $r['excuses'] ?></td>
  <td class="small"><?= $r['tuteur_tel'] ? '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', $r['tuteur_tel'])) . '">' . e($r['tuteur_tel']) . '</a>' : '' ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="text-secondary text-center py-3">Aucun inscrit.</td></tr><?php endif; ?>
</tbody></table></div></div>
