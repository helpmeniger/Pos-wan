<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Verifia\Analysis\TextAnalyzer;
use Verifia\Auth;
use Verifia\Database;
use Verifia\View;

Auth::require('viewer');
$bare = !empty($_GET['window']);
View::header("Centre d'alertes", 'alerts', ['bare' => $bare]);
$sessions = Database::all('SELECT DISTINCT session_id, session_name FROM alerts WHERE session_id IS NOT NULL ORDER BY session_name');
$sites = Database::all('SELECT DISTINCT site_name FROM alerts WHERE site_name IS NOT NULL ORDER BY site_name');
$users = Database::all('SELECT u.id, u.display_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.code IN (\'analyst\',\'admin\') ORDER BY u.display_name');
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="h4 m-0">Centre d'alertes VERIFIA</h1>
  <span class="badge text-bg-danger fs-6" id="unread-badge" title="Alertes non lues">0</span>
  <span class="badge text-bg-secondary" id="live-status">connexion…</span>
  <div class="ms-auto d-flex gap-2 align-items-center">
    <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" id="opt-sound"><label class="form-check-label small" for="opt-sound">Son</label></div>
    <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" id="opt-desktop"><label class="form-check-label small" for="opt-desktop">Notifications Windows</label></div>
    <?php if (!$bare): ?><a class="btn btn-sm btn-outline-info" href="alerts.php?window=1" data-popup="alerts" data-popup-name="verifia_alerts">Ouvrir dans une fenêtre séparée</a><?php endif; ?>
    <?php if (Auth::can('analyst')): ?><button class="btn btn-sm btn-outline-secondary" id="btn-read-all">Tout marquer comme lu</button><?php endif; ?>
  </div>
</div>
<form class="row g-2 mb-2 small" id="filters">
  <div class="col-6 col-md-2"><input class="form-control form-control-sm" name="q" placeholder="Rechercher…"></div>
  <div class="col-6 col-md-1"><select class="form-select form-select-sm" name="priority"><option value="">Priorité</option><?php foreach (View::PRIORITY_LABELS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><select class="form-select form-select-sm" name="status"><option value="">Statut (hors ignorées)</option><?php foreach (View::STATUS_LABELS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><select class="form-select form-select-sm" name="category"><option value="">Type d'information</option><?php foreach (TextAnalyzer::CATEGORY_LABELS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-1"><select class="form-select form-select-sm" name="site"><option value="">Site</option><?php foreach ($sites as $s): ?><option><?= e($s['site_name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-1"><select class="form-select form-select-sm" name="session_id"><option value="">Session</option><?php foreach ($sessions as $s): ?><option value="<?= (int) $s['session_id'] ?>"><?= e($s['session_name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-1"><input class="form-control form-control-sm" type="date" name="date_from" title="Du"></div>
  <div class="col-6 col-md-1"><input class="form-control form-control-sm" type="date" name="date_to" title="Au"></div>
  <div class="col-12 col-md-1"><button class="btn btn-sm btn-secondary w-100" type="reset">Réinitialiser</button></div>
</form>
<div class="small text-body-secondary mb-2"><span id="total">0</span> alerte(s). Une priorité élevée ou critique indique un besoin de vérification humaine, pas une fausseté établie.</div>
<div id="alert-list" class="alert-list"></div>
<button class="btn btn-sm btn-outline-secondary mt-2" id="btn-more" hidden>Charger plus</button>
<script type="application/json" id="page-data"><?= json_encode(['canEdit' => Auth::can('analyst'), 'users' => $users, 'categories' => TextAnalyzer::CATEGORY_LABELS, 'priorities' => View::PRIORITY_LABELS, 'statuses' => View::STATUS_LABELS], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php View::footer(['alerts.js']);
