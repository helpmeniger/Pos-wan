<?php
use Addons\Comptabilite\Compta;
$period = 'Arrêté au ' . fdate($to, false) . ' — présentation simplifiée (plan non SYSCOHADA)'; $showFrom = false; $exportable = false;
require __DIR__ . '/_report_top.php';
$block = function (string $title, array $rows) use ($s) {
    echo '<table class="table table-sm mb-0 small cpt-table cpt-state"><thead><tr><th colspan="2">' . e($title) . '</th><th class="num">Montant</th></tr></thead><tbody>';
    foreach ($rows as $k => $v) {
        echo '<tr><td class="ref">' . e($k) . '</td><td>' . e($s['labels'][$k] ?? '') . '</td><td class="num">' . Compta::num((float) $v) . '</td></tr>';
    }
    echo '<tr class="tot"><td></td><td>Total</td><td class="num">' . Compta::num(array_sum($rows)) . '</td></tr></tbody></table>';
};
?>
<div class="row g-3">
  <div class="col-lg-6"><div class="<?= $print ? '' : 'card' ?>"><?php $block('ACTIF (soldes débiteurs, classes 1 à 5)', $s['actif']); ?></div></div>
  <div class="col-lg-6"><div class="<?= $print ? '' : 'card' ?>"><?php $block('PASSIF (soldes créditeurs, classes 1 à 5)', $s['passif'] + ['Rés.' => $s['resultat']]); ?></div></div>
  <div class="col-lg-6"><div class="<?= $print ? '' : 'card' ?>"><?php $block('CHARGES (classe 6)', $s['charges']); ?></div></div>
  <div class="col-lg-6"><div class="<?= $print ? '' : 'card' ?>"><?php $block('PRODUITS (classe 7)', $s['produits']); ?></div></div>
</div>
<p class="mt-2"><b>Résultat : <?= money($s['resultat']) ?></b> <span class="small text-secondary">(« Rés. » au passif : résultat de l'exercice non encore affecté)</span></p>
<?php require __DIR__ . '/_report_end.php'; ?>
