<?php
/** @var string $content */
use App\Core\Auth;
use App\Core\Session;
use App\Services\Menu;
use App\Services\Notifications;

$u = Auth::user();
$title = $title ?? 'POS Marwa';
$current = App\Core\Request::path();
$notes = $u ? Notifications::get() : [];
?><!doctype html>
<html <?= html_attrs() ?> data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="pw-currency" content="<?= e(json_encode(currency(), JSON_UNESCAPED_UNICODE)) ?>">
<meta name="base-url" content="<?= e(url('/')) ?>">
<title><?= e($title) ?> · POS Marwa</title>
<link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
<meta name="theme-color" content="#14213d">
<link rel="apple-touch-icon" href="<?= e(asset('img/icons/icon-180.png')) ?>">
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>">
<link rel="stylesheet" href="<?= e(bootstrap_css()) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/scanner.css')) ?>">
<?php $addonAssets = App\Core\Addons::globalAssets(); foreach ($addonAssets['css'] as $s): ?><link rel="stylesheet" href="<?= e(asset($s)) ?>"><?php endforeach; ?>
</head>
<body class="admin-body <?= setting('menu_position', 'right') === 'left' ? 'menu-left' : 'menu-right' ?>">
<div class="app-shell">
  <aside class="sidebar d-print-none" id="sidebar">
    <a class="brand" href="<?= e(url('/')) ?>">
      <img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="34" height="34">
      <span>POS <b>Marwa</b></span>
    </a>
    <?php $posUrl = App\Services\BusinessMode::posUrl(); $posPerm = ['/clinique/caisse' => 'clinique.reception', '/pressing/caisse' => 'pressing.deposit', '/ecole/caisse' => 'ecole.caisse'][$posUrl] ?? 'pos.sell'; ?>
    <?php if (can($posPerm)): ?>
    <a href="<?= e(url($posUrl)) ?>" class="btn btn-pos-launch"><i class="bi <?= $posUrl === '/pos' ? 'bi-cart3' : 'bi-cash-coin' ?>"></i> Ouvrir la caisse</a>
    <?php endif; ?>
    <?php
      // Catégories repliables : seule celle de la page affichée est ouverte (les autres en un clic)
      $menuGroups = [];
      $activeCat = null;
      foreach (Menu::visible() as $group => $items) {
          $items = array_values(array_filter($items, fn($it) => $it[2] !== '/pos'));
          if (!$items) {
              continue;
          }
          foreach ($items as $it) {
              if ($current === $it[2] || ($it[2] !== '/' && str_starts_with($current, $it[2] . '/'))) {
                  $activeCat = $activeCat ?? $group;
              }
          }
          $menuGroups[$group] = $items;
      }
    ?>
    <div class="side-search"><i class="bi bi-search"></i><input type="search" placeholder="Rechercher dans le menu…" aria-label="Rechercher dans le menu" data-side-search autocomplete="off"></div>
    <nav class="side-nav" data-side-nav data-active="<?= e((string) $activeCat) ?>">
      <?php foreach ($menuGroups as $group => $items): $open = $group === $activeCat; $cid = 'cat-' . substr(md5($group), 0, 8); ?>
        <div class="side-cat <?= $open ? 'open' : '' ?>" data-cat="<?= e($group) ?>">
          <button type="button" class="side-cat-btn" aria-expanded="<?= $open ? 'true' : 'false' ?>" aria-controls="<?= e($cid) ?>">
            <i class="bi <?= e(Menu::icon($group)) ?>"></i><span class="side-cat-label"><?= e($group) ?></span><span class="side-cat-count"><?= count($items) ?></span><i class="bi bi-chevron-down side-cat-chev"></i>
          </button>
          <div class="side-items" id="<?= e($cid) ?>">
            <?php foreach ($items as $it): ?>
              <a href="<?= e(url($it[2])) ?>" class="side-link <?= ($current === $it[2] || ($it[2] !== '/' && str_starts_with($current, $it[2] . '/'))) ? 'active' : '' ?>">
                <i class="bi <?= e($it[1]) ?>"></i><span><?= e($it[0]) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <div class="side-empty" data-side-empty hidden>Aucun résultat</div>
    </nav>
    <a class="side-about" href="<?= e(url('/about')) ?>" title="À propos, contrat de licence, assistance">
      <i class="bi bi-info-circle"></i>
      <span><b>À propos de POS Marwa</b><small>v<?= e(config('app.version')) ?> · © <?= e(config('licence.author.name')) ?><br>Ingénieur informaticien nigérien · <?= e(implode(' / ', (array) config('licence.author.phones'))) ?></small></span>
    </a>
  </aside>
  <div class="main">
    <header class="topbar d-print-none">
      <button class="btn btn-link text-body d-lg-none" data-toggle-sidebar aria-label="Menu"><i class="bi bi-list fs-4"></i></button>
      <a href="<?= e(url('/menu')) ?>" class="btn btn-sm btn-outline-secondary me-2" title="Menu de gestion"><i class="bi bi-grid-3x3-gap-fill"></i> <span class="d-none d-md-inline">Menu</span></a>
      <h1 class="page-title"><?= e($title) ?></h1>
      <div class="ms-auto d-flex align-items-center gap-2">
        <span class="clock d-none d-md-inline text-secondary" data-clock></span>
        <button class="btn btn-sm btn-outline-secondary" data-theme-toggle title="Mode clair / sombre"><i class="bi bi-moon-stars"></i></button>
        <div class="dropdown">
          <button class="btn btn-sm btn-outline-secondary position-relative" data-bs-toggle="dropdown" aria-label="Notifications">
            <i class="bi bi-bell"></i>
            <?php if ($notes): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= count($notes) ?></span><?php endif; ?>
          </button>
          <div class="dropdown-menu dropdown-menu-end p-0 notif-menu">
            <div class="px-3 py-2 border-bottom fw-semibold">Alertes</div>
            <?php foreach ($notes as [$icon, $text, $link]): ?>
              <a class="dropdown-item py-2" href="<?= e(url($link)) ?>"><i class="bi <?= e($icon) ?> me-2"></i><?= e($text) ?></a>
            <?php endforeach; ?>
            <?php if (!$notes): ?><div class="px-3 py-3 text-secondary small">Aucune alerte.</div><?php endif; ?>
          </div>
        </div>
        <div class="dropdown">
          <button class="btn btn-sm btn-light border dropdown-toggle" data-bs-toggle="dropdown">
            <i class="bi bi-person-circle"></i> <?= e($u['full_name'] ?? '') ?>
          </button>
          <div class="dropdown-menu dropdown-menu-end">
            <div class="px-3 py-1 small text-secondary"><?= e($u['role_name'] ?? '') ?></div>
            <a class="dropdown-item" href="<?= e(url('/account/password')) ?>"><i class="bi bi-key me-2"></i>Mot de passe / PIN</a>
            <div class="dropdown-divider"></div>
            <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Déconnexion</button></form>
          </div>
        </div>
      </div>
    </header>
    <main class="content">
      <?php $lic = App\Services\LicenceService::status(); ?>
      <?php if ($lic['state'] === 'expired'): ?>
        <div class="alert alert-danger py-2 small d-print-none"><i class="bi bi-lock-fill"></i> <b>Licence non activée : lecture seule.</b> Ventes et modifications suspendues (vos données sont conservées). <a href="<?= e(url('/licence')) ?>">Activer la licence</a> · <?= e(implode(' / ', (array) config('licence.author.phones'))) ?></div>
      <?php elseif ($lic['state'] === 'trial' && ($lic['days_left'] <= 7 || can('licence.manage'))): ?>
        <div class="alert alert-warning py-2 small d-print-none"><i class="bi bi-hourglass-split"></i> Version d'essai : <b><?= (int) $lic['days_left'] ?> jour(s)</b> restant(s). <a href="<?= e(url('/licence')) ?>">Activer la licence</a></div>
      <?php elseif ($lic['state'] === 'licensed' && $lic['days_left'] !== null && $lic['days_left'] <= 15): ?>
        <div class="alert alert-warning py-2 small d-print-none"><i class="bi bi-calendar-x"></i> La licence expire dans <?= (int) $lic['days_left'] ?> jour(s). <a href="<?= e(url('/licence')) ?>">Renouveler</a></div>
      <?php endif; ?>
      <?php if (setting('demo_data') === '1'): ?>
        <div class="alert alert-warning py-2 small d-print-none"><i class="bi bi-info-circle"></i> Des <b>données de démonstration</b> (préfixe « [DÉMO] ») sont présentes. Archivez-les depuis <a href="<?= e(url('/settings')) ?>">Paramètres</a> avant l'exploitation réelle.</div>
      <?php endif; ?>
      <?php foreach (Session::pullFlash() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show d-print-none" role="alert">
          <?= e($f['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
        </div>
      <?php endforeach; Session::forget('_old'); ?>
      <?= $content ?>
    </main>
  </div>
</div>
<?= App\Core\View::partial('partials/confirm') ?>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<?= i18n_script() ?>
<script src="<?= e(asset('js/money.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/scanner.js')) ?>"></script>
<?php foreach ($addonAssets['js'] as $s): ?><script src="<?= e(asset($s)) ?>"></script><?php endforeach; ?>
<?php foreach (($scripts ?? []) as $s): ?><script src="<?= e(asset($s)) ?>"></script><?php endforeach; ?>
<script src="<?= e(asset('js/pwa.js')) ?>"></script>
</body>
</html>
