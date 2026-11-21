<?php /* Ticket « Économique » : une ligne par article */ $o = $doc['mdl']; $P = fn($v) => App\Core\View::partial($v, ['doc' => $doc, 'o' => $o]); ?>
<div class="ticket m-ticket m-economique">
  <?= $P('modeles::_t_entete') ?>
  <div class="m-row b"><span><?= e($doc['title']) ?> <?= e($doc['number']) ?></span><span><?= e($o['date'] === '1' ? ($doc['date'] ?? '') : '') ?></span></div>
  <?php foreach ($doc['meta'] as [$k, $v]): if ($k === t('Date')) continue; ?><div><?= e($k) ?> : <?= e($v) ?></div><?php endforeach; ?>
  <?= $P('modeles::_t_credit') ?>
  <div class="m-sep"></div>
  <?php foreach ($doc['items'] as $i): ?><div class="m-row"><span class="t-item"><?= qty($i['qty']) ?> x <?= e($i['name']) ?><?= $i['discount'] > 0 ? ' (-' . money($i['discount'], false) . ')' : '' ?></span><span><?= money($i['total'], false) ?></span></div><?php endforeach; ?>
  <div class="m-sep"></div>
  <?= $P('modeles::_t_totaux') ?>
  <?= $P('modeles::_t_bas') ?>
</div>
