<?php
use Addons\Comptabilite\Compta;
$period = 'Arrêtée au ' . fdate($to, false); $showFrom = false;
ob_start(); ?>
<div class="col-6 col-md-2"><label class="form-label mb-0">Tiers</label><select name="type" class="form-select form-select-sm"><option value="">Tous</option><?php foreach (Compta::TIERS_TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($l) ?>s</option><?php endforeach; ?></select></div>
<?php $extra = ob_get_clean(); require __DIR__ . '/_report_top.php';
$sd = $sc = 0.0; ?>
<div class="<?= $print ? '' : 'card' ?>"><div class="table-responsive"><table class="table table-sm mb-0 small cpt-table">
<thead><tr><th>Code</th><th>Tiers</th><th>Type</th><th>Compte</th><th class="num">Débit</th><th class="num">Crédit</th><th class="num">Solde débiteur</th><th class="num">Solde créditeur</th><th class="num">Non lettré</th><th>Plus ancienne échéance</th></tr></thead><tbody>
<?php foreach ($rows as $r): $s = (float) $r['debit'] - (float) $r['credit']; $sd += max(0, $s); $sc += max(0, -$s); ?>
  <tr><td class="cpt-mono"><?= $print ? e($r['code']) : '<a href="' . e(url('/compta/lettrage', ['compte' => $r['compte'], 'tiers' => $r['code'], 'exercice' => $ex['id'], 'mode' => 'non_lettrees'])) . '">' . e($r['code']) . '</a>' ?></td>
    <td><?= e($r['nom']) ?><?= $r['telephone'] && !$print ? ' <span class="text-secondary">' . e($r['telephone']) . '</span>' : '' ?></td><td><?= e(Compta::TIERS_TYPES[$r['type']] ?? $r['type']) ?></td><td class="cpt-mono"><?= e($r['compte']) ?></td>
    <td class="num"><?= Compta::num((float) $r['debit']) ?></td><td class="num"><?= Compta::num((float) $r['credit']) ?></td><td class="num"><?= Compta::num(max(0, $s)) ?></td><td class="num"><?= Compta::num(max(0, -$s)) ?></td>
    <td class="num"><?= Compta::num((float) $r['non_lettre']) ?></td><td><?= $r['plus_ancien'] && abs((float) $r['non_lettre']) >= 0.01 ? e(fdate($r['plus_ancien'], false)) : '' ?></td></tr>
<?php endforeach; ?>
<?php if ($rows): ?><tr class="cpt-total"><td colspan="6">TOTAL</td><td class="num"><?= Compta::num($sd) ?></td><td class="num"><?= Compta::num($sc) ?></td><td colspan="2"></td></tr>
<?php else: ?><tr><td colspan="10" class="text-center text-secondary py-4">Aucun compte de tiers mouvementé.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/_report_end.php'; ?>
