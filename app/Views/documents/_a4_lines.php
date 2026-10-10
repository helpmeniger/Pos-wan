<?php /* Tableau des lignes commun aux modèles A4 */ $p = $doc['prices']; ?>
<table class="lines">
  <thead><tr><th><?= e(t('Réf.')) ?></th><th><?= e(t('Désignation')) ?></th><th class="num"><?= e(t('Qté')) ?></th><?php if ($p): ?><th class="num"><?= e(t('P.U.')) ?></th><th class="num"><?= e(t('Remise')) ?></th><th class="num"><?= e(t('Total')) ?></th><?php else: ?><th><?= e(t('Unité')) ?></th><th><?= e(t('Livré / contrôle')) ?></th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($doc['lines'] as $l): ?><tr><td><?= e($l['ref']) ?></td><td><?= e($l['name']) ?></td><td class="num"><?= qty($l['qty']) ?></td>
    <?php if ($p): ?><td class="num"><?= money($l['price']) ?></td><td class="num"><?= $l['discount'] > 0 ? money($l['discount']) : '' ?></td><td class="num"><?= money($l['total']) ?></td><?php else: ?><td><?= e($l['unit'] ?? '') ?></td><td style="border-bottom:1px solid #999"></td><?php endif; ?></tr><?php endforeach; ?>
  </tbody>
</table>
