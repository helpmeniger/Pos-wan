<!doctype html>
<html <?= html_attrs() ?> data-bs-theme="dark">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>"><meta name="base-url" content="<?= e(url('/')) ?>">
<title><?= e($public ? t('Dépôts prêts') : t('Dépôts en cours')) ?> · <?= e(setting('company_name')) ?></title>
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>">
<link rel="stylesheet" href="<?= e(bootstrap_css()) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('addons/pressing/pressing-ecran.css')) ?>">
</head>
<body class="pe<?= $public ? ' pe-public' : '' ?>" data-refresh="<?= (int) $refresh ?>" data-public="<?= $public ? '1' : '0' ?>" data-can="<?= $canProcess && !$public ? '1' : '0' ?>" data-deliver="<?= !empty($canDeliver) && !$public ? '1' : '0' ?>">
<header class="pe-head">
  <div class="pe-title"><i class="bi bi-basket2-fill"></i> <?= e($public ? t('Vos vêtements sont prêts') : t('Dépôts en cours')) ?><small><?= e(setting('company_name')) ?></small></div>
  <?php if (!$public): ?>
  <div class="pe-kpis">
    <span class="k"><b id="kOpen">–</b> <?= e(t('en cours')) ?></span>
    <span class="k late"><b id="kLate">–</b> <?= e(t('en retard')) ?></span>
    <span class="k today"><b id="kToday">–</b> <?= e(t('à rendre aujourd\'hui')) ?></span>
    <span class="k ready"><b id="kReady">–</b> <?= e(t('prêts à retirer')) ?></span>
    <span class="k deliv"><b id="kDelivery">–</b> <?= e(t('à livrer')) ?></span>
    <span class="k exp"><b id="kExpress">–</b> express</span>
    <span class="k"><b id="kPieces">–</b> <?= e(t('pièces')) ?></span>
    <span class="k due"><b id="kDue">–</b> <?= e(t('reste à encaisser')) ?></span>
  </div>
  <?php endif; ?>
  <div class="ms-auto d-flex align-items-center gap-2">
    <?php if (!$public): ?><input id="q" class="form-control form-control-sm" style="width:12rem" placeholder="<?= e(t('N°, client, téléphone')) ?>">
    <label class="small text-nowrap"><input type="checkbox" id="lateOnly"> <?= e(t('Retards seulement')) ?></label><?php endif; ?>
    <span class="pe-clock" id="clock"></span>
    <button class="btn btn-sm btn-outline-light" id="fsBtn" title="<?= e(t('Plein écran')) ?>"><i class="bi bi-fullscreen"></i></button>
    <a href="<?= e(url('/pressing/suivi')) ?>" class="btn btn-sm btn-outline-light" title="<?= e(t('Quitter')) ?>"><i class="bi bi-box-arrow-left"></i></a>
  </div>
</header>
<div class="pe-status" id="status"></div>
<main class="pe-board<?= $public ? ' pe-board-public' : '' ?>" id="board"><div class="pe-empty"><?= e(t('Chargement…')) ?></div></main>
<footer class="pe-foot"><span id="updated"></span> · <?= e(t('Actualisation automatique')) ?> <?= (int) $refresh ?> s<?= $public ? '' : ' · ' . e($u['full_name']) . ' · <a href="' . e(url('/pressing/ecran', ['public' => 1])) . '" target="_blank">' . e(t('Affichage pour les clients')) . '</a>' ?></footer>
<?= i18n_script() ?>
<script src="<?= e(asset('addons/pressing/pressing-ecran.js')) ?>"></script>
</body>
</html>
