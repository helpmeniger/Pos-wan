<div class="sheet">
  <?= App\Core\View::partial('partials/doc_head', ['docTitle' => 'FACTURE', 'docNumber' => $b['number'], 'docDate' => $b['created_at']]) ?>
  <div class="box" style="margin-top:12px;width:55%"><b>Patient</b><br><?= e($b['last_name'] . ' ' . $b['first_name']) ?><br>Dossier <?= e($b['pnum']) ?><?= $b['phone'] ? '<br>' . e($b['phone']) : '' ?></div>
  <?php if ($b['status'] === 'cancelled'): ?><p style="font-size:20px;font-weight:bold;color:#c00">ANNULÉE — <?= e($b['cancel_reason']) ?></p><?php endif; ?>
  <table class="lines" style="margin-top:12px"><thead><tr><th>Acte / prestation</th><th class="num">Qté</th><th class="num">P.U.</th><th class="num">Total</th></tr></thead><tbody>
  <?php foreach ($items as $i): ?><tr><td><?= e($i['label']) ?></td><td class="num"><?= e(qty($i['quantity'])) ?></td><td class="num"><?= money($i['unit_price']) ?></td><td class="num"><?= money($i['total']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <table class="totals">
    <tr><td>Total</td><td class="num"><?= money($b['subtotal']) ?></td></tr>
    <?php if ((float) $b['coverage_amount'] > 0): ?><tr><td>Prise en charge <?= e($b['insurer'] ?? '') ?> (<?= e((string) (float) $b['coverage_pct']) ?> %)</td><td class="num">− <?= money($b['coverage_amount']) ?></td></tr><?php endif; ?>
    <tr class="grand"><td>NET À PAYER (patient)</td><td class="num"><?= money($b['patient_amount']) ?></td></tr>
    <tr><td>Payé</td><td class="num"><?= money($b['paid']) ?></td></tr>
    <tr><td>Reste</td><td class="num"><?= money($b['status'] === 'open' ? max(0, (float) $b['patient_amount'] - (float) $b['paid']) : 0) ?></td></tr>
  </table>
  <?php if ($payments): ?><p>Règlements : <?= e(implode(' · ', array_map(fn($pm) => method_label($pm['method']) . ($pm['provider'] ? ' ' . $pm['provider'] : '') . ' ' . money($pm['amount']) . ' (' . fdate($pm['created_at']) . ')', $payments))) ?></p><?php endif; ?>
  <div class="sign"><div>La caisse</div><div>Le patient</div></div>
</div>
