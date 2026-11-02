<?php use App\Core\View; ?><!doctype html>
<html lang="fr" data-bs-theme="light" data-theme-pref="auto">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Acheter un forfait') ?> · <?= e(setting('company.name', config('app.name'))) ?></title>
    <link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>" type="image/svg+xml">
    <script src="<?= e(asset('js/theme-init.js')) ?>"></script>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/marwa.css')) ?>">
</head>
<body class="mc-public">
    <header class="mc-topbar"><a class="mc-brand" href="<?= e(url('/buy')) ?>"><img src="<?= e(asset('images/logo.svg')) ?>" alt="" width="34" height="34"><span class="mc-brand-text"><strong>MARWA</strong> CONNECT</span></a></header>
    <main class="container py-4" style="max-width: 900px">
        <?= View::partial('components/flash') ?>
        <?= View::section('content') ?>
    </main>
    <script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
    <script src="<?= e(asset('js/public.js')) ?>"></script>
</body>
</html>
