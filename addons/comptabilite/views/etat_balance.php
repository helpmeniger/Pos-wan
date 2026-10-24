<?php
use Addons\Comptabilite\Compta;
$period = 'Du ' . fdate($from, false) . ' au ' . fdate($to, false);
ob_start(); ?>
<div class="col-6 col-md-1"><label class="form-label mb-0">Du compte</label><input name="du_compte" class="form-control form-control-sm" value="<?= e($cFrom) ?>"></div>
<div class="col-6 col-md-1"><label class="form-label mb-0">Au compte</label><input name="au_compte" class="form-control form-control-sm" value="<?= e($cTo) ?>"></div>
<div class="col-6 col-md-2"><label class="form-label mb-0">Niveau</label><select name="niveau" class="form-select form-select-sm"><option value="compte">Par compte</option><option value="groupe" <?= $niveau === 'groupe' ? 'selected' : '' ?>>Par groupe (2 chiffres)</option><option value="classe" <?= $niveau === 'classe' ? 'selected' : '' ?>>Par classe</option></select></div>
<?php $extra = ob_get_clean(); require __DIR__ . '/_report_top.php';
$n = fn($v) => Compta::num((float) $v);
$cur = null;
$t = $b['total'];
$balanced = abs($t['fd'] - $t['fc']) < 0.01 && abs($t['md'] - $t['mc']) < 0.01;
?>
<?php if (!$print && $b['lignes'] && !$balanced && $cFrom === '' && $cTo === ''): ?><div class="alert alert-danger py-2 small">La balance n'est pas équilibrée : vérifiez les écritures (un brouillard incomplet ne peut normalement pas exister).</div><?php endif; ?>
<div class="<?= $print ? '' : 'card' ?>"><div class="table-responsive"><table class="table table-sm mb-0 small cpt-table">
<thead><tr><th rowspan="2">Compte</th><th rowspan="2">Libellé</th><th colspan="2" class="text-center">Soldes d'ouverture</th><th colspan="2" class="text-center">Mouvements de la période</th><th colspan="2" class="text-center">Soldes de fin</th></tr>
<tr><th class="num">Débit</th><th class="num">Crédit</th><th class="num">Débit</th><th class="num">Crédit</th><th class="num">Débiteur</th><th class="num">Créditeur</th></tr></thead><tbody>
<?php foreach ($b['lignes'] as $l): ?>
  <?php if ($cur !== null && $cur !== $l['classe'] && $niveau !== 'classe'): $c = $b['classes'][$cur]; ?><tr class="cpt-sub"><td colspan="2">Total classe <?= $cur ?></td><td class="num"><?= $n($c['od']) ?></td><td class="num"><?= $n($c['oc']) ?></td><td class="num"><?= $n($c['md']) ?></td><td class="num"><?= $n($c['mc']) ?></td><td class="num"><?= $n($c['fd']) ?></td><td class="num"><?= $n($c['fc']) ?></td></tr><?php endif; $cur = $l['classe']; ?>
  <tr><td class="cpt-mono"><?= $print || $niveau !== 'compte' ? e($l['numero']) : '<a href="' . e(url('/compta/lettrage', ['compte' => $l['numero'], 'exercice' => $ex['id']])) . '">' . e($l['numero']) . '</a>' ?></td><td><?= e($l['libelle']) ?></td>
    <td class="num"><?= $n($l['od']) ?></td><td class="num"><?= $n($l['oc']) ?></td><td class="num"><?= $n($l['md']) ?></td><td class="num"><?= $n($l['mc']) ?></td><td class="num"><?= $n($l['fd']) ?></td><td class="num"><?= $n($l['fc']) ?></td></tr>
<?php endforeach; ?>
<?php if ($cur !== null && $niveau !== 'classe'): $c = $b['classes'][$cur]; ?><tr class="cpt-sub"><td colspan="2">Total classe <?= $cur ?></td><td class="num"><?= $n($c['od']) ?></td><td class="num"><?= $n($c['oc']) ?></td><td class="num"><?= $n($c['md']) ?></td><td class="num"><?= $n($c['mc']) ?></td><td class="num"><?= $n($c['fd']) ?></td><td class="num"><?= $n($c['fc']) ?></td></tr><?php endif; ?>
<?php if ($b['lignes']): ?><tr class="cpt-total"><td colspan="2">TOTAL GÉNÉRAL</td><td class="num"><?= $n($t['od']) ?></td><td class="num"><?= $n($t['oc']) ?></td><td class="num"><?= $n($t['md']) ?></td><td class="num"><?= $n($t['mc']) ?></td><td class="num"><?= $n($t['fd']) ?></td><td class="num"><?= $n($t['fc']) ?></td></tr>
<?php else: ?><tr><td colspan="8" class="text-center text-secondary py-4">Aucun mouvement.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/_report_end.php'; ?>
