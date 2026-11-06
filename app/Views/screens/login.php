<!doctype html>
<html <?= html_attrs() ?>>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($s['label']) ?> · <?= e($company) ?></title>
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>">
<link rel="stylesheet" href="<?= e(bootstrap_css()) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="login-body">
<div class="login-card" style="max-width:460px">
  <div class="login-head">
    <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="" style="max-height:64px;max-width:200px"><?php else: ?><img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="58" height="58"><?php endif; ?>
    <h1 style="font-size:1.4rem"><i class="bi <?= e($s['icon']) ?>"></i> <?= e($s['label']) ?></h1>
    <small><?= e($company) ?></small>
  </div>
  <div class="px-4 pb-4">
    <?php foreach ($flash as $f): ?><div class="alert alert-<?= e($f['type']) ?> py-2 small"><?= e($f['message']) ?></div><?php endforeach; ?>
    <?php if (!$s['available']): ?><div class="alert alert-warning small">Cet écran n'est pas activé sur ce logiciel.</div><?php endif; ?>
    <?php if ($loggedOther): ?><div class="alert alert-info small">Le compte connecté sur ce navigateur n'a pas accès à cet écran : saisissez le compte d'une personne autorisée.</div><?php endif; ?>
    <form method="post" action="<?= e(url('/ecran/' . $code)) ?>" autocomplete="off">
      <?= csrf_field() ?>
      <label class="form-label" for="sUser">Identifiant</label>
      <input id="sUser" name="username" class="form-control form-control-lg mb-3" required autocapitalize="off" autofocus>
      <label class="form-label" for="sPwd">Mot de passe</label>
      <input id="sPwd" name="password" type="password" class="form-control form-control-lg mb-3" required>
      <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="remember" value="1" id="sRem" checked><label class="form-check-label" for="sRem">Mémoriser cet écran (il se reconnecte seul après une coupure)</label></div>
      <button class="btn btn-warning btn-lg w-100 fw-semibold"><i class="bi bi-play-circle"></i> Afficher l'écran</button>
    </form>
    <p class="small text-center mt-3 mb-0 opacity-75">Ce navigateur n'aura accès qu'à cet écran, pas au reste du logiciel.</p>
  </div>
</div>
</body>
</html>
