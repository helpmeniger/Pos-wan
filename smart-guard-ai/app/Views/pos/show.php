<div class="row g-3">
<div class="col-lg-7"><div class="card"><div class="card-body">
  <div class="d-flex justify-content-between"><div><h2 class="h5 mb-0">Ticket <?= e($sale['receipt_number']) ?></h2><div class="small text-muted"><?= dt($sale['sold_at']) ?> — <?= e($sale['cashier']) ?> — <?= e($sale['source']) ?></div></div>
  <div><span class="badge bg-secondary"><?= e(label('sale_status', $sale['status'])) ?></span></div></div>
  <table class="table table-sm mt-3"><thead><tr><th>Article</th><th class="text-end">Qté</th><th class="text-end">Prix</th><th class="text-end">Total</th><th class="text-end">Retourné</th><th class="text-end">Sorties vidéo rapprochées</th></tr></thead><tbody>
  <?php foreach ($items as $i): ?><tr><td><?= e($i['name']) ?></td><td class="text-end"><?= qty($i['quantity']) ?></td><td class="text-end"><?= money($i['unit_price']) ?></td>
    <td class="text-end"><?= money($i['line_total']) ?></td><td class="text-end"><?= qty($i['returned_quantity']) ?></td><td class="text-end"><?= (int) $i['matched_exit_count'] ?></td></tr><?php endforeach; ?>
  </tbody><tfoot><tr><th colspan="3">Total</th><th class="text-end"><?= money($sale['total_amount']) ?></th><th colspan="2"></th></tr>
  <tr><td colspan="3"><?= e(label('payment', $sale['payment_method'])) ?> — reçu</td><td class="text-end"><?= money($sale['amount_paid']) ?></td><td colspan="2"></td></tr></tfoot></table>
  <?php if ($sale['cancel_reason']): ?><div class="alert alert-secondary small">Annulée le <?= dt($sale['cancelled_at']) ?> : <?= e($sale['cancel_reason']) ?></div><?php endif; ?>
  <button class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimer</button>
</div></div></div>
<div class="col-lg-5 no-print">
  <?php if (can('pos.refund') && $sale['status'] !== 'annulee'): ?>
  <div class="card mb-3"><div class="card-header bg-white">Retour client</div><div class="card-body">
  <form method="post" action="<?= url('pos/sales/' . (int) $sale['id'] . '/refund') ?>" class="row g-2"><?= csrf_field() ?>
    <div class="col-12"><select name="item_id" class="form-select form-select-sm"><?php foreach ($items as $i): ?><option value="<?= (int) $i['id'] ?>"><?= e($i['name']) ?> (max <?= qty($i['quantity'] - $i['returned_quantity']) ?>)</option><?php endforeach; ?></select></div>
    <div class="col-4"><input name="quantity" type="number" step="0.001" min="0.001" class="form-control form-control-sm" value="1"></div>
    <div class="col-8"><input name="reason" class="form-control form-control-sm" placeholder="Motif" maxlength="255" required></div>
    <div class="col-12"><button class="btn btn-sm btn-outline-primary">Enregistrer le retour</button></div></form></div></div>
  <?php endif; ?>
  <?php if (can('pos.cancel') && $sale['status'] === 'validee'): ?>
  <div class="card"><div class="card-header bg-white">Annulation</div><div class="card-body">
  <form method="post" action="<?= url('pos/sales/' . (int) $sale['id'] . '/cancel') ?>" data-confirm="Annuler cette vente ? Le stock sera rétabli et les sorties rapprochées redeviendront à vérifier."><?= csrf_field() ?>
    <input name="reason" class="form-control form-control-sm mb-2" placeholder="Motif obligatoire" required maxlength="255"><button class="btn btn-sm btn-outline-danger">Annuler la vente</button></form></div></div>
  <?php endif; ?>
</div>
</div>
