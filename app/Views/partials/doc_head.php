<?php /* En-tête des documents A4 : $docTitle, $docNumber, $docDate */ ?>
<div class="head">
  <div>
    <?php if ($logo = setting('company_logo')): ?><img src="<?= e(upload_url($logo)) ?>" alt="" style="max-height:60px;max-width:200px"><br><?php endif; ?>
    <h1><?= e(setting('company_name')) ?></h1>
    <div><?= e(setting('company_address')) ?></div>
    <?php if (setting('company_phone')): ?><div>Tél : <?= e(setting('company_phone')) ?></div><?php endif; ?>
    <?php if (setting('company_email')): ?><div><?= e(setting('company_email')) ?></div><?php endif; ?>
    <?php if (setting('company_nif')): ?><div>NIF : <?= e(setting('company_nif')) ?></div><?php endif; ?>
    <?php if (setting('company_rccm')): ?><div>RCCM : <?= e(setting('company_rccm')) ?></div><?php endif; ?>
  </div>
  <div style="text-align:right">
    <div style="font-size:20px;font-weight:bold;color:#14213d"><?= e($docTitle) ?></div>
    <div>N° <b><?= e($docNumber) ?></b></div>
    <div>Date : <?= e(fdate($docDate)) ?></div>
  </div>
</div>
