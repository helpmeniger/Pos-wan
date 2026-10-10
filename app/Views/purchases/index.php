<div class="d-flex flex-wrap gap-2 mb-3">
  <?php foreach (['' => 'Toutes', 'draft' => 'Brouillons', 'ordered' => 'Commandées', 'partial' => 'Partielles', 'received' => 'Reçues', 'cancelled' => 'Annulées'] as $k => $l): ?><a class="btn btn-sm <?= ($_GET['status'] ?? '') === $k ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e(url('/purchases', $k ? ['status' => $k] : [])) ?>"><?= e($l) ?></a><?php endforeach; ?>
  <?php if (can('purchases.manage')): ?><a href="<?= e(url('/purchases/create')) ?>" class="btn btn-primary ms-auto"><i class="bi bi-plus"></i> Nouvelle commande</a><a href="<?= e(url('/receptions/create')) ?>" class="btn btn-success"><i class="bi bi-box-arrow-in-down"></i> Réception directe</a><?php endif; ?>
</div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>N°</th><th>Date</th><th>Fournisseur</th><th>Entrepôt</th><th>Livraison prévue</th><th class="num">Montant</th><th>Statut</th></tr></thead><tbody>
<?php foreach ($p['rows'] as $o): ?><tr><td><a href="<?= e(url('/purchases/' . $o['id'])) ?>" class="fw-semibold"><?= e($o['number']) ?></a></td><td><?= e(fdate($o['created_at'], false)) ?></td><td><?= e($o['supplier']) ?></td><td><?= e($o['warehouse']) ?></td><td><?= e(fdate($o['expected_date'], false)) ?></td><td class="num"><?= money($o['total']) ?></td><td><?= App\Core\View::partial('partials/status', ['status' => $o['status']]) ?></td></tr><?php endforeach; ?>
<?php if (!$p['rows']): ?><tr><td colspan="7" class="text-center text-secondary py-4">Aucune commande.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
