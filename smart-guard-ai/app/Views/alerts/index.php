<?php
$extra = '<div class="col-6 col-md-2"><label class="form-label small mb-0">Niveau</label><select name="level" class="form-select form-select-sm"><option value="">À vérifier + prioritaire</option>'
  . implode('', array_map(static fn ($v) => '<option value="' . $v . '" ' . ($level === $v ? 'selected' : '') . '>' . e(label('level', $v)) . '</option>', ['information', 'a_verifier', 'prioritaire'])) . '</select></div>'
  . '<div class="col-6 col-md-2"><label class="form-label small mb-0">Statut</label><select name="status" class="form-select form-select-sm"><option value="ouvertes">Ouvertes</option><option value="tous" ' . ($status === 'tous' ? 'selected' : '') . '>Tous</option>'
  . implode('', array_map(static fn ($v) => '<option value="' . $v . '" ' . ($status === $v ? 'selected' : '') . '>' . e(label('alert_status', $v)) . '</option>', App\Services\AlertService::STATUSES)) . '</select></div>';
require __DIR__ . '/../partials/filters.php';
?>
<div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i>Une alerte, même prioritaire, <strong>ne constitue pas une preuve de vol</strong>. Elle signale un événement à vérifier (images, extrait, caisse). Aucune personne n'est identifiée par le système.</div>
<p class="small text-muted"><?= (int) $total ?> alerte(s)</p>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
<thead><tr><th>Niveau</th><th>Date</th><th>Événement</th><th>Caméra</th><th>Produit</th><th>Confiance</th><th>Statut</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $a): ?><tr>
  <td><?= level_badge($a['level']) ?></td><td class="small"><?= dt($a['occurred_at']) ?></td>
  <td><a href="<?= url('alerts/' . (int) $a['id']) ?>"><?= e($a['title']) ?></a><div class="small text-muted"><?= e(label('alert_type', $a['alert_type'])) ?></div></td>
  <td><?= e($a['camera']) ?></td><td><?= e($a['product'] ?? '—') ?></td><td><?= pct($a['confidence']) ?></td><td><?= status_badge($a['status']) ?></td>
  <td><?php if ($a['snapshot_id'] && can('video.view')): ?><img src="<?= url('media/record/' . (int) $a['snapshot_id']) ?>" alt="" style="height:48px;border-radius:4px" loading="lazy"><?php endif; ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">Aucune alerte.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/../partials/pagination.php'; ?>
