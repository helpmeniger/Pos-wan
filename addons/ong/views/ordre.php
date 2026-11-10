<?php $days = (int) ((strtotime($m['end_date']) - strtotime($m['start_date'])) / 86400) + 1; ?>
<link rel="stylesheet" href="<?= e(asset('addons/ong/ong.css')) ?>">
<div class="sheet ong-doc">
  <div class="head"><?php if ($logo = setting('company_logo')): ?><img src="<?= e(upload_url($logo)) ?>" alt="" style="max-height:55px"><br><?php endif; ?><b style="font-size:17px"><?= e(setting('company_name')) ?></b><br><?= e(setting('company_address')) ?> <?= e(setting('company_phone')) ?></div>
  <h2><?= e(t('ORDRE DE MISSION')) ?></h2><div style="text-align:center">N° <b><?= e($m['number']) ?></b></div>
  <table class="kv"><tr><td><?= e(t('Objet de la mission')) ?></td><td><?= e($m['title']) ?></td></tr><tr><td><?= e(t('Destination')) ?></td><td><?= e($m['destination']) ?></td></tr>
    <tr><td><?= e(t('Départ')) ?></td><td><?= e(fdate($m['start_date'], false)) ?></td></tr><tr><td><?= e(t('Retour')) ?></td><td><?= e(fdate($m['end_date'], false)) ?> (<?= $days ?> <?= e(t('jour(s)')) ?>)</td></tr>
    <tr><td><?= e(t('Projet')) ?></td><td><?= e($m['projet_code']) ?> — <?= e($m['projet']) ?></td></tr><tr><td><?= e(t('Imputation budgétaire')) ?></td><td><?= e($m['conv_number']) ?> / <?= e($m['ligne_code']) ?> <?= e($m['ligne']) ?></td></tr>
    <tr><td><?= e(t('Moyen de transport')) ?></td><td><?= e($m['plate'] ? $m['vehicule'] . ' — ' . $m['plate'] : t('Transport en commun / autre')) ?></td></tr></table>
  <?php if ($m['objective']): ?><p><b><?= e(t('Objectifs :')) ?></b> <?= nl2br(e($m['objective'])) ?></p><?php endif; ?>
  <table><thead><tr><th><?= e(t('Matricule')) ?></th><th><?= e(t('Nom et prénom')) ?></th><th><?= e(t('Fonction')) ?></th><th class="num"><?= e(t('Per diem')) ?></th><th class="num"><?= e(t('Jours')) ?></th><th class="num"><?= e(t('Total')) ?></th><th><?= e(t('Émargement')) ?></th></tr></thead><tbody>
  <?php foreach ($membres as $x): ?><tr><td><?= e($x['matricule']) ?></td><td><?= e($x['full_name']) ?><?= (int) $x['personnel_id'] === (int) $m['chef_id'] ? ' (' . e(t('chef de mission')) . ')' : '' ?></td><td><?= e($x['position']) ?></td><td class="num"><?= money($x['per_diem'], false) ?></td><td class="num"><?= e(qty($x['days'])) ?></td><td class="num"><?= money((float) $x['per_diem'] * (float) $x['days'], false) ?></td><td style="width:22%"></td></tr><?php endforeach; ?>
  <tr style="font-weight:700"><td colspan="5"><?= e(t('Coût prévu de la mission')) ?></td><td class="num"><?= money($m['estimate'], false) ?></td><td></td></tr></tbody></table>
  <p style="font-size:12px"><?= e(t('Les autorités administratives, civiles et militaires sont priées de bien vouloir faciliter l\'accomplissement de cette mission.')) ?></p>
  <table><thead><tr><th style="width:34%"><?= e(t('Visa de départ (lieu, date, cachet)')) ?></th><th style="width:33%"><?= e(t('Visa d\'arrivée sur le terrain')) ?></th><th><?= e(t('Visa de retour')) ?></th></tr></thead><tbody><tr><td style="height:70px"></td><td></td><td></td></tr></tbody></table>
  <div class="sign"><div><?= e(t('Le chef de mission')) ?><br><?= e($m['chef']) ?></div><div><?= e(t('Approuvé par')) ?><br><?= e($m['approver']) ?></div><div><?= e(t('Le représentant légal')) ?></div></div>
</div>
