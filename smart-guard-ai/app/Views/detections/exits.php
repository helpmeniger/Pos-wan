<?php
$opt = static fn ($name, $vals, $grp) => '<div class="col-6 col-md-2"><label class="form-label small mb-0">' . ($name === 'direction' ? 'Sens' : 'Rapprochement') . '</label><select name="' . $name . '" class="form-select form-select-sm"><option value="">Tous</option>'
  . implode('', array_map(static fn ($v) => '<option value="' . $v . '" ' . (($_GET[$name] ?? '') === $v ? 'selected' : '') . '>' . e(label($grp, $v)) . '</option>', $vals)) . '</select></div>';
$extra = $opt('direction', ['sortie', 'entree', 'ambigu', 'perte_suivi'], 'direction') . $opt('recon', ['vente_trouvee', 'aucune_vente', 'incertain', 'non_applicable'], 'recon');
require __DIR__ . '/../partials/filters.php';
require __DIR__ . '/../partials/disclaimer.php';
?>
<div class="d-flex gap-3 mb-2 small"><span><strong><?= (int) ($counts['s'] ?? 0) ?></strong> sortie(s) observée(s)</span><span><strong><?= (int) ($counts['en'] ?? 0) ?></strong> entrée(s)</span><span><strong><?= (int) ($counts['u'] ?? 0) ?></strong> passage(s) incertain(s)</span></div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
<thead><tr><th>Heure</th><th>Caméra / ligne</th><th>Sens</th><th>Produit</th><th>Confiance</th><th>Suivi</th><th>Rapprochement caisse</th><th>Alerte</th><th>Image</th></tr></thead><tbody>
<?php foreach ($rows as $e): ?><tr>
  <td class="small"><?= dt($e['occurred_at']) ?></td><td><?= e($e['camera']) ?><div class="small text-muted"><?= e($e['line']) ?></div></td>
  <td><span class="badge <?= $e['direction'] === 'sortie' ? 'bg-warning text-dark' : ($e['direction'] === 'entree' ? 'bg-info text-dark' : 'bg-secondary') ?>"><?= e(label('direction', $e['direction'])) ?></span></td>
  <td><?= $e['product'] ? e($e['product']) : '<span class="text-warning">Non identifié</span>' ?></td><td><?= pct($e['confidence']) ?></td><td>n° <?= (int) $e['track_number'] ?></td>
  <td class="small"><?= e(label('recon', $e['reconciliation_status'])) ?><div class="text-muted"><?= e($e['reconciliation_note']) ?></div></td>
  <td><?= $e['alert_id'] ? '<a href="' . url('alerts/' . (int) $e['alert_id']) . '">' . level_badge($e['level']) . '</a>' : '—' ?></td>
  <td><?php if ($e['snapshot_id'] && can('video.view')): ?><a href="<?= url('media/record/' . (int) $e['snapshot_id']) ?>" target="_blank"><i class="bi bi-image"></i></a><?php endif; ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="9" class="text-muted text-center py-3">Aucun franchissement sur la période.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/../partials/pagination.php'; ?>
