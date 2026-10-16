<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-6 col-md-2"><input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>"></div>
  <div class="col-6 col-md-2"><input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>"></div>
  <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Filtrer</button></div>
  <div class="col text-end"><a href="<?= url('pos') ?>" class="btn btn-sm btn-primary"><i class="bi bi-cart3 me-1"></i>Caisse</a></div>
</form>
<p class="small text-muted"><?= (int) $total ?> vente(s) — chiffre d'affaires (hors annulations) : <strong><?= money($sum) ?></strong></p>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
<thead><tr><th>Ticket</th><th>Date</th><th>Source</th><th>Caissier</th><th>Lignes</th><th class="text-end">Total</th><th>Paiement</th><th>Statut</th></tr></thead><tbody>
<?php foreach ($rows as $s): ?><tr><td><a href="<?= url('pos/sales/' . (int) $s['id']) ?>"><?= e($s['receipt_number']) ?></a> <?= $s['is_demo'] ? '<span class="badge demo-badge">démo</span>' : '' ?></td>
  <td class="small"><?= dt($s['sold_at']) ?></td><td class="small"><?= e($s['source']) ?></td><td><?= e($s['cashier']) ?></td><td><?= (int) $s['lines_count'] ?></td>
  <td class="text-end"><?= money($s['total_amount']) ?></td><td><?= e(label('payment', $s['payment_method'])) ?></td><td><?= e(label('sale_status', $s['status'])) ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-muted text-center py-3">Aucune vente.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/../partials/pagination.php'; ?>
