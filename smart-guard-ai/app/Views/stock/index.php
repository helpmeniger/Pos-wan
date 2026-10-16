<div class="d-flex flex-wrap gap-2 mb-3 no-print">
  <a href="<?= url('stock/movements') ?>" class="btn btn-outline-primary"><i class="bi bi-arrow-left-right me-1"></i>Mouvements</a>
  <a href="<?= url('stock/reconciliation') ?>" class="btn btn-outline-primary"><i class="bi bi-intersect me-1"></i>Rapprochement</a>
  <span class="ms-auto text-muted align-self-center">Valeur du stock théorique (prix d'achat) : <strong><?= money($value) ?></strong></span>
</div>
<div class="alert alert-light border small">Le stock théorique n'est modifié que par des mouvements <strong>validés</strong> (réceptions, ventes, retours, inventaires…). Les observations des caméras sont enregistrées séparément et n'affectent jamais le stock sans validation.</div>
<form method="post" action="<?= url('stock/inventory') ?>"><?= csrf_field() ?>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
<thead><tr><th>Produit</th><th>Catégorie</th><th class="text-end">Stock théorique</th><th class="text-end">Seuil</th><th>Dernier inventaire</th><?php if (can('stock.inventory')): ?><th style="width:150px">Quantité comptée</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($rows as $p): $low = (float) $p['stock_alert_threshold'] > 0 && (float) $p['stock_quantity'] <= (float) $p['stock_alert_threshold']; ?>
<tr><td><a href="<?= url('stock/movements', ['product_id' => (int) $p['id']]) ?>"><?= e($p['name']) ?></a></td><td><?= e($p['category']) ?></td>
  <td class="text-end <?= $low ? 'text-danger fw-bold' : '' ?>"><?= qty($p['stock_quantity']) ?> <?= e($p['unit']) ?></td><td class="text-end"><?= qty($p['stock_alert_threshold']) ?></td>
  <td class="small"><?= dt($p['last_inventory'], 'd/m/Y') ?></td>
  <?php if (can('stock.inventory')): ?><td><input name="counts[<?= (int) $p['id'] ?>]" type="number" step="0.001" min="0" class="form-control form-control-sm"></td><?php endif; ?></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if (can('stock.inventory') && $rows): ?><div class="card-footer bg-white d-flex flex-wrap gap-3 align-items-center">
  <input name="comment" class="form-control form-control-sm" style="max-width:320px" placeholder="Commentaire d'inventaire" maxlength="255">
  <div class="form-check"><input class="form-check-input" type="checkbox" name="adjust" value="1" id="adj" checked><label class="form-check-label" for="adj">Régulariser le stock théorique sur les quantités comptées</label></div>
  <button class="btn btn-primary btn-sm ms-auto">Enregistrer l'inventaire</button></div><?php endif; ?>
</div></form>
