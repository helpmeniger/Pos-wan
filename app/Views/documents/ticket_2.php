<?php /* Ticket — modèle 2 « Compact » : économe en papier */ ?>
<div class="ticket compact">
  <div class="c b"><?= e(setting('company_name')) ?></div>
  <div class="c"><?= e(setting('company_phone')) ?><?= setting('company_nif') ? ' · NIF ' . e(setting('company_nif')) : '' ?></div>
  <?php if ($doc['dup']): ?><div class="c b">— <?= e(t('DUPLICATA')) ?> —</div><?php endif; ?>
  <?php if ($doc['banner']): ?><div class="c b">— <?= e($doc['banner']) ?> —</div><?php endif; ?>
  <div class="c"><?= e($doc['number']) ?> · <?= e($doc['date']) ?></div>
  <hr>
  <table><?php foreach ($doc['items'] as $i): ?><tr><td><?= qty($i['qty']) ?>×</td><td><?= e($i['name']) ?><?= $i['discount'] > 0 ? ' (-' . money($i['discount'], false) . ')' : '' ?></td><td class="num"><?= money($i['total'], false) ?></td></tr><?php endforeach; ?></table>
  <hr>
  <table><?php foreach (array_merge($doc['totals'], $doc['payments']) as $r): ?><tr><td class="<?= !empty($r[2]) ? 'b' : '' ?>"><?= e($r[0]) ?></td><td class="num <?= !empty($r[2]) ? 'b' : '' ?>"><?= e($r[1]) ?></td></tr><?php endforeach; ?></table>
  <?php if ($doc['qr']): ?><div class="c qr-box"><div data-qr="<?= e($doc['qr']) ?>" data-qr-size="2" class="qr small-qr"></div></div><?php endif; ?>
  <div class="c"><?= e(strtok((string) $doc['footer'], "\n")) ?></div>
</div>
