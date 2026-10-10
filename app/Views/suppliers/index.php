<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <form class="d-flex gap-2" method="get"><input name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Nom ou téléphone"><button class="btn btn-sm btn-outline-primary">Rechercher</button></form>
  <span class="ms-3">Dettes fournisseurs : <b class="text-danger"><?= money($debt) ?></b></span>
  <?php if (can('suppliers.manage')): ?><a href="<?= e(url('/suppliers/create')) ?>" class="btn btn-primary ms-auto"><i class="bi bi-plus"></i> Nouveau fournisseur</a><?php endif; ?>
</div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Nom</th><th>Contact</th><th>Téléphone</th><th>NIF</th><th>Dernière réception</th><th class="num">Solde dû</th></tr></thead><tbody>
<?php foreach ($p['rows'] as $s): ?><tr><td><a href="<?= e(url('/suppliers/' . $s['id'])) ?>" class="fw-semibold"><?= e($s['name']) ?></a></td><td><?= e($s['contact_name'] ?? '') ?></td><td><?= e($s['phone'] ?? '') ?></td><td><?= e($s['nif'] ?? '') ?></td><td><?= e(fdate($s['last_reception'], false)) ?></td><td class="num <?= (float) $s['balance'] > 0 ? 'text-danger fw-bold' : '' ?>"><?= money($s['balance']) ?></td></tr><?php endforeach; ?>
<?php if (!$p['rows']): ?><tr><td colspan="6" class="text-center text-secondary py-4">Aucun fournisseur.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
