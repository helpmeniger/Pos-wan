<?php $action = $q['id'] ? url('/quotes/' . $q['id']) : url('/quotes'); ?>
<form method="post" action="<?= e($action) ?>"><?= csrf_field() ?>
<div class="card mb-3"><div class="card-body row g-2">
  <div class="col-md-4"><label class="form-label">Client enregistré</label>
    <select name="customer_id" class="form-select" data-customer-fill><option value="">— Client de passage —</option><?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>" data-name="<?= e($c['name']) ?>" data-phone="<?= e($c['phone'] ?? '') ?>" <?= (int) $q['customer_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?><?= $c['phone'] ? ' — ' . e($c['phone']) : '' ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label required">Nom du client</label><input name="customer_name" value="<?= e($q['customer_name']) ?>" class="form-control" maxlength="150" required></div>
  <div class="col-md-4"><label class="form-label">Téléphone</label><input name="customer_phone" value="<?= e($q['customer_phone'] ?? '') ?>" class="form-control" maxlength="50"></div>
  <div class="col-md-6"><label class="form-label">Adresse</label><input name="customer_address" value="<?= e($q['customer_address'] ?? '') ?>" class="form-control" maxlength="255"></div>
  <div class="col-md-2"><label class="form-label">Valable jusqu'au</label><input type="date" name="valid_until" value="<?= e($q['valid_until']) ?>" class="form-control"></div>
  <div class="col-md-4"><label class="form-label">Conditions / note</label><input name="note" value="<?= e($q['note'] ?? '') ?>" class="form-control" maxlength="2000" placeholder="Délai de livraison, acompte…"></div>
</div></div>
<div class="card" data-lines data-template="#tplQuote" data-price="sale"><div class="card-body">
  <input class="form-control mb-1" data-line-search placeholder="Ajouter un article (nom, référence, code-barres)…" autocomplete="off"><div class="list-group mb-2" data-line-results></div>
  <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Article</th><th style="width:120px">Quantité</th><th style="width:150px">Prix unitaire</th><th style="width:130px">Remise (montant)</th><th class="num">Total</th><th></th></tr></thead><tbody>
  <?php foreach ($items as $n => $i): ?>
    <tr><td><input type="hidden" name="items[<?= $n ?>][product_id]" value="<?= (int) $i['product_id'] ?>" data-pid><span data-name><?= e($i['name']) ?><?= $i['reference'] ? ' (' . e($i['reference']) . ')' : '' ?></span></td>
    <td><input type="number" step="any" min="0" name="items[<?= $n ?>][quantity]" value="<?= e((string) (float) $i['quantity']) ?>" class="form-control form-control-sm" data-q required></td>
    <td><input type="number" step="any" min="0" name="items[<?= $n ?>][unit_price]" value="<?= e((string) (float) $i['unit_price']) ?>" class="form-control form-control-sm" data-c <?= can('pos.price_override') ? '' : 'readonly' ?>></td>
    <td><input type="number" step="any" min="0" name="items[<?= $n ?>][discount]" value="<?= e((string) (float) $i['discount']) ?>" class="form-control form-control-sm" data-d <?= can('pos.discount') ? '' : 'readonly' ?>></td>
    <td class="num" data-line-total></td><td><button type="button" class="btn btn-sm btn-link text-danger" data-remove-line><i class="bi bi-x-circle"></i></button></td></tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr><th colspan="4" class="text-end">Total du devis</th><th class="num" data-grand-total><?= e(money(0)) ?></th><th></th></tr></tfoot></table></div>
  <button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer le devis</button>
  <a href="<?= e(url($q['id'] ? '/quotes/' . $q['id'] : '/quotes')) ?>" class="btn btn-outline-secondary">Annuler</a>
</div></div>
</form>
<template id="tplQuote"><tr><td><input type="hidden" name="items[__i__][product_id]" data-pid><span data-name></span></td><td><input type="number" step="any" min="0" name="items[__i__][quantity]" value="1" class="form-control form-control-sm" data-q required></td><td><input type="number" step="any" min="0" name="items[__i__][unit_price]" class="form-control form-control-sm" data-c <?= can('pos.price_override') ? '' : 'readonly' ?>></td><td><input type="number" step="any" min="0" name="items[__i__][discount]" value="0" class="form-control form-control-sm" data-d <?= can('pos.discount') ? '' : 'readonly' ?>></td><td class="num" data-line-total></td><td><button type="button" class="btn btn-sm btn-link text-danger" data-remove-line><i class="bi bi-x-circle"></i></button></td></tr></template>
