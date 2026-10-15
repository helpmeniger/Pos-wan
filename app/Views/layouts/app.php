<?php
/** @var string $content @var string $title @var ?array $user @var string $csrf */
$scripts = $scripts ?? [];
$needsMap = in_array('map.js', $scripts, true);
$demo = (bool) App\Core\Config::get('app.demo_mode');
$lang = App\Support\I18n::current();
?><!doctype html>
<html lang="<?= e($lang) ?>">
<head>
  <meta charset="utf-8">
  <?php if ($lang !== 'fr'): ?><script src="<?= e(url('i18n/' . $lang . '.js')) ?>?v=<?= e(App\Support\I18n::version($lang)) ?>"></script><?php endif; ?>
  <script src="<?= asset('js/i18n.js') ?>"></script>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= e($title ?? 'MARWA') ?></title>
  <meta name="description" content="MARWA : taxi, livraison de repas, colis et courses au Niger. Paiement en FCFA.">
  <meta name="theme-color" content="#0b6e4f">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="MARWA">
  <meta name="format-detection" content="telephone=no">
  <meta name="csrf-token" content="<?= e($csrf) ?>">
  <meta name="mw-base" content="<?= e(url('')) ?>">
  <meta name="mw-public" content="<?= e(public_url('')) ?>">
  <meta name="mw-languages" content="<?= e(json_encode(array_map(fn ($c) => [$c, App\Support\I18n::NAMES[$c]], App\Support\I18n::languages()), JSON_UNESCAPED_UNICODE)) ?>">
  <meta name="mw-sw" content="<?= App\Core\Paths::rewrite() ? e(url('sw.js')) : '' ?>">
  <link rel="icon" href="<?= asset('img/favicon.svg') ?>" type="image/svg+xml">
  <link rel="apple-touch-icon" href="<?= asset('img/icon-192.png') ?>">
  <link rel="manifest" href="<?= e(public_url('manifest.webmanifest')) ?>">
  <?php if ($needsMap): ?><link rel="stylesheet" href="<?= asset('vendor/leaflet/leaflet.css') ?>"><?php endif; ?>
  <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<?php if ($demo): ?><div class="demo-banner">Environnement de démonstration — données fictives, aucun paiement réel</div><?php endif; ?>
<header class="site-header">
  <a class="brand" href="<?= e(url('')) ?>"><img src="<?= asset('img/logo.svg') ?>" alt="" width="36" height="36"><span>MARWA<small>Niger</small></span></a>
  <div class="header-spacer"></div>
  <div class="header-actions">
    <button class="icon-btn lang-btn" data-lang-btn aria-label="Langue · Language"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21a9 9 0 100-18 9 9 0 000 18zM3.6 9h16.8M3.6 15h16.8M12 3a14 14 0 010 18M12 3a14 14 0 000 18"/></svg><span class="lang-code" translate="no"><?= e(strtoupper($lang === 'dje' ? 'zar' : $lang)) ?></span></button>
    <?php if ($user): ?>
      <button class="icon-btn" data-notif-btn aria-label="Notifications"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M18 16v-5a6 6 0 10-12 0v5l-2 2h16zM10 20a2 2 0 004 0"/></svg><span class="badge-dot" data-notif-count hidden>0</span></button>
      <button class="user-chip" data-user-btn aria-label="Mon compte">
        <span class="avatar"><?php if ($user['photo_url']): ?><img src="<?= e($user['photo_url']) ?>" alt=""><?php else: ?><?= e(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?><?php endif; ?></span>
        <span class="name"><?= e(explode(' ', $user['full_name'])[0]) ?></span>
      </button>
    <?php else: ?>
      <a class="btn btn-ghost btn-sm" href="<?= e(url('connexion')) ?>">Connexion</a>
      <a class="btn btn-primary btn-sm" href="<?= e(url('inscription')) ?>">Inscription</a>
    <?php endif; ?>
  </div>
</header>
<?= $content ?>
<script type="application/json" id="mw-user"><?= json_encode($user, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<?php if ($needsMap): ?><script src="<?= asset('vendor/leaflet/leaflet.js') ?>" defer></script><?php endif; ?>
<script src="<?= asset('js/core.js') ?>" defer></script>
<?php foreach ($scripts as $s): ?><script src="<?= asset('js/' . $s) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
