<?php use App\Core\View; ?><!doctype html>
<html lang="fr" data-bs-theme="light" data-theme-pref="auto">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Connexion') ?> · <?= e(config('app.name')) ?></title>
    <link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>" type="image/svg+xml">
    <script src="<?= e(asset('js/theme-init.js')) ?>"></script>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/marwa.css')) ?>">
</head>
<body class="mc-auth<?= ($cardClass ?? '') === 'wide' ? ' mc-auth-wide' : '' ?>">
    <div class="mc-auth-brand">
        <div class="mc-auth-brand-inner">
            <img src="<?= e(asset('images/logo.svg')) ?>" alt="" width="64" height="64">
            <h1>MARWA <span>CONNECT</span></h1>
            <p><?= e(config('app.tagline')) ?></p>
            <ul class="mc-auth-points">
                <li><i class="bi bi-router"></i> Gestion centralisée des routeurs MikroTik</li>
                <li><i class="bi bi-ticket-perforated"></i> Génération et vente de tickets Wi-Fi</li>
                <li><i class="bi bi-graph-up-arrow"></i> Statistiques issues de vos données réelles</li>
            </ul>
        </div>
    </div>
    <div class="mc-auth-panel">
        <div class="mc-auth-card <?= e($cardClass ?? '') ?>">
            <?= View::partial('components/flash') ?>
            <?= View::section('content') ?>
        </div>
    </div>
    <script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
    <script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
