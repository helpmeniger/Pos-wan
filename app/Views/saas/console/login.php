<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connexion · Console POS Marwa en ligne</title><meta name="robots" content="noindex">
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/saas.css')) ?>">
</head>
<body class="console-login">
<form method="post" action="<?= e(url('/console/login')) ?>" class="saas-card" style="width:min(400px,92vw)">
  <?= csrf_field() ?>
  <div class="text-center mb-3"><img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="54" height="54"><h1 class="h5 mt-2 mb-0">Console de l'exploitant</h1><small class="text-muted">POS Marwa en ligne</small></div>
  <?php foreach ($flash as $f): ?><div class="alert alert-<?= e($f['type']) ?> py-2 small"><?= e($f['message']) ?></div><?php endforeach; ?>
  <?php if ($none): ?><div class="alert alert-info small">Aucun compte de console n'existe encore. Créez le premier sur le serveur : <code>php bin/saas.php admin</code></div><?php endif; ?>
  <label class="form-label">Identifiant</label><input name="username" class="form-control mb-2" required autocomplete="username" autofocus>
  <label class="form-label">Mot de passe</label><input name="password" type="password" class="form-control mb-3" required autocomplete="current-password">
  <button class="btn btn-primary w-100">Se connecter</button>
</form>
</body>
</html>
