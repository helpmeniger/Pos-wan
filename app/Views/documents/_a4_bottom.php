<?php /* Totaux, montant en lettres, notes, QR et signatures communs aux modèles A4 */ ?>
<div class="a4-bottom">
  <div class="a4-left">
    <?php if (!empty($doc['words'])): ?><p><?= e($doc['words']) ?></p><?php endif; ?>
    <?php if (!empty($doc['notes'])): ?><p class="notes"><?= nl2br(e($doc['notes'])) ?></p><?php endif; ?>
    <?php if (!empty($doc['qr'])): ?><div class="qr-a4"><div data-qr="<?= e($doc['qr']) ?>" data-qr-size="3" class="qr"></div><div class="small"><?= e(t('Référence')) ?> : <?= e($doc['number']) ?></div></div><?php endif; ?>
  </div>
  <?php if ($doc['totals']): ?><table class="totals"><?php foreach ($doc['totals'] as $r): ?><tr class="<?= !empty($r[2]) ? 'grand' : '' ?>"><td><?= e($r[0]) ?></td><td class="num"><?= e($r[1]) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>
<?php if (!empty($doc['signatures'])): ?><div class="sign"><?php foreach ($doc['signatures'] as $s): ?><div><?= e($s) ?></div><?php endforeach; ?></div><?php endif; ?>
<div class="foot"><?= e($doc['footer'] ?? '') ?></div>
