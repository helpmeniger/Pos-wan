<?php /* A4 « Bandeau latéral » */ $o = $doc['mdl']; $P = fn($v) => App\Core\View::partial($v, ['doc' => $doc, 'o' => $o]); ?>
<div class="sheet m-sheet m-a4-lateral">
  <div class="m-side">
    <?= $P('modeles::_a4_societe') ?>
    <div class="m-label"><?= e($doc['party']['label']) ?></div>
    <?php foreach ($doc['party']['lines'] as $l): if ($l === null || $l === '') continue; ?><div><?= e($l) ?></div><?php endforeach; ?>
    <div class="m-label"><?= e(t('Informations')) ?></div>
    <?php foreach ($doc['info'] as [$k, $v]): ?><div><?= e($k) ?> : <b><?= e($v) ?></b></div><?php endforeach; ?>
  </div>
  <div class="m-main">
    <div class="m-doc"><?= e(mb_strtoupper($doc['title'])) ?></div>
    <div><?= e(t('N°')) ?> <b><?= e($doc['number']) ?></b> · <?= e(fdate($doc['date'])) ?></div>
    <?php if (!empty($doc['banner'])): ?><div class="banner"><?= e($doc['banner']) ?></div><?php endif; ?>
    <?= $P('modeles::_a4_lignes') ?>
    <?= $P('modeles::_a4_bas') ?>
  </div>
</div>
