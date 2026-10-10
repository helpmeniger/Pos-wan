<div class="card mb-3"><div class="card-body">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-4"><label class="form-label small">Toute une catégorie</label><select name="category" class="form-select"><option value="">—</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) ($_GET['category'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label small">Copies par article</label><input type="number" min="1" max="50" name="copies" value="<?= (int) $copies ?>" class="form-control"></div>
    <?php foreach ((array) ($_GET['ids'] ?? []) as $id): ?><input type="hidden" name="ids[]" value="<?= (int) $id ?>"><?php endforeach; ?>
    <div class="col-auto"><button class="btn btn-outline-primary">Afficher</button></div>
    <?php if ($products): ?><div class="col-auto"><button class="btn btn-primary" name="print" value="1"><i class="bi bi-printer"></i> Imprimer les étiquettes</button></div><?php endif; ?>
  </form>
</div></div>
<?php if ($products): ?>
<div class="card"><div class="card-header"><?= count($products) ?> article(s) · <?= count($products) * $copies ?> étiquette(s) (planche A4, 3 colonnes)</div><table class="table table-sm mb-0"><tbody>
  <?php foreach ($products as $p): ?><tr><td><?= e($p['name']) ?></td><td><?= e($p['barcode'] ?: $p['reference']) ?></td><td class="num"><?= money($p['sale_price']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php else: ?><p class="text-secondary">Choisissez une catégorie, ou lancez l'impression depuis la fiche d'un article. Les articles sans code-barres utilisent leur référence.</p><?php endif; ?>
