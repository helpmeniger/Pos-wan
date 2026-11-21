<?php /* Coordonnées de l'entreprise (A4) */ ?>
<?php if ($o['logo'] === '1' && ($l = setting('company_logo'))): ?><img class="logo" src="<?= e(upload_url($l)) ?>" alt="" style="max-height:22mm"><br><?php endif; ?>
<div class="m-company"><?= e(setting('company_name')) ?></div>
<?php if ($o['slogan'] !== ''): ?><div class="m-slogan"><?= e($o['slogan']) ?></div><?php endif; ?>
<?php if ($o['adresse'] === '1'): ?><?php if (setting('company_address')): ?><div><?= e(setting('company_address')) ?></div><?php endif; ?><?php if (setting('company_phone')): ?><div><?= e(t('Tél')) ?> : <?= e(setting('company_phone')) ?></div><?php endif; ?><?php if (setting('company_email')): ?><div><?= e(setting('company_email')) ?></div><?php endif; ?><?php endif; ?>
<?php if ($o['nif'] === '1'): ?><?php if (setting('company_nif')): ?><div>NIF : <?= e(setting('company_nif')) ?></div><?php endif; ?><?php if (setting('company_rccm')): ?><div>RCCM : <?= e(setting('company_rccm')) ?></div><?php endif; ?><?php endif; ?>
