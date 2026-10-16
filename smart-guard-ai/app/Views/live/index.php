<div class="d-flex flex-wrap gap-2 align-items-center mb-3 no-print">
  <button class="btn btn-sm btn-outline-secondary" id="btnPause"><i class="bi bi-pause-fill"></i> Pause de l'affichage</button>
  <div class="form-check form-switch ms-2"><input class="form-check-input" type="checkbox" id="chkOverlay" checked><label class="form-check-label" for="chkOverlay">Afficher détections et lignes</label></div>
  <select class="form-select form-select-sm ms-auto" style="width:auto" id="gridSize"><option value="2">2 par ligne</option><option value="1">1 par ligne</option><option value="3">3 par ligne</option></select>
</div>
<p class="small text-muted">La pause n'interrompt ni l'analyse ni l'enregistrement en arrière-plan : ces fonctions se contrôlent séparément pour chaque caméra.</p>
<div class="row g-3" id="grid">
<?php foreach ($cameras as $c): ?>
  <div class="col-md-6 cam-col" data-id="<?= (int) $c['id'] ?>">
    <div class="cam-tile"><img alt="<?= e($c['name']) ?>" data-cam="<?= (int) $c['id'] ?>"><div class="cam-offline d-none">Aucune image — caméra arrêtée ou déconnectée</div>
      <div class="cam-head d-flex justify-content-between"><span><i class="bi bi-circle-fill text-danger small"></i> <?= e($c['name']) ?></span><span class="cam-info"></span></div></div>
    <div class="d-flex flex-wrap gap-2 align-items-center mt-2 small">
      <?php if (can('cameras.control')): ?>
      <div class="form-check form-switch mb-0"><input class="form-check-input chk-analysis" type="checkbox" <?= $c['analysis_enabled'] ? 'checked' : '' ?> id="an<?= (int) $c['id'] ?>"><label class="form-check-label" for="an<?= (int) $c['id'] ?>">Analyse</label></div>
      <?php endif; ?>
      <button class="btn btn-sm btn-light btn-capture"><i class="bi bi-camera"></i> Capture</button>
      <a class="btn btn-sm btn-light" href="<?= url('exits', ['camera_id' => (int) $c['id']]) ?>"><i class="bi bi-clock-history"></i> Historique</a>
      <span class="ms-auto text-muted cam-stats"></span>
    </div>
  </div>
<?php endforeach; if (!$cameras): ?><div class="col-12"><div class="alert alert-info">Aucune caméra configurée. <a href="<?= url('cameras/create') ?>">Ajouter une caméra</a>.</div></div><?php endif; ?>
</div>
