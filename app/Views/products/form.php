<?php
use App\Core\Session;
$isEdit = !empty($p['id']);
$v = fn(string $k, $d = '') => e(Session::old($k, (string) ($p[$k] ?? $d)));
$chk = fn(string $k) => (Session::old($k, (string) ($p[$k] ?? '')) === '1') ? 'checked' : '';
$sel = fn(string $k, $id) => (string) Session::old($k, (string) ($p[$k] ?? '')) === (string) $id ? 'selected' : '';
$canPrice = !$isEdit || can('products.price_edit');
?>
<form method="post" enctype="multipart/form-data" action="<?= e(url($isEdit ? '/products/' . $p['id'] : '/products')) ?>">
<?= csrf_field() ?>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-info-circle"></i> Identification</div><div class="card-body row g-3">
      <div class="col-md-8"><label class="form-label required">Nom de l'article</label><input name="name" value="<?= $v('name') ?>" class="form-control" required maxlength="200" autofocus></div>
      <div class="col-md-4"><label class="form-label">Référence interne</label><input name="reference" value="<?= $v('reference') ?>" class="form-control" maxlength="50" placeholder="Auto si vide"></div>
      <div class="col-md-6"><label class="form-label">Code-barres</label><div class="input-group"><input name="barcode" id="barcodeField" value="<?= $v('barcode') ?>" class="form-control" maxlength="64" placeholder="Scanner le code-barres"><button type="button" class="btn btn-outline-primary" data-gen-barcode="<?= e(url('/products/new-barcode')) ?>" data-target="#barcodeField" title="Créer un code-barres interne (EAN-13) pour un article qui n'en possède pas"><i class="bi bi-upc"></i> Générer</button></div><div class="form-text">Article sans code-barres : cliquez sur « Générer », puis imprimez l'étiquette.</div></div>
      <div class="col-md-6"><label class="form-label">Catégorie</label><select name="category_id" class="form-select"><option value="">—</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel('category_id', $c['id']) ?>><?= e(($c['parent'] ? $c['parent'] . ' › ' : '') . $c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-4"><label class="form-label">Marque</label><select name="brand_id" class="form-select"><option value="">—</option><?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $sel('brand_id', $b['id']) ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-4"><label class="form-label">Unité de vente</label><select name="unit_id" class="form-select"><option value="">—</option><?php foreach ($units as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $sel('unit_id', $u['id']) ?>><?= e($u['name']) ?> (<?= e($u['short_name']) ?>)</option><?php endforeach; ?></select></div>
      <div class="col-md-4"><label class="form-label">Fournisseur principal</label><select name="supplier_id" class="form-select"><option value="">—</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sel('supplier_id', $s['id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      <?php if (feature('variants')): ?>
      <div class="col-md-6"><label class="form-label">Variante de</label><select name="parent_id" class="form-select"><option value="">— (article principal)</option><?php foreach ($parents as $pa): if (($p['id'] ?? 0) == $pa['id']) continue; ?><option value="<?= (int) $pa['id'] ?>" <?= $sel('parent_id', $pa['id']) ?>><?= e($pa['name']) ?> (<?= e($pa['reference']) ?>)</option><?php endforeach; ?></select></div>
      <div class="col-md-3"><label class="form-label">Taille</label><input name="variant_size" value="<?= $v('variant_size') ?>" class="form-control" maxlength="50" placeholder="S, M, 42…"></div>
      <div class="col-md-3"><label class="form-label">Couleur</label><input name="variant_color" value="<?= $v('variant_color') ?>" class="form-control" maxlength="50"></div>
      <?php endif; ?>
      <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2" maxlength="2000"><?= $v('description') ?></textarea></div>
    </div></div>

    <div class="card mb-3"><div class="card-header"><i class="bi bi-cash-coin"></i> Prix (TTC, en FCFA)<?= !$canPrice ? ' <span class="badge bg-warning text-dark">lecture seule</span>' : '' ?></div><div class="card-body row g-3">
      <div class="col-md-3"><label class="form-label required">Prix de vente</label><input type="number" step="any" min="0" name="sale_price" value="<?= $v('sale_price') ?>" class="form-control fw-bold" required <?= $canPrice ? '' : 'readonly' ?>></div>
      <?php if (can('products.cost_view')): ?>
      <div class="col-md-3"><label class="form-label">Prix d'achat <?= $isEdit ? '(CMP)' : '' ?></label><input type="number" step="any" min="0" name="cost_price" value="<?= $v('cost_price') ?>" class="form-control" <?= $isEdit && !can('products.price_edit') ? 'readonly' : '' ?>><div class="form-text"><?= $isEdit ? 'Recalculé automatiquement à chaque réception.' : 'Coût du stock initial.' ?></div></div>
      <?php endif; ?>
      <?php if (feature('wholesale')): ?>
      <div class="col-md-3"><label class="form-label">Prix de gros</label><input type="number" step="any" min="0" name="wholesale_price" value="<?= $v('wholesale_price') ?>" class="form-control" <?= $canPrice ? '' : 'readonly' ?>></div>
      <div class="col-md-3"><label class="form-label">Qté min. gros</label><input type="number" step="any" min="0" name="wholesale_min_qty" value="<?= $v('wholesale_min_qty') ?>" class="form-control"></div>
      <?php endif; ?>
      <?php if (feature('promotions')): ?>
      <div class="col-md-4"><label class="form-label">Prix promotionnel</label><input type="number" step="any" min="0" name="promo_price" value="<?= $v('promo_price') ?>" class="form-control" <?= $canPrice ? '' : 'readonly' ?>></div>
      <div class="col-md-4"><label class="form-label">Début promo</label><input type="date" name="promo_start" value="<?= $v('promo_start') ?>" class="form-control"></div>
      <div class="col-md-4"><label class="form-label">Fin promo</label><input type="date" name="promo_end" value="<?= $v('promo_end') ?>" class="form-control"></div>
      <?php endif; ?>
      <div class="col-md-3"><label class="form-label">Taxe incluse (%)</label><input type="number" step="any" min="0" max="100" name="tax_rate" value="<?= $v('tax_rate', setting('default_tax_rate', '0')) ?>" class="form-control"></div>
    </div></div>

    <?php if (feature('pharmacy')): ?>
    <div class="card mb-3 border-info"><div class="card-header text-info"><i class="bi bi-capsule"></i> Informations pharmaceutiques</div><div class="card-body row g-3">
      <div class="col-md-3 form-check form-switch ms-3"><input type="checkbox" class="form-check-input" name="is_medicine" value="1" id="med" <?= $chk('is_medicine') ?>><label class="form-check-label" for="med">Médicament</label></div>
      <div class="col-md-4"><label class="form-label">Principe actif (DCI)</label><input name="active_ingredient" value="<?= $v('active_ingredient') ?>" class="form-control" maxlength="200"></div>
      <div class="col-md-4"><label class="form-label">Dosage</label><input name="dosage" value="<?= $v('dosage') ?>" class="form-control" maxlength="100" placeholder="500 mg"></div>
      <div class="col-md-4"><label class="form-label">Forme</label><input name="pharma_form" value="<?= $v('pharma_form') ?>" class="form-control" maxlength="100" placeholder="Comprimé, sirop…"></div>
      <div class="col-md-4"><label class="form-label">Présentation</label><input name="presentation" value="<?= $v('presentation') ?>" class="form-control" maxlength="150" placeholder="Boîte de 16"></div>
      <div class="col-md-4 d-flex flex-column justify-content-end">
        <div class="form-check"><input type="checkbox" class="form-check-input" name="requires_prescription" value="1" id="rx" <?= $chk('requires_prescription') ?>><label class="form-check-label" for="rx">Délivrance sur ordonnance</label></div>
        <div class="form-check"><input type="checkbox" class="form-check-input" name="is_controlled" value="1" id="ctl" <?= $chk('is_controlled') ?>><label class="form-check-label" for="ctl">Produit à surveillance renforcée</label></div>
      </div>
      <div class="col-12 small text-secondary">Les règles de délivrance doivent être validées par le pharmacien responsable selon la réglementation applicable au Niger.</div>
    </div></div>
    <?php endif; ?>
  </div>

  <div class="col-xl-4">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-image"></i> Photo</div><div class="card-body text-center">
      <img id="imgPrev" src="<?= e(upload_url($p['image'] ?? null) ?? '') ?>" class="img-fluid rounded mb-2 <?= empty($p['image']) ? 'd-none' : '' ?>" style="max-height:160px" alt="">
      <input type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif" class="form-control form-control-sm" data-preview="#imgPrev">
      <div class="form-text">JPG, PNG, WEBP · 2 Mo max · redimensionnée automatiquement.</div>
      <?php if (!empty($p['image'])): ?><div class="form-check text-start"><input type="checkbox" class="form-check-input" name="remove_image" value="1" id="ri"><label for="ri" class="form-check-label small">Supprimer la photo</label></div><?php endif; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-boxes"></i> Stock</div><div class="card-body">
      <div class="form-check form-switch"><input type="checkbox" class="form-check-input" name="track_stock" value="1" id="ts" <?= $isEdit ? $chk('track_stock') : 'checked' ?>><label class="form-check-label" for="ts">Gérer le stock de cet article</label></div>
      <?php if (feature('restaurant')): ?><div class="mb-2"><label class="form-label">Vendu sur</label><select name="sales_channel" class="form-select form-select-sm"><?php foreach (App\Services\ProductService::CHANNELS as $k => $l): ?><option value="<?= e($k) ?>" <?= ($p['sales_channel'] ?? 'all') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><div class="form-text">« Restaurant uniquement » : plats et boissons du menu, invisibles à la caisse boutique. « Boutique et restaurant » suit le réglage de la catégorie.</div></div><?php endif; ?>
      <input type="hidden" name="sellable" value="0"><div class="form-check form-switch"><input type="checkbox" class="form-check-input" name="sellable" value="1" id="sl" <?= !$isEdit || (int) ($p['sellable'] ?? 1) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="sl">Vendable (décocher pour un ingrédient / matière première)</label></div>
      <?php if ($isEdit && feature('recipes') && (int) ($p['sellable'] ?? 1) === 1 && can('restaurant.manage')): ?><a class="small" href="<?= e(url('/restaurant/recipes/' . $p['id'])) ?>"><i class="bi bi-journal-text"></i> Fiche technique (ingrédients)</a><?php endif; ?>
      <?php if (feature('lots')): ?><div class="form-check form-switch"><input type="checkbox" class="form-check-input" name="track_lots" value="1" id="tl" <?= $chk('track_lots') ?>><label class="form-check-label" for="tl">Suivi par lot et date de péremption (FEFO)</label></div><?php endif; ?>
      <?php if (feature('weight')): ?><div class="form-check form-switch"><input type="checkbox" class="form-check-input" name="sold_by_weight" value="1" id="sw" <?= $chk('sold_by_weight') ?>><label class="form-check-label" for="sw">Vendu au poids / quantité décimale</label></div><?php endif; ?>
      <label class="form-label mt-2">Seuil d'alerte</label><input type="number" step="any" min="0" name="min_stock" value="<?= $v('min_stock', '0') ?>" class="form-control">
      <?php if (!$isEdit): ?>
        <hr><div class="small fw-semibold mb-1">Stock initial (optionnel)</div>
        <div class="row g-2">
          <div class="col-6"><input type="number" step="any" min="0" name="initial_qty" class="form-control" placeholder="Quantité"></div>
          <div class="col-6"><select name="initial_warehouse" class="form-select"><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
          <?php if (feature('lots')): ?><div class="col-6"><input name="initial_lot" class="form-control" placeholder="N° de lot" maxlength="80"></div><div class="col-6"><input type="date" name="initial_expiry" class="form-control" title="Péremption"></div><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="alert alert-light border small mt-3 mb-0"><i class="bi bi-info-circle"></i> Les quantités ne se modifient pas ici : utilisez une <a href="<?= e(url('/receptions/create')) ?>">réception</a>, un <a href="<?= e(url('/stock/adjust', ['product' => $p['id']])) ?>">ajustement</a> ou un inventaire (traçabilité complète).</div>
      <?php endif; ?>
    </div></div>
    <div class="d-grid gap-2">
      <button class="btn btn-primary btn-lg"><i class="bi bi-check2"></i> Enregistrer</button>
      <?php if (!$isEdit): ?><button class="btn btn-outline-primary" name="save_new" value="1"><i class="bi bi-plus"></i> Enregistrer et nouveau</button><?php endif; ?>
      <a href="<?= e(url($isEdit ? '/products/' . $p['id'] : '/products')) ?>" class="btn btn-light">Annuler</a>
    </div>
  </div>
</div>
</form>
<?php if ($isEdit && feature('packaging')): ?>
<div class="card mt-3"><div class="card-header"><i class="bi bi-box2"></i> Conditionnements (paquet, carton…)</div><div class="card-body">
  <table class="table table-sm"><thead><tr><th>Nom</th><th class="num">Contient</th><th class="num">Prix</th><th>Code-barres</th><th></th></tr></thead><tbody>
  <?php foreach ($packs as $k): ?><tr><td><?= e($k['name']) ?></td><td class="num"><?= qty($k['factor']) ?> unités</td><td class="num"><?= money($k['sale_price']) ?></td><td><?= e($k['barcode'] ?? '') ?></td><td class="text-end"><form method="post" action="<?= e(url('/products/packaging/' . $k['id'] . '/delete')) ?>" data-confirm="Supprimer ce conditionnement ?"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger"><i class="bi bi-trash"></i></button></form></td></tr><?php endforeach; ?>
  </tbody></table>
  <form method="post" action="<?= e(url('/products/' . $p['id'] . '/packaging')) ?>" class="row g-2"><?= csrf_field() ?>
    <div class="col-md-3"><input name="name" class="form-control form-control-sm" placeholder="Carton de 24" required maxlength="50"></div>
    <div class="col-md-2"><input type="number" step="any" min="2" name="factor" class="form-control form-control-sm" placeholder="Nb unités" required></div>
    <div class="col-md-2"><input type="number" step="any" min="0" name="sale_price" class="form-control form-control-sm" placeholder="Prix" required></div>
    <div class="col-md-3"><input name="barcode" class="form-control form-control-sm" placeholder="Code-barres (optionnel)" maxlength="64"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-plus"></i> Ajouter</button></div>
  </form>
</div></div>
<?php endif; ?>
