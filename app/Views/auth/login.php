<?php $tab = in_array($_GET['tab'] ?? '', ['pin', 'badge'], true) ? $_GET['tab'] : (empty($quickUsers) ? 'password' : 'pin'); ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connexion · POS Wan</title>
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="login-body">
<div class="login-card">
  <div class="login-head">
    <img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="58" height="58">
    <h1>POS WAN</h1>
    <small><?= e(setting('company_name', 'Caisse & gestion commerciale')) ?></small>
  </div>
  <div class="px-4 pb-4">
    <?php foreach ($flash as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?> py-2 small"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <ul class="nav nav-pills nav-fill mb-3 small" role="tablist">
      <?php if ($quickUsers): ?><li class="nav-item"><button class="nav-link <?= $tab === 'pin' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#t-pin" type="button"><i class="bi bi-grid-3x3-gap"></i> Accès rapide PIN</button></li><?php endif; ?>
      <li class="nav-item"><button class="nav-link <?= $tab === 'password' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#t-pwd" type="button"><i class="bi bi-person-lock"></i> Identifiant</button></li>
      <?php if (setting('rfid_enabled') === '1'): ?><li class="nav-item"><button class="nav-link <?= $tab === 'badge' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#t-badge" type="button"><i class="bi bi-wifi"></i> Badge</button></li><?php endif; ?>
    </ul>
    <div class="tab-content">
      <?php if ($quickUsers): ?>
      <div class="tab-pane fade <?= $tab === 'pin' ? 'show active' : '' ?>" id="t-pin">
        <form method="post" action="<?= e(url('/login/pin')) ?>" id="pinForm" autocomplete="off">
          <?= csrf_field() ?>
          <select name="username" class="form-select form-select-lg mb-2" required aria-label="Utilisateur">
            <?php foreach ($quickUsers as $q): ?><option value="<?= e($q['username']) ?>"><?= e($q['full_name']) ?></option><?php endforeach; ?>
          </select>
          <input type="password" name="pin" id="pinInput" class="form-control text-center visually-hidden" inputmode="numeric" maxlength="8" pattern="\d{4,8}" required aria-label="Code PIN">
          <div class="pin-dots" id="pinDots" aria-hidden="true"></div>
          <div class="keypad">
            <?php foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $k): ?><button type="button" data-key="<?= $k ?>"><?= $k ?></button><?php endforeach; ?>
            <button type="button" class="k-del" data-key="del" aria-label="Effacer"><i class="bi bi-backspace"></i></button>
            <button type="button" data-key="0">0</button>
            <button type="submit" class="k-go">GO</button>
          </div>
        </form>
      </div>
      <?php endif; ?>
      <div class="tab-pane fade <?= $tab === 'password' ? 'show active' : '' ?>" id="t-pwd">
        <form method="post" action="<?= e(url('/login')) ?>">
          <?= csrf_field() ?>
          <label class="form-label small" for="username">Identifiant</label>
          <div class="input-group mb-3"><span class="input-group-text"><i class="bi bi-person"></i></span>
            <input type="text" id="username" name="username" class="form-control" autocomplete="username" required autofocus maxlength="50"></div>
          <label class="form-label small" for="password">Mot de passe</label>
          <div class="input-group mb-4"><span class="input-group-text"><i class="bi bi-key"></i></span>
            <input type="password" id="password" name="password" class="form-control" autocomplete="current-password" required maxlength="200"></div>
          <button class="btn btn-warning w-100 fw-semibold py-2"><i class="bi bi-check2-circle"></i> Valider</button>
        </form>
      </div>
      <?php if (setting('rfid_enabled') === '1'): ?>
      <div class="tab-pane fade <?= $tab === 'badge' ? 'show active' : '' ?>" id="t-badge">
        <form method="post" action="<?= e(url('/login/badge')) ?>" autocomplete="off">
          <?= csrf_field() ?>
          <p class="small text-center text-secondary">Passez votre badge devant le lecteur RFID (lecteur USB en émulation clavier).</p>
          <input type="password" name="badge" class="form-control form-control-lg text-center" placeholder="Badge RFID" required maxlength="100" data-autofocus-badge>
          <button class="btn btn-warning w-100 mt-3">Valider</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <p class="text-center small text-secondary mt-4 mb-0"><i class="bi bi-shield-lock"></i> Connexion protégée · tentatives limitées</p>
  </div>
</div>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/login.js')) ?>"></script>
</body>
</html>
