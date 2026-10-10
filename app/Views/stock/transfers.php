<div class="mb-3"><?php if (can('stock.transfer')): ?><a href="<?= e(url('/stock/transfers/create')) ?>" class="btn btn-primary"><i class="bi bi-plus"></i> Nouveau transfert</a><?php endif; ?></div>
<div class="card"><table class="table table-sm table-hover mb-0"><thead><tr><th>N°</th><th>Date</th><th>De</th><th>Vers</th><th class="num">Lignes</th><th>Par</th><th>Note</th></tr></thead><tbody>
<?php foreach ($p['rows'] as $t): ?><tr><td><a href="<?= e(url('/stock/transfers/' . $t['id'])) ?>"><?= e($t['number']) ?></a></td><td><?= e(fdate($t['created_at'])) ?></td><td><?= e($t['from_name']) ?></td><td><?= e($t['to_name']) ?></td><td class="num"><?= (int) $t['line_count'] ?></td><td><?= e($t['full_name']) ?></td><td><?= e($t['note'] ?? '') ?></td></tr><?php endforeach; ?>
<?php if (!$p['rows']): ?><tr><td colspan="7" class="text-center text-secondary py-4">Aucun transfert.</td></tr><?php endif; ?>
</tbody></table></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
