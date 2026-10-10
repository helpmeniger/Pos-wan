<div class="card" style="max-width:640px"><div class="card-body">
  <form method="post" action="<?= e(url('/products/bulk-price')) ?>" data-confirm="Appliquer cette modification de prix ? L'ancien prix est conservé dans l'historique."><?= csrf_field() ?>
    <div class="mb-3"><label class="form-label">Catégorie</label><select name="category_id" class="form-select"><option value="">Tous les articles actifs</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="row g-2 mb-3">
      <div class="col-md-5"><label class="form-label">Type de variation</label><select name="mode" class="form-select"><option value="percent">Pourcentage (%)</option><option value="amount">Montant fixe (F)</option></select></div>
      <div class="col-md-3"><label class="form-label required">Valeur</label><input type="number" step="any" name="value" class="form-control" required placeholder="+5 ou -10"></div>
      <div class="col-md-4"><label class="form-label">Arrondir au</label><select name="rounding" class="form-select"><option value="1">unité</option><option value="5">5 <?= e(currency()['symbol']) ?></option><option value="25" selected>25 <?= e(currency()['symbol']) ?></option><option value="50">50 <?= e(currency()['symbol']) ?></option><option value="100">100 <?= e(currency()['symbol']) ?></option></select></div>
    </div>
    <button class="btn btn-warning"><i class="bi bi-percent"></i> Appliquer</button>
  </form>
</div></div>
