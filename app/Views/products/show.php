<?php $types = App\Services\Catalog::STOCK_MOVEMENT_TYPES; $total = array_sum(array_column($levels, 'quantity')); ?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <a class="btn btn-outline-secondary" href="<?= e(url('/products')) ?>"><i class="bi bi-arrow-left"></i> Articles</a>
  <?php if (can('products.manage')): ?>
    <a class="btn btn-primary" href="<?= e(url('/products/' . $p['id'] . '/edit')) ?>"><i class="bi bi-pencil"></i> Modifier</a>
    <a class="btn btn-outline-primary" href="<?= e(url('/products/create', ['duplicate' => $p['id']])) ?>"><i class="bi bi-copy"></i> Dupliquer<?= feature('variants') ? ' / variante' : '' ?></a>
  <?php endif; ?>
  <?php if (can('stock.adjust')): ?><a class="btn btn-outline-warning" href="<?= e(url('/stock/adjust', ['product' => $p['id']])) ?>"><i class="bi bi-sliders"></i> Ajuster le stock</a><?php endif; ?>
  <a class="btn btn-outline-secondary" href="<?= e(url('/products/labels', ['ids' => [$p['id']], 'copies' => 6])) ?>"><i class="bi bi-upc"></i> Étiquettes</a>
  <?php if (can('products.manage')): ?>
    <?php if ($p['status'] === 'active'): ?>
      <form method="post" action="<?= e(url('/products/' . $p['id'] . '/archive')) ?>" data-confirm="Archiver « <?= e($p['name']) ?> » ? Il ne sera plus vendable ; son historique est conservé."><?= csrf_field() ?><button class="btn btn-outline-danger"><i class="bi bi-archive"></i> Archiver</button></form>
    <?php else: ?>
      <form method="post" action="<?= e(url('/products/' . $p['id'] . '/restore')) ?>"><?= csrf_field() ?><button class="btn btn-outline-success"><i class="bi bi-arrow-counterclockwise"></i> Réactiver</button></form>
    <?php endif; ?>
  <?php endif; ?>
</div>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card mb-3"><div class="card-body">
      <div class="d-flex gap-3 align-items-center mb-3">
        <?php if ($p['image']): ?><img src="<?= e(upload_url($p['image'])) ?>" class="rounded" style="width:96px;height:96px;object-fit:contain" alt=""><?php endif; ?>
        <div><h2 class="h5 mb-1"><?= e($p['name']) ?></h2><?= App\Core\View::partial('partials/status', ['status' => $p['status']]) ?> <?= e($p['category'] ?? '') ?></div>
      </div>
      <dl class="row small mb-0">
        <dt class="col-5">Référence</dt><dd class="col-7"><?= e($p['reference']) ?></dd>
        <dt class="col-5">Code-barres</dt><dd class="col-7"><?= e($p['barcode'] ?? '—') ?><?php if ($p['barcode']): ?><br><img src="<?= e(url('/barcode/' . $p['id'])) ?>" alt="" style="height:44px"><?php elseif (can('products.manage')): ?><form method="post" action="<?= e(url('/products/' . $p['id'] . '/barcode')) ?>" class="mt-1"><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary"><i class="bi bi-upc"></i> Créer un code-barres</button></form><?php endif; ?></dd>
        <?php if ($p['brand']): ?><dt class="col-5">Marque</dt><dd class="col-7"><?= e($p['brand']) ?></dd><?php endif; ?>
        <dt class="col-5">Unité</dt><dd class="col-7"><?= e($p['unit'] ?? '—') ?></dd>
        <dt class="col-5">Fournisseur</dt><dd class="col-7"><?= e($p['supplier'] ?? '—') ?></dd>
        <?php if ($p['parent_name']): ?><dt class="col-5">Variante de</dt><dd class="col-7"><a href="<?= e(url('/products/' . $p['parent_id'])) ?>"><?= e($p['parent_name']) ?></a> (<?= e(trim($p['variant_size'] . ' ' . $p['variant_color'])) ?>)</dd><?php endif; ?>
        <dt class="col-5">Prix de vente</dt><dd class="col-7 fw-bold"><?= money($p['sale_price']) ?></dd>
        <?php if ($p['wholesale_price'] !== null): ?><dt class="col-5">Prix de gros</dt><dd class="col-7"><?= money($p['wholesale_price']) ?> (dès <?= qty($p['wholesale_min_qty']) ?>)</dd><?php endif; ?>
        <?php if ($p['promo_price'] !== null): ?><dt class="col-5">Promotion</dt><dd class="col-7"><?= money($p['promo_price']) ?> du <?= e(fdate($p['promo_start'], false)) ?> au <?= e(fdate($p['promo_end'], false)) ?></dd><?php endif; ?>
        <?php if (can('products.cost_view')): ?><dt class="col-5">Coût (CMP)</dt><dd class="col-7"><?= money($p['cost_price']) ?> · marge <?= (float) $p['sale_price'] > 0 ? number_format(((float) $p['sale_price'] - (float) $p['cost_price']) / (float) $p['sale_price'] * 100, 1, ',', ' ') : 0 ?> %</dd><?php endif; ?>
        <dt class="col-5">Seuil d'alerte</dt><dd class="col-7"><?= qty($p['min_stock']) ?></dd>
        <?php if ((int) $p['is_medicine'] === 1): ?><dt class="col-5">DCI</dt><dd class="col-7"><?= e($p['active_ingredient']) ?> <?= e($p['dosage']) ?> · <?= e($p['pharma_form']) ?> · <?= e($p['presentation']) ?><?= (int) $p['requires_prescription'] === 1 ? '<br><span class="badge bg-info">Sur ordonnance</span>' : '' ?></dd><?php endif; ?>
        <dt class="col-5">Vendu (30 j)</dt><dd class="col-7"><?= qty($stats['qty']) ?> · <?= money($stats['revenue']) ?></dd>
      </dl>
      <?php if ($p['description']): ?><p class="small mt-2 mb-0"><?= nl2br(e($p['description'])) ?></p><?php endif; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header d-flex"><span>Stock par entrepôt</span><span class="ms-auto fw-bold"><?= qty($total) ?></span></div><table class="table table-sm mb-0"><tbody>
      <?php foreach ($levels as $l): ?><tr><td><?= e($l['name']) ?></td><td class="num"><?= qty($l['quantity']) ?></td></tr><?php endforeach; ?>
      <?php if (!$levels): ?><tr><td class="text-secondary">Aucun stock.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($packs): ?><div class="card mb-3"><div class="card-header">Conditionnements</div><table class="table table-sm mb-0"><tbody><?php foreach ($packs as $k): ?><tr><td><?= e($k['name']) ?></td><td><?= qty($k['factor']) ?> u.</td><td class="num"><?= money($k['sale_price']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    <?php if ($variants): ?><div class="card"><div class="card-header">Variantes</div><table class="table table-sm mb-0"><tbody><?php foreach ($variants as $x): ?><tr><td><a href="<?= e(url('/products/' . $x['id'])) ?>"><?= e(trim($x['variant_size'] . ' ' . $x['variant_color']) ?: $x['name']) ?></a></td><td class="num"><?= money($x['sale_price']) ?></td><td class="num"><?= qty($x['stock']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div>
  <div class="col-lg-8">
    <?php if ($lots): ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-upc"></i> Lots en stock (ordre FEFO)</div><table class="table table-sm mb-0"><thead><tr><th>Lot</th><th>Entrepôt</th><th>Péremption</th><th class="num">Qté</th><th>État</th></tr></thead><tbody>
      <?php foreach ($lots as $l): $exp = $l['expiry_date'] && $l['expiry_date'] < date('Y-m-d'); ?><tr><td><?= e($l['lot_number']) ?></td><td><?= e($l['warehouse']) ?></td><td class="<?= $exp ? 'text-danger fw-bold' : '' ?>"><?= e(fdate($l['expiry_date'], false)) ?></td><td class="num"><?= qty($l['quantity']) ?></td><td><?= (int) $l['recalled'] === 1 ? '<span class="badge bg-danger">Rappelé</span>' : ($exp ? '<span class="badge bg-danger">Périmé</span>' : '<span class="badge bg-success">Vendable</span>') ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <div class="card mb-3"><div class="card-header d-flex"><span><i class="bi bi-arrow-left-right"></i> Derniers mouvements de stock</span><a class="ms-auto small" href="<?= e(url('/stock/movements', ['product' => $p['id']])) ?>">Historique complet</a></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Type</th><th>Entrepôt</th><th>Lot</th><th class="num">Qté</th><th class="num">Solde</th><th>Motif / réf.</th><th>Par</th></tr></thead><tbody>
      <?php foreach ($movements as $m): ?><tr><td class="text-nowrap"><?= e(fdate($m['created_at'])) ?></td><td><?= e($types[$m['type']] ?? $m['type']) ?></td><td><?= e($m['warehouse']) ?></td><td><?= e($m['lot_number'] ?? '') ?></td><td class="num <?= (float) $m['quantity'] < 0 ? 'text-danger' : 'text-success' ?>"><?= ((float) $m['quantity'] > 0 ? '+' : '') . qty($m['quantity']) ?></td><td class="num"><?= qty($m['balance_after']) ?></td><td class="small"><?= e($m['reason']) ?></td><td class="small"><?= e($m['full_name'] ?? 'système') ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
    <div class="card"><div class="card-header"><i class="bi bi-clock-history"></i> Historique des prix</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Champ</th><th class="num">Ancien</th><th class="num">Nouveau</th><th>Origine</th><th>Par</th></tr></thead><tbody>
      <?php $fl = ['sale_price' => 'Prix de vente', 'cost_price' => 'Coût (CMP)', 'wholesale_price' => 'Prix de gros', 'promo_price' => 'Prix promo']; foreach ($prices as $h): if ($h['field'] === 'cost_price' && !can('products.cost_view')) continue; ?><tr><td><?= e(fdate($h['created_at'])) ?></td><td><?= e($fl[$h['field']] ?? $h['field']) ?></td><td class="num"><?= $h['old_value'] !== null ? money($h['old_value']) : '—' ?></td><td class="num"><?= $h['new_value'] !== null ? money($h['new_value']) : '—' ?></td><td class="small"><?= e($h['source']) ?></td><td class="small"><?= e($h['full_name'] ?? '') ?></td></tr><?php endforeach; ?>
      <?php if (!$prices): ?><tr><td colspan="6" class="text-secondary">Aucune modification.</td></tr><?php endif; ?>
    </tbody></table></div></div>
  </div>
</div>
