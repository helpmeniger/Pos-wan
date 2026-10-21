<!doctype html>
<html <?= html_attrs() ?> data-bs-theme="dark">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>"><meta name="base-url" content="<?= e(url('/')) ?>">
<title>Écran <?= e($station) ?> · POS Marwa</title>
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>">
<link rel="stylesheet" href="<?= e(bootstrap_css()) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/kitchen.css')) ?>">
<?php $addonAssets = App\Core\Addons::globalAssets(); foreach ($addonAssets['css'] as $s): ?><link rel="stylesheet" href="<?= e(asset($s)) ?>"><?php endforeach; ?>
</head>
<body class="kds" data-station="<?= e($station) ?>">
<header class="kds-head">
  <div class="kds-title"><i class="bi bi-fire text-warning"></i> <?= e(mb_strtoupper($station)) ?></div>
  <nav class="kds-stations"><?php foreach ($stations as $s): ?><a href="<?= e(url('/kitchen', ['station' => $s])) ?>" class="<?= $s === $station ? 'on' : '' ?>"><?= e(ucfirst($s)) ?></a><?php endforeach; ?></nav>
  <div class="kds-counts"><span class="c-pending" id="cPending">0</span> en attente · <span class="c-prep" id="cPrep">0</span> en cours · <span class="c-ready" id="cReady">0</span> prêts</div>
  <div class="ms-auto d-flex align-items-center gap-3">
    <label class="small"><input type="checkbox" id="showServed"> Servis récents</label>
    <button class="btn btn-sm btn-outline-light" id="soundBtn" title="Son"><i class="bi bi-volume-up"></i></button>
    <span class="kds-clock" id="clock"></span>
    <button class="btn btn-sm btn-outline-light" id="fsBtn" title="Plein écran"><i class="bi bi-fullscreen"></i></button>
    <a href="<?= e(url('/')) ?>" class="btn btn-sm btn-outline-light" title="Quitter"><i class="bi bi-box-arrow-left"></i></a>
  </div>
</header>
<main class="kds-board" id="board"><div class="kds-empty">Chargement…</div></main>
<?= i18n_script() ?>
<script src="<?= e(asset('js/money.js')) ?>"></script>
<script src="<?= e(asset('js/kitchen.js')) ?>"></script>
<?php foreach ($addonAssets['js'] as $s): ?><script src="<?= e(asset($s)) ?>"></script><?php endforeach; ?>
</body>
</html>
