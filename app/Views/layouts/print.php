<?php /** Mise en page d'impression (ticket 58/80 mm ou A4) : aucun menu n'est imprimé. */ ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Impression') ?></title>
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/print.css')) ?>">
</head>
<body class="<?= e($bodyClass ?? 'a4') ?>">
<div class="print-toolbar no-print">
  <button type="button" data-print class="pbtn primary"><i class="bi bi-printer"></i> Imprimer</button>
  <a href="<?= e($backUrl ?? url('/')) ?>" class="pbtn"><i class="bi bi-arrow-left"></i> Retour</a>
  <?php if (!empty($autoPrint)): ?><span data-autoprint></span><?php endif; ?>
  <?php if (!empty($returnAfterPrint)): ?><a href="<?= e($backUrl) ?>" data-return-after-print style="display:none"></a><?php endif; ?>
</div>
<?= $content ?>
<script src="<?= e(asset('js/print.js')) ?>"></script>
</body>
</html>
