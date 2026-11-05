<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'POS Marwa en ligne') ?> · <?= e($operator['operator_name'] ?? 'POS Marwa en ligne') ?></title>
<meta name="description" content="POS Marwa en ligne : caisse, stock, achats, clients et rapports pour les commerces, accessible depuis un navigateur.">
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/saas.css')) ?>">
<?php if (!empty($refreshTo)): ?><meta http-equiv="refresh" content="2;url=<?= e($refreshTo) ?>"><?php endif; ?>
</head>
<body class="saas-public">
<nav class="navbar navbar-expand-md saas-nav">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e(url('/')) ?>"><img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="34" height="34"><span><b>POS Marwa</b> <small>en ligne</small></span></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nv" aria-label="Menu"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="nv">
      <ul class="navbar-nav ms-auto align-items-md-center gap-md-2">
        <li class="nav-item"><a class="nav-link" href="<?= e(url('/')) ?>#offres">Offres</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= e(url('/trouver')) ?>">Se connecter à mon espace</a></li>
        <li class="nav-item"><a class="btn btn-warning fw-semibold" href="<?= e(url('/inscription')) ?>">Essai gratuit</a></li>
      </ul>
    </div>
  </div>
</nav>
<main><?= $content ?></main>
<footer class="saas-foot">
  <div class="container d-flex flex-wrap justify-content-between gap-3 small">
    <div><b><?= e($operator['operator_name']) ?></b><?php if ($operator['operator_address']): ?> · <?= e($operator['operator_address']) ?><?php endif; ?><?php if ($operator['operator_phone']): ?> · <i class="bi bi-telephone"></i> <?= e($operator['operator_phone']) ?><?php endif; ?></div>
    <div><a href="<?= e(url('/conditions')) ?>">Conditions et données personnelles</a> · Logiciel POS Marwa © <?= e(config('licence.author.name')) ?></div>
  </div>
</footer>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/saas.js')) ?>"></script>
</body>
</html>
