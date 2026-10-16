<?php $m = $metrics['metrics'] ?? null; ?>
<?php if ($m): ?>
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card kpi"><div class="card-body"><div class="label">Précision sur le jeu de test</div><div class="value <?= $m['accuracy'] >= $minAccuracy ? 'text-success' : 'text-danger' ?>"><?= pct($m['accuracy'], 1) ?></div><div class="small text-muted">minimum requis <?= pct($minAccuracy) ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card kpi"><div class="card-body"><div class="label">Photos de test</div><div class="value"><?= (int) $m['test_images'] ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card kpi"><div class="card-body"><div class="label">Erreurs (autre produit)</div><div class="value"><?= (int) $m['wrong'] ?></div><div class="small text-muted">taux <?= pct($m['false_acceptance_rate'], 1) ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card kpi"><div class="card-body"><div class="label">Non identifiées</div><div class="value"><?= (int) $m['unidentified'] ?></div></div></div></div>
</div>
<?php endif; ?>
<?php if (!empty($metrics['message'])): ?><div class="alert <?= ($v['status'] ?? '') === 'echec' ? 'alert-danger' : 'alert-success' ?>"><?= e($metrics['message']) ?></div><?php endif; ?>
<?php foreach ($metrics['warnings'] ?? [] as $w): ?><div class="alert alert-warning py-2"><?= e($w) ?></div><?php endforeach; ?>
<?php if (!empty($metrics['products'])): ?>
<div class="card mb-3"><div class="card-header bg-white">Vérification des photos par produit</div><div class="table-responsive"><table class="table table-sm mb-0">
<thead><tr><th>Produit</th><th>Photos exploitables</th><th>Référence / test</th><th>Inclus</th><th>Remarques</th></tr></thead><tbody>
<?php foreach ($metrics['products'] as $p): ?><tr><td><?= e($p['name']) ?></td><td><?= (int) $p['usable_images'] ?></td>
  <td><?= isset($p['reference_images']) ? (int) $p['reference_images'] . ' / ' . (int) $p['test_images'] : '—' ?></td>
  <td><?= $p['included'] ? '<i class="bi bi-check-lg text-success"></i>' : '<i class="bi bi-x-lg text-danger"></i>' ?></td>
  <td class="small text-warning"><?= e(implode(' ; ', $p['issues'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php if (!empty($results)): ?>
<div class="card"><div class="card-header bg-white">Résultats de validation par produit</div><div class="table-responsive"><table class="table table-sm mb-0">
<thead><tr><th>Produit</th><th>Photos test</th><th>Correctes</th><th>Erronées</th><th>Non identifiées</th><th>Précision</th><th>Le plus confondu avec</th></tr></thead><tbody>
<?php foreach ($results as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= (int) $r['test_images'] ?></td><td><?= (int) $r['correct'] ?></td><td><?= (int) $r['wrong'] ?></td><td><?= (int) $r['unidentified'] ?></td>
  <td class="<?= (float) $r['accuracy'] >= $minAccuracy ? 'text-success' : 'text-danger' ?>"><?= pct($r['accuracy']) ?></td><td><?= e($r['confused'] ?? '—') ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
