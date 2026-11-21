<?php /* Totaux d'un ticket : lignes secondaires, puis le total principal sous le libellé choisi. */
$grand = null; $rest = [];
foreach ($doc['totals'] as $r) { if (!empty($r[2]) && $grand === null) { $grand = $r; } else { $rest[] = $r; } } ?>
<?php foreach ($rest as $r): ?><div class="m-row"><span><?= e($r[0]) ?></span><span><?= e($r[1]) ?></span></div><?php endforeach; ?>
<?php if ($grand): ?><div class="m-net"><span><?= e($o['libelle_total'] !== '' ? $o['libelle_total'] : $grand[0]) ?> :</span><span><?= e($grand[1]) ?></span></div><?php endif; ?>
<?php if ($o['montant_lettres'] === '1' && isset($doc['total_value'])): ?><div class="m-words"><?= e(Addons\Modeles\Modeles::words((float) $doc['total_value'])) ?></div><?php endif; ?>
