<div class="d-flex flex-wrap gap-2 mb-2 d-print-none">
  <?php foreach (['/reports/sales' => 'Ventes', '/reports/products' => 'Par article', '/reports/profit' => 'Bénéfices', '/reports/vat' => 'TVA', '/reports/stock' => 'Stock', '/reports/debts' => 'Créances & dettes'] as $u => $l): if (in_array($u, ['/reports/profit', '/reports/vat', '/reports/stock', '/reports/debts'], true) && !can('reports.financial')) continue; ?>
    <a class="btn btn-sm <?= App\Core\Request::path() === $u ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e(url($u, array_intersect_key($_GET, ['from' => 1, 'to' => 1, 'store' => 1]))) ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
</div>
<?php if (isset($from)): ?>
<div class="card mb-3 d-print-none"><div class="card-body py-2"><form class="row g-2 align-items-end" method="get">
  <?= App\Core\View::partial('partials/period', ['from' => $from, 'to' => $to]) ?>
  <div class="col-auto d-flex align-items-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(query_with(['from' => date('Y-01-01'), 'to' => date('Y-m-d')])) ?>">Cette année</a></div>
  <?php if (can('sales.view_all') && count($stores) > 1): ?><div class="col-auto"><label class="form-label small mb-0">Boutique</label><select name="store" class="form-select form-select-sm"><option value="">Toutes</option><?php foreach ($stores as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $storeId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <?php foreach ($extra ?? [] as $html) echo $html; ?>
  <div class="col-auto"><button class="btn btn-sm btn-primary">Afficher</button></div>
  <div class="col-auto ms-auto"><button type="button" class="btn btn-sm btn-outline-secondary" data-print-page><i class="bi bi-printer"></i> Imprimer / PDF</button><?php if (can('reports.export')): ?> <a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> CSV</a> <a class="btn btn-sm btn-outline-danger" href="<?= e(query_with(['export' => 'pdf'])) ?>"><i class="bi bi-filetype-pdf"></i> PDF</a><?php endif; ?></div>
</form></div></div>
<?php endif; ?>
