<?php $t = $today; ?>
<script type="application/json" id="surv-data"><?= json_encode(['cameras' => $cameras], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<?php $ROLE = ['passage' => ['', '', ''], 'etagere' => ['Étagère', 'bg-warning text-dark', 'bi-bookshelf'], 'caisse' => ['Caisse', 'bg-success', 'bi-cart-check'], 'sortie' => ['Sortie', 'bg-danger', 'bi-door-open']]; ?>
<div class="row g-2 mb-3 surv-stats">
  <div class="col-6 col-md"><div class="card h-100"><div class="card-body py-2"><div class="small text-secondary">Passages aujourd'hui</div><div class="fs-4 fw-bold" data-t="events"><?= (int) $t['events'] ?></div></div></div></div>
  <div class="col-6 col-md"><div class="card h-100"><div class="card-body py-2"><div class="small text-secondary">Articles reconnus</div><div class="fs-4 fw-bold" data-t="identified"><?= (int) $t['identified'] ?></div></div></div></div>
  <div class="col-6 col-md"><div class="card h-100"><div class="card-body py-2"><div class="small text-secondary">Non identifiés</div><div class="fs-4 fw-bold" data-t="unidentified"><?= (int) $t['unidentified'] ?></div></div></div></div>
  <div class="col-6 col-md"><div class="card h-100"><div class="card-body py-2"><div class="small text-secondary">Unités vendues</div><div class="fs-4 fw-bold" data-t="sold"><?= qty($t['sold']) ?></div></div></div></div>
  <div class="col-12 col-md"><a class="card h-100 text-decoration-none border-danger" href="<?= e(url('/vision/rapport')) ?>"><div class="card-body py-2"><div class="small text-danger">Passages sans vente correspondante</div><div class="fs-4 fw-bold text-danger" data-t="unmatched"><?= (int) $t['unmatched'] ?></div></div></a></div>
</div>

<div class="card border-warning mb-3" id="survAlerts">
  <div class="card-header d-flex flex-wrap align-items-center gap-2">
    <i class="bi bi-bell-fill text-warning"></i> <b>Vérifications demandées</b>
    <span class="badge bg-danger" data-alert-count>0</span>
    <span class="small text-secondary">· en cours de suivi : <b data-open>0</b> (dont <b data-vucaisse>0</b> vus en caisse)</span>
    <div class="form-check form-switch ms-auto mb-0 small"><input class="form-check-input" type="checkbox" id="survSound" checked><label class="form-check-label" for="survSound">Son</label></div>
    <a class="btn btn-sm btn-outline-warning" href="<?= e(url('/vision/controles')) ?>"><i class="bi bi-list-check"></i> Toutes les alertes</a>
  </div>
  <div class="ctl-live" data-alerts><span class="small text-secondary p-2 d-block">Aucune vérification demandée. Les articles pris en rayon puis encaissés ou remis ne s'affichent pas ici.</span></div>
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
      <?php $r = $ROLE[$c['role']] ?? $ROLE['passage']; if ($r[0]): ?><span class="badge <?= $r[1] ?>"><i class="bi <?= $r[2] ?>"></i> <?= e($r[0]) ?></span><?php endif; ?>
      <?php if ($c['place']): ?><span class="small text-secondary"><?= e($c['place']) ?></span><?php endif; ?>
      <?php if ($c['role'] === 'etagere'): ?>
        <span class="badge bg-light text-dark border" data-shelf-state>Emplacements surveillés</span>
        <span class="ms-auto small"><span class="badge bg-warning text-dark" data-c="prise" title="Pris en rayon">0</span> <span class="badge bg-success" data-c="remis" title="Remis en rayon">0</span></span>
      <?php else: ?>
        <span class="badge bg-light text-dark border"><?= $c['mode'] === 'zone' ? 'Articles vus dans la zone' : 'Articles qui franchissent la ligne' ?></span>
        <span class="ms-auto small"><span class="badge bg-danger" data-c="sortie" title="Sorties">0</span> <span class="badge bg-success" data-c="entree" title="Retours">0</span> <span class="badge bg-primary" data-c="vu" title="Vus dans la zone">0</span></span>
      <?php endif; ?>
    </div>
    <div class="surv-stage"><video playsinline muted></video><canvas class="surv-overlay"></canvas><div class="surv-wait" data-wait>Caméra arrêtée</div></div>
    <div class="card-body py-2">
      <div class="d-flex flex-wrap gap-1 align-items-center small">
        <?php if ($c['kind'] !== 'ip'): ?><select class="form-select form-select-sm" style="max-width:220px" data-device title="Caméra de ce poste"><option value="">Caméra par défaut</option></select><?php endif; ?>
        <?php if ($c['role'] === 'etagere'): ?>
        <button type="button" class="btn btn-sm btn-outline-warning" data-draw="slot" title="Faites glisser un rectangle autour d'un article (ou d'une rangée d'articles identiques)"><i class="bi bi-plus-square-dotted"></i> Tracer un emplacement</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-full title="À faire quand le rayon est rempli et que personne ne le cache"><i class="bi bi-camera"></i> Rayon plein : mémoriser</button>
        <button type="button" class="btn btn-sm btn-success d-none" data-save-slots><i class="bi bi-check2"></i> Enregistrer les emplacements</button>
        <span class="text-secondary" data-msg></span>
      </div>
      <div class="surv-slots" data-slots></div>
        <?php else: ?>
        <button type="button" class="btn btn-sm btn-outline-danger" data-draw="ligne" title="Cliquez deux points sur l'image"><i class="bi bi-slash-lg"></i> Tracer la ligne</button>
        <button type="button" class="btn btn-sm btn-outline-primary" data-draw="zone" title="Faites glisser un rectangle sur l'image"><i class="bi bi-bounding-box"></i> Tracer la zone</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-invert title="Inverser le sens « sortie »"><i class="bi bi-arrow-left-right"></i> Sens</button>
        <select class="form-select form-select-sm" style="max-width:150px" data-mode><option value="ligne" <?= $c['mode'] === 'ligne' ? 'selected' : '' ?>>Compter : ligne</option><option value="zone" <?= $c['mode'] === 'zone' ? 'selected' : '' ?>>Compter : zone</option></select>
        <button type="button" class="btn btn-sm btn-success d-none" data-save><i class="bi bi-check2"></i> Enregistrer le tracé</button>
        <span class="text-secondary" data-msg></span>
      </div>
        <?php endif; ?>
      <div class="surv-events" data-events><span class="small text-secondary">Les passages détectés s'affichent ici.</span></div>
    </div>
  </div></div>
  <?php endforeach; ?>
</div>

<div class="alert alert-light border small mt-3 mb-0">
  <i class="bi bi-shield-exclamation text-danger"></i> <b>À savoir</b>
  <ul class="mb-0">
    <li>La détection et la reconnaissance sont automatiques et <b>imparfaites</b> : un passage « sans vente » ou une alerte « vérification demandée » est une indication à vérifier (revoir la vignette, le rayon, la caisse), <b>pas une preuve de vol</b>. Vérifiez avec discrétion et sans accuser un client.</li>
    <li>Cette page doit rester ouverte sur le poste de surveillance (écran maintenu allumé). Le calcul se fait sur ce poste ; aucune vidéo n'est enregistrée, seulement une petite vignette par passage, effacée après la durée de conservation.</li>
    <li>Filmer des personnes est encadré par la loi : informez le personnel et la clientèle (affichage), limitez les caméras aux zones utiles et respectez la réglementation sur les données personnelles en vigueur au Niger.</li>
  </ul>
</div>
