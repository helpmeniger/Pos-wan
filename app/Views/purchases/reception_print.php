<div class="sheet">
  <?= App\Core\View::partial('partials/doc_head', ['docTitle' => 'BON DE RÉCEPTION', 'docNumber' => $r['number'], 'docDate' => $r['created_at']]) ?>
  <div style="display:flex;gap:20px;margin-top:12px"><div class="box" style="flex:1"><b>Fournisseur</b><br><?= e($r['supplier']) ?><br><?= e($r['supplier_phone'] ?? '') ?></div><div class="box" style="flex:1">Entrepôt : <?= e($r['warehouse']) ?><br>Commande : <?= e($r['po_number'] ?? '—') ?><br>Facture fournisseur : <?= e($r['supplier_invoice_ref'] ?? '—') ?><br>Reçu par : <?= e($r['full_name']) ?></div></div>
  <table class="lines"><thead><tr><th>Référence</th><th>Article</th><th>Lot</th><th>Péremption</th><th class="num">Qté</th><th class="num">P.U.</th><th class="num">Coût de revient</th><th class="num">Total</th></tr></thead><tbody>
  <?php foreach ($items as $i): ?><tr><td><?= e($i['reference']) ?></td><td><?= e($i['name']) ?></td><td><?= e($i['lot_number'] ?? '') ?></td><td><?= e(fdate($i['expiry_date'], false)) ?></td><td class="num"><?= qty($i['quantity']) ?></td><td class="num"><?= money($i['unit_cost']) ?></td><td class="num"><?= money($i['landed_unit_cost']) ?></td><td class="num"><?= money((float) $i['quantity'] * (float) $i['unit_cost']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <table class="totals"><tr><td>Marchandises</td><td class="num"><?= money($r['goods_total']) ?></td></tr><tr><td>Frais d'approche</td><td class="num"><?= money($r['extra_costs']) ?></td></tr><tr class="grand"><td>TOTAL</td><td class="num"><?= money($r['total']) ?></td></tr><?php if ($r['due_date']): ?><tr><td>Échéance</td><td class="num"><?= e(fdate($r['due_date'], false)) ?></td></tr><?php endif; ?></table>
  <?php if ($r['note']): ?><p>Note : <?= e($r['note']) ?></p><?php endif; ?>
  <div class="sign"><div>Le magasinier</div><div>Le livreur</div></div>
</div>
