<?php $isEdit = !empty($p['id']); ?>
<form method="post" action="<?= url($isEdit ? 'products/' . (int) $p['id'] : 'products') ?>" class="card"><div class="card-body"><?= csrf_field() ?>
<div class="row g-3">
  <div class="col-md-6"><label class="form-label">Nom du produit *</label><input name="name" class="form-control" required maxlength="150" value="<?= e($p['name'] ?? '') ?>" placeholder="Ex. Riz parfumé 5 kg"></div>
  <div class="col-md-3"><label class="form-label">Catégorie</label><select name="category_id" class="form-select"><option value="">—</option>
    <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) ($p['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label">Marque</label><input name="brand" class="form-control" maxlength="100" value="<?= e($p['brand'] ?? '') ?>"></div>
  <div class="col-md-3"><label class="form-label">Référence commerciale</label><input name="reference" class="form-control" maxlength="80" value="<?= e($p['reference'] ?? '') ?>"></div>
  <div class="col-md-3"><label class="form-label">Code-barres</label><input name="barcode" class="form-control" maxlength="64" value="<?= e($p['barcode'] ?? '') ?>"></div>
  <div class="col-md-3"><label class="form-label">Prix d'achat (FCFA)</label><input name="purchase_price" type="number" step="1" min="0" class="form-control" value="<?= e($p['purchase_price'] ?? 0) ?>"></div>
  <div class="col-md-3"><label class="form-label">Prix de vente (FCFA)</label><input name="sale_price" type="number" step="1" min="0" class="form-control" value="<?= e($p['sale_price'] ?? 0) ?>"></div>
  <div class="col-md-3"><label class="form-label">Unité de mesure</label><input name="unit" class="form-control" required maxlength="20" value="<?= e($p['unit'] ?? 'pièce') ?>"></div>
  <div class="col-md-3"><label class="form-label">Seuil d'alerte du stock</label><input name="stock_alert_threshold" type="number" step="0.001" min="0" class="form-control" value="<?= e($p['stock_alert_threshold'] ?? 0) ?>"></div>
  <?php if (!$isEdit): ?><div class="col-md-3"><label class="form-label">Stock initial</label><input name="initial_stock" type="number" step="0.001" min="0" class="form-control" value="0">
    <div class="form-text">Enregistré comme une réception de marchandises.</div></div>
  <?php else: ?><div class="col-md-3"><label class="form-label">Stock théorique</label><input class="form-control" disabled value="<?= e(qty($p['stock_quantity'])) ?>"><div class="form-text">Modifiable via le module Stocks.</div></div><?php endif; ?>
  <div class="col-md-3"><label class="form-label">Confiance minimale de détection</label><input name="min_confidence" type="number" step="0.01" min="0.5" max="0.99" class="form-control" value="<?= e($p['min_confidence'] ?? 0.75) ?>">
    <div class="form-text">En dessous : « Produit non identifié ».</div></div>
  <div class="col-md-3 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="act" <?= !empty($p['is_active']) ? 'checked' : '' ?>><label class="form-check-label" for="act">Produit actif</label></div></div>
</div></div>
<div class="card-footer bg-white d-flex gap-2"><button class="btn btn-primary">Enregistrer</button><a href="<?= url($isEdit ? 'products/' . (int) $p['id'] : 'products') ?>" class="btn btn-light">Annuler</a></div></form>
