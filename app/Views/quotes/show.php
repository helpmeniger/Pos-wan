<?php $open = in_array($q['status'], ['draft', 'sent', 'accepted'], true); $expired = $open && $q['valid_until'] && $q['valid_until'] < date('Y-m-d'); ?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <a class="btn btn-outline-secondary" href="<?= e(url('/quotes')) ?>"><i class="bi bi-arrow-left"></i> Devis</a>
  <a class="btn btn-outline-primary" target="_blank" rel="noopener" href="<?= e(url('/quotes/' . $q['id'] . '/print')) ?>"><i class="bi bi-printer"></i> Imprimer le devis</a>
  <?php if ($open): ?><a class="btn btn-outline-secondary" href="<?= e(url('/quotes/' . $q['id'] . '/edit')) ?>"><i class="bi bi-pencil"></i> Modifier</a><?php endif; ?>
  <form method="post" action="<?= e(url('/quotes/' . $q['id'] . '/duplicate')) ?>"><?= csrf_field() ?><button class="btn btn-outline-secondary"><i class="bi bi-files"></i> Dupliquer</button></form>
  <?php if ($open && can('pos.sell')): ?><a class="btn btn-success" href="<?= e(url('/quotes/' . $q['id'] . '/convert')) ?>"><i class="bi bi-cart-check"></i> Convertir en vente</a><?php endif; ?>
  <?php if ($q['sale_id']): ?>
    <a class="btn btn-outline-success" href="<?= e(url('/sales/' . $q['sale_id'])) ?>"><i class="bi bi-receipt"></i> Vente <?= e($q['sale_number']) ?></a>
    <a class="btn btn-outline-primary" target="_blank" rel="noopener" href="<?= e(url('/sales/' . $q['sale_id'] . '/delivery')) ?>"><i class="bi bi-truck"></i> Bon de livraison</a>
  <?php endif; ?>
</div>
<?php if ($expired): ?><div class="alert alert-warning py-2"><i class="bi bi-hourglass-bottom"></i> Ce devis a dépassé sa date de validité : vérifiez les prix avant de le facturer, ou dupliquez-le.</div><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card"><div class="card-header d-flex"><span>Articles</span><span class="ms-auto"><?= App\Core\View::partial('partials/status', ['status' => $q['status']]) ?></span></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Article</th><th class="num">Qté</th><th class="num">P.U.</th><th class="num">Remise</th><th class="num">Total</th></tr></thead><tbody>
    <?php foreach ($items as $i): ?><tr><td><?= e($i['name']) ?> <small class="text-secondary"><?= e($i['reference']) ?></small></td><td class="num"><?= qty($i['quantity']) ?> <?= e($i['unit'] ?? '') ?></td><td class="num"><?= money($i['unit_price']) ?></td><td class="num"><?= (float) $i['discount'] > 0 ? money($i['discount']) : '' ?></td><td class="num fw-semibold"><?= money($i['line_total']) ?></td></tr><?php endforeach; ?>
    </tbody><tfoot>
      <?php if ((float) $q['discount_total'] > 0): ?><tr><td colspan="4" class="text-end">Sous-total</td><td class="num"><?= money($q['subtotal']) ?></td></tr><tr><td colspan="4" class="text-end">Remises</td><td class="num">− <?= money($q['discount_total']) ?></td></tr><?php endif; ?>
      <tr><th colspan="4" class="text-end">TOTAL</th><th class="num"><?= money($q['total']) ?></th></tr>
    </tfoot></table></div></div>
  </div>
  <div class="col-lg-4">
    <div class="card mb-3"><div class="card-body small"><dl class="row mb-0">
      <dt class="col-5">Numéro</dt><dd class="col-7"><?= e($q['number']) ?></dd>
      <dt class="col-5">Date</dt><dd class="col-7"><?= e(fdate($q['created_at'])) ?></dd>
      <dt class="col-5">Client</dt><dd class="col-7"><?= $q['customer_id'] ? '<a href="' . e(url('/customers/' . $q['customer_id'])) . '">' . e($q['customer_name']) . '</a>' : e($q['customer_name']) ?><br><?= e($q['customer_phone'] ?? '') ?></dd>
      <dt class="col-5">Valable jusqu'au</dt><dd class="col-7"><?= e(fdate($q['valid_until'], false)) ?></dd>
      <dt class="col-5">Établi par</dt><dd class="col-7"><?= e($q['user_name']) ?></dd>
      <?php if ($q['note']): ?><dt class="col-5">Note</dt><dd class="col-7"><?= nl2br(e($q['note'])) ?></dd><?php endif; ?>
    </dl></div></div>
    <?php if ($q['status'] !== 'converted'): ?>
    <div class="card"><div class="card-header">Suivi</div><div class="card-body d-flex flex-wrap gap-2">
      <?php foreach (['sent' => ['Marquer envoyé', 'send', 'outline-info'], 'accepted' => ['Accepté par le client', 'hand-thumbs-up', 'outline-primary'], 'refused' => ['Refusé', 'hand-thumbs-down', 'outline-danger'], 'cancelled' => ['Annuler', 'x-octagon', 'outline-danger'], 'draft' => ['Repasser en brouillon', 'arrow-counterclockwise', 'outline-secondary']] as $st => [$lbl, $ic, $cls]): ?>
        <?php if ($st !== $q['status'] && in_array($st, App\Services\QuoteService::TRANSITIONS[$q['status']] ?? [], true)): ?>
          <form method="post" action="<?= e(url('/quotes/' . $q['id'] . '/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="<?= e($st) ?>"><button class="btn btn-sm btn-<?= e($cls) ?>"><i class="bi bi-<?= e($ic) ?>"></i> <?= e($lbl) ?></button></form>
        <?php endif; ?>
      <?php endforeach; ?>
    </div></div>
    <?php endif; ?>
  </div>
</div>
