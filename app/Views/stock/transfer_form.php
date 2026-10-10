<form method="post" action="<?= e(url('/stock/transfers')) ?>" data-confirm="Valider ce transfert ? Les quantités sortent immédiatement de l'entrepôt de départ."><?= csrf_field() ?>
<div class="card mb-3"><div class="card-body row g-2">
  <div class="col-md-4"><label class="form-label required">De l'entrepôt</label><select name="from_warehouse_id" class="form-select" required><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= (int) $w['id'] === $wh ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label required">Vers l'entrepôt</label><select name="to_warehouse_id" class="form-select" required><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= (int) $w['id'] !== $wh ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Note</label><input name="note" class="form-control" maxlength="255"></div>
</div></div>
<div class="card" data-lines data-template="#tplLine" data-warehouse="<?= (int) $wh ?>"><div class="card-body">
  <input class="form-control mb-1" data-line-search placeholder="Ajouter un article (nom, référence, scanner le code-barres)…" autocomplete="off"><div class="list-group mb-2" data-line-results></div>
  <table class="table table-sm"><thead><tr><th>Article</th><th class="num">Stock départ</th><th style="width:160px">Quantité</th><th></th></tr></thead><tbody></tbody></table>
  <button class="btn btn-primary"><i class="bi bi-truck"></i> Valider le transfert</button>
</div></div>
</form>
<template id="tplLine"><tr><td><input type="hidden" name="items[__i__][product_id]" data-pid><span data-name></span></td><td class="num" data-stock></td><td><input type="number" step="any" min="0" name="items[__i__][quantity]" class="form-control form-control-sm" data-q required></td><td><button type="button" class="btn btn-sm btn-link text-danger" data-remove-line><i class="bi bi-x-circle"></i></button></td></tr></template>
