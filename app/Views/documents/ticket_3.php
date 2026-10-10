<?php /* Ticket — modèle 3 « Moderne » : en-tête inversé, total encadré, gros QR */ ?>
<div class="ticket modern">
  <div class="head-inv c"><?= App\Core\View::partial('documents/_company', ['doc' => $doc, 'logo' => true]) ?></div>
  <?php if ($doc['dup']): ?><div class="dup"><?= e(t('DUPLICATA')) ?></div><?php endif; ?>
  <?php if ($doc['banner']): ?><div class="dup"><?= e($doc['banner']) ?></div><?php endif; ?>
  <div class="c doc-title"><?= e(mb_strtoupper($doc['title'])) ?> N° <?= e($doc['number']) ?></div>
  <table class="meta"><?php foreach ($doc['meta'] as [$k, $v]): ?><tr><td><?= e($k) ?></td><td class="num"><?= e($v) ?></td></tr><?php endforeach; ?></table>
  <table class="items"><thead><tr><th><?= e(t('Article')) ?></th><th class="num"><?= e(t('Qté')) ?></th><th class="num"><?= e(t('Total')) ?></th></tr></thead><tbody>
  <?php foreach ($doc['items'] as $i): ?><tr><td><?= e($i['name']) ?><div class="sub"><?= money($i['price']) ?><?= $i['discount'] > 0 ? ' · ' . e(t('remise')) . ' ' . money($i['discount']) : '' ?></div></td><td class="num"><?= qty($i['qty']) ?></td><td class="num"><?= money($i['total'], false) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php foreach ($doc['totals'] as $r): if (!empty($r[2])): ?><div class="total-box"><span><?= e($r[0]) ?></span><span><?= e($r[1]) ?></span></div><?php else: ?><div class="row2"><span><?= e($r[0]) ?></span><span><?= e($r[1]) ?></span></div><?php endif; endforeach; ?>
  <?php foreach ($doc['payments'] as $r): ?><div class="row2 <?= !empty($r[2]) ? 'b' : '' ?>"><span><?= e($r[0]) ?></span><span><?= e($r[1]) ?></span></div><?php endforeach; ?>
  <?php if ($doc['qr']): ?><div class="c qr-box"><div data-qr="<?= e($doc['qr']) ?>" data-qr-size="4" class="qr big-qr"></div><div class="small"><?= e(t('Scannez pour vérifier ce ticket')) ?></div></div><?php endif; ?>
  <div class="c footer-msg"><?= nl2br(e($doc['footer'])) ?></div>
</div>
