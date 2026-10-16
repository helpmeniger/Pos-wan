<?php $rest = round((float) $b['patient_amount'] - (float) $b['paid'], 2); ?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <a class="btn btn-outline-secondary" href="<?= e(url('/clinique/factures')) ?>"><i class="bi bi-arrow-left"></i> Factures</a>
  <a class="btn btn-outline-primary" href="<?= e(url('/clinique/patients/' . $b['patient_id'])) ?>"><i class="bi bi-folder2-open"></i> Dossier patient</a>
  <a class="btn btn-outline-primary" target="_blank" rel="noopener" href="<?= e(url('/clinique/factures/' . $b['id'] . '/print')) ?>"><i class="bi bi-printer"></i> Imprimer la facture</a>
  <a class="btn btn-outline-primary" target="_blank" rel="noopener" href="<?= e(url('/clinique/factures/' . $b['id'] . '/ticket')) ?>"><i class="bi bi-receipt-cutoff"></i> Reçu (ticket)</a>
  <span class="ms-auto fs-5"><span class="badge bg-<?= ['open' => 'warning text-dark', 'paid' => 'success', 'cancelled' => 'secondary'][$b['status']] ?>"><?= e(['open' => 'À payer', 'paid' => 'Payée', 'cancelled' => 'Annulée'][$b['status']]) ?></span></span>
</div>
<?php if ($b['status'] === 'cancelled'): ?><div class="alert alert-secondary py-2">Annulée : <?= e($b['cancel_reason']) ?></div><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card mb-3"><div class="card-header"><?= e($b['number']) ?> · <?= e($b['last_name'] . ' ' . $b['first_name']) ?> (<?= e($b['pnum']) ?>) · <?= e(fdate($b['created_at'])) ?></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Acte</th><th class="num">Qté</th><th class="num">P.U.</th><th class="num">Total</th></tr></thead><tbody>
      <?php foreach ($items as $i): ?><tr><td><?= e($i['label']) ?></td><td class="num"><?= e(qty($i['quantity'])) ?></td><td class="num"><?= money($i['unit_price']) ?></td><td class="num"><?= money($i['total']) ?></td></tr><?php endforeach; ?>
    </tbody><tfoot>
      <tr class="fw-bold"><td colspan="3" class="text-end">TOTAL</td><td class="num"><?= money($b['subtotal']) ?></td></tr>
      <?php if ((float) $b['coverage_amount'] > 0): ?><tr><td colspan="3" class="text-end">Prise en charge <?= e($b['insurer'] ?? '') ?> (<?= e((string) (float) $b['coverage_pct']) ?> %)</td><td class="num">− <?= money($b['coverage_amount']) ?></td></tr><?php endif; ?>
      <tr class="fw-bold"><td colspan="3" class="text-end">Part patient</td><td class="num"><?= money($b['patient_amount']) ?></td></tr>
      <tr><td colspan="3" class="text-end">Payé</td><td class="num text-success"><?= money($b['paid']) ?></td></tr>
      <tr class="fw-bold"><td colspan="3" class="text-end">Reste à payer</td><td class="num <?= $rest > 0 && $b['status'] === 'open' ? 'text-danger' : 'text-success' ?>"><?= money($b['status'] === 'open' ? max(0, $rest) : 0) ?></td></tr>
    </tfoot></table></div></div>
    <div class="card"><div class="card-header">Paiements</div><ul class="list-group list-group-flush small">
      <?php foreach ($payments as $pm): ?><li class="list-group-item d-flex"><span><?= e(method_label($pm['method'])) ?><?= $pm['provider'] ? ' · ' . e($pm['provider']) : '' ?><?= $pm['reference'] ? ' · ' . e($pm['reference']) : '' ?> <span class="text-secondary"><?= e(fdate($pm['created_at'])) ?> · <?= e($pm['full_name']) ?></span></span><span class="ms-auto fw-semibold"><?= money($pm['amount']) ?></span></li><?php endforeach; ?>
      <?php if (!$payments): ?><li class="list-group-item text-secondary">Aucun paiement.</li><?php endif; ?>
    </ul></div>
  </div>
  <div class="col-lg-4">
    <?php if ($b['status'] === 'open'): ?>
    <div class="card mb-3 border-success"><div class="card-header bg-success text-white"><i class="bi bi-cash-coin"></i> Encaisser</div><div class="card-body">
      <form method="post" action="<?= e(url('/clinique/factures/' . $b['id'] . '/pay')) ?>" class="row g-1"><?= csrf_field() ?>
        <div class="col-12 small mb-1">Reste à payer : <b class="text-danger"><?= money($rest) ?></b></div>
        <?= App\Core\View::partial('clinique::_pay', ['amount' => (string) $rest]) ?>
        <div class="col-12 mt-2"><button class="btn btn-success w-100"><i class="bi bi-check2"></i> Encaisser</button></div>
      </form>
      <div class="small text-secondary mt-2">L'encaissement est enregistré dans votre caisse (mouvement « clinique »).</div>
    </div></div>
    <?php endif; ?>
    <?php if ($b['status'] !== 'cancelled' && (float) $b['paid'] <= 0 && can('clinique.manage')): ?>
    <form method="post" action="<?= e(url('/clinique/factures/' . $b['id'] . '/cancel')) ?>" data-confirm="Annuler la facture <?= e($b['number']) ?> ?" data-reason="1"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-x-octagon"></i> Annuler la facture</button></form>
    <?php endif; ?>
  </div>
</div>
