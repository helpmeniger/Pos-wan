<div class="d-flex justify-content-between align-items-center mb-3">
  <p class="text-muted mb-0">Webcams USB, caméras IP (RTSP/HTTP) et enregistreurs compatibles.</p>
  <?php if (can('cameras.manage')): ?><a href="<?= url('cameras/create') ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Ajouter une caméra</a><?php endif; ?>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
<thead><tr><th>Nom</th><th>Type</th><th>Emplacement</th><th>Fonction</th><th>Lignes</th><th>Analyse</th><th>Statut</th><th>Dernier signal</th></tr></thead>
<tbody>
<?php foreach ($cameras as $c): ?>
<tr>
  <td><a href="<?= url('cameras/' . (int) $c['id']) ?>" class="fw-semibold"><?= e($c['name']) ?></a></td>
  <td><?= e(label('camera_type', $c['type'])) ?></td>
  <td><?= e($c['location']) ?></td>
  <td><?= e(label('camera_role', $c['role'])) ?></td>
  <td><?= (int) $c['lines_count'] ?: '<span class="text-warning">aucune</span>' ?></td>
  <td><?= $c['analysis_enabled'] ? '<span class="badge bg-success">active</span>' : '<span class="badge bg-secondary">arrêtée</span>' ?></td>
  <td><span class="badge <?= $c['status'] === 'en_ligne' ? 'bg-success' : ($c['status'] === 'inconnu' ? 'bg-secondary' : 'bg-danger') ?>"><?= e(label('camera_status', $c['status'])) ?></span></td>
  <td class="small"><?= dt($c['last_seen_at']) ?></td>
</tr>
<?php endforeach; if (!$cameras): ?><tr><td colspan="8" class="text-center text-muted py-4">Aucune caméra. Commencez par une webcam USB branchée sur l'ordinateur qui exécute le service IA.</td></tr><?php endif; ?>
</tbody></table></div></div>
