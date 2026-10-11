<div class="d-flex flex-wrap gap-1 mb-2 align-items-center">
  <?php foreach (['todo' => 'À délivrer', 'dispensed' => 'Délivrées', 'cancelled' => 'Annulées'] as $k => $l): ?><a class="btn btn-sm <?= $status === $k || ($k === 'todo' && !in_array($status, ['dispensed', 'cancelled'], true)) ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e(url('/clinique/pharmacie', ['status' => $k])) ?>"><?= e($l) ?></a><?php endforeach; ?>
  <span class="ms-auto small text-secondary">Les ordonnances rédigées par les médecins de la clinique arrivent ici automatiquement.</span>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Ordonnance</th><th>Patient</th><th>Prescripteur</th><th class="num">Médicaments</th><th>Statut</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
  <tr>
    <td><a class="fw-semibold" href="<?= e(url('/clinique/pharmacie/' . $r['id'])) ?>"><?= e($r['number']) ?></a><br><small class="text-secondary"><?= e(fdate($r['created_at'])) ?></small><?= $r['stay_id'] ? ' <span class="badge bg-danger">Hospitalisé</span>' : '' ?></td>
    <td><?= e($r['last_name'] . ' ' . $r['first_name']) ?><br><small class="text-secondary"><?= e($r['pnum']) ?></small></td>
    <td class="small">Dr <?= e($r['prescriber']) ?></td>
    <td class="num"><?= (int) $r['n'] ?><?= in_array($r['status'], ['new', 'partial'], true) ? '<br><small class="text-secondary">reste ' . (int) $r['left_qty'] . ' unité(s)</small>' : '' ?></td>
    <td><?= App\Core\View::partial('clinique::_presc_badge', ['st' => $r['status']]) ?></td>
    <td class="text-end"><a class="btn btn-sm <?= in_array($r['status'], ['new', 'partial'], true) ? 'btn-success' : 'btn-outline-primary' ?>" href="<?= e(url('/clinique/pharmacie/' . $r['id'])) ?>"><i class="bi bi-capsule"></i> <?= in_array($r['status'], ['new', 'partial'], true) ? 'Délivrer' : 'Voir' ?></a></td>
  </tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="text-center text-secondary py-4">Aucune ordonnance.</td></tr><?php endif; ?>
</tbody></table></div></div>
