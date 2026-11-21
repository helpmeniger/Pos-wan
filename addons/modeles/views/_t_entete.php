<?php /* Modèles de documents — en-tête des tickets ($doc, $o = options). */ ?>
<div class="m-head">
  <?php if ($o['logo'] === '1' && ($l = setting('company_logo'))): ?><img class="logo" src="<?= e(upload_url($l)) ?>" alt=""><br><?php endif; ?>
  <div class="t-company"><?= e(setting('company_name')) ?></div>
  <?php if ($o['slogan'] !== ''): ?><div class="m-slogan"><?= e($o['slogan']) ?></div><?php endif; ?>
  <?php if ($o['adresse'] === '1'): ?>
    <?php if (setting('company_address')): ?><div><?= e(setting('company_address')) ?></div><?php endif; ?>
    <?php if (setting('company_phone')): ?><div><?= e(t('Tél')) ?> : <?= e(setting('company_phone')) ?></div><?php endif; ?>
  <?php endif; ?>
  <?php if ($o['nif'] === '1' && setting('company_nif')): ?><div>NIF : <?= e(setting('company_nif')) ?><?= setting('company_rccm') ? ' · RCCM : ' . e(setting('company_rccm')) : '' ?></div><?php endif; ?>
</div>
<?php if (!empty($doc['dup'])): ?><div class="dup"><?= e(t('DUPLICATA')) ?></div><?php endif; ?>
<?php if (!empty($doc['banner'])): ?><div class="dup"><?= e($doc['banner']) ?></div><?php endif; ?>
