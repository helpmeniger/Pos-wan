<?php $colors = ['open' => 'warning text-dark', 'paid' => 'success', 'cancelled' => 'secondary']; $labels = ['open' => 'À payer', 'paid' => 'Payées', 'cancelled' => 'Annulées']; ?>
<div class="d-flex flex-wrap gap-1 mb-2">
  <?php foreach ($labels as $k => $l): ?><a class="btn btn-sm <?= $status === $k ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e(url('/clinique/factures', ['status' => $k])) ?>"><?= e($l) ?></a><?php endforeach; ?>
  <a class="btn btn-sm <?= !isset($labels[$status]) ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e(url('/clinique/factures', ['status' => 'all'])) ?>">Toutes</a>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>N°</th><th>Date</th><th>Patient</th><th class="num">Total</th><th class="num">Prise en charge</th><th class="num">Part patient</th><th class="num">Reste</th><th>Statut</th></tr></thead><tbody>
<?php foreach ($p['rows'] as $b): $rest = $b['status'] === 'open' ? (float) $b['patient_amount'] - (float) $b['paid'] : 0; ?>
  <tr>
    <td><a class="fw-semibold" href="<?= e(url('/clinique/factures/' . $b['id'])) ?>"><?= e($b['number']) ?></a></td>
    <td class="small"><?= e(fdate($b['created_at'])) ?></td>
    <td><a href="<?= e(url('/clinique/patients/' . $b['patient_id'])) ?>"><?= e($b['last_name'] . ' ' . $b['first_name']) ?></a><br><small class="text-secondary"><?= e($b['pnum']) ?></small></td>
    <td class="num"><?= money($b['subtotal']) ?></td>
    <td class="num small"><?= (float) $b['coverage_amount'] > 0 ? money($b['coverage_amount']) . '<br><span class="text-secondary">' . e($b['insurer'] ?? '') . '</span>' : '—' ?></td>
    <td class="num"><?= money($b['patient_amount']) ?></td>
    <td class="num <?= $rest > 0 ? 'text-danger fw-semibold' : '' ?>"><?= $rest > 0 ? money($rest) : '—' ?></td>
    <td><span class="badge bg-<?= e($colors[$b['status']]) ?>"><?= e(['open' => 'À payer', 'paid' => 'Payée', 'cancelled' => 'Annulée'][$b['status']]) ?></span></td>
  </tr>
<?php endforeach; ?>
<?php if (!$p['rows']): ?><tr><td colspan="8" class="text-center text-secondary py-4">Aucune facture.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
