<?php
use Addons\Comptabilite\Compta;
use Addons\Comptabilite\Reports;
$period = 'Du ' . fdate($ex['date_debut'], false) . ' au ' . fdate($to, false) . ' — présentation SYSCOHADA révisé'; $showFrom = false;
require __DIR__ . '/_report_top.php';
$n = fn($v) => Compta::num((float) $v) ?: '—';
?>
<div class="<?= $print ? '' : 'card' ?>"><div class="table-responsive"><table class="table table-sm mb-0 small cpt-table cpt-state">
<thead><tr><th>Réf</th><th>Libellé</th><th class="num">Exercice N</th><th class="num">Exercice N-1</th></tr></thead><tbody>
<?php foreach (Reports::RESULTAT as $l): $sub = $l[2] === null; ?>
  <tr class="<?= $sub ? ($l[0] === 'XI' ? 'big' : 'tot') : '' ?>"><td class="ref"><?= e($l[0]) ?></td><td><?= e($l[1]) ?>
    <?php if (!$sub && !$print && !empty($r['detail'][$l[0]])): ?><div class="text-secondary" style="font-size:.8em"><?= e(implode(' · ', array_map(fn($k, $v) => $k . ' : ' . Compta::num((float) $v), array_keys($r['detail'][$l[0]]), $r['detail'][$l[0]]))) ?></div><?php endif; ?></td>
    <td class="num <?= $r['valeurs'][$l[0]] < 0 ? 'text-danger' : '' ?>"><?= $n($r['valeurs'][$l[0]]) ?></td><td class="num"><?= $rp ? $n($rp['valeurs'][$l[0]]) : '' ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<p class="small text-secondary mt-2">Produits en positif, charges en négatif (−). Colonne N-1 : <?= $prev ? 'exercice ' . e($prev['code']) : 'aucun exercice précédent' ?>.</p>
<?php require __DIR__ . '/_report_end.php'; ?>
