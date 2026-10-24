<form class="d-flex gap-2 mb-3" method="get"><input name="q" value="<?= e($q) ?>" class="form-control" placeholder="Nom, téléphone, n° de pièce, société" autofocus><button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button></form>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Client</th><th>Téléphone</th><th>Nationalité</th><th class="num">Séjours</th><th class="num">Nuits</th><th class="num">Dépensé</th><th>Dernier séjour</th></tr></thead><tbody>
<?php foreach ($p['rows'] as $g): ?>
  <tr><td><a class="fw-semibold" href="<?= e(url('/hotel/clients/' . $g['id'])) ?>"><?= e(trim($g['last_name'] . ' ' . $g['first_name'])) ?></a><?= (int) $g['vip'] ? ' <i class="bi bi-star-fill text-warning" title="VIP"></i>' : '' ?><?= (int) $g['blacklisted'] ? ' <span class="badge bg-danger">refusé</span>' : '' ?><?= $g['company'] ? '<br><small class="text-secondary">' . e($g['company']) . '</small>' : '' ?></td>
    <td><?= e((string) $g['phone']) ?></td><td><?= e((string) $g['nationality']) ?></td><td class="num"><?= (int) $g['stays'] ?></td><td class="num"><?= (int) $g['nights'] ?></td><td class="num"><?= money($g['spent']) ?></td><td><?= $g['last_stay'] ? e(fdate($g['last_stay'], false)) : '—' ?></td></tr>
<?php endforeach; ?>
<?php if (!$p['rows']): ?><tr><td colspan="7" class="text-center text-secondary py-4">Aucun client.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
