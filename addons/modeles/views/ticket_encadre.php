<?php /* Ticket « Encadré à colonnes » */ $o = $doc['mdl']; $P = fn($v, $d = []) => App\Core\View::partial($v, ['doc' => $doc, 'o' => $o] + $d); ?>
<div class="ticket m-ticket m-encadre">
  <?= $P('modeles::_t_entete') ?>
  <div class="m-numero <?= $o['cadre_numero'] === '1' ? 'm-cadre' : '' ?>"><?= e(mb_strtoupper($doc['title'])) ?> N° <?= e($doc['number']) ?></div>
  <table class="m-info">
    <?php if (!empty($doc['store'])): ?><tr><td><?= e(t('Boutique')) ?> :</td><td class="num"><?= e($doc['store']) ?></td></tr><?php endif; ?>
    <?php foreach ($doc['meta'] as [$k, $v]): ?><tr><td><?= e($k) ?> :</td><td class="num"><?= e($v) ?></td></tr><?php endforeach; ?>
  </table>
  <?= $P('modeles::_t_credit') ?>
  <div class="m-sep"></div>
  <table class="m-lignes">
    <thead><tr><th><?= e(t('Qté')) ?></th><th><?= e(t('Produit')) ?></th><th class="num"><?= e(t('PU')) ?></th><th class="num"><?= e(t('Total')) ?></th></tr></thead>
    <tbody><?php foreach ($doc['items'] as $i): ?><tr>
      <td class="q"><?= qty($i['qty']) ?></td>
      <td class="t-item"><?= e($i['name']) ?><?php if ($o['reference'] === '1' && ($i['ref'] ?? '') !== ''): ?><div class="m-sub"><?= e($i['ref']) ?></div><?php endif; ?><?php if ($i['discount'] > 0): ?><div class="m-sub"><?= e(t('remise')) ?> -<?= money($i['discount'], false) ?></div><?php endif; ?></td>
      <td class="num"><?= money($i['price'], false) ?></td><td class="num"><?= money($i['total'], false) ?></td>
    </tr><?php endforeach; ?></tbody>
  </table>
  <div class="m-sep"></div>
  <?= $P('modeles::_t_totaux') ?>
  <?= $P('modeles::_t_bas') ?>
</div>
