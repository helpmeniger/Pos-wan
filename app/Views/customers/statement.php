<?php $tl = ['credit_sale' => 'Vente à crédit', 'payment' => 'Règlement', 'deposit' => 'Acompte', 'refund' => 'Avoir', 'adjustment' => 'Ajustement']; ?>
<div class="sheet">
  <?= App\Core\View::partial('partials/doc_head', ['docTitle' => 'RELEVÉ DE COMPTE', 'docNumber' => 'CL-' . $c['id'], 'docDate' => date('Y-m-d H:i:s')]) ?>
  <div class="box" style="margin-top:12px;width:50%"><b><?= e($c['name']) ?></b><br><?= e($c['phone'] ?? '') ?><br><?= e($c['address'] ?? '') ?></div>
  <form method="get" class="no-print" style="margin:10px 0">Du <input type="date" name="from" value="<?= e($from) ?>"> au <input type="date" name="to" value="<?= e($to) ?>"> <button>Actualiser</button></form>
  <p>Période du <?= e(fdate($from, false)) ?> au <?= e(fdate($to, false)) ?> · Solde d'ouverture : <b><?= money($opening) ?></b></p>
  <table class="lines"><thead><tr><th>Date</th><th>Opération</th><th>Détail</th><th class="num">Débit</th><th class="num">Crédit</th><th class="num">Solde</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><?= e(fdate($r['created_at'])) ?></td><td><?= e($tl[$r['type']] ?? $r['type']) ?></td><td><?= e($r['note'] ?? '') ?></td><td class="num"><?= (float) $r['amount'] > 0 ? money($r['amount']) : '' ?></td><td class="num"><?= (float) $r['amount'] < 0 ? money(-(float) $r['amount']) : '' ?></td><td class="num"><?= money($r['balance_after']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <table class="totals"><tr class="grand"><td>Solde actuel dû</td><td class="num"><?= money($c['balance']) ?></td></tr></table>
</div>
