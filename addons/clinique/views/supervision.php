<!doctype html>
<html <?= html_attrs() ?> data-bs-theme="dark">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>"><meta name="base-url" content="<?= e(url('/')) ?>">
<title>Supervision des malades · <?= e(setting('company_name')) ?></title>
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>">
<link rel="stylesheet" href="<?= e(bootstrap_css()) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('addons/clinique/clinique.css')) ?>">
</head>
<body class="sup" data-refresh="<?= (int) $refresh ?>" data-can-log="<?= can('clinique.nurse') ? '1' : '0' ?>">
<header class="sup-head">
  <div class="sup-title"><i class="bi bi-heart-pulse-fill"></i> <?= e(t('Supervision des malades')) ?><small><?= e(setting('company_name')) ?></small></div>
  <div class="sup-kpis">
    <span class="k"><b id="kPatients">–</b> <?= e(t('patients')) ?></span>
    <span class="k alert-k"><b id="kAlerts">–</b> <?= e(t('en alerte')) ?></span>
    <span class="k late-k"><b id="kLate">–</b> <?= e(t('soins en retard')) ?></span>
    <span class="k due-k"><b id="kDue">–</b> <?= e(t('soins dans l\'heure')) ?></span>
    <span class="k vit-k"><b id="kVitals">–</b> <?= e(t('constantes à reprendre')) ?></span>
    <span class="k free-k"><b id="kFree">–</b> <?= e(t('lits libres')) ?></span>
  </div>
  <div class="ms-auto d-flex align-items-center gap-2">
    <select id="ward" class="form-select form-select-sm w-auto"><option value=""><?= e(t('Tous les services')) ?></option></select>
    <label class="small text-nowrap"><input type="checkbox" id="showFree"> <?= e(t('Lits libres')) ?></label>
    <button class="btn btn-sm btn-outline-light" id="soundBtn" title="<?= e(t('Son des alertes')) ?>"><i class="bi bi-volume-up"></i></button>
    <span class="sup-clock" id="clock"></span>
    <button class="btn btn-sm btn-outline-light" id="fsBtn" title="<?= e(t('Plein écran')) ?>"><i class="bi bi-fullscreen"></i></button>
    <a href="<?= e(url('/clinique/hospitalisation')) ?>" class="btn btn-sm btn-outline-light" title="<?= e(t('Quitter')) ?>"><i class="bi bi-box-arrow-left"></i></a>
  </div>
</header>
<div class="sup-status" id="status"></div>
<main class="sup-board" id="board"><div class="sup-empty"><?= e(t('Chargement…')) ?></div></main>
<footer class="sup-foot"><span id="updated"></span> · <?= e(t('Actualisation automatique')) ?> <?= (int) $refresh ?> s · <?= e($u['full_name']) ?> · <?= e(t('Données médicales confidentielles : ne pas laisser cet écran visible du public.')) ?></footer>
<?= i18n_script() ?>
<script src="<?= e(asset('addons/clinique/supervision.js')) ?>"></script>
</body>
</html>
