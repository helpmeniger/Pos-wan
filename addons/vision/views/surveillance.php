<?php $t = $today; ?>
<script type="application/json" id="surv-data"><?= json_encode(['cameras' => $cameras], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<div class="row g-2 mb-3 surv-stats">
  <div class="col-6 col-md"><div class="card h-100"><div class="card-body py-2"><div class="small text-secondary">Passages aujourd'hui</div><div class="fs-4 fw-bold" data-t="events"><?= (int) $t['events'] ?></div></div></div></div>
  <div class="col-6 col-md"><div class="card h-100"><div class="card-body py-2"><div class="small text-secondary">Articles identifiés</div><div class="fs-4 fw-bold" data-t="identified"><?= (int) $t['identified'] ?></div></div></div></div>
  <div class="col-6 col-md"><div class="card h-100"><div class="card-body py-2"><div class="small text-secondary">Non identifiés</div><div class="fs-4 fw-bold" data-t="unidentified"><?= (int) $t['unidentified'] ?></div></div></div></div>
  <div class="col-6 col-md"><div class="card h-100"><div class="card-body py-2"><div class="small text-secondary">Unités vendues</div><div class="fs-4 fw-bold" data-t="sold"><?= qty($t['sold']) ?></div></div></div></div>
  <div class="col-12 col-md"><a class="card h-100 text-decoration-none border-danger" href="<?= e(url('/vision/rapport')) ?>"><div class="card-body py-2"><div class="small text-danger">Passages sans vente correspondante</div><div class="fs-4 fw-bold text-danger" data-t="unmatched"><?= (int) $t['unmatched'] ?></div></div></a></div>
</div>

<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <button type="button" class="btn btn-danger" id="survStart" <?= $cameras ? '' : 'disabled' ?>><i class="bi bi-record-circle"></i> Démarrer la surveillance</button>
  <button type="button" class="btn btn-outline-secondary d-none" id="survStop"><i class="bi bi-stop-circle"></i> Arrêter</button>
  <span class="small text-secondary" id="survEngine"></span>
  <a class="btn btn-outline-secondary ms-auto" href="<?= e(url('/vision/cameras')) ?>"><i class="bi bi-webcam"></i> Caméras</a>
  <a class="btn btn-outline-primary" href="<?= e(url('/vision/rapport')) ?>"><i class="bi bi-clipboard-data"></i> Rapport du jour</a>
</div>

<?php if (!$cameras): ?>
  <div class="alert alert-info">Aucune caméra de surveillance. <a href="<?= e(url('/vision/cameras')) ?>">Ajoutez une caméra</a> (USB, carte d'acquisition ou caméra IP) en cochant « Surveillance ».</div>
<?php endif; ?>

<div class="row g-3" id="survGrid">
  <?php foreach ($cameras as $c): ?>
  <div class="col-xl-6"><div class="card surv-cam" data-cam="<?= (int) $c['id'] ?>">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
      <span class="surv-dot" data-dot title="État"></span><b><?= e($c['name']) ?></b>
      <span class="badge bg-light text-dark border"><?= $c['mode'] === 'zone' ? 'Articles vus dans la zone' : 'Articles qui franchissent la ligne' ?></span>
      <span class="ms-auto small"><span class="badge bg-danger" data-c="sortie" title="Sorties">0</span> <span class="badge bg-success" data-c="entree" title="Retours">0</span> <span class="badge bg-primary" data-c="vu" title="Vus dans la zone">0</span></span>
    </div>
    <div class="surv-stage"><video playsinline muted></video><canvas class="surv-overlay"></canvas><div class="surv-wait" data-wait>Caméra arrêtée</div></div>
    <div class="card-body py-2">
      <div class="d-flex flex-wrap gap-1 align-items-center small">
        <?php if ($c['kind'] !== 'ip'): ?><select class="form-select form-select-sm" style="max-width:220px" data-device title="Caméra de ce poste"><option value="">Caméra par défaut</option></select><?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline-danger" data-draw="ligne" title="Cliquez deux points sur l'image"><i class="bi bi-slash-lg"></i> Tracer la ligne</button>
        <button type="button" class="btn btn-sm btn-outline-primary" data-draw="zone" title="Faites glisser un rectangle sur l'image"><i class="bi bi-bounding-box"></i> Tracer la zone</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-invert title="Inverser le sens « sortie »"><i class="bi bi-arrow-left-right"></i> Sens</button>
        <select class="form-select form-select-sm" style="max-width:150px" data-mode><option value="ligne" <?= $c['mode'] === 'ligne' ? 'selected' : '' ?>>Compter : ligne</option><option value="zone" <?= $c['mode'] === 'zone' ? 'selected' : '' ?>>Compter : zone</option></select>
        <button type="button" class="btn btn-sm btn-success d-none" data-save><i class="bi bi-check2"></i> Enregistrer le tracé</button>
        <span class="text-secondary" data-msg></span>
      </div>
      <div class="surv-events" data-events><span class="small text-secondary">Les passages détectés s'affichent ici.</span></div>
    </div>
  </div></div>
  <?php endforeach; ?>
</div>

<div class="alert alert-light border small mt-3 mb-0">
  <i class="bi bi-shield-exclamation text-danger"></i> <b>À savoir</b>
  <ul class="mb-0">
    <li>La détection et la reconnaissance sont automatiques et <b>imparfaites</b> : un passage « sans vente » est une indication à vérifier (revoir la vignette), <b>pas une preuve de vol</b>.</li>
    <li>Cette page doit rester ouverte sur le poste de surveillance (écran maintenu allumé). Le calcul se fait sur ce poste ; aucune vidéo n'est enregistrée, seulement une petite vignette par passage, effacée après la durée de conservation.</li>
    <li>Filmer des personnes est encadré par la loi : informez le personnel et la clientèle (affichage), limitez les caméras aux zones utiles et respectez la réglementation sur les données personnelles en vigueur au Niger.</li>
  </ul>
</div>
