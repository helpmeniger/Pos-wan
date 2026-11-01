<?php /** Écran client : second écran du poste de caisse (panier, total, monnaie ; images et vidéos au repos). */ ?><!doctype html>
<html <?= html_attrs() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="pw-currency" content="<?= e(json_encode(currency(), JSON_UNESCAPED_UNICODE)) ?>">
<meta name="robots" content="noindex">
<title><?= e(t('Écran client')) ?> · <?= e($company) ?></title>
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/customer-display.css')) ?>">
</head>
<body class="cd theme-<?= e($cfg['theme']) ?>">
<div class="cd-media" id="cdMedia" aria-hidden="true"></div>
<div class="cd-welcome" id="cdWelcome">
  <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="" class="cd-logo"><?php endif; ?>
  <div class="cd-company"><?= e($company) ?></div>
  <div class="cd-welcome-text"><?= e($cfg['welcome']) ?></div>
</div>
<aside class="cd-cart d-none" id="cdCart" aria-live="polite">
  <header><?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php endif; ?><span><?= e($company) ?></span></header>
  <div class="cd-lines" id="cdLines"></div>
  <div class="cd-sum" id="cdSum"></div>
  <div class="cd-total"><span><?= e(t('Total')) ?></span><output id="cdTotal"></output></div>
  <div class="cd-pay d-none" id="cdPay"><span><?= e(t('Reçu')) ?> <b id="cdTendered"></b></span><span><?= e(t('À rendre')) ?> <b id="cdChange"></b></span></div>
</aside>
<div class="cd-thanks d-none" id="cdThanks" role="status" aria-live="assertive">
  <i class="bi bi-check-circle-fill"></i>
  <div class="cd-thanks-text"><?= e($cfg['thanks']) ?></div>
  <div class="cd-thanks-grid">
    <div><span><?= e(t('Total')) ?></span><b id="thTotal"></b></div>
    <div><span><?= e(t('Payé')) ?></span><b id="thPaid"></b></div>
    <div class="cd-change"><span><?= e(t('Monnaie rendue')) ?></span><b id="thChange"></b></div>
  </div>
</div>
<button type="button" class="cd-fs" id="cdFs"><i class="bi bi-arrows-fullscreen"></i> <?= e(t('Toucher pour passer en plein écran')) ?></button>
<script type="application/json" id="cd-config"><?= json_encode(['cfg' => $cfg, 'playlist' => $playlist], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= e(asset('js/money.js')) ?>"></script>
<script src="<?= e(asset('js/customer-display.js')) ?>"></script>
</body>
</html>
