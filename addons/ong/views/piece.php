<?php use Addons\Ong\OngService as S; ?>
<link rel="stylesheet" href="<?= e(asset('addons/ong/ong.css')) ?>">
<div class="sheet ong-doc">
  <div class="head"><?php if ($logo = setting('company_logo')): ?><img src="<?= e(upload_url($logo)) ?>" alt="" style="max-height:55px"><br><?php endif; ?><b style="font-size:17px"><?= e(setting('company_name')) ?></b><br><?= e(setting('company_address')) ?></div>
  <h2><?= e(t('PIÈCE DE DÉPENSE')) ?></h2><div style="text-align:center">N° <b><?= e($d['number']) ?></b> — <?= e(fdate($d['exp_date'], false)) ?> — <?= e(mb_strtoupper(t(S::DEP_STATUS[$d['status']]))) ?></div>
  <div class="box"><?= money($d['amount']) ?></div><div style="text-align:center;font-style:italic"><?= e(amount_words((float) $d['amount'])) ?> <?= e(currency()['name'] ?? '') ?></div>
  <table class="kv"><tr><td><?= e(t('Payé à')) ?></td><td><?= e($d['payee']) ?></td></tr><tr><td><?= e(t('Objet')) ?></td><td><?= e($d['description']) ?></td></tr><tr><td><?= e(t('Pièce justificative')) ?></td><td><?= e($d['piece'] ?: '—') ?></td></tr>
    <tr><td><?= e(t('Projet')) ?></td><td><?= e($d['projet_code']) ?> — <?= e($d['projet']) ?></td></tr><tr><td><?= e(t('Bailleur / convention')) ?></td><td><?= e($d['bailleur']) ?> — <?= e($d['conv_number']) ?> <?= e($d['conv_title']) ?></td></tr>
    <tr><td><?= e(t('Ligne budgétaire')) ?></td><td><?= e($d['ligne_code']) ?> — <?= e($d['ligne']) ?></td></tr><tr><td><?= e(t('Payée depuis')) ?></td><td><?= e($d['compte'] ?: '—') ?><?= $d['paid_at'] ? ' · ' . e(fdate($d['paid_at'])) : '' ?></td></tr>
    <?php if ($d['reason']): ?><tr><td><?= e(t('Motif (rejet / annulation)')) ?></td><td><?= e($d['reason']) ?></td></tr><?php endif; ?></table>
  <div class="sign"><div><?= e(t('Demandé par')) ?><br><?= e($d['author']) ?></div><div><?= e(t('Approuvé par')) ?><br><?= e($d['approver']) ?></div><div><?= e(t('Payé par (comptable)')) ?></div><div><?= e(t('Reçu par le bénéficiaire')) ?></div></div>
</div>
