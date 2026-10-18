<?php use Addons\Ecole\Ecole; $modes = payment_methods(); ?>
<div class="sheet">
  <div class="head"><div><h1><?= e(Ecole::nom()) ?></h1><?= e((string) setting('company_address', '')) ?></div><div style="text-align:right"><h1>PAIEMENT DE SALAIRE</h1>N° <?= e($s['numero']) ?><br>Mois : <b><?= e($s['periode']) ?></b><br>Date : <?= e(fdate($s['created_at'])) ?><?php if ($s['annule']): ?><div class="dup">ANNULÉ</div><?php endif; ?></div></div>
  <div class="box" style="margin-top:12px">Bénéficiaire : <b><?= e($s['prenom'] . ' ' . $s['nom']) ?></b> · matricule <?= e($s['matricule']) ?><br>Fonction : <?= e($s['poste'] ?: Ecole::FONCTIONS[$s['fonction']]) ?></div>
  <table class="totals"><tr class="grand"><td>Montant versé</td><td class="num"><?= money($s['montant']) ?></td></tr><tr><td>Mode</td><td class="num"><?= e($modes[$s['mode']] ?? $s['mode']) ?><?= $s['reference'] ? ' · ' . e($s['reference']) : '' ?></td></tr></table>
  <?php if ($s['note']): ?><p><?= e($s['note']) ?></p><?php endif; ?>
  <div class="sign"><div>Signature du bénéficiaire</div><div><?= e(setting('ecole_signature', 'La direction')) ?></div></div>
</div>
