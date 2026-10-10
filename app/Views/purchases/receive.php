<form method="post" action="<?= e(url('/receptions')) ?>" data-confirm="Valider la réception ? Le stock et la dette fournisseur seront mis à jour."><?= csrf_field() ?>
<input type="hidden" name="purchase_order_id" value="<?= (int) $po['id'] ?>"><input type="hidden" name="supplier_id" value="<?= (int) $po['supplier_id'] ?>"><input type="hidden" name="warehouse_id" value="<?= (int) $po['warehouse_id'] ?>">
<div class="card mb-3"><div class="card-body row g-2">
  <div class="col-md-4">Fournisseur : <b><?= e($po['supplier']) ?></b><br>Entrepôt : <?= e($po['warehouse']) ?></div>
  <div class="col-md-3"><label class="form-label">N° facture fournisseur</label><input name="supplier_invoice_ref" class="form-control" maxlength="100"></div>
  <div class="col-md-2"><label class="form-label">Frais d'approche (F)</label><input type="number" step="any" min="0" name="extra_costs" class="form-control" value="0" title="Transport, manutention… répartis au prorata"></div>
  <div class="col-md-3"><label class="form-label">Échéance de paiement</label><input type="date" name="due_date" class="form-control"></div>
</div></div>
<?= App\Core\View::partial('purchases/_cost_help') ?>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0 align-middle"><thead><tr><th>Article</th><th class="num">Commandé</th><th class="num">Déjà reçu</th><th style="width:120px">Reçu maintenant</th><th style="width:140px">P.U. facturé</th><th style="width:150px">Coût article</th><?php if (can('products.price_edit')): ?><th style="width:140px">Nouveau prix de vente</th><?php endif; ?><?php if (feature('lots')): ?><th>Lot</th><th>Péremption</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($items as $n => $i): $rest = max(0, (float) $i['quantity'] - (float) $i['received_qty']); ?>
<tr><td><input type="hidden" name="items[<?= $n ?>][product_id]" value="<?= (int) $i['product_id'] ?>"><input type="hidden" name="items[<?= $n ?>][po_item_id]" value="<?= (int) $i['id'] ?>"><?= e($i['name']) ?></td><td class="num"><?= qty($i['quantity']) ?></td><td class="num"><?= qty($i['received_qty']) ?></td>
<td><input type="number" step="any" min="0" name="items[<?= $n ?>][quantity]" value="<?= e($rest) ?>" class="form-control form-control-sm"></td><td><input type="number" step="any" min="0" name="items[<?= $n ?>][unit_cost]" value="<?= e((float) $i['unit_cost']) ?>" class="form-control form-control-sm"></td>
<td><select name="items[<?= $n ?>][cost_mode]" class="form-select form-select-sm" data-cost-mode title="Calcul du coût d'achat de l'article"><option value="average">Prix moyen</option><option value="new">Nouveau prix</option><option value="old">Ancien prix</option></select><?php if (can('products.cost_view')): ?><div class="form-text">actuel : <?= money($i['cost_price']) ?></div><?php endif; ?></td>
<?php if (can('products.price_edit')): ?><td><input type="number" step="any" min="0" name="items[<?= $n ?>][new_sale_price]" class="form-control form-control-sm" placeholder="<?= e(money($i['sale_price'], false)) ?>"><div class="form-text">actuel : <?= money($i['sale_price']) ?></div></td><?php endif; ?>
<?php if (feature('lots')): ?><td><input name="items[<?= $n ?>][lot_number]" class="form-control form-control-sm" maxlength="80" <?= (int) $i['track_lots'] === 1 ? 'required' : 'disabled' ?>></td><td><input type="date" name="items[<?= $n ?>][expiry_date]" class="form-control form-control-sm" <?= (int) $i['track_lots'] === 1 ? 'required' : 'disabled' ?>></td><?php endif; ?></tr>
<?php endforeach; ?>
</tbody></table></div>
<div class="card-footer"><input name="note" class="form-control mb-2" placeholder="Note (état de la livraison, manquants…)" maxlength="255"><button class="btn btn-success"><i class="bi bi-check2-all"></i> Valider la réception</button></div></div>
</form>
