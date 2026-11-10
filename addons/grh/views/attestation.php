<?php use Addons\Grh\GrhService as S; ?>
<link rel="stylesheet" href="<?= e(asset('addons/grh/grh.css')) ?>">
<div class="sheet grh-doc">
  <div class="head"><?php if ($logo = setting('company_logo')): ?><img src="<?= e(upload_url($logo)) ?>" alt="" style="max-height:60px"><br><?php endif; ?><b style="font-size:17px"><?= e(setting('company_name')) ?></b><br><?= e(setting('company_address')) ?> <?= e(setting('company_phone')) ?><?= setting('company_nif') ? '<br>NIF : ' . e(setting('company_nif')) : '' ?><?= setting('company_rccm') ? ' · RCCM : ' . e(setting('company_rccm')) : '' ?></div>
  <h2 style="margin-top:40px"><?= e(t($e['status'] === 'sorti' ? 'CERTIFICAT DE TRAVAIL' : 'ATTESTATION DE TRAVAIL')) ?></h2>
  <p class="letter"><?= e(t('Je soussigné(e), représentant(e) de')) ?> <b><?= e(setting('company_name')) ?></b>, <?= e(t('atteste que')) ?> <b><?= e($e['civility']) ?> <?= e($e['first_name']) ?> <?= e(mb_strtoupper($e['last_name'])) ?></b><?= $e['birth_date'] ? ', ' . e(t('né(e) le')) . ' ' . e(fdate($e['birth_date'], false)) . ($e['birth_place'] ? ' ' . e(t('à')) . ' ' . e($e['birth_place']) : '') : '' ?>, <?= e(t('matricule')) ?> <b><?= e($e['matricule']) ?></b>,
  <?php if ($e['status'] === 'sorti'): ?><?= e(t('a été employé(e) dans notre structure du')) ?> <b><?= e(fdate($e['hire_date'], false)) ?></b> <?= e(t('au')) ?> <b><?= e(fdate($e['exit_date'], false)) ?></b><?= $e['poste'] ? ' ' . e(t('en qualité de')) . ' <b>' . e($e['poste']) . '</b>' : '' ?>. <?= e(t('Il / elle nous quitte libre de tout engagement.')) ?>
  <?php else: ?><?= e(t('est employé(e) dans notre structure depuis le')) ?> <b><?= e(fdate($e['hire_date'], false)) ?></b><?= $e['poste'] ? ' ' . e(t('en qualité de')) . ' <b>' . e($e['poste']) . '</b>' : '' ?><?= $e['departement'] ? ' (' . e($e['departement']) . ')' : '' ?><?= $contrat ? ', ' . e(t('sous contrat')) . ' <b>' . e(S::CONTRACT_KINDS[$contrat['kind']]) . '</b>' : '' ?>.<?php endif; ?></p>
  <p class="letter"><?= e(t('En foi de quoi, la présente attestation lui est délivrée pour servir et valoir ce que de droit.')) ?></p>
  <p style="text-align:right;margin:40px 20px 0"><?= e(t('Fait le')) ?> <?= e(fdate(date('Y-m-d'), false)) ?></p>
  <div class="sign" style="justify-content:flex-end"><div style="flex:0 0 45%"><?= e(t('Signature et cachet')) ?></div></div>
</div>
