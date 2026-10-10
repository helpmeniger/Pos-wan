<div class="sheet">
  <?= App\Core\View::partial('partials/doc_head', ['docTitle' => $s['status'] === 'cancelled' ? 'FACTURE ANNULÉE' : 'FACTURE', 'docNumber' => $s['number'], 'docDate' => $s['created_at']]) ?>
  <div style="display:flex;gap:20px;margin-top:12px">
    <div class="box" style="flex:1"><b>Client</b><br><?= e($s['customer_name'] ?? 'Client comptoir') ?><br><?= e($s['customer_phone'] ?? '') ?><br><?= e($s['customer_address'] ?? '') ?><?= $s['customer_nif'] ? '<br>NIF : ' . e($s['customer_nif']) : '' ?></div>
    <div class="box" style="flex:1"><b>Vente</b><br>Boutique : <?= e($s['store_name']) ?><br>Vendeur : <?= e($s['cashier']) ?><br>Caisse : <?= e($s['register_name'] ?? '') ?></div>
  </div>
  <table class="lines">
    <thead><tr><th>Réf.</th><th>Désignation</th><th class="num">Qté</th><th class="num">P.U. TTC</th><th class="num">Remise</th><th class="num">Total TTC</th></tr></thead>
    <tbody>
    <?php foreach ($items as $i): ?><tr><td><?= e($i['reference']) ?></td><td><?= e($i['product_name']) ?></td><td class="num"><?= qty($i['quantity']) ?></td><td class="num"><?= money($i['unit_price']) ?></td><td class="num"><?= (float) $i['discount'] > 0 ? money($i['discount']) : '' ?></td><td class="num"><?= money($i['line_total']) ?></td></tr><?php endforeach; ?>
    </tbody>
  </table>
  <table class="totals">
    <tr><td>Sous-total</td><td class="num"><?= money($s['subtotal']) ?></td></tr>
    <?php if ((float) $s['discount_total'] > 0): ?><tr><td>Remises</td><td class="num">− <?= money($s['discount_total']) ?></td></tr><?php endif; ?>
    <?php if ((float) $s['tax_total'] > 0): ?><tr><td>Total HT</td><td class="num"><?= money((float) $s['total'] - (float) $s['tax_total']) ?></td></tr><tr><td>Taxes (incluses)</td><td class="num"><?= money($s['tax_total']) ?></td></tr><?php endif; ?>
    <tr class="grand"><td>TOTAL TTC</td><td class="num"><?= money($s['total']) ?></td></tr>
    <?php foreach ($payments as $pm): ?><tr><td><?= e(method_label($pm['method'])) ?></td><td class="num"><?= money($pm['amount']) ?></td></tr><?php endforeach; ?>
  </table>
  <p style="margin-top:14px">Arrêtée la présente facture à la somme de : <b><?= e(ucfirst(amount_words((float) $s['total']))) ?> francs CFA</b>.</p>
  <div class="sign"><div>Le client</div><div>Pour <?= e(setting('company_name')) ?></div></div>
  <div class="foot"><?= e(setting('invoice_footer', setting('ticket_footer', ''))) ?></div>
</div>
