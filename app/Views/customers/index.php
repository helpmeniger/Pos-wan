<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <form class="d-flex gap-2" method="get"><input name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Nom ou téléphone"><div class="form-check ms-1 mt-1"><input type="checkbox" name="debt" value="1" id="dt" class="form-check-input" <?= ($_GET['debt'] ?? '') === '1' ? 'checked' : '' ?>><label for="dt" class="form-check-label small">Avec dette</label></div><button class="btn btn-sm btn-outline-primary">Rechercher</button></form>
  <span class="ms-3">Total des créances : <b class="text-danger"><?= money($total) ?></b></span>
  <?php if (can('customers.manage')): ?><a href="<?= e(url('/customers/create')) ?>" class="btn btn-primary ms-auto"><i class="bi bi-person-plus"></i> Nouveau client</a><?php endif; ?>
</div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Nom</th><th>Téléphone</th><th>Dernier achat</th><?php if (feature('loyalty')): ?><th class="num">Points</th><?php endif; ?><th class="num">Plafond</th><th class="num">Solde dû</th></tr></thead><tbody>
<?php foreach ($p['rows'] as $c): ?><tr><td><a class="fw-semibold" href="<?= e(url('/customers/' . $c['id'])) ?>"><?= e($c['name']) ?></a></td><td><?= e($c['phone'] ?? '') ?></td><td><?= e(fdate($c['last_sale'], false)) ?></td><?php if (feature('loyalty')): ?><td class="num"><?= (int) $c['loyalty_points'] ?></td><?php endif; ?><td class="num"><?= money($c['credit_limit']) ?></td>
<td class="num <?= (float) $c['balance'] > 0 ? 'text-danger fw-bold' : ((float) $c['balance'] < 0 ? 'text-success' : '') ?>"><?= money($c['balance']) ?><?= (float) $c['balance'] < 0 ? ' <small>(avoir)</small>' : '' ?></td></tr><?php endforeach; ?>
<?php if (!$p['rows']): ?><tr><td colspan="6" class="text-center text-secondary py-4">Aucun client.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
