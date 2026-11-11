<?php /** En-tête commun des pages publiques de la boutique. Variables : $shopTitle, $color, $pageTitle, $pageDesc */ ?>
<!doctype html>
<html <?= html_attrs() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e(($pageTitle ?? '') !== '' ? $pageTitle . ' · ' . $shopTitle : $shopTitle) ?></title>
<meta name="description" content="<?= e($pageDesc ?? $tagline ?? '') ?>">
<meta name="theme-color" content="<?= e($color) ?>">
<meta property="og:title" content="<?= e($shopTitle) ?>">
<meta property="og:description" content="<?= e($tagline ?? '') ?>">
<?php if (!empty($logo)): ?><link rel="icon" href="<?= e($logo) ?>"><?php else: ?><link rel="icon" href="<?= e(asset('img/icons/icon-32.png')) ?>"><?php endif; ?>
<link rel="stylesheet" href="<?= e(bootstrap_css()) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('addons/boutique/shop.css')) ?>">
<style>:root{--shop:<?= e($color) ?>;}</style>
</head>
<body class="shop">
<header class="shop-header">
  <div class="container d-flex align-items-center gap-2 py-2">
    <a class="shop-brand" href="<?= e(url('/boutique')) ?>">
      <?php if (!empty($logo)): ?><img src="<?= e($logo) ?>" alt="" class="shop-logo"><?php else: ?><span class="shop-logo shop-logo-txt"><?= e(mb_strtoupper(mb_substr($shopTitle, 0, 1))) ?></span><?php endif; ?>
      <span class="shop-name"><?= e($shopTitle) ?></span>
    </a>
    <?php if (!empty($withSearch)): ?><div class="shop-search flex-grow-1 d-none d-md-block"><i class="bi bi-search"></i><input type="search" class="form-control" placeholder="Rechercher un article…" data-search aria-label="Rechercher"></div><?php else: ?><div class="flex-grow-1"></div><?php endif; ?>
    <a class="btn shop-cart-btn<?= empty($withCart) ? ' ms-auto' : '' ?>" href="<?= e(url('/boutique/compte')) ?>" aria-label="Mon compte"><i class="bi bi-person<?= !empty($account) ? '-check' : '' ?>"></i><span class="d-none d-sm-inline ms-1"><?= !empty($account) ? e(explode(' ', $account['name'])[0]) : 'Mon compte' ?></span></a>
    <?php if (!empty($withCart)): ?><button type="button" class="btn shop-cart-btn" data-open-cart aria-label="Panier"><i class="bi bi-bag"></i><span class="d-none d-sm-inline ms-1">Panier</span><span class="shop-badge" data-cart-count hidden>0</span></button><?php endif; ?>
  </div>
  <?php if (!empty($withSearch)): ?><div class="container d-md-none pb-2"><div class="shop-search"><i class="bi bi-search"></i><input type="search" class="form-control" placeholder="Rechercher un article…" data-search aria-label="Rechercher"></div></div><?php endif; ?>
</header>
<?php if (empty($open)): ?><div class="shop-closed"><i class="bi bi-moon-stars"></i> Les commandes en ligne sont momentanément suspendues. Vous pouvez consulter les articles<?= $phone !== '' ? ' ou appeler le ' . e($phone) : '' ?>.</div><?php endif; ?>
