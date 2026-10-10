<?php $draft = $inv['status'] === 'draft'; $dv = 0; foreach ($items as $i) { if ($i['counted_qty'] !== null) $dv += ((float) $i['counted_qty'] - (float) $i['expected_qty']) * (float) $i['cost_price']; } ?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <a class="btn btn-outline-secondary" href="<?= e(url('/inventories')) ?>"><i class="bi bi-arrow-left"></i></a>
  <span><?= e($inv['warehouse']) ?> · créé le <?= e(fdate($inv['created_at'])) ?> par <?= e($inv['full_name']) ?> · <?= App\Core\View::partial('partials/status', ['status' => $inv['status']]) ?><?= $inv['validator'] ? ' par ' . e($inv['validator']) : '' ?></span>
  <a class="btn btn-outline-secondary ms-auto" target="_blank" rel="noopener" href="<?= e(url('/inventories/' . $inv['id'], ['print' => 1])) ?>"><i class="bi bi-printer"></i> Feuille de comptage</a>
  <?php if ($draft && can('stock.inventory_validate')): ?><form method="post" action="<?= e(url('/inventories/' . $inv['id'] . '/validate')) ?>" data-confirm="Valider l'inventaire ? Les écarts seront appliqués au stock (irréversible, tracé)."><?= csrf_field() ?><button class="btn btn-success"><i class="bi bi-check2-all"></i> Valider l'inventaire</button></form><?php endif; ?>
  <?php if ($draft): ?><form method="post" action="<?= e(url('/inventories/' . $inv['id'] . '/cancel')) ?>" data-confirm="Annuler ce brouillon d'inventaire ?"><?= csrf_field() ?><button class="btn btn-outline-danger">Annuler</button></form><?php endif; ?>
</div>
<?php if (can('products.cost_view')): ?><div class="alert alert-light border py-2">Écart valorisé des articles comptés : <b class="<?= $dv < 0 ? 'text-danger' : 'text-success' ?>"><?= money($dv) ?></b></div><?php endif; ?>
<form method="get" class="mb-2" style="max-width:420px"><div class="input-group input-group-sm"><input name="q" value="<?= e($q) ?>" class="form-control" placeholder="Filtrer / scanner un article"><button class="btn btn-outline-secondary">Filtrer</button></div></form>
<form method="post" action="<?= e(url('/inventories/' . $inv['id'])) ?>"><?= csrf_field() ?>
<div class="card"><div class="table-responsive" style="max-height:65vh"><table class="table table-sm table-hover mb-0 align-middle"><thead class="sticky-top"><tr><th>Catégorie</th><th>Article</th><th>Code-barres</th><th class="num">Théorique</th><th style="width:140px">Compté</th><th class="num">Écart</th></tr></thead><tbody>
<?php foreach ($items as $i): $diff = $i['counted_qty'] !== null ? (float) $i['counted_qty'] - (float) $i['expected_qty'] : null; ?>
<tr><td class="small"><?= e($i['category'] ?? '') ?></td><td><?= e($i['name']) ?> <small class="text-secondary"><?= e($i['reference']) ?></small></td><td class="small"><?= e($i['barcode'] ?? '') ?></td><td class="num"><?= qty($i['expected_qty']) ?></td>
<td><?php if ($draft): ?><input type="number" step="any" min="0" name="counted[<?= (int) $i['id'] ?>]" value="<?= $i['counted_qty'] !== null ? e((float) $i['counted_qty']) : '' ?>" class="form-control form-control-sm"><?php else: ?><?= $i['counted_qty'] !== null ? qty($i['counted_qty']) : '—' ?><?php endif; ?></td>
<td class="num fw-semibold <?= $diff === null ? '' : ($diff < 0 ? 'text-danger' : ($diff > 0 ? 'text-success' : '')) ?>"><?= $diff === null ? '' : (($diff > 0 ? '+' : '') . qty($diff)) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($draft): ?><div class="card-footer"><button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer les comptages</button> <span class="small text-secondary ms-2">Laissez vide un article non compté : il ne sera pas ajusté.</span></div><?php endif; ?>
</div></form>
