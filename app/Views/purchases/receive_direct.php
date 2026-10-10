<form method="post" action="<?= e(url('/receptions')) ?>" data-confirm="Valider la réception ? Le stock et la dette fournisseur seront mis à jour."><?= csrf_field() ?>
<div class="card mb-3"><div class="card-body row g-2">
  <div class="col-md-3"><label class="form-label required">Fournisseur</label><select name="supplier_id" class="form-select" required><option value="">—</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $supplierId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label required">Entrepôt</label><select name="warehouse_id" class="form-select"><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= (int) $w['id'] === $wh ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label">N° facture</label><input name="supplier_invoice_ref" class="form-control" maxlength="100"></div>
  <div class="col-md-2"><label class="form-label">Frais d'approche</label><input type="number" step="any" min="0" name="extra_costs" class="form-control" value="0"></div>
  <div class="col-md-2"><label class="form-label">Échéance</label><input type="date" name="due_date" class="form-control"></div>
</div></div>
<div class="card" data-lines data-template="#tplRec"><div class="card-body">
  <input class="form-control mb-1" data-line-search placeholder="Ajouter un article (nom, référence, scanner le code-barres)…" autocomplete="off"><div class="list-group mb-2" data-line-results></div>
  <table class="table table-sm"><thead><tr><th>Article</th><th style="width:110px">Quantité</th><th style="width:140px">P.U. d'achat</th><?php if (feature('lots')): ?><th>Lot / péremption</th><?php endif; ?><th class="num">Total</th><th></th></tr></thead><tbody></tbody>
  <tfoot><tr><th colspan="<?= feature('lots') ? 4 : 3 ?>" class="text-end">Total marchandises</th><th class="num" data-grand-total>0 F</th><th></th></tr></tfoot></table>
  <input name="note" class="form-control mb-2" placeholder="Note" maxlength="255">
  <button class="btn btn-success"><i class="bi bi-check2-all"></i> Valider la réception</button>
</div></div>
</form>
<template id="tplRec"><tr><td><input type="hidden" name="items[__i__][product_id]" data-pid><span data-name></span></td><td><input type="number" step="any" min="0" name="items[__i__][quantity]" class="form-control form-control-sm" data-q required></td><td><input type="number" step="any" min="0" name="items[__i__][unit_cost]" class="form-control form-control-sm" data-c required></td><?php if (feature('lots')): ?><td><div class="d-flex gap-1" data-lot-fields><input name="items[__i__][lot_number]" class="form-control form-control-sm" placeholder="N° lot" maxlength="80"><input type="date" name="items[__i__][expiry_date]" class="form-control form-control-sm"></div></td><?php endif; ?><td class="num" data-line-total></td><td><button type="button" class="btn btn-sm btn-link text-danger" data-remove-line><i class="bi bi-x-circle"></i></button></td></tr></template>
