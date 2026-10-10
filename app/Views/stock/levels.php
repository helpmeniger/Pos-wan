<?php $cost = can('products.cost_view'); ?>
<div class="card mb-3"><div class="card-body py-2">
  <form class="row g-2 align-items-end" method="get">
    <div class="col-md-3"><label class="form-label small mb-0">Recherche</label><input name="q" value="<?= e($_GET['q'] ?? '') ?>" class="form-control form-control-sm" placeholder="Nom, réf., code-barres"></div>
    <div class="col-auto"><label class="form-label small mb-0">Entrepôt</label><select name="warehouse" class="form-select form-select-sm" data-autosubmit><option value="">Tous</option><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $wh === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><label class="form-label small mb-0">Catégorie</label><select name="category" class="form-select form-select-sm" data-autosubmit><option value="">Toutes</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) ($_GET['category'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><label class="form-label small mb-0">Filtre</label><select name="filter" class="form-select form-select-sm" data-autosubmit><option value="">Tous</option><option value="low" <?= ($_GET['filter'] ?? '') === 'low' ? 'selected' : '' ?>>Sous le seuil</option><option value="out" <?= ($_GET['filter'] ?? '') === 'out' ? 'selected' : '' ?>>Rupture</option></select></div>
    <div class="col-auto"><button class="btn btn-sm btn-primary">Filtrer</button></div>
    <div class="col-auto ms-auto"><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> Exporter</a></div>
  </form>
</div></div>
<?php if ($cost): ?><div class="alert alert-light border py-2">Valeur du stock affiché (au coût moyen pondéré) : <b><?= money($value) ?></b></div><?php endif; ?>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Référence</th><th>Article</th><th>Catégorie</th><th class="num">Stock</th><th>Unité</th><th class="num">Seuil</th><?php if ($cost): ?><th class="num">CMP</th><th class="num">Valeur</th><?php endif; ?><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): $q = (float) $r['qty']; ?><tr><td class="small"><?= e($r['reference']) ?></td><td><a href="<?= e(url('/products/' . $r['id'])) ?>"><?= e($r['name']) ?></a></td><td><?= e($r['category'] ?? '') ?></td>
<td class="num fw-bold <?= $q <= 0 ? 'text-danger' : ($q <= (float) $r['min_stock'] ? 'text-warning' : '') ?>"><?= qty($q) ?></td><td><?= e($r['unit'] ?? '') ?></td><td class="num"><?= qty($r['min_stock']) ?></td>
<?php if ($cost): ?><td class="num"><?= money($r['cost_price']) ?></td><td class="num"><?= money(max(0, $q) * (float) $r['cost_price']) ?></td><?php endif; ?>
<td class="text-end text-nowrap"><a class="btn btn-sm btn-light" title="Mouvements" href="<?= e(url('/stock/movements', ['product' => $r['id'], 'from' => date('Y-m-d', strtotime('-90 days'))])) ?>"><i class="bi bi-arrow-left-right"></i></a><?php if (can('stock.adjust')): ?> <a class="btn btn-sm btn-light" title="Ajuster" href="<?= e(url('/stock/adjust', ['product' => $r['id']])) ?>"><i class="bi bi-sliders"></i></a><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="9" class="text-center text-secondary py-4">Aucun article.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
