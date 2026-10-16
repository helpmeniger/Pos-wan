<?php use App\Services\ReportService; $s = $summary; $d = $rep['report_date']; ?>
<div class="d-flex gap-2 mb-3 no-print">
  <?php if (can('reports.export')): foreach (['pdf' => 'PDF', 'xlsx' => 'Excel', 'csv' => 'CSV'] as $fmt => $l): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('reports/export', ['from' => $d, 'to' => $d, 'format' => $fmt]) ?>"><?= $l ?></a><?php endforeach; endif; ?>
  <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Imprimer</button>
  <?php if (can('reports.generate')): ?><form method="post" action="<?= url('reports/daily/generate') ?>"><?= csrf_field() ?><input type="hidden" name="date" value="<?= e($d) ?>"><button class="btn btn-sm btn-light">Régénérer</button></form><?php endif; ?>
</div>
<p class="small text-muted">Généré <?= e($rep['generated_by']) ?> le <?= dt($rep['generated_at']) ?>.</p>
<div class="card mb-3"><div class="card-body"><div class="row small g-2">
<?php foreach ([['Événements détectés (total)', $s['events_total']], ['Produits identifiés', $s['products_identified']], ['Franchissements vers la sortie', $s['exits']], ['Franchissements vers l\'entrée', $s['entries']],
  ['Objets non identifiés', $s['objects_unidentified']], ['Événements à vérifier', $s['to_verify']], ['Événements vérifiés', $s['verified']], ['Erreurs de suivi', $s['tracking_errors']],
  ['Durée effective de surveillance', ReportService::duration((int) $s['monitored_seconds'])], ['Périodes sans vidéo exploitable', ReportService::duration((int) $s['unusable_seconds'])]] as [$l, $v]): ?>
  <div class="col-6 col-md-3"><div class="text-muted"><?= e($l) ?></div><div class="fs-5 fw-semibold"><?= e($v) ?></div></div>
<?php endforeach; ?></div></div></div>
<div class="card mb-3"><div class="card-header bg-white">Par produit</div><div class="table-responsive"><table class="table table-sm mb-0">
<thead><tr><th>Produit</th><th class="text-end">Détections vidéo</th><th class="text-end">Sorties observées</th><th class="text-end">Entrées observées</th><th class="text-end">À vérifier</th><th class="text-end">Ventes en caisse</th><th class="text-end">Rapprochées</th><th class="text-end">Non rapprochées</th></tr></thead><tbody>
<?php foreach ($products as $p): ?><tr><td><?= e($p['name']) ?></td><td class="text-end"><?= (int) $p['detections'] ?></td><td class="text-end"><?= (int) $p['exits'] ?></td><td class="text-end"><?= (int) $p['entries'] ?></td><td class="text-end"><?= (int) $p['to_verify'] ?></td><td class="text-end"><?= qty($p['sold']) ?></td><td class="text-end"><?= (int) $p['matched'] ?></td><td class="text-end"><?= (int) $p['unmatched'] ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<div class="card mb-3"><div class="card-header bg-white">État des caméras</div><ul class="list-group list-group-flush small">
<?php foreach ($cams as $c): ?><li class="list-group-item"><?= e($c['name']) ?> : surveillance <?= ReportService::duration($c['monitored_seconds']) ?>, sans vidéo <?= ReportService::duration($c['unusable_seconds']) ?>, <?= (int) $c['disconnections'] ?> déconnexion(s)</li><?php endforeach; ?>
</ul></div>
<p class="small text-muted"><?= e($s['disclaimer'] ?? ReportService::DISCLAIMER) ?></p>
