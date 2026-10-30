<?php /* Fichier des clients pressing (données personnelles : accès réservé au droit « Clients pressing »). */ ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <span class="text-secondary"><?= (int) $count ?> client(s) enregistré(s)</span>
  <form class="d-flex gap-1 ms-auto" method="get" style="min-width:300px"><input name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Nom, téléphone ou adresse"><button class="btn btn-sm btn-outline-secondary" aria-label="Rechercher"><i class="bi bi-search"></i></button></form>
</div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead><tr><th>Client</th><th>Téléphone</th><th>Adresse</th><th class="num">Dépôts</th><th class="num">Total</th><th class="num">Reste dû</th><th>Dernier dépôt</th><th></th></tr></thead><tbody>
<?php foreach ($p['rows'] as $c): $bad = strlen((string) preg_replace('/\D/', '', $c['phone'])) > 15; ?>
  <tr>
    <td class="fw-semibold"><?= e($c['name']) ?><?= $c['note'] ? '<br><small class="text-secondary">' . e($c['note']) . '</small>' : '' ?></td>
    <td><?= e($c['phone']) ?><?= $bad ? ' <span class="badge bg-warning text-dark" title="Plus de 15 chiffres : numéro probablement saisi deux fois">à corriger</span>' : '' ?></td>
    <td class="small"><?= e($c['address'] ?? '') ?></td>
    <td class="num"><?= (int) $c['orders'] ?></td>
    <td class="num"><?= money($c['total']) ?></td>
    <td class="num <?= (float) $c['due'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= money($c['due']) ?></td>
    <td class="small"><?= e(fdate($c['last_order_at'] ?? $c['created_at'])) ?></td>
    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/pressing/clients/' . $c['id'])) ?>"><i class="bi bi-pencil"></i> Modifier</a></td>
  </tr>
<?php endforeach; ?>
<?php if (!$p['rows']): ?><tr><td colspan="8" class="text-center text-secondary py-4"><?= $q === '' ? 'Aucun client : la liste se remplit à chaque nouveau dépôt.' : 'Aucun client ne correspond.' ?></td></tr><?php endif; ?>
</tbody></table></div></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
