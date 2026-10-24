<?php use Addons\Comptabilite\Compta; $td = array_sum(array_column($e['lignes'], 'debit')); ?>
<link rel="stylesheet" href="<?= e(asset('addons/comptabilite/compta.css')) ?>">
<div class="sheet">
  <div class="cpt-title"><div><h1>Écriture <?= e(Compta::pieceNo($e)) ?></h1><div><?= e($e['journal_code'] . ' — ' . $e['journal_libelle']) ?> · exercice <?= e($e['exercice_code']) ?></div></div>
    <div style="text-align:right"><b><?= e((string) setting('company_name', 'POS Marwa')) ?></b><br>Date : <?= e(fdate($e['date_ecriture'], false)) ?><br><?= $e['statut'] === 'valide' ? 'Validée' : 'BROUILLARD (provisoire)' ?></div></div>
  <p><b><?= e($e['libelle']) ?></b><?= $e['piece'] ? ' — pièce ' . e($e['piece']) : '' ?></p>
  <table class="cpt-table"><thead><tr><th>Compte</th><th>Tiers</th><th>Libellé</th><th class="num">Débit</th><th class="num">Crédit</th></tr></thead><tbody>
  <?php foreach ($e['lignes'] as $l): ?><tr><td><?= e($l['numero'] . ' ' . $l['compte_libelle']) ?></td><td><?= e((string) $l['tiers_code']) ?></td><td><?= e($l['libelle']) ?></td><td class="num"><?= Compta::num((float) $l['debit']) ?></td><td class="num"><?= Compta::num((float) $l['credit']) ?></td></tr><?php endforeach; ?>
  <tr class="cpt-sub"><td colspan="3">Total</td><td class="num"><?= Compta::num($td) ?></td><td class="num"><?= Compta::num($td) ?></td></tr>
  </tbody></table>
  <div class="sign"><div>Saisi par : <?= e((string) ($e['auteur'] ?? '')) ?></div><div>Validé par : <?= e((string) ($e['valideur'] ?? '')) ?></div><div>Visa :</div></div>
</div>
