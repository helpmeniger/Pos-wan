<?php $path = App\Core\Request::path(); $is = fn(string $p) => $p === '/console' ? $path === '/console' : str_starts_with($path, $p); ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Console') ?> · Console POS Marwa en ligne</title>
<meta name="robots" content="noindex">
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/saas.css')) ?>">
</head>
<body class="console-body">
<aside class="console-side">
  <div class="brand"><img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="32" height="32"><span>POS Marwa<small>Console de l'exploitant</small></span></div>
  <a href="<?= e(url('/console')) ?>" class="<?= $is('/console') ? 'active' : '' ?>"><i class="bi bi-speedometer2"></i> Tableau de bord</a>
  <a href="<?= e(url('/console/clients')) ?>" class="<?= $is('/console/clients') ? 'active' : '' ?>"><i class="bi bi-shop"></i> Clients<?php if ($waitingCount): ?><span class="badge text-bg-info"><?= (int) $waitingCount ?></span><?php endif; ?></a>
  <a href="<?= e(url('/console/paiements')) ?>" class="<?= $is('/console/paiements') ? 'active' : '' ?>"><i class="bi bi-cash-coin"></i> Paiements<?php if ($pendingCount): ?><span class="badge text-bg-warning"><?= (int) $pendingCount ?></span><?php endif; ?></a>
  <a href="<?= e(url('/console/offres')) ?>" class="<?= $is('/console/offres') ? 'active' : '' ?>"><i class="bi bi-tags"></i> Offres</a>
  <a href="<?= e(url('/console/bases')) ?>" class="<?= $is('/console/bases') ? 'active' : '' ?>"><i class="bi bi-database"></i> Bases de données</a>
  <a href="<?= e(url('/console/parametres')) ?>" class="<?= $is('/console/parametres') ? 'active' : '' ?>"><i class="bi bi-gear"></i> Paramètres</a>
  <a href="<?= e(url('/console/journal')) ?>" class="<?= $is('/console/journal') ? 'active' : '' ?>"><i class="bi bi-journal-text"></i> Journal</a>
  <a href="<?= e(url('/')) ?>" target="_blank"><i class="bi bi-box-arrow-up-right"></i> Site public</a>
  <form method="post" action="<?= e(url('/console/logout')) ?>" class="mt-3 px-2"><?= csrf_field() ?><button class="btn btn-sm btn-outline-light w-100"><i class="bi bi-box-arrow-right"></i> Déconnexion (<?= e($admin['username']) ?>)</button></form>
</aside>
<main class="console-main">
  <?php foreach ($flash as $f): ?><div class="alert alert-<?= e($f['type']) ?> py-2"><?= e($f['message']) ?></div><?php endforeach; ?>
  <?= $content ?>
</main>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/saas.js')) ?>"></script>
</body>
</html>
