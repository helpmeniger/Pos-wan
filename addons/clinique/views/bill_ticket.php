<?php $rest = round((float) $b['patient_amount'] - (float) $b['paid'], 2); ?>
<?php if (!empty($autoPrint)): ?><span data-autoprint></span><?php endif; ?>
<style>@page { size: <?= $bodyClass === 't58' ? '58mm' : '80mm' ?> auto; margin: 0; }</style>
<div class="ticket">
  <div class="c">
    <?php if ($logo = setting('company_logo')): ?><img class="logo" src="<?= e(upload_url($logo)) ?>" alt=""><br><?php endif; ?>
    <div class="big"><?= e(setting('company_name')) ?></div>
    <div><?= e(setting('company_address')) ?></div>
    <?php if (setting('company_phone')): ?><div><?= e(t('Tél')) ?> : <?= e(setting('company_phone')) ?></div><?php endif; ?>
  </div>
  <hr><div class="c b"><?= e(t('REÇU — PRESTATIONS MÉDICALES')) ?></div>
  <?php if ($b['status'] === 'cancelled'): ?><div class="dup"><?= e(t('ANNULÉE')) ?></div><?php endif; ?>
  <hr>
  <div>N° : <b><?= e($b['number']) ?></b> · <?= e(fdate($b['created_at'])) ?></div>
  <div><?= e(t('Patient')) ?> : <?= e($b['last_name'] . ' ' . $b['first_name']) ?> (<?= e($b['pnum']) ?>)</div>
  <hr>
  <table><?php foreach ($items as $i): ?>
    <tr><td colspan="2"><?= e($i['label']) ?></td></tr>
    <tr><td><?= e(qty($i['quantity'])) ?> x <?= money($i['unit_price'], false) ?></td><td class="num"><?= money($i['total'], false) ?></td></tr>
  <?php endforeach; ?></table>
  <hr>
  <table>
    <tr><td><?= e(t('Total')) ?></td><td class="num"><?= money($b['subtotal']) ?></td></tr>
    <?php if ((float) $b['coverage_amount'] > 0): ?><tr><td><?= e(t('Prise en charge')) ?> <?= e($b['insurer'] ?? '') ?> (<?= e((string) (float) $b['coverage_pct']) ?> %)</td><td class="num">-<?= money($b['coverage_amount']) ?></td></tr><?php endif; ?>
    <tr><td class="big"><?= e(t('À PAYER')) ?></td><td class="num big"><?= money($b['patient_amount']) ?></td></tr>
    <?php foreach ($payments as $pm): ?><tr><td><?= e(method_label($pm['method'])) ?><?= $pm['provider'] ? ' ' . e($pm['provider']) : '' ?></td><td class="num"><?= money($pm['amount']) ?></td></tr><?php endforeach; ?>
    <tr><td class="b"><?= e(t('Reste à payer')) ?></td><td class="num b"><?= money($b['status'] === 'open' ? max(0, $rest) : 0) ?></td></tr>
  </table>
  <hr>
  <div class="c" style="font-size:.85em"><?= e(t('Caissier')) ?> : <?= e($payments ? end($payments)['full_name'] : '') ?></div>
  <div class="c qr-box"><div data-qr="<?= e($b['number']) ?>" data-qr-size="3" class="qr"></div></div>
  <div class="c" style="font-size:.85em">POS Marwa · Clinique</div>
</div>
