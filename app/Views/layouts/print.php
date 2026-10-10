<?php /** Mise en page d'impression (ticket 58/80 mm ou A4) : aucun menu n'est imprimé. */ ?><!doctype html>
<html <?= html_attrs() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Impression') ?></title>
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/print.css')) ?>">
<?php if (App\Core\Lang::dir() === 'rtl'): ?><style>body { direction: rtl; } .ticket .num, .sheet .num { text-align: left; } .sheet .totals { margin-left: 0; margin-right: auto; }</style><?php endif; ?>
</head>
<body class="<?= e($bodyClass ?? 'a4') ?>">
<div class="print-toolbar no-print">
  <button type="button" data-print class="pbtn primary"><i class="bi bi-printer"></i> Imprimer</button>
  <a href="<?= e($backUrl ?? url('/')) ?>" class="pbtn"><i class="bi bi-arrow-left"></i> Retour</a>
  <?php foreach ($toolbarLinks ?? [] as [$tlUrl, $tlLabel, $tlIcon]): ?><a href="<?= e($tlUrl) ?>" class="pbtn"><i class="bi bi-<?= e($tlIcon) ?>"></i> <?= e($tlLabel) ?></a><?php endforeach; ?>
  <?php if (!empty($autoPrint)): ?><span data-autoprint></span><?php endif; ?>
  <?php if (!empty($returnAfterPrint)): ?><a href="<?= e($backUrl) ?>" data-return-after-print style="display:none"></a><?php endif; ?>
</div>
<?= $content ?>
<script src="<?= e(asset('vendor/qrcode/qrcode.js')) ?>"></script>
<script src="<?= e(asset('js/qr.js')) ?>"></script>
<script src="<?= e(asset('js/print.js')) ?>"></script>
</body>
</html>
