<div class="ticket">
  <div class="c"><div class="big"><?= e(setting('company_name')) ?></div><div><?= e(setting('company_address')) ?></div></div>
  <hr><div class="c b">REÇU DE REMBOURSEMENT</div><hr>
  <div>N° : <b><?= e($r['number']) ?></b></div><div>Date : <?= e(fdate($r['created_at'])) ?></div><div>Vente d'origine : <?= e($r['sale_number']) ?></div>
  <?php if ($r['customer']): ?><div>Client : <?= e($r['customer']) ?></div><?php endif; ?>
  <div>Opérateur : <?= e($r['full_name']) ?></div><hr>
  <table><?php foreach ($items as $i): ?><tr><td><?= e($i['product_name']) ?><br><?= qty($i['quantity']) ?> x <?= money($i['unit_price'], false) ?></td><td class="num"><?= money($i['amount'], false) ?></td></tr><?php endforeach; ?></table>
  <hr><table><tr><td class="big">REMBOURSÉ</td><td class="num big"><?= money($r['total']) ?></td></tr><tr><td>Mode</td><td class="num"><?= e(method_label($r['refund_method'])) ?></td></tr></table>
  <div>Motif : <?= e($r['reason']) ?></div><div>Remise en stock : <?= (int) $r['restock'] === 1 ? 'oui' : 'non' ?></div>
  <hr><div style="margin-top:18px">Signature client :</div><br><br>
</div>
