<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= e($title ?? 'Document') ?> — JOB NIGER</title>
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="jn-print">
  <div class="container py-3 no-print d-flex gap-2 justify-content-end">
    <button type="button" class="btn btn-primary" data-print>Imprimer / enregistrer en PDF</button>
    <a class="btn btn-outline-secondary" href="<?= e(url('/tableau-de-bord')) ?>" data-back>Retour</a>
  </div>
  <main class="container jn-print-sheet"><?= $this->section('content') ?></main>
  <script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
