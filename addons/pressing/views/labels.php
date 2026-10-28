<?php use Addons\Pressing\PressingService as PS; ?>
<style>@page { size: 58mm auto; margin: 0; } .p-label { page-break-after: always; break-after: page; padding: 2mm 3mm; border-bottom: 1px dashed #999; font-family: monospace; } .p-label .tag { font-size: 26px; font-weight: 700; text-align: center; letter-spacing: 1px; } .p-label .x { text-align: center; font-size: 12px; }</style>
<?php foreach ($items as $i): $kg = ($i['unit'] ?? 'piece') === 'kg'; $nb = $kg ? 1 : (int) round((float) $i['quantity']); for ($n = 1; $n <= $nb; $n++): ?>
<div class="p-label">
  <div class="tag"><?= e($i['tag']) ?><?= $nb > 1 ? '/' . $n : '' ?></div>
  <div class="x"><b><?= e(mb_strimwidth($o['customer_name'], 0, 22, '…')) ?></b> · <?= e(substr(preg_replace('/\D/', '', $o['phone']), -4)) ?></div>
  <div class="x"><?= e($i['article_name']) ?><?= $kg ? ' ' . e(PS::qty($i['quantity'], 'kg')) : '' ?> — <?= e(PS::SERVICES[$i['service']] ?? $i['service']) ?><?= $i['color'] ? ' · ' . e($i['color']) : '' ?></div>
  <div class="x"><?= e(t('Prêt le')) ?> <?= e(fdate($o['due_date'], false)) ?><?= (int) $o['express'] ? ' · EXPRESS' : '' ?> · <?= e($o['number']) ?></div>
</div>
<?php endfor; endforeach; ?>
