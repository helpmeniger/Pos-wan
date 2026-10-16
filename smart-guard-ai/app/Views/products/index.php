<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-md-4"><input name="q" class="form-control" placeholder="Nom, marque, code-barres, référence" value="<?= e($_GET['q'] ?? '') ?>"></div>
  <div class="col-md-3"><select name="category" class="form-select"><option value="">Toutes catégories</option>
    <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) ($_GET['category'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><select name="recognition" class="form-select"><option value="">État de reconnaissance</option>
    <?php foreach (['aucune', 'photos_insuffisantes', 'prete', 'validee', 'active', 'echec'] as $s): ?><option value="<?= $s ?>" <?= ($_GET['recognition'] ?? '') === $s ? 'selected' : '' ?>><?= e(label('recognition', $s)) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2 d-flex gap-2"><button class="btn btn-outline-primary flex-fill">Filtrer</button></div>
</form>
<div class="d-flex justify-content-between mb-2">
  <span class="text-muted"><?= (int) $total ?> produit(s)</span>
  <?php if (can('products.manage')): ?><div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="<?= url('products/import') ?>"><i class="bi bi-upload me-1"></i>Importer (CSV)</a>
    <a class="btn btn-primary btn-sm" href="<?= url('products/create') ?>"><i class="bi bi-plus-lg me-1"></i>Nouveau produit</a></div><?php endif; ?>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
<thead><tr><th>Produit</th><th>Catégorie</th><th>Code-barres</th><th class="text-end">Prix de vente</th><th class="text-end">Stock théorique</th><th>Photos</th><th>Reconnaissance</th></tr></thead><tbody>
<?php foreach ($products as $p): ?>
<tr class="<?= $p['is_active'] ? '' : 'text-muted' ?>">
  <td><a href="<?= url('products/' . (int) $p['id']) ?>" class="fw-semibold"><?= e($p['name']) ?></a> <?= $p['is_demo'] ? '<span class="badge demo-badge">démo fictive</span>' : '' ?><div class="small text-muted"><?= e($p['brand']) ?></div></td>
  <td><?= e($p['category']) ?></td><td><code><?= e($p['barcode']) ?></code></td>
  <td class="text-end"><?= money($p['sale_price']) ?></td>
  <td class="text-end <?= (float) $p['stock_alert_threshold'] > 0 && (float) $p['stock_quantity'] <= (float) $p['stock_alert_threshold'] ? 'text-danger fw-bold' : '' ?>"><?= qty($p['stock_quantity']) ?> <?= e($p['unit']) ?></td>
  <td><?= (int) $p['photos'] ?> / <?= $minImages ?></td>
  <td><span class="badge <?= $p['recognition_status'] === 'active' ? 'bg-success' : ($p['recognition_status'] === 'echec' ? 'bg-danger' : 'bg-secondary') ?>"><?= e(label('recognition', $p['recognition_status'])) ?></span></td>
</tr>
<?php endforeach; if (!$products): ?><tr><td colspan="7" class="text-center text-muted py-4">Aucun produit.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/../partials/pagination.php'; ?>
