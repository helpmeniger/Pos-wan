<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Verifia\Auth;
use Verifia\View;

Auth::require('viewer');
View::header('Sessions de surveillance', 'sessions');
$canEdit = Auth::can('analyst');
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <h1 class="h4 m-0 me-3">Sessions de surveillance</h1>
  <?php if ($canEdit): ?><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-session" id="btn-new">+ Nouvelle session</button><?php endif; ?>
  <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="Affichage">
    <button class="btn btn-outline-secondary" data-view="grid">Grille</button>
    <button class="btn btn-outline-secondary" data-view="list">Liste</button>
    <button class="btn btn-outline-secondary" data-view="tabs">Onglets</button>
  </div>
  <select class="form-select form-select-sm w-auto" id="grid-cols" title="Colonnes de la grille">
    <option value="auto">Auto</option><option value="2">2 colonnes</option><option value="3">3 colonnes</option><option value="4">4 colonnes</option><option value="6">6 colonnes</option>
  </select>
  <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" id="show-closed"><label class="form-check-label small" for="show-closed">Sessions fermées</label></div>
</div>
<div class="alert alert-secondary small py-2" id="capacity-line">Capacité : —</div>
<div class="alert alert-info small py-2">
  Chaque session ouvre un navigateur indépendant (profil séparé) dans sa propre fenêtre, piloté par le moteur de surveillance.
  Connectez-vous <b>manuellement</b> aux sites dans ces fenêtres : VERIFIA ne demande ni n'enregistre aucun mot de passe et ne lit pas les cookies.
  Cette page affiche l'état et les captures des sessions ; elle n'intègre pas les sites dans des iframes.
</div>
<div id="tabs-bar" class="nav nav-tabs mb-2" hidden></div>
<div id="sessions" class="session-grid"></div>
<p id="no-session" class="text-body-secondary" hidden>Aucune session. Créez-en une pour commencer.</p>

<div class="modal fade" id="modal-session" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form id="form-session">
  <div class="modal-header"><h5 class="modal-title" id="modal-title">Nouvelle session</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body row g-2">
    <input type="hidden" name="id">
    <div class="col-md-5"><label class="form-label">Nom</label><input class="form-control" name="name" maxlength="120" placeholder="Ex. Facebook — pages locales"></div>
    <div class="col-md-7"><label class="form-label">URL de départ</label><input class="form-control" name="start_url" type="url" required placeholder="https://..."></div>
    <div class="col-6 col-md-3"><label class="form-label">Pas de défilement (px)</label><input class="form-control" name="scroll_step_px" type="number" min="50" max="5000" value="600"></div>
    <div class="col-6 col-md-3"><label class="form-label">Attente entre défilements (ms)</label><input class="form-control" name="scroll_delay_ms" type="number" min="500" value="4000"></div>
    <div class="col-6 col-md-3"><label class="form-label">Fréquence d'analyse (ms)</label><input class="form-control" name="processing_interval_ms" type="number" min="500" value="2000"></div>
    <div class="col-6 col-md-3"><label class="form-label">Publications max.</label><input class="form-control" name="max_posts" type="number" min="1" value="500"></div>
    <div class="col-6 col-md-3"><label class="form-label">Durée max. (min)</label><input class="form-control" name="max_duration_min" type="number" min="1" value="120"></div>
    <div class="col-6 col-md-3 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="autoscroll_enabled" id="f-as" checked><label class="form-check-label" for="f-as">Défilement auto</label></div></div>
    <div class="col-6 col-md-3 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="analysis_enabled" id="f-an" checked><label class="form-check-label" for="f-an">Analyse auto</label></div></div>
    <div class="col-12"><hr class="my-1"><span class="small text-body-secondary">Position de la fenêtre du navigateur (facultatif, en pixels du bureau Windows ; un 2e écran à droite commence par exemple à x = 1920)</span></div>
    <div class="col-3"><label class="form-label">X</label><input class="form-control" name="window_x" type="number"></div>
    <div class="col-3"><label class="form-label">Y</label><input class="form-control" name="window_y" type="number"></div>
    <div class="col-3"><label class="form-label">Largeur</label><input class="form-control" name="window_w" type="number" min="320"></div>
    <div class="col-3"><label class="form-label">Hauteur</label><input class="form-control" name="window_h" type="number" min="240"></div>
  </div>
  <div class="modal-footer"><span class="text-danger small me-auto" id="form-error"></span><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button class="btn btn-primary">Enregistrer</button></div>
  </form>
</div></div></div>

<div class="modal fade" id="modal-history" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Historique de la session</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><table class="table table-sm small"><tbody id="history-body"></tbody></table></div>
</div></div></div>
<script type="application/json" id="page-data"><?= json_encode(['canEdit' => $canEdit]) ?></script>
<?php View::footer(['sessions.js']);
