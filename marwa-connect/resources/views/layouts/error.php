<?php use App\Core\View; ?><!doctype html>
<html lang="fr" data-bs-theme="light" data-theme-pref="auto">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Erreur <?= (int) ($status ?? 500) ?> · <?= e(config('app.name')) ?></title>
    <script src="<?= e(asset('js/theme-init.js')) ?>"></script>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/marwa.css')) ?>">
</head>
<body class="mc-error-page">
    <div class="mc-error-box"><?= View::section('content') ?></div>
</body>
</html>
