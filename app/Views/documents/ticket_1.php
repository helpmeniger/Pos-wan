<?php /* Ticket — modèle 1 « Classique » */ ?>
<div class="ticket">
  <div class="c"><?= App\Core\View::partial('documents/_company', ['doc' => $doc, 'logo' => true]) ?></div>
  <?php if ($doc['dup']): ?><div class="dup"><?= e(t('DUPLICATA')) ?></div><?php endif; ?>
  <?php if ($doc['banner']): ?><div class="dup"><?= e($doc['banner']) ?></div><?php endif; ?>
  <hr>
  <div><?= e($doc['title']) ?> : <b><?= e($doc['number']) ?></b></div>
  <?php foreach ($doc['meta'] as [$k, $v]): ?><div><?= e($k) ?> : <?= e($v) ?></div><?php endforeach; ?>
  <hr>
  <table><?php foreach ($doc['items'] as $i): ?>
    <tr><td colspan="2"><?= e($i['name']) ?></td></tr>
    <tr><td><?= qty($i['qty']) ?> x <?= money($i['price'], false) ?><?= $i['discount'] > 0 ? ' -' . money($i['discount'], false) : '' ?></td><td class="num"><?= money($i['total'], false) ?></td></tr>
  <?php endforeach; ?></table>
  <hr>
  <table><?php foreach ($doc['totals'] as $r): ?><tr><td class="<?= !empty($r[2]) ? 'big' : '' ?>"><?= e($r[0]) ?></td><td class="num <?= !empty($r[2]) ? 'big' : '' ?>"><?= e($r[1]) ?></td></tr><?php endforeach; ?></table>
  <table><?php foreach ($doc['payments'] as $r): ?><tr><td class="<?= !empty($r[2]) ? 'b' : '' ?>"><?= e($r[0]) ?></td><td class="num <?= !empty($r[2]) ? 'b' : '' ?>"><?= e($r[1]) ?></td></tr><?php endforeach; ?></table>
  <hr>
  <div class="c"><?= nl2br(e($doc['footer'])) ?></div>
  <?php if ($doc['qr']): ?><div class="c qr-box"><div data-qr="<?= e($doc['qr']) ?>" data-qr-size="3" class="qr"></div><div class="small"><?= e($doc['number']) ?></div></div><?php endif; ?>
  <div class="c" style="font-size:.85em"><?= e(t('Articles')) ?> : <?= qty($doc['count']) ?> · POS Wan</div>
</div>
