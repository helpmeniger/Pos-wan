<!doctype html>
<html <?= html_attrs() ?>>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="base-url" content="<?= e(url('/')) ?>">
<title>POS Marwa</title>
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>">
<link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
<meta name="theme-color" content="#14213d">
<link rel="stylesheet" href="<?= e(bootstrap_css()) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
</head>
<body class="bg-body-tertiary">
<main class="container py-4" style="max-width:480px">
  <div class="text-center mb-3"><img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="64" height="64"><h1 class="h3 mt-2">POS <span class="text-warning">Marwa</span></h1><div class="text-secondary small"><?= e(setting('company_name', '')) ?></div></div>
  <ul class="list-group mb-3" id="checks">
    <li class="list-group-item d-flex align-items-center gap-2" data-check="server"><span class="spinner-border spinner-border-sm"></span> Connexion au serveur…</li>
    <li class="list-group-item d-flex align-items-center gap-2" data-check="camera"><span class="spinner-border spinner-border-sm"></span> Caméra (codes-barres)…</li>
  </ul>
  <a href="<?= e(url('/login')) ?>" class="btn btn-warning btn-lg w-100 fw-semibold"><i class="bi bi-box-arrow-in-right"></i> Se connecter</a>
  <p class="small text-secondary mt-3 text-center">Votre identifiant et votre mot de passe restent obligatoires. Ajoutez cette page à l'écran d'accueil pour l'ouvrir comme une application.</p>
</main>
<script src="<?= e(asset('js/mobile-landing.js')) ?>"></script>
<script src="<?= e(asset('js/pwa.js')) ?>"></script>
</body>
</html>
