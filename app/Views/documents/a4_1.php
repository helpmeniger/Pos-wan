<?php /* A4 — modèle 1 « Classique » */ ?>
<div class="sheet tpl1">
  <?= App\Core\View::partial('partials/doc_head', ['docTitle' => $doc['title'], 'docNumber' => $doc['number'], 'docDate' => $doc['date']]) ?>
  <?php if (!empty($doc['banner'])): ?><div class="banner"><?= e($doc['banner']) ?></div><?php endif; ?>
  <?= App\Core\View::partial('documents/_a4_parties', ['doc' => $doc]) ?>
  <?= App\Core\View::partial('documents/_a4_lines', ['doc' => $doc]) ?>
  <?= App\Core\View::partial('documents/_a4_bottom', ['doc' => $doc]) ?>
</div>
