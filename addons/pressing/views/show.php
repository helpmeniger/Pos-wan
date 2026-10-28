<?php
use Addons\Pressing\PressingService as PS;
$colors = ['received' => 'secondary', 'processing' => 'info text-dark', 'ready' => 'success', 'partial' => 'warning text-dark', 'delivered' => 'dark', 'cancelled' => 'danger'];
$rest = round((float) $o['total'] - (float) $o['paid'], 2);
$open = !in_array($o['status'], ['delivered', 'cancelled'], true);
$lateDays = PS::lateDays($o);
$late = in_array($o['status'], ['received', 'processing'], true) && $o['due_date'] < date('Y-m-d');
$wa = preg_replace('/\D/', '', $o['phone']);
$wa = strlen($wa) === 8 ? '227' . $wa : $wa;
$msg = sprintf(t('Bonjour %s, votre linge (dépôt %s) est prêt chez %s.'), $o['customer_name'], $o['number'], setting('company_name')) . ($rest > 0 ? ' ' . t('Reste à payer') . ' : ' . money($rest) . '.' : '');
?>
<?php if ($autoPrint): ?><span data-autoprint-url="<?= e(url('/pressing/' . $o['id'] . '/ticket', ['auto' => 1])) ?>"></span><?php endif; ?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <a class="btn btn-outline-secondary" href="<?= e(url('/pressing')) ?>"><i class="bi bi-arrow-left"></i> Dépôts</a>
  <a class="btn btn-outline-primary" target="_blank" rel="noopener" href="<?= e(url('/pressing/' . $o['id'] . '/ticket')) ?>"><i class="bi bi-receipt"></i> Ticket client</a>
  <a class="btn btn-outline-primary" target="_blank" rel="noopener" href="<?= e(url('/pressing/' . $o['id'] . '/labels')) ?>"><i class="bi bi-tags"></i> Étiquettes (<?= (int) $o['pieces'] ?>)<?= (float) ($o['weight'] ?? 0) > 0 ? ' · ' . e(PS::qty($o['weight'], 'kg')) : '' ?></a>
  <?php if ($o['status'] === 'ready'): ?><a class="btn btn-outline-success" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= e(rawurlencode($msg)) ?>"><i class="bi bi-whatsapp"></i> Prévenir le client</a><?php endif; ?>
  <a class="btn btn-outline-secondary" href="<?= e(url('/pressing/client', ['phone' => $o['phone']])) ?>"><i class="bi bi-clock-history"></i> Historique du client</a>
  <span class="ms-auto fs-5"><span class="badge bg-<?= e($colors[$o['status']]) ?>"><?= e(PS::STATUSES[$o['status']]) ?></span><?= (int) $o['express'] ? ' <span class="badge bg-danger">EXPRESS</span>' : '' ?></span>
</div>
<?php if ($lateDays): ?><div class="alert alert-danger py-2"><i class="bi bi-alarm"></i> <?= $o['status'] === 'ready' || $o['status'] === 'partial' ? 'Prêt mais non retiré' : 'En retard' ?> : <b><?= $lateDays ?> jour(s)</b> après la date prévue (<?= e(fdate($o['due_date'], false)) ?>).</div><?php endif; ?>
<?php if ($o['status'] === 'cancelled'): ?><div class="alert alert-secondary py-2">Annulé : <?= e($o['cancel_reason']) ?></div><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card mb-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Étiquette</th><th>Article</th><th>Service</th><th class="num">Qté</th><th class="num">P.U.</th><th class="num">Total</th></tr></thead><tbody>
      <?php foreach ($items as $i): ?><tr><td><span class="badge bg-dark fs-6"><?= e($i['tag']) ?></span></td><td><?= e($i['article_name']) ?><?= (float) ($i['picked_qty'] ?? 0) > 0 ? ' <span class="badge bg-' . ((float) $i['picked_qty'] >= (float) $i['quantity'] - 0.0005 ? 'dark' : 'warning text-dark') . '">retiré ' . e(PS::qty($i['picked_qty'])) . '/' . e(PS::qty($i['quantity'], $i['unit'] ?? 'piece')) . '</span>' : '' ?><?php $d = array_filter([$i['color'], $i['brand']]); ?><?= $d ? '<br><small class="text-secondary">' . e(implode(' · ', $d)) . '</small>' : '' ?><?= $i['defects'] ? '<br><small class="text-danger"><i class="bi bi-exclamation-triangle"></i> ' . e($i['defects']) . '</small>' : '' ?></td>
      <td><?= e(PS::SERVICES[$i['service']] ?? $i['service']) ?></td><td class="num"><?= e(PS::qty($i['quantity'], $i['unit'] ?? 'piece')) ?></td><td class="num"><?= money($i['unit_price']) ?><?= ($i['unit'] ?? '') === 'kg' ? '<small class="text-secondary">/kg</small>' : '' ?><?= abs((float) $i['unit_price'] - (float) $i['list_price']) >= 0.01 ? ' <span class="badge bg-warning text-dark" title="Tarif ' . e(money($i['list_price'])) . '">modifié</span>' : '' ?></td><td class="num fw-semibold"><?= money($i['line_total']) ?></td></tr><?php endforeach; ?>
    </tbody><tfoot>
      <tr><td colspan="5" class="text-end">Sous-total</td><td class="num"><?= money($o['subtotal']) ?></td></tr>
      <?php if ((float) $o['express_fee'] > 0): ?><tr><td colspan="5" class="text-end">Supplément express</td><td class="num"><?= money($o['express_fee']) ?></td></tr><?php endif; ?>
      <?php if ((float) $o['discount'] > 0): ?><tr><td colspan="5" class="text-end">Remise</td><td class="num">− <?= money($o['discount']) ?></td></tr><?php endif; ?>
      <tr class="fw-bold"><td colspan="5" class="text-end">TOTAL</td><td class="num"><?= money($o['total']) ?></td></tr>
      <tr><td colspan="5" class="text-end">Payé</td><td class="num text-success"><?= money($o['paid']) ?></td></tr>
      <tr class="fw-bold"><td colspan="5" class="text-end">Reste à payer</td><td class="num <?= $rest > 0 ? 'text-danger' : 'text-success' ?>"><?= money(max(0, $rest)) ?></td></tr>
    </tfoot></table></div></div>
    <div class="row g-3">
      <div class="col-md-6"><div class="card h-100"><div class="card-header">Paiements</div><ul class="list-group list-group-flush small">
        <?php foreach ($payments as $pm): ?><li class="list-group-item d-flex"><span><?= e(['deposit' => 'Acompte', 'payment' => 'Paiement', 'refund' => 'Remboursement'][$pm['kind']]) ?> · <?= e(method_label($pm['method'])) ?><?= $pm['provider'] ? ' · ' . e($pm['provider']) : '' ?> <span class="text-secondary"><?= e(fdate($pm['created_at'])) ?> · <?= e($pm['full_name']) ?></span></span><span class="ms-auto fw-semibold <?= (float) $pm['amount'] < 0 ? 'text-danger' : '' ?>"><?= money($pm['amount']) ?></span></li><?php endforeach; ?>
        <?php if (!$payments): ?><li class="list-group-item text-secondary">Aucun paiement.</li><?php endif; ?>
      </ul></div></div>
      <div class="col-md-6"><div class="card h-100"><div class="card-header">Historique</div><ul class="list-group list-group-flush small">
        <?php foreach ($log as $l): ?><li class="list-group-item"><?= e(fdate($l['created_at'])) ?> · <b><?= e(PS::STATUSES[$l['to_status']] ?? $l['to_status']) ?></b> · <?= e($l['full_name'] ?? '') ?><?= $l['note'] ? '<br><span class="text-secondary">' . e($l['note']) . '</span>' : '' ?></li><?php endforeach; ?>
      </ul></div></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card mb-3"><div class="card-body small"><dl class="row mb-0">
      <dt class="col-5">N°</dt><dd class="col-7"><?= e($o['number']) ?></dd>
      <dt class="col-5">Client</dt><dd class="col-7"><?= e($o['customer_name']) ?><br><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $o['phone'])) ?>"><?= e($o['phone']) ?></a></dd>
      <dt class="col-5">Déposé le</dt><dd class="col-7"><?= e(fdate($o['created_at'])) ?><br><span class="text-secondary"><?= e($o['user_name']) ?></span></dd>
      <dt class="col-5">Retrait prévu</dt><dd class="col-7 <?= $late ? 'text-danger fw-semibold' : '' ?>"><?= e(fdate($o['due_date'], false)) ?></dd>
      <?php if ($o['ready_at']): ?><dt class="col-5">Prêt le</dt><dd class="col-7"><?= e(fdate($o['ready_at'])) ?></dd><?php endif; ?>
      <?php if ($o['delivered_at']): ?><dt class="col-5">Retiré le</dt><dd class="col-7"><?= e(fdate($o['delivered_at'])) ?><br><span class="text-secondary"><?= e($o['delivered_name'] ?? '') ?></span></dd><?php endif; ?>
      <?php if ($o['note']): ?><dt class="col-5">Note</dt><dd class="col-7"><?= e($o['note']) ?></dd><?php endif; ?>
    </dl></div></div>

    <?php if ($open && can('pressing.process') && !empty(PS::STEPS[$o['status']])): ?>
    <div class="card mb-3"><div class="card-header">Traitement</div><div class="card-body d-flex flex-wrap gap-2">
      <?php foreach (PS::STEPS[$o['status']] as $to): ?><form method="post" action="<?= e(url('/pressing/' . $o['id'] . '/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="<?= e($to) ?>"><button class="btn <?= $to === 'ready' ? 'btn-success' : ($to === 'processing' ? 'btn-info' : 'btn-outline-secondary') ?>"><i class="bi <?= $to === 'ready' ? 'bi-check2-circle' : 'bi-arrow-repeat' ?>"></i> <?= e($to === 'received' ? 'Revenir à « Reçu »' : PS::STATUSES[$to]) ?></button></form><?php endforeach; ?>
    </div></div>
    <?php endif; ?>

    <?php if ($open && can('pressing.deposit')): ?>
    <div class="card mb-3 border-success"><div class="card-header bg-success text-white"><i class="bi bi-bag-check"></i> Retrait par le client</div><div class="card-body">
      <form method="post" action="<?= e(url('/pressing/' . $o['id'] . '/deliver')) ?>" class="row g-1"><?= csrf_field() ?>
        <?php if ($rest > 0): ?>
          <div class="col-12 small mb-1">Reste à payer : <b class="text-danger"><?= money($rest) ?></b> <button type="button" class="btn btn-sm btn-link p-0" data-fill-amount="<?= e((string) $rest) ?>">encaisser le solde</button></div>
          <?= App\Core\View::partial('pressing::_pay', ['amount' => (string) $rest]) ?>
          <?php if (can('pressing.manage')): ?><div class="col-12"><input name="unpaid_reason" class="form-control form-control-sm mt-1" placeholder="Remettre sans paiement complet : motif (responsable)" maxlength="180"></div><?php endif; ?>
        <?php else: ?><div class="col-12 small text-success mb-1"><i class="bi bi-check-circle"></i> Entièrement payé.</div><?php endif; ?>
        <div class="col-12 mt-2"><button class="btn btn-success w-100" <?= !in_array($o['status'], ['ready', 'partial'], true) && !can('pressing.manage') ? 'disabled title="Le linge doit être marqué Prêt"' : '' ?>><i class="bi bi-bag-check"></i> Remettre le linge<?= $rest > 0 ? ' et encaisser' : '' ?></button></div>
      </form>
    </div></div>
    <?php $remainingItems = array_filter($items, fn($i) => (float) $i['quantity'] - (float) ($i['picked_qty'] ?? 0) > 0.0005); ?>
    <?php if (in_array($o['status'], ['ready', 'partial', 'processing'], true) && setting('pressing_retrait_partiel', '1') === '1' && (count($remainingItems) > 1 || array_sum(array_map(fn($i) => ($i['unit'] ?? 'piece') === 'kg' ? 0 : (int) round((float) $i['quantity'] - (float) $i['picked_qty']), $remainingItems)) > 1)): ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-bag-dash"></i> Retrait partiel</div><div class="card-body">
      <form method="post" action="<?= e(url('/pressing/' . $o['id'] . '/partial')) ?>" class="small"><?= csrf_field() ?>
        <?php foreach ($remainingItems as $i): $kg = ($i['unit'] ?? 'piece') === 'kg'; $left = round((float) $i['quantity'] - (float) $i['picked_qty'], 3); ?>
          <div class="d-flex align-items-center gap-2 mb-1"><span class="badge bg-dark"><?= e($i['tag']) ?></span><span class="flex-grow-1"><?= e($i['article_name']) ?></span><span class="text-secondary">reste <?= e(PS::qty($left, $kg ? 'kg' : 'piece')) ?></span>
            <?php if ($kg): ?><input name="qty[<?= (int) $i['id'] ?>]" value="0" inputmode="decimal" pattern="[0-9]+([,.][0-9]{1,3})?" title="Kilos, ex. 2,5" class="form-control form-control-sm text-end" style="width:80px"><?php else: ?><input type="number" name="qty[<?= (int) $i['id'] ?>]" min="0" max="<?= (int) round($left) ?>" value="0" class="form-control form-control-sm" style="width:64px"><?php endif; ?></div>
        <?php endforeach; ?>
        <div class="row g-1 mt-1"><?= App\Core\View::partial('pressing::_pay') ?></div>
        <button class="btn btn-sm btn-outline-success w-100 mt-2">Remettre ces pièces</button>
        <div class="text-secondary mt-1">Paiement facultatif ici ; le solde est exigé au retrait des dernières pièces.</div>
      </form>
    </div></div>
    <?php endif; ?>
    <?php if ($rest > 0): ?>
    <div class="card mb-3"><div class="card-header">Paiement partiel / acompte</div><div class="card-body">
      <form method="post" action="<?= e(url('/pressing/' . $o['id'] . '/pay')) ?>" class="row g-1"><?= csrf_field() ?><?= App\Core\View::partial('pressing::_pay') ?><div class="col-12 mt-1"><button class="btn btn-sm btn-outline-primary w-100">Encaisser</button></div></form>
    </div></div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($open && can('pressing.manage')): ?>
    <form method="post" action="<?= e(url('/pressing/' . $o['id'] . '/cancel')) ?>" data-confirm="Annuler le dépôt <?= e($o['number']) ?> ?<?= (float) $o['paid'] > 0 ? ' ' . e(money($o['paid'])) . ' seront remboursés en espèces.' : '' ?>" data-reason="1"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-x-octagon"></i> Annuler le dépôt</button></form>
    <?php endif; ?>
  </div>
</div>
