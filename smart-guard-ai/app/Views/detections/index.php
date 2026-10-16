<?php $extra = '<div class="col-6 col-md-2 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="unidentified" value="1" id="un" ' . (!empty($_GET['unidentified']) ? 'checked' : '') . '><label class="form-check-label small" for="un">Non identifiés seulement</label></div></div>'; require __DIR__ . '/../partials/filters.php'; ?>
<p class="small text-muted">Une ligne = un objet suivi par la caméra (et non chaque image). <?= (int) $total ?> détection(s).</p>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
<thead><tr><th>Heure</th><th>Caméra</th><th>Produit</th><th>Confiance</th><th>Suivi</th><th>Images</th><th>Fin de suivi</th><th>Rectangle (x, y, l, h)</th></tr></thead><tbody>
<?php foreach ($rows as $d): ?><tr>
  <td class="small"><?= dt($d['detected_at']) ?></td><td><?= e($d['camera']) ?></td>
  <td><?= $d['product'] ? e($d['product']) : '<span class="text-warning">Produit non identifié</span>' ?></td>
  <td><?= pct($d['confidence']) ?></td><td>Objet n° <?= (int) $d['track_number'] ?></td><td><?= (int) $d['frames_seen'] ?></td><td class="small"><?= e($d['end_reason']) ?></td>
  <td class="small text-muted"><?= e(sprintf('%.2f, %.2f, %.2f, %.2f', $d['bbox_x'], $d['bbox_y'], $d['bbox_w'], $d['bbox_h'])) ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-muted text-center py-3">Aucune détection sur la période.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/../partials/pagination.php'; ?>
