<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= e(($title ?? 'Installation') . ' — JOB NIGER') ?></title>
  <link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<nav class="navbar jn-navbar"><div class="container"><span class="navbar-brand"><img src="<?= e(asset('images/logo.svg')) ?>" alt="JOB NIGER" width="200" height="47"></span><span class="text-muted small">Assistant d'installation</span></div></nav>
<main>
  <?= $this->partial('partials/flash') ?>
  <?= $this->section('content') ?>
</main>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
