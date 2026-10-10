<div class="ticket">
  <div class="c"><div class="big"><?= e(setting('company_name')) ?></div><div><?= e(setting('company_address')) ?></div><?php if (setting('company_phone')): ?><div>Tél : <?= e(setting('company_phone')) ?></div><?php endif; ?></div>
  <hr><div class="c b">REÇU DE PAIEMENT</div><hr>
  <div>N° : RP-<?= (int) $t['id'] ?></div><div>Date : <?= e(fdate($t['created_at'])) ?></div><div>Client : <b><?= e($t['name']) ?></b> <?= e($t['phone'] ?? '') ?></div>
  <hr><table><tr><td class="big"><?= $t['type'] === 'deposit' ? 'ACOMPTE' : 'MONTANT REÇU' ?></td><td class="num big"><?= money(-(float) $t['amount']) ?></td></tr><tr><td>Mode</td><td class="num"><?= e(method_label($t['method'])) ?></td></tr><tr><td class="b">Nouveau solde dû</td><td class="num b"><?= money($t['balance_after']) ?></td></tr></table>
  <?php if ($t['note']): ?><div>Note : <?= e($t['note']) ?></div><?php endif; ?>
  <div>Reçu par : <?= e($t['full_name'] ?? '') ?></div>
  <hr><div class="c"><?= e(ucfirst(amount_words(-(float) $t['amount']))) ?> francs CFA</div><br><div>Signature :</div><br><br>
</div>
