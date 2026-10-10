<form method="post" action="<?= e(url('/purchases')) ?>"><?= csrf_field() ?>
<div class="card mb-3"><div class="card-body row g-2">
  <div class="col-md-4"><label class="form-label required">Fournisseur</label><select name="supplier_id" class="form-select" required><option value="">—</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $supplierId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select><a class="small" href="<?= e(url('/suppliers/create')) ?>">+ nouveau fournisseur</a></div>
  <div class="col-md-3"><label class="form-label required">Entrepôt de livraison</label><select name="warehouse_id" class="form-select"><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= (int) $w['id'] === $wh ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label">Livraison prévue</label><input type="date" name="expected_date" class="form-control"></div>
  <div class="col-md-3"><label class="form-label">Note</label><input name="note" class="form-control" maxlength="255"></div>
</div></div>
<div class="card" data-lines data-template="#tplPo"><div class="card-body">
  <input class="form-control mb-1" data-line-search placeholder="Ajouter un article (nom, référence, code-barres)…" autocomplete="off"><div class="list-group mb-2" data-line-results></div>
  <table class="table table-sm"><thead><tr><th>Article</th><th style="width:140px">Quantité</th><th style="width:160px">Prix d'achat unitaire</th><th class="num">Total</th><th></th></tr></thead><tbody></tbody>
  <tfoot><tr><th colspan="3" class="text-end">Total commande</th><th class="num" data-grand-total><?= e(money(0)) ?></th><th></th></tr></tfoot></table>
  <button class="btn btn-primary" name="status" value="ordered"><i class="bi bi-send"></i> Enregistrer la commande</button>
  <button class="btn btn-outline-secondary" name="status" value="draft">Brouillon</button>
</div></div>
</form>
<template id="tplPo"><tr><td><input type="hidden" name="items[__i__][product_id]" data-pid><span data-name></span></td><td><input type="number" step="any" min="0" name="items[__i__][quantity]" class="form-control form-control-sm" data-q required></td><td><input type="number" step="any" min="0" name="items[__i__][unit_cost]" class="form-control form-control-sm" data-c required></td><td class="num" data-line-total></td><td><button type="button" class="btn btn-sm btn-link text-danger" data-remove-line><i class="bi bi-x-circle"></i></button></td></tr></template>
