<?php
declare(strict_types=1);
// Organisation multi-écrans : ouvre les vues de VERIFIA dans des fenêtres séparées, sur l'écran choisi.
require __DIR__ . '/_init.php';

use Verifia\Auth;
use Verifia\View;

Auth::require('viewer');
View::header('Poste multi-écrans', 'workspace');
?>
<h1 class="h4">Organisation des écrans</h1>
<div class="row g-3">
<div class="col-lg-7">
<div class="card mb-3"><div class="card-header">Mode A — Supervision centralisée</div><div class="card-body small">
  Une seule fenêtre : utilisez le <a href="index.php">tableau de bord</a>, la page <a href="sessions.php">Sessions</a> (grille / liste / onglets) et le <a href="alerts.php">centre d'alertes</a>.
  Les navigateurs surveillés s'ouvrent dans leurs propres fenêtres (une par session), que vous pouvez réduire.
</div></div>
<div class="card mb-3"><div class="card-header">Mode B — Poste multi-écrans</div><div class="card-body small">
  <p id="screens-info">Détection des écrans…</p>
  <button class="btn btn-sm btn-outline-info mb-2" id="btn-detect">Détecter mes écrans (autorisation du navigateur)</button>
  <table class="table table-sm align-middle"><thead><tr><th>Vue</th><th>Écran</th><th></th></tr></thead><tbody id="views"></tbody></table>
  <button class="btn btn-primary btn-sm" id="btn-open-all">Ouvrir toutes les vues sur les écrans choisis</button>
  <p class="mt-2 text-body-secondary">Les navigateurs surveillés (Playwright) se placent avec les champs « Position de la fenêtre » de chaque session :
  un écran secondaire situé à droite d'un écran 1920×1080 commence à X = 1920. Un poste à écran unique fonctionne normalement : toutes les fenêtres s'ouvrent sur l'écran principal.</p>
</div></div>
</div>
<div class="col-lg-5"><div class="card"><div class="card-header">Garder les alertes visibles</div><div class="card-body small">
  <ul>
    <li>Ouvrez le centre d'alertes dans une fenêtre séparée sur un écran dédié.</li>
    <li>Activez « Notifications Windows » dans le centre d'alertes : une notification système apparaît pour chaque nouvelle alerte prioritaire <b>sans prendre le focus</b> de votre fenêtre de travail.</li>
    <li>Le titre de l'onglet affiche le nombre d'alertes non lues.</li>
    <li>Un navigateur web ne peut pas forcer une fenêtre « toujours au premier plan ». Sous Windows 10/11, utilisez Microsoft PowerToys → « Toujours visible » (Win + Ctrl + T) sur la fenêtre du centre d'alertes si vous le souhaitez.</li>
  </ul>
</div></div></div>
</div>
<?php View::footer(['workspace.js']);
