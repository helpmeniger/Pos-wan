<?php $lines = []; foreach ($o['items'] as $i) { if ($i['status'] === 'cancelled') continue; $k = $i['name'] . '|' . $i['unit_price']; $lines[$k] = ['name' => $i['name'], 'price' => (float) $i['unit_price'], 'qty' => ($lines[$k]['qty'] ?? 0) + (float) $i['quantity']]; } ?>
<style>@page { size: <?= $bodyClass === 't58' ? '58mm' : '80mm' ?> auto; margin: 0; }</style>
<div class="ticket">
  <div class="c"><?php if ($logo = setting('company_logo')): ?><img class="logo" src="<?= e(upload_url($logo)) ?>" alt=""><br><?php endif; ?><div class="big"><?= e(setting('company_name')) ?></div><div><?= e(setting('company_address')) ?></div><?php if (setting('company_phone')): ?><div>Tél : <?= e(setting('company_phone')) ?></div><?php endif; ?></div>
  <hr><div class="c b">ADDITION — NE VAUT PAS TICKET DE CAISSE</div><hr>
  <div><?= $o['table_name'] ? 'Table <b>' . e($o['table_name']) . '</b> · ' : '' ?><?= (int) $o['covers'] ?> couvert(s)</div>
  <div>Commande <?= e($o['number']) ?> · <?= e(date('d/m/Y H:i')) ?></div><div>Serveur : <?= e($o['waiter']) ?></div>
  <hr><table><?php foreach ($lines as $l): ?><tr><td colspan="2"><?= e($l['name']) ?></td></tr><tr><td><?= qty($l['qty']) ?> x <?= money($l['price'], false) ?></td><td class="num"><?= money($l['qty'] * $l['price'], false) ?></td></tr><?php endforeach; ?></table>
  <hr><table><tr><td class="big">TOTAL</td><td class="num big"><?= money($o['total']) ?></td></tr>
  <?php if ($o['paid'] > 0): ?><tr><td>Déjà réglé</td><td class="num"><?= money($o['paid']) ?></td></tr><tr><td class="b">RESTE À PAYER</td><td class="num b"><?= money($o['due']) ?></td></tr><?php endif; ?>
  <?php if ((int) $o['covers'] > 1): ?><tr><td>Par personne (<?= (int) $o['covers'] ?>)</td><td class="num"><?= money($o['due'] / (int) $o['covers']) ?></td></tr><?php endif; ?></table>
  <hr><div class="c"><?= nl2br(e(setting('ticket_footer', 'Merci de votre visite !'))) ?></div>
</div>
