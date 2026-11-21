<?php /* Ticket « Tableau à colonnes » */ $o = $doc['mdl']; $P = fn($v) => App\Core\View::partial($v, ['doc' => $doc, 'o' => $o]); ?>
<div class="ticket m-ticket m-colonnes">
  <?= $P('modeles::_t_entete') ?>
  <div class="m-sep"></div>
  <div class="m-numero <?= $o['cadre_numero'] === '1' ? 'm-cadre' : '' ?>"><?= e(mb_strtoupper($doc['title'])) ?> N° <?= e($doc['number']) ?></div>
  <?php foreach ($doc['meta'] as [$k, $v]): ?><div class="m-row"><span><?= e($k) ?></span><span><?= e($v) ?></span></div><?php endforeach; ?>
  <?= $P('modeles::_t_credit') ?>
  <table class="m-lignes" style="margin-top:4px">
    <thead><tr><th><?= e(t('Désignation')) ?></th><th class="num"><?= e(t('Qté')) ?></th><th class="num"><?= e(t('P.U.')) ?></th><th class="num"><?= e(t('Montant')) ?></th></tr></thead>
    <tbody><?php foreach ($doc['items'] as $i): ?><tr>
      <td class="t-item"><?= e($i['name']) ?><?php if ($o['reference'] === '1' && ($i['ref'] ?? '') !== ''): ?> <span class="m-sub">(<?= e($i['ref']) ?>)</span><?php endif; ?><?php if ($i['discount'] > 0): ?><div class="m-sub"><?= e(t('remise')) ?> -<?= money($i['discount'], false) ?></div><?php endif; ?></td>
      <td class="num"><?= qty($i['qty']) ?></td><td class="num"><?= money($i['price'], false) ?></td><td class="num"><?= money($i['total'], false) ?></td>
    </tr><?php endforeach; ?></tbody>
  </table>
  <?= $P('modeles::_t_totaux') ?>
  <?= $P('modeles::_t_bas') ?>
</div>
