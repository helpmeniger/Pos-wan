<div class="d-flex flex-wrap gap-2 mb-3 d-print-none">
  <a class="btn btn-outline-secondary" href="<?= e(url('/sales')) ?>"><i class="bi bi-arrow-left"></i> Ventes</a>
  <a class="btn btn-outline-primary" target="_blank" rel="noopener" href="<?= e(url('/sales/' . $s['id'] . '/ticket')) ?>"><i class="bi bi-printer"></i> Ticket<?= (int) $s['print_count'] > 0 ? ' (duplicata)' : '' ?></a>
  <a class="btn btn-outline-primary" target="_blank" rel="noopener" href="<?= e(url('/sales/' . $s['id'] . '/invoice')) ?>"><i class="bi bi-file-earmark-text"></i> Facture A4</a>
  <a class="btn btn-outline-primary" target="_blank" rel="noopener" href="<?= e(url('/sales/' . $s['id'] . '/delivery')) ?>"><i class="bi bi-truck"></i> Bon de livraison</a>
  <?php if ($hasPrinter && ((int) $s['print_count'] === 0 || can('pos.reprint'))): ?>
    <form method="post" action="<?= e(url('/sales/' . $s['id'] . '/print')) ?>"><?= csrf_field() ?><button class="btn btn-outline-dark"><i class="bi bi-printer-fill"></i> Imprimer sur l'imprimante de caisse</button></form>
  <?php endif; ?>
  <?php if (can('returns.create') && in_array($s['status'], ['completed', 'partially_returned'], true)): ?>
    <a class="btn btn-outline-warning" href="<?= e(url('/sales/' . $s['id'] . '/return')) ?>"><i class="bi bi-arrow-counterclockwise"></i> Retour / remboursement</a>
  <?php endif; ?>
  <?php if (can('pos.cancel_sale') && $s['status'] === 'completed'): ?>
    <form method="post" action="<?= e(url('/sales/' . $s['id'] . '/cancel')) ?>" data-confirm="Annuler la vente <?= e($s['number']) ?> ? Le stock, la caisse et le compte client seront contre-passés." data-reason="1">
      <?= csrf_field() ?><button class="btn btn-outline-danger"><i class="bi bi-x-octagon"></i> Annuler la vente</button></form>
  <?php endif; ?>
</div>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card"><div class="card-header d-flex"><span>Articles</span><span class="ms-auto"><?= App\Core\View::partial('partials/status', ['status' => $s['status']]) ?></span></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Article</th><th class="num">Qté</th><th class="num">P.U.</th><th class="num">Remise</th><th class="num">Total</th><th class="num">Retourné</th><?php if (can('products.cost_view')): ?><th class="num">Coût</th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($items as $i): ?>
      <tr><td><?= e($i['product_name']) ?> <small class="text-secondary"><?= e($i['reference']) ?></small><?= abs((float) $i['unit_price'] - (float) $i['list_price']) >= 0.01 ? ' <span class="badge bg-warning text-dark" title="Prix catalogue ' . e(money($i['list_price'])) . '">prix modifié</span>' : '' ?></td>
      <td class="num"><?= qty($i['quantity']) ?></td><td class="num"><?= money($i['unit_price']) ?></td><td class="num"><?= (float) $i['discount'] > 0 ? money($i['discount']) : '' ?></td><td class="num fw-semibold"><?= money($i['line_total']) ?></td>
      <td class="num"><?= (float) $i['returned_qty'] > 0 ? qty($i['returned_qty']) : '' ?></td><?php if (can('products.cost_view')): ?><td class="num text-secondary"><?= money((float) $i['unit_cost'] * (float) $i['base_quantity']) ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
    <?php if ($lots): ?>
    <div class="card mt-3"><div class="card-header"><i class="bi bi-upc"></i> Traçabilité des lots (FEFO)</div><table class="table table-sm mb-0"><thead><tr><th>Article</th><th>Lot</th><th>Péremption</th><th class="num">Qté</th></tr></thead><tbody>
      <?php foreach ($lots as $l): ?><tr><td><?= e($l['product_name']) ?></td><td><?= e($l['lot_number']) ?></td><td><?= e(fdate($l['expiry_date'], false)) ?></td><td class="num"><?= qty($l['quantity']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <?php if ($returns): ?>
    <div class="card mt-3"><div class="card-header">Retours</div><table class="table table-sm mb-0"><tbody>
      <?php foreach ($returns as $r): ?><tr><td><a href="<?= e(url('/returns/' . $r['id'])) ?>"><?= e($r['number']) ?></a></td><td><?= e(fdate($r['created_at'])) ?></td><td><?= e($r['full_name']) ?></td><td><?= e(method_label($r['refund_method'])) ?></td><td><?= e($r['reason']) ?></td><td class="num"><?= money($r['total']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  </div>
  <div class="col-lg-4">
    <div class="card mb-3"><div class="card-body small">
      <dl class="row mb-0">
        <dt class="col-5">Date</dt><dd class="col-7"><?= e(fdate($s['created_at'])) ?></dd>
        <dt class="col-5">Boutique</dt><dd class="col-7"><?= e($s['store_name']) ?> / <?= e($s['register_name'] ?? '') ?></dd>
        <dt class="col-5">Vendeur</dt><dd class="col-7"><?= e($s['cashier']) ?></dd>
        <dt class="col-5">Client</dt><dd class="col-7"><?= $s['customer_id'] ? '<a href="' . e(url('/customers/' . $s['customer_id'])) . '">' . e($s['customer_name']) . '</a>' : 'Comptoir' ?></dd>
        <?php if ($s['authorizer']): ?><dt class="col-5">Autorisé par</dt><dd class="col-7"><?= e($s['authorizer']) ?></dd><?php endif; ?>
        <?php if ((int) $s['is_wholesale'] === 1): ?><dt class="col-5">Type</dt><dd class="col-7">Vente en gros</dd><?php endif; ?>
        <?php if ($s['note']): ?><dt class="col-5">Note</dt><dd class="col-7"><?= e($s['note']) ?></dd><?php endif; ?>
        <dt class="col-5">Impressions</dt><dd class="col-7"><?= (int) $s['print_count'] ?></dd>
        <?php if ($s['status'] === 'cancelled'): ?><dt class="col-5 text-danger">Annulée</dt><dd class="col-7"><?= e(fdate($s['cancelled_at'])) ?> par <?= e($s['canceller']) ?><br><i><?= e($s['cancel_reason']) ?></i></dd><?php endif; ?>
      </dl>
    </div></div>
    <div class="card mb-3"><div class="card-body">
      <div class="d-flex justify-content-between"><span>Sous-total</span><span><?= money($s['subtotal']) ?></span></div>
      <?php if ((float) $s['discount_total'] > 0): ?><div class="d-flex justify-content-between text-danger"><span>Remises</span><span>− <?= money($s['discount_total']) ?></span></div><?php endif; ?>
      <div class="d-flex justify-content-between fs-5 fw-bold border-top mt-1 pt-1"><span>Total TTC</span><span><?= money($s['total']) ?></span></div>
      <?php if ((float) $s['tax_total'] > 0): ?><div class="d-flex justify-content-between small text-secondary"><span>dont taxes</span><span><?= money($s['tax_total']) ?></span></div><?php endif; ?>
      <hr>
      <?php foreach ($payments as $pm): ?><div class="d-flex justify-content-between small"><span><?= e(method_label($pm['method'])) ?> <?= e($pm['reference'] ?? '') ?></span><span><?= money($pm['amount']) ?></span></div><?php endforeach; ?>
      <div class="d-flex justify-content-between small text-secondary"><span>Montant remis</span><span><?= money($s['tendered']) ?></span></div>
      <div class="d-flex justify-content-between small text-secondary"><span>Monnaie rendue</span><span><?= money($s['change_due']) ?></span></div>
      <?php if (can('reports.financial')): ?><div class="d-flex justify-content-between small mt-2"><span>Marge brute</span><span><?= money((float) $s['total'] - (float) $s['cost_total']) ?></span></div><?php endif; ?>
    </div></div>
    <?php if ($prescription): ?>
    <div class="card"><div class="card-header"><i class="bi bi-file-medical"></i> Ordonnance</div><div class="card-body small">
      Prescripteur : <b><?= e($prescription['prescriber']) ?></b><br>Patient : <b><?= e($prescription['patient_name']) ?></b><br>Réf. : <?= e($prescription['prescription_ref'] ?? '—') ?> du <?= e(fdate($prescription['prescription_date'], false)) ?><br>Validée par : <?= e($prescription['validator']) ?>
    </div></div>
    <?php endif; ?>
  </div>
</div>
