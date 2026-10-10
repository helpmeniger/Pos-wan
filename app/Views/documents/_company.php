<?php /* En-tête entreprise des tickets ($logo booléen) */ ?>
<?php if (!empty($logo) && ($l = setting('company_logo'))): ?><img class="logo" src="<?= e(upload_url($l)) ?>" alt=""><br><?php endif; ?>
<div class="big"><?= e(setting('company_name')) ?></div>
<?php if (!empty($doc['store'])): ?><div><?= e($doc['store']) ?></div><?php endif; ?>
<div><?= e(setting('company_address')) ?></div>
<?php if (setting('company_phone')): ?><div><?= e(t('Tél')) ?> : <?= e(setting('company_phone')) ?></div><?php endif; ?>
<?php if (setting('company_nif')): ?><div>NIF : <?= e(setting('company_nif')) ?><?= setting('company_rccm') ? ' · RCCM : ' . e(setting('company_rccm')) : '' ?></div><?php endif; ?>
