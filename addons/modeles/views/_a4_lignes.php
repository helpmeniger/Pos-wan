<?php /* Lignes d'un document A4 du module ($doc, $o) */ $p = $doc['prices']; $ref = $o['reference'] === '1'; $disc = $p && array_filter(array_column($doc['lines'], 'discount')); ?>
<table class="m-lignes">
  <thead><tr><?php if ($ref): ?><th><?= e(t('Réf.')) ?></th><?php endif; ?><th><?= e(t('Désignation')) ?></th><th class="num"><?= e(t('Qté')) ?></th>
    <?php if ($p): ?><th class="num"><?= e(t('P.U.')) ?></th><?php if ($disc): ?><th class="num"><?= e(t('Remise')) ?></th><?php endif; ?><th class="num"><?= e(t('Montant')) ?></th><?php else: ?><th><?= e(t('Unité')) ?></th><th><?= e(t('Livré / contrôle')) ?></th><?php endif; ?></tr></thead>
  <tbody><?php foreach ($doc['lines'] as $l): ?><tr>
    <?php if ($ref): ?><td><?= e($l['ref']) ?></td><?php endif; ?><td><?= e($l['name']) ?></td><td class="num"><?= qty($l['qty']) ?></td>
    <?php if ($p): ?><td class="num"><?= money($l['price'], false) ?></td><?php if ($disc): ?><td class="num"><?= $l['discount'] > 0 ? money($l['discount'], false) : '' ?></td><?php endif; ?><td class="num"><?= money($l['total'], false) ?></td>
    <?php else: ?><td><?= e($l['unit'] ?? '') ?></td><td></td><?php endif; ?>
  </tr><?php endforeach; ?></tbody>
</table>
