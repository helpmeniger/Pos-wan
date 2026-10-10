<?= App\Core\View::partial('reports/_filters', []) ?>
<div class="d-flex justify-content-end mb-2 d-print-none"><?php if (can('reports.export')): ?><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> Export créances</a><?php endif; ?></div>
<div class="row g-3">
  <div class="col-lg-6"><div class="card"><div class="card-header d-flex"><span>Créances clients</span><b class="ms-auto text-danger"><?= money(array_sum(array_column($customers, 'balance'))) ?></b></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Client</th><th>Téléphone</th><th class="num">Dû</th><th>Échéance</th></tr></thead><tbody>
    <?php foreach ($customers as $c): $late = $c['oldest_due'] && $c['oldest_due'] < date('Y-m-d'); ?><tr><td><a href="<?= e(url('/customers/' . $c['id'])) ?>"><?= e($c['name']) ?></a><?= (float) $c['balance'] > (float) $c['credit_limit'] ? ' <span class="badge bg-danger">plafond dépassé</span>' : '' ?></td><td><?= e($c['phone'] ?? '') ?></td><td class="num fw-semibold"><?= money($c['balance']) ?></td><td class="<?= $late ? 'text-danger fw-bold' : '' ?>"><?= e(fdate($c['oldest_due'], false)) ?><?= $late ? ' (' . (int) ((time() - strtotime($c['oldest_due'])) / 86400) . ' j de retard)' : '' ?></td></tr><?php endforeach; ?>
    <?php if (!$customers): ?><tr><td colspan="4" class="text-success">Aucune créance.</td></tr><?php endif; ?>
  </tbody></table></div></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header d-flex"><span>Dettes fournisseurs</span><b class="ms-auto text-danger"><?= money(array_sum(array_column($suppliers, 'balance'))) ?></b></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Fournisseur</th><th class="num">Dû</th><th>Échéance</th></tr></thead><tbody>
    <?php foreach ($suppliers as $s): $late = $s['oldest_due'] && $s['oldest_due'] < date('Y-m-d'); ?><tr><td><a href="<?= e(url('/suppliers/' . $s['id'])) ?>"><?= e($s['name']) ?></a></td><td class="num fw-semibold"><?= money($s['balance']) ?></td><td class="<?= $late ? 'text-danger fw-bold' : '' ?>"><?= e(fdate($s['oldest_due'], false)) ?></td></tr><?php endforeach; ?>
    <?php if (!$suppliers): ?><tr><td colspan="3" class="text-success">Aucune dette.</td></tr><?php endif; ?>
  </tbody></table></div></div></div>
</div>
