<?php $courses = App\Services\RestaurantService::COURSES; $last = null; ?>
<style>@page { size: <?= $bodyClass === 't58' ? '58mm' : '80mm' ?> auto; margin: 0; } .ticket .kq { font-size: 1.5em; font-weight: bold; } .ticket .knote { font-weight: bold; padding-left: 1.2em; }</style>
<div class="ticket">
  <div class="c big"><?= $t['type'] === 'cancel' ? '*** ANNULATION ***' : 'BON ' . e(mb_strtoupper($t['station'])) ?></div>
  <div class="c" style="font-size:2em;font-weight:bold"><?= $t['table_name'] ? 'TABLE ' . e($t['table_name']) : e(['takeaway' => 'À EMPORTER', 'delivery' => 'LIVRAISON'][$t['order_type']] ?? '') ?></div>
  <hr><div><?= e($t['number']) ?> · <?= e($t['order_number']) ?> · <?= e(date('H:i', strtotime($t['created_at']))) ?></div>
  <div>Serveur : <?= e($t['waiter']) ?> · <?= (int) $t['covers'] ?> couv.<?= $t['customer_name'] ? ' · ' . e($t['customer_name']) : '' ?></div>
  <?php if ((int) $t['print_count'] > 0): ?><div class="dup">REIMPRESSION</div><?php endif; ?>
  <hr>
  <table>
  <?php foreach ($items as $i): if ($t['type'] === 'order' && $i['course'] !== $last): $last = $i['course']; ?><tr><td colspan="2" class="b">— <?= e(mb_strtoupper($courses[(int) $i['course']] ?? '')) ?> —</td></tr><?php endif; ?>
    <tr><td class="kq" style="width:3em"><?= qty($i['quantity']) ?> x</td><td class="kq" style="font-size:1.25em"><?= e($i['name']) ?></td></tr>
    <?php if ($i['note']): ?><tr><td colspan="2" class="knote">» <?= e($i['note']) ?></td></tr><?php endif; ?>
    <?php if (!empty($i['cancel_reason'])): ?><tr><td colspan="2" class="knote">Motif : <?= e($i['cancel_reason']) ?></td></tr><?php endif; ?>
  <?php endforeach; ?>
  </table>
  <?php if ($t['order_note']): ?><hr><div class="b">Note : <?= e($t['order_note']) ?></div><?php endif; ?>
  <hr><br>
</div>
