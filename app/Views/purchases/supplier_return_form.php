<form method="post" action="<?= e(url('/supplier-returns')) ?>" data-confirm="Valider le retour ? Le stock sera diminué et la dette fournisseur réduite."><?= csrf_field() ?>
<div class="card mb-3"><div class="card-body row g-2">
  <div class="col-md-4"><label class="form-label required">Fournisseur</label><select name="supplier_id" class="form-select" required><option value="">—</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label required">Entrepôt</label><select name="warehouse_id" class="form-select"><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= (int) $w['id'] === $wh ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-5"><label class="form-label required">Motif</label><input name="reason" class="form-control" required maxlength="255" placeholder="Produit défectueux, rappel de lot, erreur de livraison…"></div>
</div></div>
<div class="card" data-lines data-template="#tplSr" data-warehouse="<?= (int) $wh ?>"><div class="card-body">
  <input class="form-control mb-1" data-line-search placeholder="Ajouter un article…" autocomplete="off"><div class="list-group mb-2" data-line-results></div>
  <table class="table table-sm"><thead><tr><th>Article</th><th class="num">Stock</th><th style="width:120px">Quantité</th><th style="width:150px">Valeur unitaire</th><th class="num">Total</th><th></th></tr></thead><tbody></tbody>
  <tfoot><tr><th colspan="4" class="text-end">Total à déduire</th><th class="num" data-grand-total>0 F</th><th></th></tr></tfoot></table>
  <button class="btn btn-warning"><i class="bi bi-box-arrow-up"></i> Valider le retour</button>
</div></div>
</form>
<template id="tplSr"><tr><td><input type="hidden" name="items[__i__][product_id]" data-pid><span data-name></span></td><td class="num" data-stock></td><td><input type="number" step="any" min="0" name="items[__i__][quantity]" class="form-control form-control-sm" data-q required></td><td><input type="number" step="any" min="0" name="items[__i__][unit_cost]" class="form-control form-control-sm" data-c required></td><td class="num" data-line-total></td><td><button type="button" class="btn btn-sm btn-link text-danger" data-remove-line><i class="bi bi-x-circle"></i></button></td></tr></template>
