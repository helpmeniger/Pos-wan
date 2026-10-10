<div class="parties">
  <div class="box" style="flex:1"><b><?= e($doc['party']['label']) ?></b><?php foreach ($doc['party']['lines'] as $l): if ($l === null || $l === '') continue; ?><br><?= e($l) ?><?php endforeach; ?></div>
  <div class="box" style="flex:1"><?php foreach ($doc['info'] as $i => [$k, $v]): ?><?= $i ? '<br>' : '' ?><?= e($k) ?> : <b><?= e($v) ?></b><?php endforeach; ?></div>
</div>
