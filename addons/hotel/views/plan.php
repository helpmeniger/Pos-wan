<!doctype html>
<html <?= html_attrs() ?> data-bs-theme="dark">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>"><meta name="base-url" content="<?= e(url('/')) ?>">
<title><?= e(t('Plan des chambres')) ?> · <?= e(setting('company_name')) ?></title>
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>">
<link rel="stylesheet" href="<?= e(bootstrap_css()) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('addons/hotel/hotel-plan.css')) ?>">
</head>
<body class="hp" data-refresh="<?= (int) $refresh ?>" data-can-reception="<?= $can['reception'] ? '1' : '0' ?>" data-can-hk="<?= $can['hk'] ? '1' : '0' ?>" data-can-manage="<?= $can['manage'] ? '1' : '0' ?>">
<header class="hp-head">
  <div class="hp-title"><i class="bi bi-building"></i> <?= e(t('Plan des chambres')) ?><small><?= e(setting('company_name')) ?> · <span id="today"></span></small></div>
  <div class="hp-kpis" id="kpis">
    <span class="k occ"><b id="kOcc">–</b> % <?= e(t('occupation')) ?></span>
    <span class="k"><b id="kOccupied">–</b> <?= e(t('occupées')) ?></span>
    <span class="k free"><b id="kFree">–</b> <?= e(t('libres')) ?></span>
    <span class="k arr"><b id="kArr">–</b> <?= e(t('arrivées')) ?></span>
    <span class="k dep"><b id="kDep">–</b> <?= e(t('départs')) ?></span>
    <span class="k dirty"><b id="kDirty">–</b> <?= e(t('à nettoyer')) ?></span>
    <span class="k ooo"><b id="kOoo">–</b> <?= e(t('hors service')) ?></span>
    <span class="k"><b id="kGuests">–</b> <?= e(t('clients')) ?></span>
  </div>
  <div class="ms-auto d-flex align-items-center gap-2 flex-wrap">
    <?php if ($can['reception']): ?>
    <a class="btn btn-sm btn-success" href="<?= e(url('/hotel/reservations/nouvelle', ['walkin' => 1])) ?>"><i class="bi bi-person-plus"></i> <?= e(t('Client sans réservation')) ?></a>
    <a class="btn btn-sm btn-primary" href="<?= e(url('/hotel/reservations/nouvelle')) ?>"><i class="bi bi-calendar-plus"></i> <?= e(t('Réservation')) ?></a>
    <?php endif; ?>
    <span class="hp-clock" id="clock"></span>
    <button class="btn btn-sm btn-outline-light" id="themeBtn" title="<?= e(t('Clair / sombre')) ?>"><i class="bi bi-circle-half"></i></button>
    <button class="btn btn-sm btn-outline-light" id="fsBtn" title="<?= e(t('Plein écran')) ?>"><i class="bi bi-fullscreen"></i></button>
    <a href="<?= e(url('/hotel')) ?>" class="btn btn-sm btn-outline-light" title="<?= e(t('Quitter')) ?>"><i class="bi bi-box-arrow-left"></i></a>
  </div>
</header>
<div class="hp-tools">
  <div class="hp-filters" id="stateFilters">
    <button class="on" data-state=""><?= e(t('Toutes')) ?> <span data-count=""></span></button>
    <button data-state="libre"><i class="dot libre"></i><?= e(t('Libres')) ?> <span data-count="libre"></span></button>
    <button data-state="occupee"><i class="dot occupee"></i><?= e(t('Occupées')) ?> <span data-count="occupee"></span></button>
    <button data-state="arrivee"><i class="dot arrivee"></i><?= e(t('Arrivées')) ?> <span data-count="arrivee"></span></button>
    <button data-state="depart"><i class="dot depart"></i><?= e(t('Départs')) ?> <span data-count="depart"></span></button>
    <button data-state="sale"><i class="dot sale"></i><?= e(t('À nettoyer')) ?> <span data-count="sale"></span></button>
    <button data-state="hors_service"><i class="dot hors_service"></i><?= e(t('Hors service')) ?> <span data-count="hors_service"></span></button>
  </div>
  <div class="d-flex gap-2 ms-auto">
    <select id="floorSel" class="form-select form-select-sm"><option value=""><?= e(t('Tous les étages')) ?></option></select>
    <select id="typeSel" class="form-select form-select-sm"><option value=""><?= e(t('Tous les types')) ?></option><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select>
    <input id="q" class="form-control form-control-sm" style="width:12rem" placeholder="<?= e(t('Chambre, client, n°')) ?>">
  </div>
</div>
<div class="hp-status" id="status"></div>
<main class="hp-board" id="board"><div class="hp-empty"><?= e(t('Chargement…')) ?></div></main>
<aside class="hp-drawer" id="drawer" aria-hidden="true"><div class="hp-drawer-in" id="drawerBody"></div></aside>
<div class="hp-veil" id="veil"></div>
<footer class="hp-foot">
  <span class="legend"><i class="dot libre"></i><?= e(t('Libre et propre')) ?> <i class="dot sale"></i><?= e(t('Libre, à nettoyer')) ?> <i class="dot occupee"></i><?= e(t('Occupée')) ?> <i class="dot arrivee"></i><?= e(t('Arrivée attendue')) ?>
    <i class="dot depart"></i><?= e(t('Départ aujourd\'hui')) ?> <i class="dot retard"></i><?= e(t('Départ dépassé')) ?> <i class="dot hors_service"></i><?= e(t('Hors service')) ?></span>
  <span class="ms-auto"><span id="updated"></span> · <?= e(t('Actualisation automatique')) ?> <?= (int) $refresh ?> s · <?= e($u['full_name']) ?></span>
</footer>
<?= i18n_script() ?>
<script src="<?= e(asset('addons/hotel/hotel-plan.js')) ?>"></script>
</body>
</html>
