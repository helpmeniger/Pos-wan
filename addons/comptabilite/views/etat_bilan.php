<?php
use Addons\Comptabilite\Compta;
use Addons\Comptabilite\Reports;
$period = 'Arrêté au ' . fdate($to, false) . ' — présentation SYSCOHADA révisé'; $showFrom = false;
require __DIR__ . '/_report_top.php';
$n = fn($v) => Compta::num((float) $v) ?: '—';
?>
<?php if (abs($b['ecart']) >= 0.01): ?><div class="alert alert-warning py-2 small">Total actif et total passif diffèrent de <?= Compta::num(abs($b['ecart'])) ?> : un compte n'est pas classé (voir ci-dessous) ou la balance n'est pas équilibrée.</div><?php endif; ?>
<?php if ($b['non_classes']): ?><div class="alert alert-warning py-2 small">Comptes non classés dans le bilan : <?= e(implode(', ', array_map(fn($k, $v) => $k . ' (' . Compta::num((float) $v) . ')', array_keys($b['non_classes']), $b['non_classes']))) ?>.</div><?php endif; ?>
<div class="row g-3">
<div class="col-xl-6"><div class="<?= $print ? '' : 'card' ?>"><div class="table-responsive"><table class="table table-sm mb-0 small cpt-table cpt-state">
  <thead><tr><th>Réf</th><th>ACTIF</th><th class="num">Brut</th><th class="num">Amort. / dépréc.</th><th class="num">Net N</th><th class="num">Net N-1</th></tr></thead><tbody>
  <?php foreach (Reports::BILAN_ACTIF as $r): $tot = isset($r[2]); ?>
    <tr class="<?= $tot ? ($r[0] === 'BZ' ? 'big' : 'tot') : '' ?>"><td class="ref"><?= e($r[0]) ?></td><td><?= e($r[1]) ?></td><td class="num"><?= $n($b['brut'][$r[0]]) ?></td><td class="num"><?= $n($b['amort'][$r[0]]) ?></td><td class="num"><?= $n($b['net'][$r[0]]) ?></td><td class="num"><?= $bp ? $n($bp['net'][$r[0]]) : '' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div></div>
<div class="col-xl-6"><div class="<?= $print ? '' : 'card' ?>"><div class="table-responsive"><table class="table table-sm mb-0 small cpt-table cpt-state">
  <thead><tr><th>Réf</th><th>PASSIF</th><th class="num">Net N</th><th class="num">Net N-1</th></tr></thead><tbody>
  <?php foreach (Reports::BILAN_PASSIF as $r): $tot = isset($r[2]); ?>
    <tr class="<?= $tot ? ($r[0] === 'DZ' ? 'big' : 'tot') : '' ?>"><td class="ref"><?= e($r[0]) ?></td><td><?= e($r[1]) ?></td><td class="num"><?= $n($b['passif'][$r[0]]) ?></td><td class="num"><?= $bp ? $n($bp['passif'][$r[0]]) : '' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div></div>
</div>
<p class="small text-secondary mt-2">Résultat de l'exercice (CJ) calculé à partir des comptes de charges et de produits tant que l'exercice n'est pas clôturé. Colonne N-1 : <?= $prev ? 'exercice ' . e($prev['code']) . ' (écritures validées)' : 'aucun exercice précédent' ?>. Ce bilan est un état de gestion produit par le logiciel ; les états financiers annuels déposés (DSF) restent sous la responsabilité de votre comptable ou expert-comptable.</p>
<?php require __DIR__ . '/_report_end.php'; ?>
