<div class="card mb-3"><div class="card-body py-2"><form class="row g-2" method="get"><div class="col-md-5"><input name="q" value="<?= e($q) ?>" class="form-control" placeholder="N° de lot ou nom d'article" autofocus></div><div class="col-auto"><button class="btn btn-primary"><i class="bi bi-search"></i> Rechercher</button></div></form></div></div>
<?php if ($rows): ?>
<div class="card mb-3"><div class="table-responsive"><table class="table table-sm mb-0 align-middle"><thead><tr><th>Lot</th><th>Article</th><th>Entrepôt</th><th>Péremption</th><th class="num">En stock</th><th class="num">Délivré</th><th>État</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $l): ?><tr class="<?= $lotId === (int) $l['id'] ? 'table-warning' : '' ?>"><td><a href="<?= e(url('/stock/lots', ['q' => $q, 'lot' => $l['id']])) ?>"><?= e($l['lot_number']) ?></a></td><td><?= e($l['name']) ?></td><td><?= e($l['warehouse']) ?></td><td><?= e(fdate($l['expiry_date'], false)) ?></td><td class="num"><?= qty($l['quantity']) ?></td><td class="num"><?= qty($l['sold']) ?></td>
<td><?= (int) $l['recalled'] === 1 ? '<span class="badge bg-dark" title="' . e($l['recall_reason']) . '">Rappelé</span>' : '<span class="badge bg-success">Actif</span>' ?></td>
<td><?php if (can('pharmacy.manage') && (int) $l['recalled'] === 0): ?><form method="post" action="<?= e(url('/stock/lots/' . $l['id'] . '/recall')) ?>" data-confirm="Rappeler le lot <?= e($l['lot_number']) ?> ? Il sera immédiatement bloqué à la vente." data-reason="1"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-exclamation-octagon"></i> Rappel</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php elseif ($q !== ''): ?><p class="text-secondary">Aucun lot trouvé.</p><?php endif; ?>
<?php if ($lotId): ?>
<div class="card"><div class="card-header"><i class="bi bi-diagram-3"></i> Délivrances de ce lot (traçabilité descendante)</div><table class="table table-sm mb-0"><thead><tr><th>Vente</th><th>Date</th><th>Client</th><th>Téléphone</th><th>Patient (ordonnance)</th><th class="num">Qté</th></tr></thead><tbody>
<?php foreach ($sales as $s): ?><tr><td><a href="<?= e(url('/sales/' . $s['id'])) ?>"><?= e($s['number']) ?></a></td><td><?= e(fdate($s['created_at'])) ?></td><td><?= e($s['customer'] ?? 'comptoir') ?></td><td><?= e($s['phone'] ?? '') ?></td><td><?= e($s['patient_name'] ?? '') ?></td><td class="num"><?= qty($s['quantity']) ?></td></tr><?php endforeach; ?>
<?php if (!$sales): ?><tr><td colspan="6" class="text-secondary">Aucune délivrance enregistrée pour ce lot.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>
