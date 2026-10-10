<?php $fin = can('reports.financial'); $J = fn($a) => e(json_encode($a, JSON_UNESCAPED_UNICODE)); ?>
<?= App\Core\View::partial('reports/_filters', get_defined_vars()) ?>
<h2 class="h6 text-secondary d-none d-print-block">Période du <?= e(fdate($from, false)) ?> au <?= e(fdate($to, false)) ?></h2>
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="kpi bg-g1"><div class="kpi-label">CA net</div><div class="kpi-value"><?= money($sum['net']) ?></div><span class="small">Brut <?= money($sum['gross']) ?> − retours <?= money($sum['returns']) ?></span></div></div>
  <div class="col-6 col-lg-3"><div class="kpi bg-g2"><div class="kpi-label">Tickets</div><div class="kpi-value"><?= (int) $sum['tickets'] ?></div><span class="small">Panier moyen <?= money($sum['avg_basket']) ?> · <?= (int) $sum['cancelled'] ?> annulé(s)</span></div></div>
  <div class="col-6 col-lg-3"><div class="kpi bg-g3"><div class="kpi-label">Remises accordées</div><div class="kpi-value"><?= money($sum['discounts']) ?></div><span class="small">Ventes à crédit : <?= money($sum['credit']) ?></span></div></div>
  <?php if ($fin): ?><div class="col-6 col-lg-3"><div class="kpi bg-g4"><div class="kpi-label">Marge brute</div><div class="kpi-value"><?= money($sum['margin']) ?></div><span class="small">Taux <?= number_format($sum['margin_rate'], 1, ',', ' ') ?> %</span></div></div><?php endif; ?>
</div>
<div class="row g-3 mb-3">
  <div class="col-xl-8"><div class="card h-100"><div class="card-header">Évolution journalière</div><div class="card-body"><canvas height="110" data-chart="<?= $J(['type' => 'bar', 'labels' => array_map(fn($d) => date('d/m', strtotime($d['d'])), $daily), 'series' => array_values(array_filter([['label' => 'CA', 'data' => array_map(fn($d) => round($d['total']), $daily)], $fin ? ['label' => 'Marge', 'type' => 'line', 'data' => array_map(fn($d) => round($d['margin']), $daily), 'color' => '#f39c12'] : null]))]) ?>"></canvas></div></div></div>
  <div class="col-xl-4"><div class="card h-100"><div class="card-header">Par catégorie</div><div class="card-body"><canvas height="220" data-chart="<?= $J(['type' => 'doughnut', 'labels' => array_column($categories, 'label'), 'series' => [['label' => '', 'data' => array_map(fn($c) => round((float) $c['revenue']), $categories), 'colors' => array_column($categories, 'color')]]]) ?>"></canvas></div></div></div>
</div>
<div class="row g-3">
  <div class="col-lg-6"><div class="card"><div class="card-header">Par catégorie</div><table class="table table-sm mb-0"><thead><tr><th>Catégorie</th><th class="num">CA</th><?php if ($fin): ?><th class="num">Marge</th><th class="num">Taux</th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($categories as $c): ?><tr><td><?= e($c['label']) ?></td><td class="num"><?= money($c['revenue']) ?></td><?php if ($fin): ?><td class="num"><?= money($c['margin']) ?></td><td class="num"><?= (float) $c['revenue'] > 0 ? number_format((float) $c['margin'] / (float) $c['revenue'] * 100, 1, ',', ' ') : 0 ?> %</td><?php endif; ?></tr><?php endforeach; ?>
  </tbody></table></div></div>
  <div class="col-lg-6"><div class="card mb-3"><div class="card-header">Par vendeur</div><table class="table table-sm mb-0"><thead><tr><th>Vendeur</th><th class="num">Tickets</th><th class="num">Remises</th><th class="num">CA</th></tr></thead><tbody>
    <?php foreach ($users as $x): ?><tr><td><?= e($x['label']) ?></td><td class="num"><?= (int) $x['n'] ?></td><td class="num"><?= money($x['discounts']) ?></td><td class="num"><?= money($x['total']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <div class="card mb-3"><div class="card-header">Par boutique / caisse</div><table class="table table-sm mb-0"><tbody><?php foreach ($registers as $x): ?><tr><td><?= e($x['label']) ?></td><td class="num"><?= (int) $x['n'] ?> tickets</td><td class="num"><?= money($x['total']) ?></td></tr><?php endforeach; ?></tbody></table></div>
  <div class="card"><div class="card-header">Par mode de paiement</div><table class="table table-sm mb-0"><tbody><?php foreach ($methods as $x): ?><tr><td><?= e(method_label($x['method'])) ?></td><td class="num"><?= money($x['total']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  <div class="col-12"><div class="card"><div class="card-header">Affluence par heure</div><div class="card-body"><canvas height="70" data-chart="<?= $J(['type' => 'bar', 'unit' => ' tickets', 'labels' => array_map(fn($h) => $h['h'] . ' h', $hours), 'series' => [['label' => 'Tickets', 'data' => array_map(fn($h) => (int) $h['n'], $hours), 'color' => '#2e86c1']]]) ?>"></canvas></div></div></div>
</div>
