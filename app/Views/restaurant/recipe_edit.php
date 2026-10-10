<form method="post" action="<?= e(url('/restaurant/recipes/' . $p['id'])) ?>"><?= csrf_field() ?>
<div class="card mb-3"><div class="card-body d-flex flex-wrap gap-4 align-items-center">
  <div><div class="small text-secondary">Prix de vente</div><div class="fs-5 fw-bold"><?= money($p['sale_price']) ?></div></div>
  <div><div class="small text-secondary">Coût matière actuel</div><div class="fs-5 fw-bold"><?= money($cost) ?></div></div>
  <div><div class="small text-secondary">Marge par portion</div><div class="fs-5 fw-bold text-success"><?= money((float) $p['sale_price'] - $cost) ?></div></div>
  <a href="<?= e(url('/restaurant/recipes')) ?>" class="btn btn-light ms-auto">Toutes les fiches</a>
</div></div>
<div class="card" data-lines data-template="#tplRc"><div class="card-body">
  <input class="form-control mb-1" data-line-search placeholder="Ajouter un ingrédient (riz, poulet, huile, oignon…)" autocomplete="off"><div class="list-group mb-2" data-line-results></div>
  <table class="table table-sm"><thead><tr><th>Ingrédient</th><th style="width:170px">Quantité par portion</th><th>Unité</th><th class="num">Coût</th><th></th></tr></thead><tbody>
  <?php foreach ($lines as $n => $l): ?><tr><td><input type="hidden" name="items[<?= $n ?>][product_id]" value="<?= (int) $l['ingredient_id'] ?>" data-pid><span data-name><?= e($l['name']) ?></span></td><td><input type="number" step="any" min="0" name="items[<?= $n ?>][quantity]" value="<?= e((float) $l['quantity']) ?>" class="form-control form-control-sm" data-q></td><td><?= e($l['unit'] ?? '') ?></td><td class="num"><input type="hidden" data-c value="<?= e((float) $l['cost_price']) ?>"><span data-line-total></span></td><td><button type="button" class="btn btn-sm btn-link text-danger" data-remove-line><i class="bi bi-x-circle"></i></button></td></tr><?php endforeach; ?>
  </tbody><tfoot><tr><th colspan="3" class="text-end">Coût matière</th><th class="num" data-grand-total></th><th></th></tr></tfoot></table>
  <button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer la fiche</button>
  <span class="small text-secondary ms-2">Quantité à 0 ou ligne retirée = ingrédient supprimé. Exemple : 0,25 kg de riz, 1 pièce de poulet.</span>
</div></div>
</form>
<template id="tplRc"><tr><td><input type="hidden" name="items[__i__][product_id]" data-pid><span data-name></span></td><td><input type="number" step="any" min="0" name="items[__i__][quantity]" class="form-control form-control-sm" data-q required></td><td></td><td class="num"><input type="hidden" data-c><span data-line-total></span></td><td><button type="button" class="btn btn-sm btn-link text-danger" data-remove-line><i class="bi bi-x-circle"></i></button></td></tr></template>
