<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Verifia\Auth;
use Verifia\View;

Auth::require('viewer');
View::header('Tableau de bord', 'dashboard');
$cards = [
    'sessions_active' => 'Sessions actives', 'sites_monitored' => 'Sites surveillés', 'publications_detected' => 'Publications détectées',
    'publications_analyzed' => 'Publications analysées', 'alerts_unread' => 'Alertes non lues', 'alerts_priority' => 'Alertes prioritaires',
    'image_matches' => 'Images similaires retrouvées', 'pending_verifications' => 'Vérifications en attente', 'navigation_errors' => 'Erreurs (24 h)',
];
?>
<div id="emergency-banner" class="alert alert-danger" hidden><div class="d-flex justify-content-between align-items-center">
  <span><b>Arrêt immédiat actif.</b> Toutes les sessions sont arrêtées ; aucun démarrage n'est possible.</span>
  <?php if (Auth::can('analyst')): ?><button class="btn btn-light btn-sm" id="btn-release">Lever l'arrêt immédiat</button><?php endif; ?>
</div></div>
<div class="row g-2 mb-3" id="stat-cards">
<?php foreach ($cards as $k => $label): ?>
  <div class="col-6 col-md-4 col-xl"><div class="card stat-card h-100"><div class="card-body p-2">
    <div class="stat-value" data-stat="<?= e($k) ?>">—</div><div class="stat-label"><?= e($label) ?></div>
  </div></div></div>
<?php endforeach; ?>
</div>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card mb-3"><div class="card-header d-flex justify-content-between"><span>Activité (24 dernières heures, données enregistrées)</span>
      <span class="small"><span class="legend l-pub"></span> publications <span class="legend l-alert ms-2"></span> alertes <span class="legend l-err ms-2"></span> erreurs</span></div>
      <div class="card-body"><canvas id="chart" height="220" class="w-100"></canvas></div></div>
    <div class="card"><div class="card-header d-flex justify-content-between"><span>Journal des événements</span><a href="events.php" class="small">Tout voir</a></div>
      <div class="card-body p-0"><table class="table table-sm table-striped mb-0 small"><tbody id="events"></tbody></table></div></div>
  </div>
  <div class="col-lg-4">
    <div class="card mb-3"><div class="card-header">File d'analyse</div><div class="card-body small" id="queue">—</div></div>
    <div class="card mb-3"><div class="card-header">Capacité et ressources</div><div class="card-body small" id="capacity">—</div></div>
    <div class="card"><div class="card-header">Services</div><div class="card-body small" id="services">—</div></div>
  </div>
</div>
<?php View::footer(['dashboard.js']);
