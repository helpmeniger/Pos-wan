<?php /* A4 — modèle 3 « Épuré » : noir et blanc, économique en encre */ ?>
<div class="sheet tpl3">
  <div class="plain-head">
    <div><div class="plain-company"><?= e(setting('company_name')) ?></div><div class="small"><?= e(setting('company_address')) ?> · <?= e(setting('company_phone')) ?><?= setting('company_nif') ? ' · NIF ' . e(setting('company_nif')) : '' ?></div></div>
    <div class="plain-doc"><?= e(mb_strtoupper($doc['title'])) ?><br><span><?= e($doc['number']) ?> — <?= e(fdate($doc['date'], false)) ?></span></div>
  </div>
  <?php if (!empty($doc['banner'])): ?><div class="banner"><?= e($doc['banner']) ?></div><?php endif; ?>
  <?= App\Core\View::partial('documents/_a4_parties', ['doc' => $doc]) ?>
  <?= App\Core\View::partial('documents/_a4_lines', ['doc' => $doc]) ?>
  <?= App\Core\View::partial('documents/_a4_bottom', ['doc' => $doc]) ?>
</div>
