<?php
use Addons\Comptabilite\Compta;
$period = 'Du ' . fdate($from, false) . ' au ' . fdate($to, false) . ($cFrom !== '' || $cTo !== '' ? ' · comptes ' . ($cFrom ?: '…') . ' à ' . ($cTo ?: '…') : '');
ob_start(); ?>
<div class="col-6 col-md-1"><label class="form-label mb-0">Du compte</label><input name="du_compte" class="form-control form-control-sm" value="<?= e($cFrom) ?>" placeholder="ex. 4"></div>
<div class="col-6 col-md-1"><label class="form-label mb-0">Au compte</label><input name="au_compte" class="form-control form-control-sm" value="<?= e($cTo) ?>" placeholder="ex. 4"></div>
<?php $extra = ob_get_clean(); require __DIR__ . '/_report_top.php'; ?>
<div class="<?= $print ? '' : 'card' ?>"><div class="table-responsive"><table class="table table-sm mb-0 small cpt-table">
<thead><tr><th>Date</th><th>Jnl</th><th>N°</th><th>Pièce</th><th>Tiers</th><th>Libellé</th><th>Let.</th><th class="num">Débit</th><th class="num">Crédit</th><th class="num">Solde</th></tr></thead><tbody>
<?php foreach ($gl['comptes'] as $a): ?>
  <tr class="cpt-head"><td colspan="10"><span class="cpt-mono"><?= e($a['numero']) ?></span> <?= e($a['libelle']) ?></td></tr>
  <?php if (abs($a['report']) >= 0.005): ?><tr><td colspan="6"><i>Report (soldes antérieurs et à-nouveaux)</i></td><td></td><td class="num"><?= $a['report'] > 0 ? Compta::num($a['report']) : '' ?></td><td class="num"><?= $a['report'] < 0 ? Compta::num(-$a['report']) : '' ?></td><td class="num"><?= Compta::num($a['report']) ?></td></tr><?php endif; ?>
  <?php foreach ($a['lignes'] as $l): ?><tr>
    <td class="text-nowrap"><?= e(fdate($l['date_ecriture'], false)) ?></td><td><?= e($l['journal_code']) ?></td>
    <td class="text-nowrap"><?php $no = $l['piece_no'] ? str_pad((string) $l['piece_no'], 5, '0', STR_PAD_LEFT) : 'Br.'; echo $print ? e($no) : '<a href="' . e(url('/compta/ecritures/' . $l['ecriture_id'])) . '">' . e($no) . '</a>'; ?></td>
    <td><?= e((string) $l['piece']) ?></td><td><?= e((string) $l['tiers_code']) ?></td><td><?= e($l['libelle']) ?></td><td class="cpt-lettre"><?= e((string) $l['lettrage']) ?></td>
    <td class="num"><?= Compta::num((float) $l['debit']) ?></td><td class="num"><?= Compta::num((float) $l['credit']) ?></td><td class="num"><?= Compta::num((float) $l['solde']) ?: '0' ?></td></tr><?php endforeach; ?>
  <tr class="cpt-sub"><td colspan="7">Total <?= e($a['numero']) ?> — solde <?= $a['solde'] > 0.004 ? 'débiteur' : ($a['solde'] < -0.004 ? 'créditeur' : 'nul') ?></td><td class="num"><?= Compta::num($a['debit']) ?></td><td class="num"><?= Compta::num($a['credit']) ?></td><td class="num"><?= Compta::num($a['solde']) ?: '0' ?></td></tr>
<?php endforeach; ?>
<?php if (!$gl['comptes']): ?><tr><td colspan="10" class="text-center text-secondary py-4">Aucun mouvement.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/_report_end.php'; ?>
