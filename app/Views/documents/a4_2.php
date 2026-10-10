<?php /* A4 — modèle 2 « Moderne » : bandeau de couleur, cartes */ ?>
<div class="sheet tpl2">
  <div class="band">
    <div><?php if ($l = setting('company_logo')): ?><img src="<?= e(upload_url($l)) ?>" alt="" class="band-logo"><?php endif; ?><div class="band-company"><?= e(setting('company_name')) ?></div>
      <div class="band-sub"><?= e(setting('company_address')) ?><?= setting('company_phone') ? ' · ' . e(setting('company_phone')) : '' ?></div>
      <div class="band-sub"><?= setting('company_nif') ? 'NIF ' . e(setting('company_nif')) : '' ?><?= setting('company_rccm') ? ' · RCCM ' . e(setting('company_rccm')) : '' ?></div></div>
    <div class="band-doc"><div class="band-title"><?= e($doc['title']) ?></div><div>N° <?= e($doc['number']) ?></div><div><?= e(fdate($doc['date'])) ?></div></div>
  </div>
  <?php if (!empty($doc['banner'])): ?><div class="banner"><?= e($doc['banner']) ?></div><?php endif; ?>
  <?= App\Core\View::partial('documents/_a4_parties', ['doc' => $doc]) ?>
  <?= App\Core\View::partial('documents/_a4_lines', ['doc' => $doc]) ?>
  <?= App\Core\View::partial('documents/_a4_bottom', ['doc' => $doc]) ?>
</div>
