<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Erreur <?= (int) $status ?> · POS Wan</title>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>"><link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>"></head>
<body class="error-page d-flex align-items-center justify-content-center">
<div class="text-center p-4">
  <div class="display-1 fw-bold text-warning"><?= (int) $status ?></div>
  <p class="lead"><?= e($message) ?></p>
  <?php if (!empty($detail)): ?><pre class="text-start small bg-light p-3 border rounded"><?= e($detail) ?></pre><?php endif; ?>
  <a href="<?= e(url('/')) ?>" class="btn btn-primary">Retour à l'accueil</a>
</div></body></html>
