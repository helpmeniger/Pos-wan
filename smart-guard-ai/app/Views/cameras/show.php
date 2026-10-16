<?php $id = (int) $cam['id']; ?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php if (can('cameras.manage')): ?>
  <a href="<?= url("cameras/$id/edit") ?>" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i>Modifier</a>
  <button class="btn btn-outline-secondary" id="btnTest" data-id="<?= $id ?>"><i class="bi bi-plug me-1"></i>Tester la connexion</button>
  <?php endif; ?>
  <?php if (can('cameras.control')): ?>
  <form method="post" action="<?= url("cameras/$id/start") ?>"><?= csrf_field() ?><button class="btn btn-success"><i class="bi bi-play-fill me-1"></i>Démarrer / redémarrer l'analyse</button></form>
  <form method="post" action="<?= url("cameras/$id/stop") ?>"><?= csrf_field() ?><button class="btn btn-outline-danger"><i class="bi bi-stop-fill me-1"></i>Arrêter</button></form>
  <?php endif; ?>
  <a href="<?= url('live') ?>" class="btn btn-light ms-auto"><i class="bi bi-camera-video me-1"></i>Surveillance en direct</a>
</div>
<div class="row g-3">
<div class="col-lg-8">
  <div class="card mb-3"><div class="card-header bg-white d-flex justify-content-between"><span>Ligne virtuelle de sortie</span><small class="text-muted">Cliquez deux points sur l'image pour tracer la ligne</small></div>
  <div class="card-body">
    <div id="testOut" class="small mb-2"></div>
    <div class="line-editor mb-2" id="editor" data-id="<?= $id ?>" data-lines='<?= e(json_encode(array_map(static fn ($l) => ['id' => (int) $l['id'], 'name' => $l['name'], 'x1' => (float) $l['x1'], 'y1' => (float) $l['y1'], 'x2' => (float) $l['x2'], 'y2' => (float) $l['y2'], 'exit_direction' => $l['exit_direction']], $lines))) ?>'>
      <img id="editorImg" alt="Aperçu de la caméra" src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" width="960" height="540" style="background:#222">
      <canvas id="editorCanvas"></canvas>
    </div>
    <div class="small text-muted mb-3">L'aperçu provient de la caméra démarrée (ou du test de connexion). La flèche rouge indique le sens considéré comme une <strong>sortie</strong>.</div>
    <?php if (can('cameras.manage')): ?>
    <form id="lineForm" class="row g-2 align-items-end">
      <input type="hidden" name="line_id" value="<?= (int) ($lines[0]['id'] ?? 0) ?>">
      <?php foreach (['x1', 'y1', 'x2', 'y2'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= e($lines[0][$k] ?? '') ?>"><?php endforeach; ?>
      <div class="col-md-3"><label class="form-label small">Nom</label><input name="name" class="form-control form-control-sm" value="<?= e($lines[0]['name'] ?? 'Porte de sortie') ?>"></div>
      <div class="col-md-3"><label class="form-label small">Sens de sortie</label><select name="exit_direction" class="form-select form-select-sm">
        <option value="gauche_vers_droite" <?= ($lines[0]['exit_direction'] ?? '') === 'gauche_vers_droite' ? 'selected' : '' ?>>Côté gauche → côté droit</option>
        <option value="droite_vers_gauche" <?= ($lines[0]['exit_direction'] ?? '') === 'droite_vers_gauche' ? 'selected' : '' ?>>Côté droit → côté gauche</option></select></div>
      <div class="col-md-2"><label class="form-label small">Images min. du suivi</label><input name="min_track_frames" type="number" min="1" max="50" class="form-control form-control-sm" value="<?= e($lines[0]['min_track_frames'] ?? 3) ?>"></div>
      <div class="col-md-2"><label class="form-label small">Marge anti-doublon</label><input name="hysteresis" type="number" step="0.005" min="0" max="0.2" class="form-control form-control-sm" value="<?= e($lines[0]['hysteresis'] ?? 0.02) ?>"></div>
      <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Enregistrer la ligne</button></div>
    </form>
    <?php endif; ?>
  </div></div>
  <div class="card"><div class="card-header bg-white">Historique de la caméra</div><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Date</th><th>Événement</th><th>Message</th></tr></thead><tbody>
    <?php foreach ($events as $ev): ?><tr><td class="small"><?= dt($ev['occurred_at']) ?></td><td><?= e($ev['event_type']) ?></td><td class="small"><?= e($ev['message']) ?></td></tr><?php endforeach; ?>
    <?php if (!$events): ?><tr><td colspan="3" class="text-muted">Aucun événement.</td></tr><?php endif; ?>
  </tbody></table></div></div>
</div>
<div class="col-lg-4">
  <div class="card mb-3"><div class="card-body small">
    <div><strong>Type :</strong> <?= e(label('camera_type', $cam['type'])) ?></div>
    <?php if ($cam['type'] === 'usb'): ?><div><strong>Périphérique :</strong> #<?= (int) $cam['device_index'] ?></div>
    <?php else: ?><div class="text-break"><strong>Flux :</strong> <code><?= e($urlMasked) ?></code></div><?php endif; ?>
    <div><strong>Emplacement :</strong> <?= e($cam['location']) ?> — <?= e($cam['monitored_zone']) ?></div>
    <div><strong>Résolution :</strong> <?= (int) $cam['resolution_w'] ?>×<?= (int) $cam['resolution_h'] ?>, analyse <?= (int) $cam['analysis_fps'] ?> img/s</div>
    <div><strong>Statut :</strong> <?= e(label('camera_status', $cam['status'])) ?> <?= $cam['last_error'] ? '— <span class="text-danger">' . e($cam['last_error']) . '</span>' : '' ?></div>
    <hr>
    <?php if ($state): ?>
      <div><strong>Service IA :</strong> <?= e($state['status']) ?><?= isset($state['fps_in']) ? ' — ' . e($state['fps_in']) . ' img/s reçues, ' . e($state['proc_ms']) . ' ms/analyse' : '' ?></div>
    <?php else: ?><div class="text-danger"><?= e($aiError) ?></div><?php endif; ?>
    <hr>
    <div><strong>Aujourd'hui :</strong> <?= (int) ($stats['exits'] ?? 0) ?> sortie(s), <?= (int) ($stats['entries'] ?? 0) ?> entrée(s), <?= (int) ($stats['uncertain'] ?? 0) ?> incertain(s)</div>
  </div></div>
  <?php if (count($lines) > 0 && can('cameras.manage')): ?>
  <div class="card"><div class="card-header bg-white">Lignes actives</div><ul class="list-group list-group-flush">
  <?php foreach ($lines as $l): ?><li class="list-group-item d-flex justify-content-between align-items-center small"><?= e($l['name']) ?>
    <form method="post" action="<?= url("cameras/$id/lines/" . (int) $l['id'] . '/delete') ?>" data-confirm="Supprimer cette ligne ?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form></li><?php endforeach; ?>
  </ul></div>
  <?php endif; ?>
</div>
</div>
