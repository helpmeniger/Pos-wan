<?php $J = fn($a) => e(json_encode($a, JSON_UNESCAPED_UNICODE)); ?>
<?= App\Core\View::partial('reports/_filters', get_defined_vars()) ?>
<div class="row g-3">
  <div class="col-lg-5"><div class="card"><div class="card-header">Compte de résultat simplifié (<?= e(fdate($from, false)) ?> → <?= e(fdate($to, false)) ?>)</div><table class="table mb-0">
    <tr><td>Ventes brutes TTC</td><td class="num"><?= money($sum['gross']) ?></td></tr>
    <tr><td>− Retours / remboursements</td><td class="num text-danger"><?= money($sum['returns']) ?></td></tr>
    <tr class="fw-bold"><td>= Chiffre d'affaires net</td><td class="num"><?= money($sum['net']) ?></td></tr>
    <tr><td>− Coût des marchandises vendues (CMP)</td><td class="num text-danger"><?= money($sum['cogs']) ?></td></tr>
    <tr class="fw-bold table-light"><td>= Marge brute (<?= number_format($sum['margin_rate'], 1, ',', ' ') ?> %)</td><td class="num"><?= money($sum['margin']) ?></td></tr>
    <tr><td>− Dépenses (charges saisies)</td><td class="num text-danger"><?= money($sum['expenses']) ?></td></tr>
    <tr><td>+ Recettes diverses</td><td class="num text-success"><?= money($sum['incomes']) ?></td></tr>
    <tr class="fw-bold fs-5 <?= $sum['profit'] >= 0 ? 'table-success' : 'table-danger' ?>"><td>= Bénéfice estimé</td><td class="num"><?= money($sum['profit']) ?></td></tr>
  </table><div class="card-footer small text-secondary">Estimation de gestion : les prix sont TTC (taxes incluses : <?= money($sum['tax']) ?>). Le bénéfice ne tient compte que des dépenses saisies dans POS Wan (ni amortissements, ni impôt sur les bénéfices). Le chiffre d'affaires n'est ni la trésorerie, ni la valeur du stock.</div></div></div>
  <div class="col-lg-7"><div class="card mb-3"><div class="card-header">Évolution sur 12 mois</div><div class="card-body"><canvas height="140" data-chart="<?= $J(['type' => 'bar', 'labels' => array_map(fn($m) => date('m/Y', strtotime($m['m'])), $months), 'series' => [['label' => 'CA net', 'data' => array_map(fn($m) => round($m['net']), $months), 'color' => '#2e86c1'], ['label' => 'Marge brute', 'data' => array_map(fn($m) => round($m['margin']), $months), 'color' => '#27ae60'], ['label' => 'Bénéfice estimé', 'type' => 'line', 'data' => array_map(fn($m) => round($m['profit']), $months), 'color' => '#f39c12']]]) ?>"></canvas></div></div>
    <div class="row g-3"><div class="col-md-6"><div class="card"><div class="card-header">Marge par catégorie</div><table class="table table-sm mb-0"><tbody><?php foreach ($categories as $c): ?><tr><td><?= e($c['label']) ?></td><td class="num"><?= money($c['margin']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    <div class="col-md-6"><div class="card"><div class="card-header">Dépenses / recettes</div><table class="table table-sm mb-0"><tbody><?php foreach ($expenses as $x): ?><tr><td><?= e($x['name']) ?></td><td class="num <?= $x['type'] === 'expense' ? 'text-danger' : 'text-success' ?>"><?= money($x['total']) ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
  </div>
</div>
