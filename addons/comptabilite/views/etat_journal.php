<?php
use Addons\Comptabilite\Compta;
$period = 'Du ' . fdate($from, false) . ' au ' . fdate($to, false);
ob_start(); ?>
<div class="col-6 col-md-2"><label class="form-label mb-0">Journal</label><select name="journal" class="form-select form-select-sm"><option value="">Tous</option><?php foreach ($journaux as $j): ?><option value="<?= (int) $j['id'] ?>" <?= (int) $jid === (int) $j['id'] ? 'selected' : '' ?>><?= e($j['code'] . ' — ' . $j['libelle']) ?></option><?php endforeach; ?></select></div>
<?php $extra = ob_get_clean(); require __DIR__ . '/_report_top.php';
$byJ = [];
foreach ($entries as $en) { $byJ[$en['journal_code'] . ' — ' . $en['journal_libelle']][] = $en; }
$gd = $gc = 0.0;
?>
<div class="<?= $print ? '' : 'card' ?>"><div class="table-responsive"><table class="table table-sm mb-0 small cpt-table">
<thead><tr><th>Date</th><th>N°</th><th>Pièce</th><th>Compte</th><th>Tiers</th><th>Libellé</th><th class="num">Débit</th><th class="num">Crédit</th></tr></thead><tbody>
<?php foreach ($byJ as $jl => $list): $jd = $jc = 0.0; ?>
  <tr class="cpt-head"><td colspan="8"><?= e($jl) ?></td></tr>
  <?php foreach ($list as $en): foreach ($en['lignes'] as $k => $l): $jd += (float) $l['debit']; $jc += (float) $l['credit']; ?>
    <tr><td class="text-nowrap"><?= $k === 0 ? e(fdate($en['date_ecriture'], false)) : '' ?></td>
      <td class="text-nowrap"><?php if ($k === 0): ?><?= $print ? e(Compta::pieceNo($en)) : '<a href="' . e(url('/compta/ecritures/' . $en['id'])) . '">' . e(Compta::pieceNo($en)) . '</a>' ?><?php endif; ?></td>
      <td><?= $k === 0 ? e((string) $en['piece']) : '' ?></td><td class="cpt-mono"><?= e($l['compte']) ?></td><td><?= e((string) $l['tiers_code']) ?></td><td><?= e($l['libelle']) ?></td>
      <td class="num"><?= Compta::num((float) $l['debit']) ?></td><td class="num"><?= Compta::num((float) $l['credit']) ?></td></tr>
  <?php endforeach; endforeach; $gd += $jd; $gc += $jc; ?>
  <tr class="cpt-sub"><td colspan="6">Total <?= e($jl) ?></td><td class="num"><?= Compta::num($jd) ?></td><td class="num"><?= Compta::num($jc) ?></td></tr>
<?php endforeach; ?>
<?php if (!$entries): ?><tr><td colspan="8" class="text-center text-secondary py-4">Aucune écriture sur cette période.</td></tr><?php else: ?>
  <tr class="cpt-total"><td colspan="6">TOTAL GÉNÉRAL</td><td class="num"><?= Compta::num($gd) ?></td><td class="num"><?= Compta::num($gc) ?></td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/_report_end.php'; ?>
