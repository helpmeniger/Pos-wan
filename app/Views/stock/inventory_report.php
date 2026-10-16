<?php $cost = can('products.cost_view'); ?>
<div class="sheet">
  <?= App\Core\View::partial('partials/doc_head', ['docTitle' => 'RAPPORT D\'INVENTAIRE', 'docNumber' => $inv['number'], 'docDate' => $inv['validated_at'] ?? $inv['created_at']]) ?>
  <div class="box" style="margin-top:10px">
    Entrepôt : <b><?= e($inv['warehouse']) ?></b><?= $inv['category'] ? ' · Catégorie : ' . e($inv['category']) : '' ?> · Statut : <b><?= e(['draft' => 'en cours (non validé)', 'validated' => 'validé', 'cancelled' => 'annulé'][$inv['status']]) ?></b><br>
    Lancé par <?= e($inv['full_name']) ?> le <?= e(fdate($inv['created_at'])) ?><?= $inv['validator'] ? ' · validé par ' . e($inv['validator']) . ' le ' . e(fdate($inv['validated_at'])) : '' ?><br>
    Articles : <?= (int) $sum['items'] ?> · comptés <?= (int) $sum['counted'] ?> · conformes <?= (int) $sum['conform'] ?> · excédents <?= (int) $sum['surplus'] ?> (+<?= e(qty($sum['surplus_qty'])) ?>) · manquants <?= (int) $sum['missing'] ?> (−<?= e(qty($sum['missing_qty'])) ?>) · non comptés <?= (int) $sum['uncounted'] ?>
    <?php if ($cost): ?><br>Écart valorisé au coût : +<?= money($sum['surplus_value']) ?> / −<?= money($sum['missing_value']) ?> (net <?= money($sum['surplus_value'] - $sum['missing_value']) ?>)<?php endif; ?>
  </div>
  <p class="no-print" style="margin:8px 0">Afficher : <?php foreach (['diff' => 'écarts', 'missing' => 'manquants', 'surplus' => 'excédents', 'uncounted' => 'non comptés', 'all' => 'tout'] as $k => $l): ?><a href="<?= e(url('/inventories/' . $inv['id'] . '/report', ['f' => $k])) ?>" style="<?= $filter === $k ? 'font-weight:bold' : '' ?>"><?= e($l) ?></a> · <?php endforeach; ?></p>
  <table class="lines" style="margin-top:8px"><thead><tr><th>Article</th><th>Réf.</th><th class="num">Théorique</th><th class="num">Compté</th><th class="num">Écart</th><?php if ($cost): ?><th class="num">Valeur</th><?php endif; ?><th>Observation</th><th>Par</th></tr></thead><tbody>
  <?php foreach ($items as $i): $d = $i['counted_qty'] !== null ? (float) $i['counted_qty'] - (float) $i['expected_qty'] : null; ?>
    <tr><td><?= e($i['name']) ?></td><td><?= e($i['reference']) ?></td><td class="num"><?= qty($i['expected_qty']) ?></td><td class="num"><?= $i['counted_qty'] !== null ? qty($i['counted_qty']) : '—' ?></td>
      <td class="num"><b><?= $d === null ? '' : (($d > 0 ? '+' : '') . qty($d)) ?></b></td><?php if ($cost): ?><td class="num"><?= $d === null ? '' : money($d * (float) $i['cost_price']) ?></td><?php endif; ?><td><?= e($i['note'] ?? '') ?></td><td><?= e($i['counter'] ?? '') ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$items): ?><tr><td colspan="8">Aucun article.</td></tr><?php endif; ?>
  </tbody></table>
  <div class="sign"><div>Compté par</div><div>Vérifié et validé par</div></div>
</div>
