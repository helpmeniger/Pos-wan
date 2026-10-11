<?php
use App\Core\Auth;
use App\Core\Config;
use App\Core\Settings;
?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="data:,">
    <title><?= e($title ?? 'Impression') ?></title>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="print-page">
<div class="print-toolbar d-print-none">
    <button class="btn btn-primary btn-sm" type="button" data-print><i class="bi bi-printer"></i> Imprimer</button>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('persons.show', ['id' => $p['id'] ?? 0])) ?>">Retour</a>
</div>
<div class="print-header">
    <div><strong>DOSSIER ID</strong> — <?= e(Settings::get('institution_name')) ?></div>
    <div class="small">Document à usage interne — imprimé le <?= e(date('d/m/Y à H:i')) ?> par <?= e(Auth::user()['full_name'] ?? '') ?></div>
    <?php if (Config::isDemo()): ?><div class="small fw-bold">DONNÉES FICTIVES — DÉMONSTRATION</div><?php endif; ?>
</div>
<?= $content ?>
<div class="print-footer small">
    Une mention dans un dossier ne constitue pas une preuve de culpabilité. Seule une décision judiciaire documentée et définitive établit une condamnation.
    Diffusion restreinte aux personnes habilitées.
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
