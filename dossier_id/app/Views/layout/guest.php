<?php
use App\Core\Config;
use App\Core\Session;
?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="data:,">
    <title><?= e($title ?? 'Connexion') ?> — DOSSIER ID</title>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="guest">
<?php if (Config::isDemo()): ?>
    <div class="demo-banner"><i class="bi bi-exclamation-triangle-fill"></i> ENVIRONNEMENT DE DÉMONSTRATION — données fictives uniquement.</div>
<?php endif; ?>
<div class="guest-wrap">
    <div class="guest-card">
        <div class="text-center mb-4">
            <i class="bi bi-shield-lock guest-logo"></i>
            <h1 class="h4 mt-2 mb-0">DOSSIER ID</h1>
            <div class="text-muted small">Gestion numérique des dossiers et identification administrative</div>
        </div>
        <?php foreach (Session::pullFlashes() as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
        <?php endforeach; ?>
        <?= $content ?>
    </div>
    <p class="text-center small text-muted mt-3">Accès réservé aux agents habilités. Toute connexion est journalisée.</p>
</div>
<script src="<?= e(asset('vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
</body>
</html>
