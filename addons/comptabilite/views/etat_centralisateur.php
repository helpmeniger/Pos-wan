<?php
use Addons\Comptabilite\Compta;
$period = 'Exercice complet'; $showFrom = false; $to = $ex['date_fin'];
require __DIR__ . '/_report_top.php';
$months = ['01' => 'janvier', '02' => 'février', '03' => 'mars', '04' => 'avril', '05' => 'mai', '06' => 'juin', '07' => 'juillet', '08' => 'août', '09' => 'septembre', '10' => 'octobre', '11' => 'novembre', '12' => 'décembre'];
$cur = null; $jd = $jc = $gd = $gc = 0.0;
?>
<div class="<?= $print ? '' : 'card' ?>"><div class="table-responsive"><table class="table table-sm mb-0 small cpt-table">
<thead><tr><th>Journal</th><th>Mois</th><th class="num">Écritures</th><th class="num">Débit</th><th class="num">Crédit</th></tr></thead><tbody>
<?php foreach ($rows as $i => $r): ?>
  <?php if ($cur !== null && $cur !== $r['code']): ?><tr class="cpt-sub"><td colspan="3">Total <?= e($cur) ?></td><td class="num"><?= Compta::num($jd) ?></td><td class="num"><?= Compta::num($jc) ?></td></tr><?php $jd = $jc = 0.0; endif; $cur = $r['code']; ?>
  <tr><td><?= e($r['code'] . ' — ' . $r['libelle']) ?></td><td><?= e(($months[substr($r['mois'], 5, 2)] ?? '') . ' ' . substr($r['mois'], 0, 4)) ?></td><td class="num"><?= (int) $r['nb'] ?></td><td class="num"><?= Compta::num((float) $r['debit']) ?></td><td class="num"><?= Compta::num((float) $r['credit']) ?></td></tr>
  <?php $jd += (float) $r['debit']; $jc += (float) $r['credit']; $gd += (float) $r['debit']; $gc += (float) $r['credit']; ?>
<?php endforeach; ?>
<?php if ($cur !== null): ?><tr class="cpt-sub"><td colspan="3">Total <?= e($cur) ?></td><td class="num"><?= Compta::num($jd) ?></td><td class="num"><?= Compta::num($jc) ?></td></tr>
  <tr class="cpt-total"><td colspan="3">TOTAL GÉNÉRAL</td><td class="num"><?= Compta::num($gd) ?></td><td class="num"><?= Compta::num($gc) ?></td></tr>
<?php else: ?><tr><td colspan="5" class="text-center text-secondary py-4">Aucune écriture.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/_report_end.php'; ?>
