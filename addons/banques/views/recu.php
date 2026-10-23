<?php use Addons\Banques\BankService as BS; ?>
<link rel="stylesheet" href="<?= e(asset('addons/banques/banques.css')) ?>">
<div class="bq-receipt">
  <div style="text-align:center">
    <?php if ($logo = setting('company_logo')): ?><img src="<?= e(upload_url($logo)) ?>" alt="" style="max-height:50px"><br><?php endif; ?>
    <b style="font-size:16px"><?= e(setting('company_name')) ?></b><br><?= e(setting('company_address')) ?>
  </div>
  <h3 style="text-align:center;margin:10px 0 4px"><?= e(mb_strtoupper(t(BS::TYPES[$t['type']]))) ?></h3>
  <div style="text-align:center"><?= e(t('Opération')) ?> <b><?= e($t['number']) ?></b> — <?= e(fdate($t['op_date'], false)) ?></div>
  <?php if ($t['cancelled_at']): ?><div class="cancelled"><?= e(t('ANNULÉE LE')) ?> <?= e(fdate($t['cancelled_at'])) ?> — <?= e($t['cancel_reason']) ?></div><?php endif; ?>
  <div class="amount"><?= (int) $t['direction'] > 0 ? '+' : '−' ?> <?= money($t['amount']) ?></div>
  <div style="text-align:center;font-style:italic;margin-bottom:8px"><?= e(amount_words((float) $t['amount'])) ?> <?= e(currency()['name'] ?? '') ?></div>
  <table>
    <tr><td><?= e(t('Compte')) ?></td><td><b><?= e($t['account_name']) ?></b> (<?= e(t(BS::KINDS[$t['kind']])) ?>)<?= $t['bank_name'] ? '<br>' . e($t['bank_name']) : '' ?><?= $t['account_number'] ? ' · ' . e(t('N°')) . ' ' . e($t['account_number']) : '' ?></td></tr>
    <?php if ($t['counter_name']): ?><tr><td><?= e((int) $t['direction'] > 0 ? t('Provenant de') : t('Vers')) ?></td><td><?= e($t['counter_name']) ?></td></tr><?php endif; ?>
    <?php if ($session): ?><tr><td><?= e(t('Clôture de caisse')) ?></td><td>n° <?= (int) $session['id'] ?> — <?= e($session['register_name']) ?> — <?= e($session['cashier']) ?> — <?= e(fdate($session['closed_at'])) ?></td></tr><?php endif; ?>
    <tr><td><?= e(t('Mode')) ?></td><td><?= e(t(BS::METHODS[$t['method']] ?? $t['method'])) ?><?= $t['reference'] ? ' — ' . e(t('Réf.')) . ' ' . e($t['reference']) : '' ?></td></tr>
    <?php if ($t['person']): ?><tr><td><?= e((int) $t['direction'] > 0 ? t('Déposé / versé par') : t('Retiré par / bénéficiaire')) ?></td><td><?= e($t['person']) ?></td></tr><?php endif; ?>
    <?php if ($t['description']): ?><tr><td><?= e(t('Libellé')) ?></td><td><?= e($t['description']) ?></td></tr><?php endif; ?>
    <tr><td><?= e(t('Saisi par')) ?></td><td><?= e($t['user_name']) ?> — <?= e(fdate($t['created_at'])) ?></td></tr>
  </table>
  <div class="sign"><div><?= e(t('Remis par')) ?></div><div><?= e(t('Reçu par')) ?></div></div>
</div>
