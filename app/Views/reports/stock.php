<?= App\Core\View::partial('reports/_filters', []) ?>
<?php $tc = array_sum(array_column($byWarehouse, 'cost_value')); $ts = array_sum(array_column($byWarehouse, 'sale_value')); ?>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="kpi bg-g7"><div class="kpi-label">Valeur au coût (CMP)</div><div class="kpi-value"><?= money($tc) ?></div></div></div>
  <div class="col-md-4"><div class="kpi bg-g2"><div class="kpi-label">Valeur au prix de vente</div><div class="kpi-value"><?= money($ts) ?></div></div></div>
  <div class="col-md-4"><div class="kpi bg-g1"><div class="kpi-label">Marge potentielle</div><div class="kpi-value"><?= money($ts - $tc) ?></div></div></div>
</div>
<div class="row g-3">
  <div class="col-lg-6"><div class="card mb-3"><div class="card-header">Par entrepôt</div><table class="table table-sm mb-0"><thead><tr><th>Entrepôt</th><th class="num">Articles</th><th class="num">Valeur coût</th><th class="num">Valeur vente</th></tr></thead><tbody><?php foreach ($byWarehouse as $w): ?><tr><td><?= e($w['name']) ?></td><td class="num"><?= (int) $w['n'] ?></td><td class="num"><?= money($w['cost_value']) ?></td><td class="num"><?= money($w['sale_value']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <div class="card"><div class="card-header">Par catégorie</div><table class="table table-sm mb-0"><tbody><?php foreach ($byCategory as $c): ?><tr><td><?= e($c['name']) ?></td><td class="num"><?= money($c['cost_value']) ?></td><td class="num text-secondary"><?= money($c['sale_value']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header"><i class="bi bi-hourglass"></i> Stock dormant (aucune vente depuis 90 jours)</div><table class="table table-sm mb-0"><thead><tr><th>Article</th><th class="num">Qté</th><th class="num">Valeur</th><th>Dernière vente</th></tr></thead><tbody>
    <?php foreach ($dormant as $d): ?><tr><td><a href="<?= e(url('/products/' . $d['id'])) ?>"><?= e($d['name']) ?></a></td><td class="num"><?= qty($d['qty']) ?></td><td class="num"><?= money($d['value']) ?></td><td><?= e($d['last_sale'] ? fdate($d['last_sale'], false) : 'jamais') ?></td></tr><?php endforeach; ?>
    <?php if (!$dormant): ?><tr><td colspan="4" class="text-success">Aucun stock dormant.</td></tr><?php endif; ?>
  </tbody></table></div></div>
</div>
