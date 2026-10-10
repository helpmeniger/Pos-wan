<style>@page { size: <?= $bodyClass === 't58' ? '58mm' : '80mm' ?> auto; margin: 0; }</style>
<div class="ticket">
  <div class="c">
    <?php if ($logo = setting('company_logo')): ?><img class="logo" src="<?= e(upload_url($logo)) ?>" alt=""><br><?php endif; ?>
    <div class="big"><?= e(setting('company_name')) ?></div>
    <div><?= e($s['store_name']) ?></div>
    <div><?= e(setting('company_address')) ?></div>
    <?php if (setting('company_phone')): ?><div>Tél : <?= e(setting('company_phone')) ?></div><?php endif; ?>
    <?php if (setting('company_nif')): ?><div>NIF : <?= e(setting('company_nif')) ?><?= setting('company_rccm') ? ' · RCCM : ' . e(setting('company_rccm')) : '' ?></div><?php endif; ?>
  </div>
  <?php if ($dup): ?><div class="dup">DUPLICATA</div><?php endif; ?>
  <?php if ($s['status'] === 'cancelled'): ?><div class="dup">VENTE ANNULÉE</div><?php endif; ?>
  <hr>
  <div>Ticket : <b><?= e($s['number']) ?></b></div>
  <div><?= e(fdate($s['created_at'])) ?> · <?= e($s['register_name'] ?? '') ?></div>
  <div>Caissier : <?= e($s['cashier']) ?></div>
  <?php if ($s['customer_name']): ?><div>Client : <?= e($s['customer_name']) ?></div><?php endif; ?>
  <hr>
  <table>
  <?php foreach ($items as $i): ?>
    <tr><td colspan="2"><?= e($i['product_name']) ?></td></tr>
    <tr><td><?= qty($i['quantity']) ?> x <?= money($i['unit_price'], false) ?><?= (float) $i['discount'] > 0 ? ' -' . money($i['discount'], false) : '' ?></td><td class="num"><?= money($i['line_total'], false) ?></td></tr>
  <?php endforeach; ?>
  </table>
  <hr>
  <?php if ((float) $s['discount_total'] > 0): ?><table><tr><td>Sous-total</td><td class="num"><?= money($s['subtotal']) ?></td></tr><tr><td>Remise</td><td class="num">-<?= money($s['discount_total']) ?></td></tr></table><?php endif; ?>
  <table><tr><td class="big">TOTAL</td><td class="num big"><?= money($s['total']) ?></td></tr></table>
  <?php if ((float) $s['tax_total'] > 0): ?><div class="r">dont taxes : <?= money($s['tax_total']) ?></div><?php endif; ?>
  <table>
    <?php foreach ($payments as $pm): ?><tr><td><?= e(method_label($pm['method'])) ?></td><td class="num"><?= money($pm['amount']) ?></td></tr><?php endforeach; ?>
    <tr><td>Montant remis</td><td class="num"><?= money($s['tendered']) ?></td></tr>
    <tr><td class="b">Monnaie rendue</td><td class="num b"><?= money($s['change_due']) ?></td></tr>
    <?php if ((float) $s['credit_amount'] > 0): ?><tr><td class="b">Reste dû (crédit)</td><td class="num b"><?= money($s['credit_amount']) ?></td></tr><?php endif; ?>
  </table>
  <hr>
  <div class="c"><?= nl2br(e(setting('ticket_footer', 'Merci de votre visite !'))) ?></div>
  <div class="c" style="font-size:.85em">Articles : <?= qty(array_sum(array_column($items, 'quantity'))) ?> · POS Wan</div>
</div>
