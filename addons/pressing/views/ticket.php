<?php use Addons\Pressing\PressingService as PS; $rest = round((float) $o['total'] - (float) $o['paid'], 2); ?>
<style>@page { size: <?= $bodyClass === 't58' ? '58mm' : '80mm' ?> auto; margin: 0; }</style>
<div class="ticket">
  <div class="c">
    <?php if ($logo = setting('company_logo')): ?><img class="logo" src="<?= e(upload_url($logo)) ?>" alt=""><br><?php endif; ?>
    <div class="big"><?= e(setting('company_name')) ?></div>
    <div><?= e(setting('company_address')) ?></div>
    <?php if (setting('company_phone')): ?><div><?= e(t('Tél')) ?> : <?= e(setting('company_phone')) ?></div><?php endif; ?>
  </div>
  <hr><div class="c b"><?= e(t('DÉPÔT PRESSING')) ?><?= (int) $o['express'] ? ' — EXPRESS' : '' ?><?= (int) ($o['delivery'] ?? 0) ? ' — ' . e(t('LIVRAISON')) : '' ?></div>
  <?php if ($o['status'] === 'cancelled'): ?><div class="dup"><?= e(t('ANNULÉ')) ?></div><?php endif; ?>
  <?php if ($o['status'] === 'delivered'): ?><div class="dup"><?= e(t('RETIRÉ LE')) ?> <?= e(fdate($o['delivered_at'])) ?></div><?php endif; ?>
  <hr>
  <div>N° : <b class="big"><?= e($o['number']) ?></b></div>
  <div><?= e(t('Déposé le')) ?> : <?= e(fdate($o['created_at'])) ?></div>
  <div class="b"><?= e(t('Retrait prévu')) ?> : <?= e(fdate($o['due_date'], false)) ?></div>
  <div><?= e(t('Client')) ?> : <?= e($o['customer_name']) ?> · <?= e($o['phone']) ?></div>
  <?php if ((int) ($o['delivery'] ?? 0)): ?><div class="b"><?= e(t('Livraison')) ?> : <?= e((string) $o['delivery_address']) ?></div><?php endif; ?>
  <hr>
  <table><?php foreach ($items as $i): ?>
    <tr><td colspan="2"><b>[<?= e($i['tag']) ?>]</b> <?= e($i['article_name']) ?> — <?= e(t(PS::SERVICES[$i['service']] ?? $i['service'])) ?></td></tr>
    <?php if ($i['color'] || $i['defects']): ?><tr><td colspan="2" style="font-size:.85em"><?= e(implode(' · ', array_filter([$i['color'], $i['brand'], $i['defects'] ? t('Défauts') . ' : ' . $i['defects'] : null]))) ?></td></tr><?php endif; ?>
    <tr><td><?= e(PS::qty($i['quantity'], $i['unit'] ?? 'piece')) ?> x <?= money($i['unit_price'], false) ?><?= PS::measured($i['unit'] ?? null) ? '/' . e(PS::UNIT_SYMBOLS[$i['unit']]) : '' ?></td><td class="num"><?= money($i['line_total'], false) ?></td></tr>
  <?php endforeach; ?></table>
  <hr>
  <table>
    <tr><td><?= e(t('Pièces')) ?></td><td class="num"><?= (int) $o['pieces'] ?></td></tr>
    <?php if ((float) ($o['weight'] ?? 0) > 0): ?><tr><td><?= e(t('Poids')) ?></td><td class="num"><?= e(PS::qty($o['weight'], 'kg')) ?></td></tr><?php endif; ?>
    <?php if ((float) ($o['area'] ?? 0) > 0): ?><tr><td><?= e(t('Surface')) ?></td><td class="num"><?= e(PS::qty($o['area'], 'm2')) ?></td></tr><?php endif; ?>
    <?php if ((float) $o['express_fee'] > 0): ?><tr><td><?= e(t('Supplément express')) ?><?= $o['express_pct'] !== null ? ' (' . e(PS::qty($o['express_pct'])) . ' %)' : '' ?></td><td class="num"><?= money($o['express_fee']) ?></td></tr><?php endif; ?>
    <?php if ((float) $o['discount'] > 0): ?><tr><td><?= e(t('Remise')) ?></td><td class="num">-<?= money($o['discount']) ?></td></tr><?php endif; ?>
    <?php if ((float) ($o['delivery_fee'] ?? 0) > 0): ?><tr><td><?= e(t('Livraison')) ?></td><td class="num"><?= money($o['delivery_fee']) ?></td></tr><?php endif; ?>
    <tr><td class="big">TOTAL</td><td class="num big"><?= money($o['total']) ?></td></tr>
    <tr><td><?= e(t('Payé')) ?></td><td class="num"><?= money($o['paid']) ?></td></tr>
    <tr><td class="b"><?= e(t('Reste à payer')) ?></td><td class="num b"><?= money(max(0, $rest)) ?></td></tr>
  </table>
  <hr>
  <div class="c" style="font-size:.85em"><?= nl2br(e((string) setting('pressing_conditions', ''))) ?></div>
  <div class="c qr-box"><div data-qr="<?= e($o['number']) ?>" data-qr-size="3" class="qr"></div><div class="small"><?= e($o['number']) ?></div></div>
  <div class="c" style="font-size:.85em">POS Marwa · Pressing</div>
</div>
