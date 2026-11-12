<?php /* Bloc fiscal (module de certification) : affiché seulement s'il est fourni. $doc['fiscal'] = [status, title, lines[[libellé, valeur]], mentions[], note] */
$f = $doc['fiscal'] ?? null; if (!$f) return; $ok = ($f['status'] ?? '') === 'certified'; ?>
<div class="fiscal-box" style="border:1px <?= $ok ? 'solid' : 'dashed' ?> #000;padding:4px 6px;margin:6px 0;font-size:.9em">
  <div style="font-weight:bold;text-align:center"><?= e($f['title'] ?? '') ?></div>
  <?php foreach ((array) ($f['lines'] ?? []) as [$k, $v]): ?><div style="word-break:break-all"><?= e($k) ?> : <b><?= e($v) ?></b></div><?php endforeach; ?>
  <?php foreach ((array) ($f['mentions'] ?? []) as $m): ?><div style="font-size:.9em"><?= nl2br(e($m)) ?></div><?php endforeach; ?>
  <?php if (!empty($f['note'])): ?><div style="font-size:.9em;font-style:italic"><?= e($f['note']) ?></div><?php endif; ?>
</div>
