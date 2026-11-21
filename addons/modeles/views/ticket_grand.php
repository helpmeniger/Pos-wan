<?php /* Ticket « Grands caractères » */ $o = $doc['mdl']; $P = fn($v) => App\Core\View::partial($v, ['doc' => $doc, 'o' => $o]); ?>
<div class="ticket m-ticket m-grand">
  <?= $P('modeles::_t_entete') ?>
  <div class="m-numero <?= $o['cadre_numero'] === '1' ? 'm-cadre' : '' ?>"><?= e(mb_strtoupper($doc['title'])) ?><br><?= e($doc['number']) ?></div>
  <?php foreach ($doc['meta'] as [$k, $v]): ?><div><b><?= e($k) ?></b> : <?= e($v) ?></div><?php endforeach; ?>
  <?= $P('modeles::_t_credit') ?>
  <div class="m-sep"></div>
  <?php foreach ($doc['items'] as $i): ?>
    <div class="m-item t-item"><?= e($i['name']) ?></div>
    <div class="m-row"><span><?= qty($i['qty']) ?> × <?= money($i['price'], false) ?><?= $i['discount'] > 0 ? ' −' . money($i['discount'], false) : '' ?></span><span class="b"><?= money($i['total'], false) ?></span></div>
  <?php endforeach; ?>
  <div class="m-sep"></div>
  <?= $P('modeles::_t_totaux') ?>
  <?= $P('modeles::_t_bas') ?>
</div>
