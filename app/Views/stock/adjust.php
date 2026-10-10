<div class="row g-3">
  <div class="col-lg-7"><div class="card"><div class="card-header"><i class="bi bi-sliders"></i> Ajustement justifié</div><div class="card-body">
    <?php if (!$product): ?>
      <label class="form-label">Article</label>
      <input class="form-control" data-product-picker="<?= e(url('/stock/adjust')) ?>?product=" placeholder="Rechercher un article (nom, référence, code-barres)…" autocomplete="off" autofocus>
      <div class="list-group mt-1" data-picker-results></div>
      <p class="small text-secondary mt-2">Sélectionnez un article pour l'ajuster.</p>
    <?php else: ?>
      <h5><?= e($product['name']) ?> <small class="text-secondary"><?= e($product['reference']) ?></small></h5>
      <form method="post" action="<?= e(url('/stock/adjust')) ?>" class="row g-3" data-confirm="Confirmer cet ajustement de stock ? Il sera tracé dans l'historique."><?= csrf_field() ?>
        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
        <div class="col-md-6"><label class="form-label required">Entrepôt</label><select name="warehouse_id" class="form-select"><?php foreach ($levels as $l): ?><option value="<?= (int) $l['id'] ?>" <?= (int) $l['id'] === $wh ? 'selected' : '' ?>><?= e($l['name']) ?> (stock : <?= qty($l['q']) ?>)</option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label required">Nature</label><select name="type" class="form-select"><option value="adjustment">Correction / ajustement</option><option value="loss">Perte, casse, vol</option><?php if ((int) $product['track_lots'] === 1): ?><option value="expired">Produit périmé (destruction)</option><?php endif; ?></select></div>
        <div class="col-md-6"><label class="form-label required">Opération</label><select name="mode" class="form-select"><option value="remove">Retirer une quantité</option><option value="add">Ajouter une quantité</option><option value="set">Fixer la quantité réelle</option></select></div>
        <div class="col-md-6"><label class="form-label required">Quantité</label><input type="number" step="any" min="0" name="quantity" class="form-control" required></div>
        <?php if ((int) $product['track_lots'] === 1): ?>
          <div class="col-md-12"><label class="form-label">Lot concerné (sortie)</label><select name="lot_id" class="form-select"><option value="">Automatique (FEFO)</option><?php foreach ($lots as $l): ?><option value="<?= (int) $l['id'] ?>"><?= e($l['lot_number']) ?> · <?= e($l['warehouse']) ?> · exp. <?= e(fdate($l['expiry_date'], false)) ?> · <?= qty($l['quantity']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label">N° de lot (entrée)</label><input name="lot_number" class="form-control" maxlength="80"></div>
          <div class="col-md-6"><label class="form-label">Péremption (entrée)</label><input type="date" name="expiry_date" class="form-control"></div>
        <?php endif; ?>
        <div class="col-12"><label class="form-label required">Motif détaillé</label><input name="reason" class="form-control" required maxlength="255" placeholder="ex. carton endommagé lors du déchargement"></div>
        <div class="col-12"><button class="btn btn-warning"><i class="bi bi-check2"></i> Valider l'ajustement</button> <a href="<?= e(url('/stock/adjust')) ?>" class="btn btn-light">Autre article</a></div>
      </form>
    <?php endif; ?>
  </div></div></div>
  <div class="col-lg-5"><div class="alert alert-info small"><i class="bi bi-info-circle"></i> Le stock n'est jamais modifié directement : chaque ajustement crée un mouvement daté, signé (utilisateur) et motivé. Pour une recherche de quantité à l'unité, préférez un <a href="<?= e(url('/inventories')) ?>">inventaire</a>.</div></div>
</div>
