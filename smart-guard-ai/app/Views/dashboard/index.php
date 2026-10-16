<?php $k = [
  ['cameras_online', 'webcam', 'Caméras connectées', 'text-success'], ['cameras_offline', 'camera-video-off', 'Caméras hors ligne', 'text-danger'],
  ['monitored', 'clock-history', 'Surveillance du jour', 'text-primary'], ['identified', 'upc-scan', 'Produits reconnus (jour)', 'text-primary'],
  ['exits', 'box-arrow-right', 'Sorties observées (jour)', 'text-warning'], ['to_verify', 'exclamation-triangle', 'Événements à vérifier', 'text-danger'],
  ['closed', 'check2-circle', 'Événements vérifiés (jour)', 'text-success'], ['validated_accuracy', 'bullseye', 'Taux de reconnaissance validé', 'text-info'],
]; ?>
<?php require __DIR__ . '/../partials/disclaimer.php'; ?>
<div class="row g-3 mb-3">
<?php foreach ($k as [$key, $icon, $label, $cls]): ?>
  <div class="col-6 col-md-3"><div class="card kpi h-100"><div class="card-body d-flex justify-content-between align-items-start">
    <div><div class="label"><?= e($label) ?></div><div class="value" data-kpi="<?= e($key) ?>">…</div></div><i class="bi bi-<?= $icon ?> <?= $cls ?>"></i>
  </div></div></div>
<?php endforeach; ?>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-8"><div class="card h-100"><div class="card-header bg-white">Mouvements observés par heure (aujourd'hui)</div><div class="card-body"><canvas id="chHourly" height="110"></canvas></div></div></div>
  <div class="col-lg-4"><div class="card h-100"><div class="card-header bg-white">État du système</div><div class="card-body small" id="sysBox">Chargement…</div></div></div>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-6"><div class="card"><div class="card-header bg-white">Sorties observées par jour (14 jours)</div><div class="card-body"><canvas id="chDaily" height="140"></canvas></div></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header bg-white">Produits les plus observés à la sortie (7 jours)</div><div class="card-body"><canvas id="chTopExits" height="140"></canvas></div></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header bg-white">Produits les plus détectés (7 jours)</div><div class="card-body"><canvas id="chTopDet" height="140"></canvas></div></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header bg-white">Événements par caméra (7 jours)</div><div class="card-body"><canvas id="chCam" height="140"></canvas></div></div></div>
  <div class="col-lg-4"><div class="card"><div class="card-header bg-white">Alertes par niveau (30 jours)</div><div class="card-body"><canvas id="chLevel" height="200"></canvas></div></div></div>
  <div class="col-lg-4"><div class="card"><div class="card-header bg-white">Résultats de la vérification humaine (30 jours)</div><div class="card-body"><canvas id="chReview" height="200"></canvas></div></div></div>
  <div class="col-lg-4"><div class="card"><div class="card-header bg-white">Écarts d'inventaire (90 jours)</div><div class="card-body"><canvas id="chVar" height="200"></canvas></div></div></div>
</div>
