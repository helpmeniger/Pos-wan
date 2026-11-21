<?php /* A4 « Facture encadrée (grille) » */ $o = $doc['mdl']; $P = fn($v) => App\Core\View::partial($v, ['doc' => $doc, 'o' => $o]); ?>
<div class="sheet m-sheet m-a4-encadre">
  <div class="m-top">
    <div><?= $P('modeles::_a4_societe') ?></div>
    <div class="m-cartouche"><div class="m-doc"><?= e(mb_strtoupper($doc['title'])) ?></div><div><?= e(t('N°')) ?> <b><?= e($doc['number']) ?></b></div><div><?= e(t('Date')) ?> : <?= e(fdate($doc['date'])) ?></div></div>
  </div>
  <?php if (!empty($doc['banner'])): ?><div class="banner"><?= e($doc['banner']) ?></div><?php endif; ?>
  <div style="display:flex;gap:14px;margin-top:12px"><?= $P('modeles::_a4_partie') ?></div>
  <?= $P('modeles::_a4_lignes') ?>
  <?= $P('modeles::_a4_bas') ?>
</div>
