<div class="card mb-3"><div class="card-body py-2"><form class="row g-2 align-items-end" method="get">
  <div class="col-auto"><label class="form-label small mb-0">Horizon (jours)</label><input type="number" name="days" min="1" max="730" value="<?= (int) $days ?>" class="form-control form-control-sm"></div>
  <div class="col-auto"><button class="btn btn-sm btn-primary">Afficher</button></div>
  <div class="col-auto"><a href="<?= e(url('/stock/lots')) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i> Traçabilité d'un lot</a></div>
  <div class="col-auto ms-auto"><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> Exporter</a></div>
</form></div></div>
<?php $valueExp = 0; foreach ($rows as $r) { if ((int) $r['days_left'] < 0) $valueExp += (float) $r['quantity'] * (float) $r['cost_price']; } ?>
<?php if ($valueExp > 0 && can('products.cost_view')): ?><div class="alert alert-danger py-2">Valeur des lots déjà périmés encore en stock : <b><?= money($valueExp) ?></b>. Retirez-les de la vente (ajustement « périmé ») ou retournez-les au fournisseur.</div><?php endif; ?>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Article</th><th>Lot</th><th>Entrepôt</th><th>Péremption</th><th class="num">Jours</th><th class="num">Quantité</th><th>État</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): $d = (int) $r['days_left']; ?><tr><td><a href="<?= e(url('/products/' . $r['product_id'])) ?>"><?= e($r['name']) ?></a></td><td><?= e($r['lot_number']) ?></td><td><?= e($r['warehouse']) ?></td><td><?= e(fdate($r['expiry_date'], false)) ?></td><td class="num"><?= $d ?></td><td class="num"><?= qty($r['quantity']) ?></td>
<td><?php if ((int) $r['recalled'] === 1): ?><span class="badge bg-dark">Rappelé</span><?php elseif ($d < 0): ?><span class="badge bg-danger">Périmé</span><?php elseif ($d <= 30): ?><span class="badge bg-warning text-dark">&lt; 30 j</span><?php else: ?><span class="badge bg-secondary">à surveiller</span><?php endif; ?></td>
<td class="text-end"><?php if (can('stock.adjust')): ?><a class="btn btn-sm btn-light" href="<?= e(url('/stock/adjust', ['product' => $r['product_id']])) ?>" title="Sortir du stock"><i class="bi bi-box-arrow-right"></i></a><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8" class="text-center text-success py-4"><i class="bi bi-check-circle"></i> Aucun lot ne périme dans cet horizon.</td></tr><?php endif; ?>
</tbody></table></div></div>
