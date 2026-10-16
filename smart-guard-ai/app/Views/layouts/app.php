<?php
use App\Core\Auth;
use App\Core\Session;
use App\Services\NotificationService;
$user = Auth::user();
try {
    $storeId = Auth::storeId();
} catch (App\Core\HttpException $e) {
    $storeId = 0;
}
$stores = Auth::accessibleStores();
$flash = Session::pullFlash();
$path = trim(substr(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', strlen(App\Core\Router::basePath())), '/');
$active = static fn (string $p) => ($p === '' ? $path === '' || $path === 'dashboard' : str_starts_with($path, $p)) ? ' active' : '';
$menu = [
    ['dashboard', 'speedometer2', 'Tableau de bord', 'dashboard.view'],
    ['live', 'camera-video', 'Surveillance en direct', 'live.view'],
    ['cameras', 'webcam', 'Caméras', 'cameras.view'],
    ['products', 'box-seam', 'Produits', 'products.view'],
    ['training', 'cpu', 'Apprentissage IA', 'ai.manage|ai.test'],
    ['detections', 'bounding-box', 'Détections', 'detections.view'],
    ['exits', 'box-arrow-right', 'Sorties observées', 'detections.view'],
    ['alerts', 'exclamation-triangle', 'Alertes', 'alerts.view'],
    ['pos', 'cart3', 'Caisse', 'pos.sell|sales.view'],
    ['stock', 'boxes', 'Stocks', 'stock.view'],
    ['reports', 'graph-up', 'Rapports', 'reports.view'],
    ['users', 'people', 'Utilisateurs', 'users.manage'],
    ['settings', 'gear', 'Paramètres', 'settings.manage'],
    ['maintenance', 'tools', 'Maintenance', 'maintenance.manage|audit.view'],
];
$canAny = static function (string $perms): bool {
    foreach (explode('|', $perms) as $p) { if (Auth::can($p)) { return true; } }
    return false;
};
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(App\Core\Csrf::token()) ?>">
<meta name="base-url" content="<?= e(url('')) ?>">
<title><?= e(($title ?? 'Tableau de bord') . ' — SMART GUARD AI') ?></title>
<link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body>
<div class="d-flex app-wrap">
  <nav id="sidebar" class="sidebar d-flex flex-column flex-shrink-0">
    <a href="<?= url('dashboard') ?>" class="brand d-flex align-items-center gap-2 text-decoration-none">
      <i class="bi bi-shield-check fs-3"></i><div><div class="fw-bold">SMART GUARD AI</div><small class="opacity-75">La vision intelligente au service de votre commerce.</small></div>
    </a>
    <ul class="nav nav-pills flex-column mb-auto mt-2">
      <?php foreach ($menu as [$p, $icon, $label, $perm]): if (!$canAny($perm)) { continue; } ?>
        <li class="nav-item"><a href="<?= url($p) ?>" class="nav-link<?= $active($p) ?>"><i class="bi bi-<?= $icon ?> me-2"></i><?= e($label) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </nav>
  <div class="flex-grow-1 main-col">
    <header class="topbar d-flex align-items-center gap-2 px-3">
      <button class="btn btn-sm btn-outline-secondary d-lg-none" id="toggleSidebar" aria-label="Menu"><i class="bi bi-list"></i></button>
      <h1 class="h5 mb-0 me-auto text-truncate"><?= e($title ?? '') ?></h1>
      <?php if (count($stores) > 1): ?>
      <form method="post" action="<?= url('store/switch') ?>" class="d-none d-md-block"><?= csrf_field() ?>
        <select name="store_id" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Magasin">
          <?php foreach ($stores as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $storeId ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select></form>
      <?php else: ?><span class="badge text-bg-light d-none d-md-inline"><?= e($stores[0]['name'] ?? '') ?></span><?php endif; ?>
      <div class="dropdown">
        <button class="btn btn-sm btn-light position-relative" data-bs-toggle="dropdown" id="notifBtn" aria-label="Notifications">
          <i class="bi bi-bell"></i><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" id="notifCount">0</span>
        </button>
        <div class="dropdown-menu dropdown-menu-end p-0 notif-menu"><div class="p-2 border-bottom d-flex justify-content-between"><strong>Notifications</strong>
          <a href="#" id="notifReadAll" class="small">Tout marquer comme lu</a></div><div id="notifList" class="list-group list-group-flush"><div class="p-3 text-muted small">Aucune notification.</div></div></div>
      </div>
      <div class="dropdown">
        <button class="btn btn-sm btn-light dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-person-circle"></i> <span class="d-none d-sm-inline"><?= e($user['full_name']) ?></span></button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><span class="dropdown-item-text small text-muted"><?= e(implode(', ', $user['roles'])) ?></span></li>
          <li><a class="dropdown-item" href="<?= url('account/password') ?>"><i class="bi bi-key me-2"></i>Changer mon mot de passe</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><form method="post" action="<?= url('logout') ?>"><?= csrf_field() ?><button class="dropdown-item"><i class="bi bi-box-arrow-right me-2"></i>Déconnexion</button></form></li>
        </ul>
      </div>
    </header>
    <main class="p-3 p-lg-4">
      <?php foreach ($flash as $type => $messages): foreach ($messages as $m): ?>
        <div class="alert alert-<?= e($type === 'error' ? 'danger' : $type) ?> alert-dismissible fade show"><?= e($m) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
      <?php endforeach; endforeach; ?>
      <?= $content ?>
    </main>
  </div>
</div>
<script src="<?= asset('vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('js/app.js') ?>"></script>
<?php foreach (($scripts ?? []) as $s): ?><script src="<?= asset($s) ?>"></script><?php endforeach; ?>
</body>
</html>
