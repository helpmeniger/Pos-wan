<div class="sheet">
  <?= App\Core\View::partial('partials/doc_head', ['docTitle' => 'BON DE COMMANDE', 'docNumber' => $po['number'], 'docDate' => $po['created_at']]) ?>
  <div class="box" style="margin-top:12px;width:50%"><b>Fournisseur</b><br><?= e($po['supplier']) ?><br><?= e($po['supplier_phone'] ?? '') ?><br><?= e($po['supplier_address'] ?? '') ?></div>
  <p>Livraison : <?= e($po['warehouse']) ?><?= $po['expected_date'] ? ' — souhaitée le ' . e(fdate($po['expected_date'], false)) : '' ?></p>
  <table class="lines"><thead><tr><th>Référence</th><th>Désignation</th><th class="num">Quantité</th><th class="num">P.U.</th><th class="num">Total</th></tr></thead><tbody>
  <?php foreach ($items as $i): ?><tr><td><?= e($i['reference']) ?></td><td><?= e($i['name']) ?></td><td class="num"><?= qty($i['quantity']) ?></td><td class="num"><?= money($i['unit_cost']) ?></td><td class="num"><?= money((float) $i['quantity'] * (float) $i['unit_cost']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <table class="totals"><tr class="grand"><td>TOTAL</td><td class="num"><?= money($po['total']) ?></td></tr></table>
  <?php if ($po['note']): ?><p>Note : <?= e($po['note']) ?></p><?php endif; ?>
  <div class="sign"><div>Le responsable des achats</div><div>Le fournisseur</div></div>
</div>
