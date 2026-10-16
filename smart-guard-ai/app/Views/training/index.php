<div class="alert alert-light border small"><i class="bi bi-info-circle me-1"></i>
<strong>Méthode utilisée :</strong> reconnaissance par <em>similarité visuelle</em>. Les photos de référence sont converties en empreintes par un extracteur pré-entraîné ; aucun réseau n'est ré-entraîné.
La précision affichée est <strong>mesurée réellement</strong> sur des photos de test jamais utilisées comme référence. Lorsque la ressemblance est insuffisante ou ambiguë, le système répond « Produit non identifié ».</div>
<div class="row g-3">
<div class="col-lg-7">
  <div class="card mb-3"><div class="card-header bg-white d-flex justify-content-between align-items-center"><span>Préparer une nouvelle version</span>
    <span class="small"><?= $health['ok'] ? '<span class="badge bg-success">Service IA opérationnel</span> ' . e($health['data']['embedder'] ?? '') : '<span class="badge bg-danger">Service IA indisponible</span>' ?></span></div>
  <div class="card-body">
    <?php if (can('ai.manage')): ?>
    <form method="post" action="<?= url('training/start') ?>" class="d-flex gap-2"><?= csrf_field() ?>
      <input name="notes" class="form-control" maxlength="500" placeholder="Note (facultative) : ex. ajout du lait en poudre">
      <button class="btn btn-primary text-nowrap" <?= $health['ok'] ? '' : 'disabled' ?>><i class="bi bi-cpu me-1"></i>Lancer la préparation</button></form>
    <div class="form-text">Tous les produits actifs ayant au moins <?= (int) $minImages ?> photos sont inclus. La version obtenue doit être validée puis activée.</div>
    <?php endif; ?>
  </div></div>
  <div class="card mb-3"><div class="card-header bg-white">Historique des versions</div><div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Version</th><th>Date</th><th>Produits</th><th>Photos</th><th>Précision (test)</th><th>Statut</th><th></th></tr></thead><tbody>
    <?php foreach ($versions as $v): $m = json_decode((string) $v['metrics_json'], true)['metrics'] ?? null; ?>
      <tr><td><a href="<?= url('training/versions/' . (int) $v['id']) ?>">v<?= (int) $v['version_number'] ?></a></td><td class="small"><?= dt($v['trained_at'] ?? $v['created_at'], 'd/m/Y H:i') ?></td>
      <td><?= (int) $v['product_count'] ?></td><td><?= (int) $v['image_count'] ?></td>
      <td><?= $m ? pct($m['accuracy']) . ' <span class="small text-muted">(' . (int) $m['test_images'] . ' photos)</span>' : '—' ?></td>
      <td><span class="badge <?= $v['status'] === 'active' ? 'bg-success' : ($v['status'] === 'echec' ? 'bg-danger' : 'bg-secondary') ?>"><?= e(label('version', $v['status'])) ?></span></td>
      <td><?php if (can('ai.manage') && in_array($v['status'], ['prete', 'archivee'], true)): ?>
        <form method="post" action="<?= url('training/versions/' . (int) $v['id'] . '/activate') ?>" data-confirm="Activer cette version pour la détection ?"><?= csrf_field() ?>
        <button class="btn btn-sm btn-outline-success"><?= $v['status'] === 'archivee' ? 'Restaurer' : 'Activer' ?></button></form><?php endif; ?></td></tr>
    <?php endforeach; if (!$versions): ?><tr><td colspan="7" class="text-muted">Aucune version préparée.</td></tr><?php endif; ?>
  </tbody></table></div></div>
  <div class="card"><div class="card-header bg-white">Tâches récentes</div><ul class="list-group list-group-flush small">
    <?php foreach ($jobs as $j): ?><li class="list-group-item d-flex justify-content-between"><a href="<?= url('training/jobs/' . (int) $j['id']) ?>">#<?= (int) $j['id'] ?> — <?= dt($j['created_at'], 'd/m H:i') ?></a>
      <span><?= e(label('job', $j['status'])) ?> <?= (int) $j['progress'] ?> % — <?= e($j['message']) ?></span></li><?php endforeach; ?>
    <?php if (!$jobs): ?><li class="list-group-item text-muted">Aucune tâche.</li><?php endif; ?>
  </ul></div>
</div>
<div class="col-lg-5">
  <div class="card mb-3"><div class="card-header bg-white">Tester la reconnaissance sur une image</div><div class="card-body">
    <form id="testImageForm" class="d-flex gap-2"><input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="form-control" required><button class="btn btn-outline-primary">Tester</button></form>
    <div id="testImageOut" class="mt-2 small"></div>
  </div></div>
  <div class="card mb-3"><div class="card-header bg-white">Tester le comptage sur une courte vidéo</div><div class="card-body">
    <form id="testVideoForm" class="row g-2">
      <div class="col-12"><input type="file" name="video" accept="video/*" class="form-control" required></div>
      <div class="col-7"><select name="camera_id" class="form-select form-select-sm"><option value="">Ligne verticale au centre (défaut)</option>
        <?php foreach ($cameras as $c): ?><option value="<?= (int) $c['id'] ?>">Lignes de : <?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-3"><input type="number" name="analysis_fps" min="1" max="30" value="5" class="form-control form-control-sm" title="Images analysées par seconde"></div>
      <div class="col-2"><button class="btn btn-outline-primary btn-sm w-100">Analyser</button></div>
    </form>
    <div id="testVideoOut" class="mt-2 small"></div>
  </div></div>
  <div class="card"><div class="card-header bg-white">État des produits</div><div class="table-responsive" style="max-height:420px"><table class="table table-sm mb-0">
    <thead><tr><th>Produit</th><th>Photos</th><th>Reconnaissance</th></tr></thead><tbody>
    <?php foreach ($readiness as $p): ?><tr><td><a href="<?= url('products/' . (int) $p['id']) ?>"><?= e($p['name']) ?></a></td>
      <td class="<?= $p['photos'] < $minImages ? 'text-warning' : '' ?>"><?= (int) $p['photos'] ?></td><td><?= e(label('recognition', $p['recognition_status'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
</div>
</div>
