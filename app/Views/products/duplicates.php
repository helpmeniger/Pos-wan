<p class="text-secondary small">Articles actifs portant exactement le même nom (sans tenir compte des majuscules). Les références et codes-barres sont uniques par construction : la base refuse tout doublon.</p>
<div class="card"><table class="table table-sm mb-0"><thead><tr><th>Nom</th><th>Occurrences</th><th>Articles</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['k']) ?></td><td><?= (int) $r['n'] ?></td><td><?php foreach (explode(';;', $r['items']) as $it): [$id, $ref, $bc] = array_pad(explode('|', $it), 3, ''); ?><a class="me-2" href="<?= e(url('/products/' . $id)) ?>"><?= e($ref) ?></a><small class="text-secondary me-3"><?= e($bc) ?></small><?php endforeach; ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="3" class="text-success py-3 text-center"><i class="bi bi-check-circle"></i> Aucun doublon détecté.</td></tr><?php endif; ?>
</tbody></table></div>
