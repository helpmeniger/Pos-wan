<?php $cost = can('products.cost_view'); $sortLink = fn($k, $l) => '<a class="text-reset text-decoration-none" href="' . e(query_with(['sort' => $k, 'dir' => (($_GET['sort'] ?? 'name') === $k && ($_GET['dir'] ?? '') !== 'desc') ? 'desc' : 'asc'])) . '">' . e($l) . ' <i class="bi bi-arrow-down-up small opacity-50"></i></a>'; ?>
<div class="row g-3">
  <div class="col-xl-10">
    <div class="card mb-3"><div class="card-body py-2">
      <form class="row g-2 align-items-end filters" method="get">
        <div class="col-md-4"><label class="form-label small mb-0">Recherche par nom / référence</label><div class="input-group input-group-sm"><input name="q" value="<?= e($_GET['q'] ?? '') ?>" class="form-control" placeholder="Nom, référence, DCI…" autofocus><button class="btn btn-success"><i class="bi bi-search"></i> RECHERCHE</button></div></div>
        <div class="col-md-3"><label class="form-label small mb-0">Recherche par code-barres</label><input name="barcode" value="<?= e($_GET['barcode'] ?? '') ?>" class="form-control form-control-sm" placeholder="Scanner ici"></div>
        <div class="col-auto"><label class="form-label small mb-0">Catégorie</label><select name="category" class="form-select form-select-sm" data-autosubmit><option value="">Toutes</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) ($_GET['category'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e(($c['parent'] ? $c['parent'] . ' › ' : '') . $c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-auto"><label class="form-label small mb-0">Stock</label><select name="stock" class="form-select form-select-sm" data-autosubmit><option value="">Tous</option><option value="low" <?= ($_GET['stock'] ?? '') === 'low' ? 'selected' : '' ?>>Sous le seuil</option><option value="out" <?= ($_GET['stock'] ?? '') === 'out' ? 'selected' : '' ?>>En rupture</option></select></div>
        <div class="col-auto"><label class="form-label small mb-0">Statut</label><select name="status" class="form-select form-select-sm" data-autosubmit><option value="active">Actifs</option><option value="archived" <?= ($_GET['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archivés</option><option value="all" <?= ($_GET['status'] ?? '') === 'all' ? 'selected' : '' ?>>Tous</option></select></div>
        <div class="col-auto"><a href="<?= e(url('/products')) ?>" class="btn btn-sm btn-outline-danger" title="Effacer les filtres"><i class="bi bi-trash3"></i></a></div>
      </form>
    </div></div>
    <div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0 align-middle">
      <thead><tr><th>Photo</th><th><?= $sortLink('name', 'Produit') ?></th><th><?= $sortLink('cat', 'Catégorie') ?></th><th class="num"><?= $sortLink('price', 'Prix vente') ?></th><?php if (feature('wholesale')): ?><th class="num">Prix gros</th><?php endif; ?><?php if ($cost): ?><th class="num">Prix achat (CMP)</th><?php endif; ?><th class="num"><?= $sortLink('stock', 'Stock') ?></th><th>Unité</th><th>Code-barres</th><th class="num">Alerte</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($p['rows'] as $r): $low = (int) $r['track_stock'] === 1 && (float) $r['stock'] <= (float) $r['min_stock']; ?>
        <tr class="<?= $r['status'] === 'archived' ? 'text-secondary' : '' ?>">
          <td><?php if ($r['image']): ?><img class="thumb" src="<?= e(upload_url($r['image'])) ?>" alt="" loading="lazy"><?php else: ?><span class="thumb-ph" style="background:<?= e($r['cat_color'] ?? '#95a5a6') ?>"><?= e(mb_strtoupper(mb_substr($r['name'], 0, 2))) ?></span><?php endif; ?></td>
          <td><a href="<?= e(url('/products/' . $r['id'])) ?>" class="fw-semibold text-decoration-none"><?= e($r['name']) ?></a><div class="small text-secondary"><?= e($r['reference']) ?><?= $r['variant_size'] || $r['variant_color'] ? ' · ' . e(trim($r['variant_size'] . ' ' . $r['variant_color'])) : '' ?><?= (int) $r['requires_prescription'] === 1 ? ' · <span class="badge bg-info">Ordonnance</span>' : '' ?><?= (int) $r['track_lots'] === 1 ? ' · <span class="badge badge-soft">lots</span>' : '' ?></div></td>
          <td><?= e($r['category'] ?? '—') ?></td>
          <td class="num fw-semibold"><?= money($r['sale_price']) ?></td>
          <?php if (feature('wholesale')): ?><td class="num"><?= $r['wholesale_price'] !== null ? money($r['wholesale_price']) : '' ?></td><?php endif; ?>
          <?php if ($cost): ?><td class="num text-secondary"><?= money($r['cost_price']) ?></td><?php endif; ?>
          <td class="num <?= $low ? ((float) $r['stock'] <= 0 ? 'text-danger fw-bold' : 'text-warning fw-bold') : '' ?>"><?= (int) $r['track_stock'] === 1 ? qty($r['stock']) : '∞' ?></td>
          <td><?= e($r['unit'] ?? '') ?></td>
          <td class="small"><?= e($r['barcode'] ?? '') ?></td>
          <td class="num"><?= qty($r['min_stock']) ?></td>
          <td class="text-end text-nowrap">
            <?php if (can('products.manage')): ?><a href="<?= e(url('/products/' . $r['id'] . '/edit')) ?>" class="btn btn-sm btn-light" title="Modifier"><i class="bi bi-pencil"></i></a><?php endif; ?>
            <a href="<?= e(url('/products/labels', ['ids' => [$r['id']]])) ?>" class="btn btn-sm btn-light" title="Étiquette"><i class="bi bi-upc"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$p['rows']): ?><tr><td colspan="11" class="text-center text-secondary py-5"><i class="bi bi-box-seam fs-1 d-block"></i>Aucun article trouvé.</td></tr><?php endif; ?>
      </tbody></table></div></div>
    <?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
  </div>
  <div class="col-xl-2">
    <div class="d-grid gap-2">
      <?php if (can('products.manage')): ?><a href="<?= e(url('/products/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-square"></i> Nouveau</a><?php endif; ?>
      <?php if (can('products.import')): ?>
        <a href="<?= e(url('/products/import')) ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-spreadsheet"></i> Importer</a>
        <a href="<?= e(url('/products/export') . query_with([])) ?>" class="btn btn-outline-success"><i class="bi bi-download"></i> Exporter (CSV)</a>
      <?php endif; ?>
      <?php if (can('products.price_edit')): ?><a href="<?= e(url('/products/bulk-price')) ?>" class="btn btn-outline-secondary"><i class="bi bi-percent"></i> Prix groupés</a><?php endif; ?>
      <a href="<?= e(url('/products/labels')) ?>" class="btn btn-outline-secondary"><i class="bi bi-upc-scan"></i> Étiquettes</a>
      <a href="<?= e(url('/products/duplicates')) ?>" class="btn btn-outline-danger btn-sm"><i class="bi bi-files"></i> Doublons potentiels</a>
      <?php if (can('categories.manage')): ?><a href="<?= e(url('/categories')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-tags"></i> Catégories</a><?php endif; ?>
    </div>
  </div>
</div>
