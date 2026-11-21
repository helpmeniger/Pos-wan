<?php /* Client / destinataire et informations du document (A4) */ ?>
<div class="m-box" style="flex:1"><b><?= e($doc['party']['label']) ?></b><?php foreach ($doc['party']['lines'] as $l): if ($l === null || $l === '') continue; ?><br><?= e($l) ?><?php endforeach; ?></div>
<div class="m-box" style="flex:1"><?php foreach ($doc['info'] as $i => [$k, $v]): ?><?= $i ? '<br>' : '' ?><?= e($k) ?> : <b><?= e($v) ?></b><?php endforeach; ?></div>
