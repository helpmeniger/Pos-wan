<?php use App\Services\ReportService; ?>
<?php $q = array_filter(['from' => $from, 'to' => $to, 'camera_id' => $f['camera_id'], 'product_id' => $f['product_id'], 'level' => $f['level']]); $s = $summary; ?>
<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-12 d-flex flex-wrap gap-1 mb-1"><?php foreach (['' => 'Période choisie', 'week' => 'Cette semaine', 'month' => 'Ce mois', 'year' => 'Cette année'] as $k => $l): ?>
    <a class="btn btn-sm <?= ($_GET['period'] ?? '') === $k ? 'btn-primary' : 'btn-light' ?>" href="?<?= e(http_build_query($k ? ['period' => $k] : $q)) ?>"><?= e($l) ?></a><?php endforeach; ?>
    <a class="btn btn-sm btn-light ms-auto" href="<?= url('reports/daily') ?>"><i class="bi bi-calendar-day me-1"></i>Rapports journaliers</a></div>
  <div class="col-6 col-md-2"><input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>"></div>
  <div class="col-6 col-md-2"><input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>"></div>
  <div class="col-6 col-md-2"><select name="camera_id" class="form-select form-select-sm"><option value="">Toutes caméras</option><?php foreach ($cameras as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $f['camera_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><select name="product_id" class="form-select form-select-sm"><option value="">Tous produits</option><?php foreach ($productList as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $f['product_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><select name="level" class="form-select form-select-sm"><option value="">Tous niveaux</option><?php foreach (['information', 'a_verifier', 'prioritaire'] as $l): ?><option value="<?= $l ?>" <?= $f['level'] === $l ? 'selected' : '' ?>><?= e(label('level', $l)) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Appliquer</button></div>
</form>
<div class="d-flex flex-wrap gap-2 mb-3 no-print">
  <?php if (can('reports.export')): foreach (['pdf' => 'PDF', 'xlsx' => 'Excel', 'csv' => 'CSV'] as $fmt => $l): ?>
    <a class="btn btn-sm btn-outline-secondary" href="<?= url('reports/export', $q + ['format' => $fmt]) ?>"><i class="bi bi-download me-1"></i><?= $l ?></a><?php endforeach; endif; ?>
  <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimer</button>
</div>
<?php require __DIR__ . '/../partials/disclaimer.php'; ?>
<div class="row g-3 mb-3">
<?php foreach ([['Détections vidéo', $s['detections_total'], 'objets suivis'], ['Sorties observées', $s['exits'], $s['exits_unidentified'] . ' non identifiée(s)'], ['Entrées observées', $s['entries'], ''],
  ['Rapprochées d\'une vente', $s['exits_matched'], $s['exits_unmatched'] . ' non rapprochée(s)'], ['À vérifier', $s['to_verify'], $s['verified'] . ' vérifié(s), ' . $s['open_alerts'] . ' ouvert(s)'],
  ['Passages incertains', $s['ambiguous'] + $s['track_lost_events'], 'ambigus + pertes de suivi'], ['Surveillance effective', ReportService::duration((int) $s['monitored_seconds']), 'sans vidéo : ' . ReportService::duration((int) $s['unusable_seconds'])],
  ['Ventes (caisse)', $s['sales_tickets'], qty($s['sales_articles']) . ' article(s)']] as [$l, $v, $sub]): ?>
  <div class="col-6 col-md-3"><div class="card kpi h-100"><div class="card-body"><div class="label"><?= e($l) ?></div><div class="value"><?= e($v) ?></div><div class="small text-muted"><?= e($sub) ?></div></div></div></div>
<?php endforeach; ?>
</div>
<?php if (count($daily) > 1): ?>
<div class="card mb-3"><div class="card-header bg-white">Comparaison par jour</div><div class="card-body"><canvas id="chDays" height="90"></canvas></div></div>
<script>document.addEventListener('DOMContentLoaded',function(){var d=<?= json_encode($daily) ?>;new Chart(document.getElementById('chDays'),{type:'bar',data:{labels:d.map(function(x){return x.date.slice(5)}),datasets:[{label:'Sorties observées',data:d.map(function(x){return x.exits}),backgroundColor:'#e08a00'},{label:'Entrées',data:d.map(function(x){return x.entries}),backgroundColor:'#0f4c81'},{label:'Non rapprochées',data:d.map(function(x){return x.unmatched}),backgroundColor:'#c0392b'}]},options:{scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});});</script>
<?php endif; ?>
<div class="card mb-3"><div class="card-header bg-white">Par produit</div><div class="table-responsive"><table class="table table-sm table-hover mb-0">
<thead><tr><th>Produit</th><th class="text-end">Détections</th><th class="text-end">Sorties observées</th><th class="text-end">Entrées observées</th><th class="text-end">À vérifier</th><th class="text-end">Vendus (caisse)</th><th class="text-end">Rapprochées</th><th class="text-end">Non rapprochées</th></tr></thead><tbody>
<?php foreach ($products as $p): ?><tr><td><?= e($p['name']) ?></td><td class="text-end"><?= (int) $p['detections'] ?></td><td class="text-end"><?= (int) $p['exits'] ?></td><td class="text-end"><?= (int) $p['entries'] ?></td>
  <td class="text-end"><?= (int) $p['to_verify'] ?></td><td class="text-end"><?= qty($p['sold']) ?></td><td class="text-end"><?= (int) $p['matched'] ?></td><td class="text-end"><?= (int) $p['unmatched'] ?></td></tr><?php endforeach; ?>
<?php if (!$products): ?><tr><td colspan="8" class="text-muted text-center">Aucune donnée sur la période.</td></tr><?php endif; ?>
</tbody></table></div><div class="card-footer bg-white small text-muted">Le rapprochement vidéo/caisse peut comporter des erreurs. Aucun « nombre de vols » n'est calculé par soustraction des ventes et des sorties.</div></div>
<div class="card"><div class="card-header bg-white">Fonctionnement des caméras</div><div class="table-responsive"><table class="table table-sm mb-0">
<thead><tr><th>Caméra</th><th>Statut actuel</th><th>Surveillance effective</th><th>Sans vidéo exploitable</th><th>Déconnexions</th><th>Périodes sans vidéo</th></tr></thead><tbody>
<?php foreach ($s['cameras'] as $c): ?><tr><td><?= e($c['name']) ?></td><td><?= e(label('camera_status', $c['status'])) ?></td><td><?= ReportService::duration($c['monitored_seconds']) ?></td><td><?= ReportService::duration($c['unusable_seconds']) ?></td>
  <td><?= (int) $c['disconnections'] ?></td><td class="small"><?= e(implode(', ', array_map(static fn ($g) => $g[0] . '–' . $g[1], array_slice($c['gaps'], 0, 6)))) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
