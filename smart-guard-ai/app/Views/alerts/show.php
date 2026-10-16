<?php $ind = json_decode((string) $a['indicators_json'], true) ?: []; ?>
<div class="row g-3">
<div class="col-lg-7">
  <div class="card mb-3"><div class="card-body">
    <div class="d-flex gap-2 mb-2"><?= level_badge($a['level']) ?> <?= status_badge($a['status']) ?> <span class="badge bg-light text-dark"><?= e(label('alert_type', $a['alert_type'])) ?></span></div>
    <h2 class="h5"><?= e($a['title']) ?></h2>
    <p class="small text-muted mb-2"><?= e($a['description']) ?></p>
    <dl class="row small mb-0">
      <dt class="col-sm-4">Identifiant</dt><dd class="col-sm-8"><code><?= e($a['uuid']) ?></code></dd>
      <dt class="col-sm-4">Date et heure</dt><dd class="col-sm-8"><?= dt($a['occurred_at']) ?></dd>
      <dt class="col-sm-4">Caméra</dt><dd class="col-sm-8"><?= e($camera ?? '—') ?><?= $exit && $exit['line'] ? ' — ' . e($exit['line']) : '' ?></dd>
      <dt class="col-sm-4">Produit</dt><dd class="col-sm-8"><?= $product ? '<a href="' . url('products/' . (int) $product['id']) . '">' . e($product['name']) . '</a>' : 'Produit non identifié' ?></dd>
      <dt class="col-sm-4">Score de confiance</dt><dd class="col-sm-8"><?= pct($a['confidence']) ?></dd>
      <?php if ($exit): ?>
      <dt class="col-sm-4">Type d'événement</dt><dd class="col-sm-8"><?= e(label('direction', $exit['direction'])) ?> — objet suivi n° <?= (int) $exit['track_number'] ?></dd>
      <dt class="col-sm-4">Rapprochement caisse</dt><dd class="col-sm-8"><?= e(label('recon', $exit['reconciliation_status'])) ?> — <?= e($exit['reconciliation_note']) ?>
        <?= $sale ? '<br>Ticket <a href="' . url('pos/sales/' . (int) $sale['sale_id']) . '">' . e($sale['receipt_number']) . '</a> du ' . dt($sale['sold_at']) : '' ?></dd>
      <?php endif; ?>
      <dt class="col-sm-4">Indices</dt><dd class="col-sm-8"><?= $ind ? '<ul class="mb-0 ps-3">' . implode('', array_map(static fn ($i) => '<li>' . e($i) . '</li>', $ind)) . '</ul>' : '—' ?></dd>
    </dl>
  </div></div>
  <?php if (can('video.view')): ?>
  <div class="card mb-3"><div class="card-header bg-white">Image de référence et extrait vidéo</div><div class="card-body">
    <?php if ($snapshot): ?><img src="<?= url('media/record/' . (int) $snapshot['id']) ?>" class="img-fluid rounded mb-3" alt="Image de l'événement"><?php else: ?><p class="text-muted small">Image non disponible (expirée ou non enregistrée).</p><?php endif; ?>
    <?php if ($clip): ?>
      <div class="clip-player" data-base="<?= e(url('media/clip/' . (int) $clip['id'])) ?>" data-frames="<?= (int) $clip['frame_count'] ?>">
        <img alt="Extrait vidéo"><div class="d-flex gap-2 align-items-center mt-2"><button class="btn btn-sm btn-outline-secondary" type="button" data-act="play"><i class="bi bi-play-fill"></i> Lire l'extrait</button><span class="small text-muted" data-pos></span></div></div>
    <?php else: ?><p class="text-muted small mb-0">Pas d'extrait vidéo pour cet événement.</p><?php endif; ?>
  </div></div>
  <?php endif; ?>
</div>
<div class="col-lg-5">
  <?php if (can('alerts.review')): ?>
  <div class="card mb-3"><div class="card-header bg-white">Vérification humaine</div><div class="card-body">
  <form method="post" action="<?= url('alerts/' . (int) $a['id'] . '/review') ?>"><?= csrf_field() ?>
    <label class="form-label">Conclusion</label>
    <select name="status" class="form-select mb-2">
      <?php foreach (App\Services\AlertService::STATUSES as $s): if (in_array($s, App\Services\AlertService::CLOSING, true) && !can('alerts.close')) { continue; } ?>
        <option value="<?= $s ?>" <?= $a['status'] === $s ? 'selected' : '' ?>><?= e(label('alert_status', $s)) ?></option><?php endforeach; ?>
    </select>
    <label class="form-label">Commentaire</label><textarea name="comment" class="form-control mb-2" rows="3" maxlength="1000" placeholder="Vérification réalisée (visionnage, caisse, échange avec le personnel…)"></textarea>
    <?php if (can('stock.manage') && $product): ?>
    <details class="mb-2"><summary class="small">Enregistrer un effet sur le stock (facultatif)</summary>
      <div class="row g-2 mt-1"><div class="col-8"><select name="stock_type" class="form-select form-select-sm"><option value="">Aucun mouvement</option>
        <option value="sortie_autorisee">Sortie autorisée</option><option value="mouvement_observe">Mouvement observé par caméra (validé)</option>
        <option value="mouvement_non_explique">Mouvement non expliqué</option><option value="casse">Casse</option></select></div>
        <div class="col-4"><input name="stock_qty" type="number" step="0.001" min="0.001" value="1" class="form-control form-control-sm"></div></div>
      <div class="form-text">Le stock comptable n'est modifié que par cette validation explicite.</div></details>
    <?php endif; ?>
    <button class="btn btn-primary w-100">Enregistrer la vérification</button>
  </form></div></div>
  <?php endif; ?>
  <div class="card"><div class="card-header bg-white">Historique</div><ul class="list-group list-group-flush small">
    <li class="list-group-item">Création : <?= dt($a['created_at']) ?></li>
    <?php foreach ($reviews as $rv): ?><li class="list-group-item"><strong><?= e($rv['full_name'] ?? 'Système') ?></strong> — <?= dt($rv['created_at']) ?><br>
      <?= e(label('alert_status', $rv['old_status'])) ?> → <?= e(label('alert_status', $rv['new_status'])) ?><?= $rv['comment'] ? '<br><em>' . e($rv['comment']) . '</em>' : '' ?><?= $rv['stock_movement_id'] ? '<br><span class="text-primary">Mouvement de stock enregistré</span>' : '' ?></li><?php endforeach; ?>
  </ul></div>
</div>
</div>
<script>
document.querySelectorAll('.clip-player').forEach(function (p) {
  var img = p.querySelector('img'), n = +p.dataset.frames, i = 0, timer = null, pos = p.querySelector('[data-pos]');
  function show(k) { img.src = p.dataset.base + '/' + k; pos.textContent = 'Image ' + (k + 1) + ' / ' + n; }
  show(0);
  p.querySelector('[data-act=play]').addEventListener('click', function () {
    if (timer) { clearInterval(timer); timer = null; return; }
    i = 0; timer = setInterval(function () { show(i); i++; if (i >= n) { clearInterval(timer); timer = null; } }, 200);
  });
});
</script>
