<?php /* « Demi-page A5 » */ $o = $doc['mdl']; $P = fn($v) => App\Core\View::partial($v, ['doc' => $doc, 'o' => $o]); ?>
<div class="sheet m-sheet m-a4-a5">
  <div class="m-top">
    <div><?= $P('modeles::_a4_societe') ?></div>
    <div><div class="m-doc"><?= e(mb_strtoupper($doc['title'])) ?></div><div style="text-align:right"><?= e(t('N°')) ?> <b><?= e($doc['number']) ?></b><br><?= e(fdate($doc['date'])) ?></div></div>
  </div>
  <?php if (!empty($doc['banner'])): ?><div class="banner"><?= e($doc['banner']) ?></div><?php endif; ?>
  <div style="display:flex;gap:8px;margin-top:8px"><?= $P('modeles::_a4_partie') ?></div>
  <?= $P('modeles::_a4_lignes') ?>
  <?= $P('modeles::_a4_bas') ?>
</div>
