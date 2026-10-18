<?php $typeLabels = ['sale' => 'Ventes', 'sale_cancel' => 'Annulations', 'refund' => 'Remboursements', 'cash_in' => 'Apports', 'cash_out' => 'Retraits', 'expense' => 'Dépenses', 'income' => 'Recettes diverses', 'customer_payment' => 'Règlements clients', 'supplier_payment' => 'Paiements fournisseurs', 'expense_reversal' => 'Contrepassation dépense', 'income_reversal' => 'Contrepassation recette', 'clinique' => 'Prestations clinique', 'pressing' => 'Pressing', 'pressing_refund' => 'Remboursements pressing', 'ecole' => 'Frais de scolarité', 'ecole_annulation' => 'Reçus de scolarité annulés', 'ecole_salaire' => 'Salaires du personnel', 'ecole_salaire_annule' => 'Salaires annulés']; ?>
<div class="sheet">
  <?= App\Core\View::partial('partials/doc_head', ['docTitle' => 'ÉTAT DE CAISSE', 'docNumber' => '#' . $s['id'], 'docDate' => $s['closed_at'] ?? date('Y-m-d H:i:s')]) ?>
  <p><b><?= e($s['store']) ?> / <?= e($s['register']) ?></b> — ouverte le <?= e(fdate($s['opened_at'])) ?> par <?= e($s['opener']) ?><?= $s['closed_at'] ? ', clôturée le ' . e(fdate($s['closed_at'])) . ' par ' . e($s['closer']) : ' (session en cours)' ?></p>
  <table class="lines"><thead><tr><th>Opération</th><th>Mode</th><th class="num">Nombre</th><th class="num">Montant</th></tr></thead><tbody>
    <?php foreach ($summary['by_type'] as $t): ?><tr><td><?= e($typeLabels[$t['type']] ?? $t['type']) ?></td><td><?= e(method_label($t['method'])) ?></td><td class="num"><?= (int) $t['n'] ?></td><td class="num"><?= money($t['total']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <table class="totals">
    <tr><td>Ventes (<?= (int) $summary['sales']['n'] ?> tickets)</td><td class="num"><?= money($summary['sales']['total']) ?></td></tr>
    <tr><td>dont à crédit</td><td class="num"><?= money($summary['sales']['credit']) ?></td></tr>
    <tr><td>Remises accordées</td><td class="num"><?= money($summary['sales']['discounts']) ?></td></tr>
    <tr><td>Ventes annulées</td><td class="num"><?= (int) $summary['cancelled'] ?></td></tr>
    <tr><td>Fond de caisse</td><td class="num"><?= money($s['opening_amount']) ?></td></tr>
    <tr class="grand"><td>Espèces théoriques</td><td class="num"><?= money($s['expected_amount'] ?? $summary['expected_cash']) ?></td></tr>
    <?php if ($s['counted_amount'] !== null): ?><tr><td>Espèces comptées</td><td class="num"><?= money($s['counted_amount']) ?></td></tr><tr class="grand"><td>ÉCART</td><td class="num"><?= money($s['difference']) ?></td></tr><?php endif; ?>
  </table>
  <?php if ($s['closing_note']): ?><p>Commentaire : <i><?= e($s['closing_note']) ?></i></p><?php endif; ?>
  <?php if ($movements): ?><h3 style="font-size:14px;margin-top:16px">Détail des mouvements hors ventes</h3><table class="lines"><tbody><?php foreach ($movements as $m): ?><tr><td><?= e(fdate($m['created_at'])) ?></td><td><?= e($typeLabels[$m['type']] ?? $m['type']) ?></td><td><?= e(method_label($m['method'])) ?></td><td><?= e($m['reason']) ?></td><td><?= e($m['full_name']) ?></td><td class="num"><?= money($m['amount']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  <div class="sign"><div>Le caissier</div><div>Le responsable</div></div>
</div>
