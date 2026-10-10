<div class="mb-3"><?php if (can('purchases.manage')): ?><a href="<?= e(url('/supplier-returns/create')) ?>" class="btn btn-primary"><i class="bi bi-plus"></i> Nouveau retour</a><?php endif; ?></div>
<div class="card"><table class="table table-sm mb-0"><thead><tr><th>N°</th><th>Date</th><th>Fournisseur</th><th>Motif</th><th>Par</th><th class="num">Montant</th></tr></thead><tbody>
<?php foreach ($p['rows'] as $r): ?><tr><td><?= e($r['number']) ?></td><td><?= e(fdate($r['created_at'])) ?></td><td><?= e($r['supplier']) ?></td><td><?= e($r['reason']) ?></td><td><?= e($r['full_name']) ?></td><td class="num"><?= money($r['total']) ?></td></tr><?php endforeach; ?>
<?php if (!$p['rows']): ?><tr><td colspan="6" class="text-center text-secondary py-4">Aucun retour.</td></tr><?php endif; ?>
</tbody></table></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
