<?php $flash = App\Core\Session::pullFlash(); ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? 'Connexion') . ' — SMART GUARD AI') ?></title>
<link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body class="guest-bg d-flex align-items-center min-vh-100">
<div class="container" style="max-width:440px">
  <div class="text-center text-white mb-4"><i class="bi bi-shield-check display-4"></i><h1 class="h3 fw-bold mt-2">SMART GUARD AI</h1>
  <p class="opacity-75 mb-0">La vision intelligente au service de votre commerce.</p></div>
  <?php foreach ($flash as $type => $messages): foreach ($messages as $m): ?>
    <div class="alert alert-<?= e($type === 'error' ? 'danger' : $type) ?>"><?= e($m) ?></div>
  <?php endforeach; endforeach; ?>
  <?= $content ?>
</div>
</body></html>
